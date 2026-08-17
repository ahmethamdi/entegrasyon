<?php

declare(strict_types=1);

namespace App\Domain\Sync\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Jobs\PushListing;
use App\Domain\Sync\Models\Listing;
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
 */
final class PublishListing
{
    public function __construct(
        private readonly OpenSyncOperation $openSyncOperation,
    ) {}

    /**
     * @return list<string> Kuyruğa atılan operasyon kimlikleri
     */
    public function run(Product $product, ChannelConnection $connection): array
    {
        $product->loadMissing('variants');

        $tenantId = $product->tenant_id;

        $pending = DB::transaction(function () use ($product, $connection): array {
            $operationIds = [];

            foreach ($product->variants as $variant) {
                $listing = $this->listingFor($product, $variant->id, $connection);

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
        foreach ($pending as $operationId) {
            PushListing::dispatch($operationId, $tenantId)->onQueue('listing:default');
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
}
