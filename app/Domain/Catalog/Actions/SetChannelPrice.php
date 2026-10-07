<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\PriceOverride;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Sync\Actions\RequestResync;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;
use Illuminate\Support\Facades\DB;

/**
 * Bir listing'in kanal fiyatını yazar (ya da kaldırır) ve kanala gönderir.
 *
 * ⚠️ ESKİ "KANALDAKİ KALSIN" KARARI SİLİNİR. `price_overrides` satırı
 * varken `PriceBatchBuilder` o listing'i ATLAR; silinmeseydi satıcının yeni
 * girdiği fiyat hiç gitmez ve panel "kaydedildi" derdi.
 *
 * ⚠️ GÖNDERİM `RequestResync` İLEDİR, sürüm uydurularak DEĞİL. Kanal fiyatı
 * varyantın `content_version`'ını değiştirmez; NORMAL_SYNC kapısı aynı
 * sürümü "zaten gönderildi" sayıp yutardı. Resync REPAIR operasyonu açar ve
 * kapıyı bilinçli olarak aşar (`ResolvePriceConflict::pushOurs` ile aynı yol).
 *
 * Yayında olmayan listing için resync İSTENMEZ: fiyat, ilan açılırken
 * `Listing::effectivePrice()` üzerinden zaten gider.
 */
final class SetChannelPrice
{
    public function __construct(
        private readonly RequestResync $requestResync,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * @param  string|null  $price  null = kanal fiyatını kaldır (ürün fiyatı gider)
     * @return bool Değişiklik oldu mu
     */
    public function run(Listing $listing, ?string $price, ?string $currency, ?string $userId = null): bool
    {
        $newPrice = $price === null ? null : number_format((float) $price, 2, '.', '');
        $newCurrency = $newPrice === null ? null : ($currency === null ? null : strtoupper($currency));

        $oldPrice = $listing->channel_price === null ? null : (string) $listing->channel_price;
        $oldCurrency = $listing->channel_price_currency;

        if ($oldPrice === $newPrice && $oldCurrency === $newCurrency) {
            return false;
        }

        DB::transaction(function () use ($listing, $newPrice, $newCurrency, $oldPrice, $oldCurrency, $userId): void {
            $listing->forceFill([
                'channel_price' => $newPrice,
                'channel_price_currency' => $newCurrency,
            ])->save();

            PriceOverride::query()->where('listing_id', $listing->id)->delete();

            $this->audit->run(
                action: AuditAction::CHANNEL_PRICE_SET,
                subjectType: 'listing',
                subjectId: $listing->id,
                changes: [
                    'old' => $oldPrice === null ? null : "{$oldPrice} {$oldCurrency}",
                    'new' => $newPrice === null ? null : "{$newPrice} {$newCurrency}",
                ],
                userId: $userId,
            );

            if ($listing->lifecycle_status === 'live') {
                $this->requestResync->run($listing, SyncDomain::PRICE, RequestResync::REASON_CHANNEL_PRICE_CHANGED);
            }
        });

        return true;
    }
}
