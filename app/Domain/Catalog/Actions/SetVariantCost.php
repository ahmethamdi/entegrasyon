<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\ChannelPriceRule;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Sync\Actions\RequestResync;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;

/**
 * Varyantın alış maliyetini yazar.
 *
 * Maliyet kanala GİTMEZ; yalnız zarar korumasının tabanıdır. Ama taban
 * değişince karar değişebilir: maliyet düşürülünce az önce durdurulan fiyat
 * artık gidebilir. Resync istenmeseydi o ilan satıcı fiyata dokunana kadar
 * eski fiyatta kalırdı. Yalnız zarar koruması AÇIK bağlantılardaki canlı
 * ilanlar yeniden gönderilir.
 */
final class SetVariantCost
{
    public function __construct(private readonly RequestResync $requestResync) {}

    /** @return bool Değişiklik oldu mu */
    public function run(Variant $variant, ?string $cost): bool
    {
        $new = $cost === null ? null : number_format((float) $cost, 2, '.', '');
        $old = $variant->cost_price === null ? null : (string) $variant->cost_price;

        if ($old === $new) {
            return false;
        }

        $variant->forceFill(['cost_price' => $new])->save();

        $guarded = ChannelPriceRule::query()->whereNotNull('min_margin_percent')->pluck('channel_connection_id');

        Listing::query()
            ->where('variant_id', $variant->id)
            ->where('lifecycle_status', 'live')
            ->whereIn('channel_connection_id', $guarded)
            ->get()
            ->each(fn (Listing $listing) => $this->requestResync->run($listing, SyncDomain::PRICE, RequestResync::REASON_COST_CHANGED));

        return true;
    }
}
