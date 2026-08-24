<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\PriceOverride;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Reconciliation\Enums\ItemStatus;
use App\Domain\Reconciliation\Models\ReconciliationItem;
use App\Domain\Sync\Actions\RequestResync;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fiyat çakışmasında satıcının kararı — §9'un "kullanıcı seçer" adımı.
 *
 * Mimari Karar Dokümanı v2.2 · §9 (PRICE politikası), §11 (denetim kaydı),
 * §1 · Karar 18 (durum değişikliği tek başına iş üretmez).
 *
 * İKİ KARAR, TEK ACTION:
 *
 *   ACCEPT_CHANNEL  "kanalınki kalsın" → `price_overrides` satırı yazılır ve
 *                   o listing fiyat fan-out'undan ELENİR. Kanala HİÇBİR
 *                   istek gitmez: zaten istenen değer orada duruyor.
 *   PUSH_OURS       "bizimki gitsin" → varsa override KALDIRILIR ve
 *                   `RequestResync` çağrılır.
 *
 * ─────────────────────────────────────────────────────────────────────
 * "BİZİMKİ GİTSİN" DURUM YAZMAKLA OLMAZ (§9 · Karar 18)
 * ─────────────────────────────────────────────────────────────────────
 * `listing_sync_states.status = 'pending'` yazmak hiçbir şey yapmazdı:
 * kanonik fiyat DEĞİŞMEDİ ve değişmeyen veriden yeni domain olayı doğmaz.
 * Satır panelde "bekliyor" görünür, sonsuza kadar bekler ve satıcı
 * düğmesinin neden işe yaramadığını anlamaz. Bu yüzden `RequestResync`
 * çağrılır ve o, AYNI transaction'da `ListingResyncRequested` yazar.
 *
 * SEBEP SABİTİ ZATEN VARDI (`REASON_PRICE_CONFLICT_RESOLVED`) — yol açık
 * bırakılmış, hiç bağlanmamıştı. Bağlanan yer burasıdır.
 *
 * NİYET REPAIR'dır ve bu ZORUNLUDUR: kanonik fiyat değişmediği için
 * `desired_version` zaten gönderilmiş olabilir ve NORMAL_SYNC ile açılsaydı
 * sürüm kapısı operasyonu SESSİZCE eler — satıcının "bizimki gitsin"i
 * hiçbir şey yapmazdı. `RequestResync` bu kararı kendi içinde taşıyor.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KALEM ÇÖZÜLDÜ İŞARETLENİR — AMA YENİ KALEM YAZILMAZ
 * ─────────────────────────────────────────────────────────────────────
 * `resolved_at` damgalanır ve satır ekranın varsayılan listesinden düşer.
 * Yeni bir kalem yazılsaydı "listing başına SON kalem" kuralı onu gösterir
 * ve satıcı kararını verdiği satırı ekranda görmeye devam ederdi.
 *
 * DURUM `PRICE_CONFLICT` KALIR, değiştirilmez: kalem o turda gerçekten
 * çakışma bulmuştu ve denetim izi geçmişi olduğu gibi taşımalıdır.
 * Çözülmüşlüğü `resolved_at` söyler — `MATCHED` yazmak "zaten doğruydu"
 * demek olurdu ve bu YANLIŞTIR.
 *
 * ─────────────────────────────────────────────────────────────────────
 * HEPSİ TEK TRANSACTION
 * ─────────────────────────────────────────────────────────────────────
 * Override yazımı, kalem damgası, denetim kaydı ve resync olayı aynı
 * transaction'dadır. Ayrı olsalardı araya düşen bir hata her yönde bozuk
 * durum bırakırdı: override yazılmış ama kalem hâlâ açık (satıcı aynı
 * kararı tekrar verir), ya da resync istenmiş ama override kaldırılmamış
 * (gönderim yükte elenir ve talep sessizce hiçbir şey yapmaz).
 */
final class ResolvePriceConflict
{
    public const ACCEPT_CHANNEL = 'accept_channel';

    public const PUSH_OURS = 'push_ours';

