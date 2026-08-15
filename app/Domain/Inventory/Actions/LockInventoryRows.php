<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Models\InventoryLevel;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Stok satırlarını TEK sorguda, deterministik sırayla kilitler.
 *
 * Mimari Karar Dokümanı v2.2 · §5 · Kilit stratejisi.
 *
 * ÇOK-SKU YAZAN HER YOL BURADAN GEÇER: sipariş alımı, iptal, iade,
 * rezervasyon, transfer. Tek SKU yazan yollar da geçer — istisna yoktur,
 * çünkü "bu yol hep tek SKU" varsayımı zamanla bozulur.
 *
 * İKİ KURAL, TEK SORGU:
 *
 *   (1) ORDER BY variant_id — iki eşzamanlı sipariş aynı SKU kümesini ters
 *       sırada isterse ve kilitler istenen sırayla alınırsa, A birinciyi
 *       B ikinciyi tutar ve ikisi de diğerini bekler: deadlock. Sıralama
 *       global bir kilit hiyerarşisi kurar ve bu döngüyü imkânsız kılar.
 *
 *   (2) Satır başına ayrı sorgu YOK. Döngü içinde kilitlemek, sorgular
 *       arasında pencere bırakır: başka bir transaction araya girip kalan
 *       satırları ters sırada kilitleyebilir. Tek IN (...) sorgusu bu
 *       pencereyi kapatır.
 *
 * ApplyMovement bu kilidi ÖN KOŞUL kabul eder ve kendisi kilit almaz;
 * ikinci bir kilit noktası ikinci bir sıralama demektir.
 */
final class LockInventoryRows
{
    /**
     * @param  list<string>  $variantIds
     * @return array<string, InventoryLevel> variant_id ile anahtarlanmış kilitli satırlar
     */
    public function run(string $warehouseId, array $variantIds): array
    {
        if (! DB::transactionLevel()) {
            throw new LogicException(
                'LockInventoryRows bir transaction içinde çağrılmalıdır: '.
                'FOR UPDATE kilidi transaction sonunda bırakılır, dışarıda anlamsızdır.'
            );
        }

        $tenantId = TenantContext::idOrFail();

        // Aynı varyant iki kez gelirse (iki sipariş satırı, aynı SKU) tek kez
        // kilitlenir; yinelenen değer IN listesini şişirmekten başka iş görmez.
        $variantIds = array_values(array_unique($variantIds));

        if ($variantIds === []) {
            return [];
        }

        // Eksik satırlar önce yaratılır: FOR UPDATE var olmayan satırı
        // kilitlemez ve o SKU korumasız kalırdı.
        $this->ensureLevelsExist($tenantId, $warehouseId, $variantIds);

        // TEK sorgu, variant_id sırasıyla kilitlenir.
        //
        // NOT: sıralama veritabanına bırakılır, PHP tarafında sort() ile
        // değil. Sorgu planlayıcı satırları hangi sırada okursa okusun,
        // ORDER BY ... FOR UPDATE kilitleri sıralı biçimde aldırır.
        $levels = InventoryLevel::query()
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('variant_id', $variantIds)
            ->orderBy('variant_id')
            ->lockForUpdate()
            ->get();

        return $levels->keyBy('variant_id')->all();
    }

    /**
     * Tek SKU için kısayol — çağıranın dizi sarmalaması gerekmesin.
     */
    public function one(string $warehouseId, string $variantId): InventoryLevel
    {
        return $this->run($warehouseId, [$variantId])[$variantId];
    }

    /**
     * Eksik projeksiyon satırlarını yaratır.
     *
     * ON CONFLICT DO NOTHING: iki eşzamanlı ilk-hareket aynı satırı yaratmaya
     * çalışabilir. Biri yazar, diğeri sessizce geçer ve ardından gelen
     * FOR UPDATE ikisini de aynı satıra kilitler.
     *
     * available generated stored kolondur ve INSERT listesine ALINMAZ.
     *
     * @param  list<string>  $variantIds
     */
    private function ensureLevelsExist(string $tenantId, string $warehouseId, array $variantIds): void
    {
        $existing = DB::table('inventory_levels')
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('variant_id', $variantIds)
            ->pluck('variant_id')
            ->all();

        $missing = array_diff($variantIds, $existing);

        if ($missing === []) {
            return;
        }

        $now = now();

        $rows = array_map(fn (string $variantId): array => [
            'id' => InventoryLevel::generateUuidV7(),
            'tenant_id' => $tenantId,
            'warehouse_id' => $warehouseId,
            'variant_id' => $variantId,
            'on_hand' => 0,
            'reserved' => 0,
            'version' => 0,
            'last_movement_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values($missing));

        DB::table('inventory_levels')->insertOrIgnore($rows);
    }
}
