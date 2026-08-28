<?php

declare(strict_types=1);

namespace App\Domain\Sync\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Support\ContentPushDispatcher;
use App\Domain\Sync\Support\PrerequisiteGate;
use App\Domain\Sync\Support\PrerequisiteResult;
use Illuminate\Support\Facades\DB;

/**
 * Ürünü bir kanala gönderilmek üzere kuyruğa alır — §13 · faz 1.5.
 *
 * Mimari Karar Dokümanı v2.2 · §8 · sync operation modeli.
 *
 * İKİ ADIM, TEK TRANSACTION: listing kimliği yaratılır (veya var olan
 * bulunur) ve içerik operasyonu açılır. Ayrı olsalardı araya düşen bir hata
 * operasyonsuz listing bırakır ve satır sonsuza kadar taslak kalırdı.
 *
 * DEĞİŞMEZ KURAL — CANLI İŞARETİ BURADA KONMAZ:
 *   Listing `draft` doğar. `live` yalnızca kanal ürünü kabul ettikten sonra,
 *   PushListing içinde yazılır. Canlı işareti stok fan-out'unun hedef
 *   filtresidir; kanalda karşılığı olmayan satıra stok göndermek her turda
 *   hata alırdı.
 *
 * DEĞİŞMEZ KURAL — İKİNCİ GÖNDERME İKİNCİ SATIR AÇMAZ:
 *   `(channel_connection_id, variant_id)` tekildir ve bu bilinçlidir: bir
 *   varyant bir mağazada bir kez listelenir. Yeniden gönderme var olan satırı
 *   kullanır; yeni satır denemek kısıt ihlali verirdi.
 *
 * DEĞİŞMEZ KURAL — İŞ EN SONDA ATILIR:
 *   Dispatch transaction'ın DIŞINDA yapılır. İçeride atılsaydı `sync`
 *   sürücüde iş derhal çalışır ve henüz commit edilmemiş operasyonu
 *   bulamazdı; kuyruk kancaları da kiracı bağlamını iş sınırında temizler.
 *
 * SÜRÜM ÜRÜNÜN content_version'INDAN GELİR: senkron kapısı ondan beslenir.
 * Uydurma bir sayaç, panelde "senkron" görünen ürünün kanala hiç gitmemesine
 * yol açardı.
 *
 * DEĞİŞMEZ KURAL — ÖN KOŞUL KAPISI GÖNDERMEDEN ÖNCE (§14):
 *   Taksonomisi olan kanalda eksik eşleştirme varsa listing `blocked`
 *   olur, operasyon AÇILMAZ ve iş atılmaz. Kapı yeteneğe göre çalışır;
 *   WooCommerce'te hiç devreye girmez. **STOK AKIŞI ETKİLENMEZ** —
 *   engellenen satır yalnızca içerik gönderiminin dışında kalır.
 */
final class PublishListing
{
    public function __construct(
        private readonly OpenSyncOperation $openSyncOperation,
        private readonly PrerequisiteGate $gate,
        private readonly ContentPushDispatcher $dispatcher,
    ) {}

