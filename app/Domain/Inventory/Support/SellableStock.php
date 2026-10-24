<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Support;

use Illuminate\Support\Facades\DB;

/**
 * Bir varyantın SATILABİLİR stoğu ve stok sürümü — kanala dönük TEK okuma.
 *
 * Standart varyant: `inventory_levels.available` / `version` (varsayılan depo).
 *
 * SET (`variants.kind = 'bundle'`): kendi seviyesi YOKTUR, TÜRETİLİR —
 *   available = min(⌊bileşen available / adet⌋)
 *   version   = Σ bileşen seviye sürümü + setin `bundle_revision`'ı
 * Sürüm TOPLAMDIR, bileşenlerden birinin sürümü DEĞİL: A bileşeni 50,
 * B bileşeni 3 sürümdeyken B'nin değişimi "3" taşısaydı set ilanının sürüm
 * kapısı onu 50'den eski sayıp eler ve set kanalda bayat kalırdı. Sürümler
 * yalnız arttığı için toplam da yalnız artar.
 *
 * Kanala giden miktarı hesaplayan, mutabakatın beklediği değeri okuyan ve
 * sürüm kapısına sürüm veren HER yer buradan okur (`InventoryBatchBuilder`,
 * `ListingProjection`, `ReconcileConnection`, `QueueRepair`,
 * `RequestResync`, fan-out). İkinci bir hesap yazılsaydı set stoğu bir
 * yerde doğru, bir yerde 0 görünürdü — denetimin uyardığı "paket sessizce
 * atlanır" hatası.
 *
 * Kırpma YOKTUR (negatif bileşen negatif set verir); kanala giden değer
 * yine `OutboundQuantity` ile kırpılır.
 */
final class SellableStock
{
    /**
     * @param  list<string>  $variantIds
     * @return array<string, StockView> varyant kimliği → görünüm (bilinmeyen varyant yok sayılır)
     */
    public static function forVariants(string $tenantId, array $variantIds, ?string $warehouseId = null): array
    {
        $variantIds = array_values(array_unique(array_filter($variantIds)));

        if ($variantIds === []) {
            return [];
        }

        $warehouseId ??= self::defaultWarehouse($tenantId);

        $variants = DB::table('variants')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $variantIds)
            ->get(['id', 'kind', 'bundle_revision'])
            ->keyBy('id');

        $bundleIds = $variants->where('kind', 'bundle')->keys()->all();
        $components = self::componentsOf($tenantId, $bundleIds);

        $levelIds = $variants->where('kind', '!=', 'bundle')->keys()->all();

        foreach ($components as $list) {
            foreach ($list as $component) {
                $levelIds[] = $component['variant_id'];
            }
        }

        $levels = $warehouseId === null ? collect() : DB::table('inventory_levels')
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('variant_id', array_values(array_unique($levelIds)))
            ->get(['variant_id', 'available', 'version'])
            ->keyBy('variant_id');

        $views = [];

        foreach ($variants as $id => $variant) {
            if ($variant->kind !== 'bundle') {
                $level = $levels->get($id);
                $views[$id] = new StockView((int) ($level->available ?? 0), (int) ($level->version ?? 0), $level !== null);

                continue;
            }

            $views[$id] = self::bundleView($components[$id] ?? [], $levels->all(), (int) $variant->bundle_revision);
        }

        return $views;
    }

    public static function forVariant(string $tenantId, string $variantId, ?string $warehouseId = null): ?StockView
    {
        return self::forVariants($tenantId, [$variantId], $warehouseId)[$variantId] ?? null;
    }

    /**
     * Setlerin bileşenleri — sipariş tarafında stok hareketinin hedefleri.
     *
     * @param  list<string>  $bundleIds
     * @return array<string, list<array{variant_id: string, quantity: int}>>
     */
    public static function componentsOf(string $tenantId, array $bundleIds): array
    {
        if ($bundleIds === []) {
            return [];
        }

        $map = [];

        $rows = DB::table('variant_components')
            ->where('tenant_id', $tenantId)
            ->whereIn('bundle_variant_id', $bundleIds)
            ->orderBy('component_variant_id')
            ->get(['bundle_variant_id', 'component_variant_id', 'quantity']);

        foreach ($rows as $row) {
            $map[$row->bundle_variant_id][] = ['variant_id' => (string) $row->component_variant_id, 'quantity' => (int) $row->quantity];
        }

        return $map;
    }

    /**
     * Varyantların stok taşıyan hedefleri: standart varyant kendisidir,
     * set bileşenlerine açılır. Sipariş, iptal, iade ve eşleşmemiş kalem
     * yolları kilidi ve hareketi bu listeye göre yapar.
     *
     * @param  list<string>  $variantIds
     * @return array<string, list<array{variant_id: string, quantity: int}>> varyant → [hedef, çarpan]
     */
    public static function targetsOf(string $tenantId, array $variantIds): array
    {
        $variantIds = array_values(array_unique(array_filter($variantIds)));

        if ($variantIds === []) {
            return [];
        }

        $bundleIds = DB::table('variants')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $variantIds)
            ->where('kind', 'bundle')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $components = self::componentsOf($tenantId, $bundleIds);
        $targets = [];

        foreach ($variantIds as $id) {
            $targets[$id] = in_array($id, $bundleIds, true)
                ? ($components[$id] ?? [])
                : [['variant_id' => $id, 'quantity' => 1]];
        }

        return $targets;
    }

    /**
     * Bu bileşeni içeren setler — fan-out bileşen değişince set ilanlarına
     * da operasyon açar.
     *
     * @return list<string>
     */
    public static function bundlesContaining(string $tenantId, string $componentVariantId): array
    {
        return DB::table('variant_components')
            ->where('tenant_id', $tenantId)
            ->where('component_variant_id', $componentVariantId)
            ->pluck('bundle_variant_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    public static function isBundle(string $tenantId, string $variantId): bool
    {
        return DB::table('variants')
            ->where('tenant_id', $tenantId)
            ->where('id', $variantId)
            ->where('kind', 'bundle')
            ->exists();
    }

    /**
     * @param  list<array{variant_id: string, quantity: int}>  $components
     * @param  array<string, object>  $levels
     */
    private static function bundleView(array $components, array $levels, int $revision): StockView
    {
        // Bileşensiz set satılamaz: 0, sınırlayan yok.
        if ($components === []) {
            return new StockView(0, $revision, false);
        }

        $available = null;
        $limiting = null;
        $version = $revision;

        foreach ($components as $component) {
            $level = $levels[$component['variant_id']] ?? null;
            $componentAvailable = (int) ($level->available ?? 0);
            $version += (int) ($level->version ?? 0);

            // ⌊a / q⌋ — negatif bileşende de aşağı yuvarlar (−1 / 2 = −1):
            // fazla satılmış bileşen seti de fazla satılmış gösterir.
            $sets = (int) floor($componentAvailable / $component['quantity']);

            if ($available === null || $sets < $available) {
                $available = $sets;
                $limiting = $component['variant_id'];
            }
        }

        return new StockView((int) $available, $version, true, $limiting);
    }

    private static function defaultWarehouse(string $tenantId): ?string
    {
        $id = DB::table('warehouses')
            ->where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->value('id');

        return $id === null ? null : (string) $id;
    }
}