    public function __construct(
        private readonly RequestResync $requestResync,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * @param  string  $decision  self::ACCEPT_CHANNEL veya self::PUSH_OURS
     */
    public function run(ReconciliationItem $item, string $decision, ?string $userId = null): void
    {
        if (! in_array($decision, [self::ACCEPT_CHANNEL, self::PUSH_OURS], true)) {
            throw new RuntimeException("Bilinmeyen fiyat çakışması kararı: {$decision}");
        }

        // KALEM GERÇEKTEN ÇAKIŞMA MI — kapı burada. Olmasaydı panelden
        // gelen herhangi bir kalem kimliği override yazdırabilirdi ve
        // satıcı hiç çakışma olmayan bir listing'in fiyatını kilitlerdi.
        if ($item->status !== ItemStatus::PRICE_CONFLICT->value) {
            throw new RuntimeException(
                "Kalem {$item->id} fiyat çakışması değil ({$item->status}); karar uygulanamaz."
            );
        }

        $listing = $item->listing;

        if ($listing === null) {
            throw new RuntimeException("Kalem {$item->id} için listing bulunamadı.");
        }

        $channelPrice = $this->channelPriceOf($item);
        $ourPrice = $this->ourPriceOf($item);

        DB::transaction(function () use ($item, $listing, $decision, $channelPrice, $ourPrice, $userId): void {
            $decision === self::ACCEPT_CHANNEL
                ? $this->acceptChannel($listing, $channelPrice, $ourPrice, $userId)
                : $this->pushOurs($listing);

            $item->forceFill(['resolved_at' => now()])->save();

            $this->audit->run(
                action: AuditAction::PRICE_CONFLICT_RESOLVED,
                subjectType: 'listing',
                subjectId: $listing->id,
                changes: [
                    'decision' => $decision,
                    'channel_price' => $channelPrice,
                    'our_price' => $ourPrice,
                    'reconciliation_item_id' => $item->id,
                ],
                userId: $userId,
            );
        });
    }

    // ---------------------------------------------------------------- iç

    /**
     * "Kanalınki kalsın" — override yazılır, kanala istek GİTMEZ.
     *
     * `updateOrCreate` KULLANILIR: listing başına tek satır vardır
     * (`UNIQUE(listing_id)`) ve satıcı aynı listing için ikinci kez karar
     * verebilir (kanal fiyatı yine değişmiştir). `create` çağrılsaydı
     * ikinci karar tekillik ihlaliyle 500 döndürürdü — oysa istek meşru.
     * Tarihçe `audit_logs` tarafında yaşar (migration başlığındaki karar).
     */
    private function acceptChannel(
        Listing $listing,
        string $channelPrice,
        string $ourPrice,
        ?string $userId,
    ): void {
        PriceOverride::query()->updateOrCreate(
            ['listing_id' => $listing->id],
            [
                'tenant_id' => TenantContext::idOrFail(),
                'channel_price' => $channelPrice,
                'our_price' => $ourPrice,
                'accepted_at' => now(),
                'accepted_by' => $userId,
                // Süre satıcıdan alınmaz: ekran bir tarih SORMUYOR ve
                // uydurma bir tarih, geldiğinde fiyatı HABERSİZCE ezerdi
                // (migration başlığındaki gerekçe). Override elle
                // kaldırılana kadar sürer.
                'expires_at' => null,
            ],
        );
    }

    /**
     * "Bizimki gitsin" — override kaldırılır ve resync istenir.
     *
     * SİLME ÖNCE GELİR: `RequestResync` olayı yazdıktan sonra silinseydi
     * araya düşen bir hata (transaction dışı bir yeniden deneme, farklı bir
     * yol) override'ı bırakır ve açılan operasyon `PriceBatchBuilder`
     * tarafından ELENİRDİ — talep sessizce hiçbir şey yapmazdı. Aynı
     * transaction içinde olsalar da sıra niyeti okunur kılıyor.
     */
    private function pushOurs(Listing $listing): void
    {
        PriceOverride::query()->where('listing_id', $listing->id)->delete();

        $this->requestResync->run(
            $listing,
            SyncDomain::PRICE,
            RequestResync::REASON_PRICE_CONFLICT_RESOLVED,
        );
    }

    /**
     * Kalemin taşıdığı KANAL fiyatı.
     *
     * Kalem yazıldığı andaki gözlemdir ve karar ona verilmiştir. Kanaldan
     * yeniden okunsaydı satıcının ekranda gördüğü değer ile kabul ettiği
     * değer AYRIŞABİLİRDİ (kanal fiyatı arada yine değişmiş olabilir) —
     * satıcı görmediği bir fiyatı kabul etmiş olurdu.
     */
    private function channelPriceOf(ReconciliationItem $item): string
    {
        $price = ($item->remote_value ?? [])['price'] ?? null;

        if ($price === null) {
            throw new RuntimeException("Kalem {$item->id} kanal fiyatı taşımıyor.");
        }

        return (string) $price;
    }

    private function ourPriceOf(ReconciliationItem $item): string
    {
        return (string) (($item->local_value ?? [])['price'] ?? '0');
    }
}