    /**
     * @return list<string> Kuyruğa atılan operasyon kimlikleri
     */
    public function run(Product $product, ChannelConnection $connection): array
    {
        $product->loadMissing('variants');

        $tenantId = $product->tenant_id;

        // ÖN KOŞUL KAPISI transaction'dan ÖNCE: yalnızca okuma yapar ve
        // sonucu tüm varyantlar için aynıdır (iç kategori üründedir).
        $prerequisite = $this->gate->check($product, $connection);

        $pending = DB::transaction(function () use ($product, $connection, $prerequisite): array {
            $operationIds = [];

            foreach ($product->variants as $variant) {
                $listing = $this->listingFor($product, $variant->id, $connection);

                // ENGELLENDİ: satır işaretlenir, operasyon AÇILMAZ.
                // Kullanıcı eksiği kapatınca akış yeniden başlar.
                if (! $prerequisite->satisfied()) {
                    $this->block($listing, $prerequisite);

                    continue;
                }

                // Engel kalkmışsa taslağa geri döndür: `blocked` kalırsa
                // satır fan-out hedefi olamaz ve kanal onayı yazılsa bile
                // panelde engelli görünürdü.
                $this->unblock($listing);

                // Sürüm kapısı burada da geçerlidir: aynı sürüm iki kez
                // gönderilirse ikinci çağrı null döner ve iş atılmaz.
                $operation = $this->openSyncOperation->run(
                    listing: $listing,
                    domain: SyncDomain::CONTENT,
                    eventVersion: $product->content_version,
                    intent: SyncIntent::NORMAL_SYNC,
                );

                if ($operation !== null) {
                    $operationIds[] = $operation->id;
                }
            }

            return $operationIds;
        });

        // İşler transaction KAPANDIKTAN sonra atılır.
        // Kiracı kimliği yükte taşınır: worker'da bağlam YOKTUR ve işin
        // kendisi kurmak zorundadır (§11 · P0).
        //
        // ⚠️ HANGİ İŞ ATILACAĞI BURADA KARARLAŞMAZ. Çok adımlı yayın
        // kullanan kanal (§03 · Delta 1) farklı bir iş ister ve o karar
        // `ContentPushDispatcher` içinde TEK KAYNAKTIR — kopyalansaydı
        // yeniden deneme yolu ile bu yol ayrışır ve eBay listing'i
        // `/failures` ekranından denendiğinde sessizce ATLANIRDI.
        foreach ($pending as $operationId) {
            $this->dispatcher->dispatch($operationId, $tenantId, $connection);
        }

        return $pending;
    }

    /**
     * Listing satırını bulur veya taslak olarak yaratır.
     *
     * `firstOrCreate` yerine açık iki adım: tekillik kısıtı bağlantı ×
     * varyant üzerindedir ve kiracı scope'u zaten sorguya giriyor.
     */
    private function listingFor(Product $product, string $variantId, ChannelConnection $connection): Listing
    {
        $existing = Listing::query()
            ->where('channel_connection_id', $connection->id)
            ->where('variant_id', $variantId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Listing::query()->create([
            'tenant_id' => $product->tenant_id,
            'channel_connection_id' => $connection->id,
            'variant_id' => $variantId,
            // TASLAK doğar; canlı işaretini kanal onayından sonra
            // PushListing yazar.
            'lifecycle_status' => 'draft',
        ]);
    }

    /**
     * Ön koşul sağlanmadı — satırı işaretle, operasyon AÇMA.
     *
     * §14: `listings.lifecycle_status = 'blocked'` +
     * `listing_sync_states(CONTENT).status = 'blocked'`.
     *
     * SEBEP YAZILIR: satıcı panelde yalnızca "engellendi" görseydi neyi
     * düzelteceğini bilemez ve destek istemek zorunda kalırdı.
     *
     * SÜRÜM ALANLARINA DOKUNULMAZ: hiçbir şey gönderilmedi. `desired_version`
     * artırılsaydı eksik kapandığında sürüm kapısı gönderimi eler ve ürün
     * sessizce hiç gitmezdi.
     */
    private function block(Listing $listing, PrerequisiteResult $prerequisite): void
    {
        $listing->forceFill(['lifecycle_status' => 'blocked'])->save();

        $state = ListingSyncState::query()->firstOrNew([
            'listing_id' => $listing->id,
            'domain' => SyncDomain::CONTENT->value,
        ]);

        $state->forceFill([
            'tenant_id' => $listing->tenant_id,
            'status' => 'blocked',
            'last_error' => $prerequisite->reason(),
        ])->save();
    }

    /**
     * Engel kalktı — satırı taslağa döndür.
     *
     * Yalnızca `blocked` satıra dokunulur: `live` bir listing'i taslağa
     * çekmek onu fan-out hedefi olmaktan çıkarır ve kanaldaki canlı ürüne
     * stok gitmemeye başlardı.
     */
    private function unblock(Listing $listing): void
    {
        if ($listing->lifecycle_status !== 'blocked') {
            return;
        }

        $listing->forceFill(['lifecycle_status' => 'draft'])->save();

        // Durum satırı `pending`'e döner ve hata metni TEMİZLENİR: eski
        // sebep kalsaydı panel eksik kapanmışken hâlâ onu gösterirdi.
        ListingSyncState::query()
            ->where('listing_id', $listing->id)
            ->where('domain', SyncDomain::CONTENT->value)
            ->where('status', 'blocked')
            ->update(['status' => 'pending', 'last_error' => null]);
    }
}
