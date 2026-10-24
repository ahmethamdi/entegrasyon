<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Inventory\Support\SellableStock;
use App\Domain\Sync\Models\Listing;
use Illuminate\Support\Facades\DB;

/**
 * Bir listing'in kanala giden stok ve fiyatı — KANONİK kaynaklardan (S3).
 *
 * İKİNCİ BİR STOK GERÇEĞİ YOKTUR: stok `inventory_levels.available`
 * (varsayılan depo, `OutboundQuantity` ile 0 altı kırpılır) — fan-out'un
 * kullandığı aynı değer (`InventoryBatchBuilder`). Fiyat
 * `Listing::effectivePrice()` (kanal fiyatı → kural → kampanya).
 *
 * HER ŞEY LISTING'İN KENDİ VARYANTINDAN: kardeş varyantın stoğu ya da
 * fiyatı hiçbir yoldan gelmez. Okur; hareket YAZMAZ.
 */
final class ListingProjection
{
    public static function quantity(Listing $listing): int
    {
        $warehouseId = DB::table('warehouses')
            ->where('tenant_id', $listing->tenant_id)
            ->where('is_default', true)
            ->value('id');

        if ($warehouseId === null) {
            return 0;
        }

        // Set ilanında bileşenlerden türetilir (`SellableStock`).
        return SellableStock::forVariant($listing->tenant_id, $listing->variant_id, (string) $warehouseId)?->outbound() ?? 0;
    }

    public static function salePrice(Listing $listing): string
    {
        return (string) $listing->effectivePrice();
    }

    public static function listPrice(Listing $listing): string
    {
        return (string) ($listing->effectiveCompareAtPrice() ?? $listing->effectivePrice());
    }
}
