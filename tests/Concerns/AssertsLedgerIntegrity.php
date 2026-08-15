<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Inventory\Models\InventoryLevel;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Ledger ↔ projeksiyon eşitliği doğrulaması.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · Inventory, §18 · P0 testleri.
 *
 * TEMEL INVARIANT — her koşulda, fazla satış dahil:
 *   on_hand  = Σ inventory_movements.on_hand_delta
 *   reserved = Σ inventory_movements.reserved_delta
 *
 * Bu yardımcı stok yazan HER testin sonunda çağrılır. Projeksiyona kırpma
 * (GREATEST/LEAST/clamp) sızarsa eşitlik bozulur ve test kırmızıya döner;
 * kırpma yasağının testle korunma biçimi budur.
 *
 * Toplam SQL tarafında alınır — Eloquent üzerinden toplarsak sayfalama veya
 * global scope kaynaklı eksik satır sessizce doğru sonuç üretebilir.
 */
trait AssertsLedgerIntegrity
{
    /**
     * Tek bir stok satırı için ledger toplamı = projeksiyon.
     *
     * Satır bulunamazsa hareket de olmamalıdır; bu da doğrulanır.
     */
    protected function assertLedgerMatchesProjection(
        string $tenantId,
        string $warehouseId,
        string $variantId,
    ): void {
        [$level, $sums] = TenantContext::runAsSystem(fn (): array => [
            InventoryLevel::query()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)
                ->where('variant_id', $variantId)
                ->first(),
            DB::table('inventory_movements')
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)
                ->where('variant_id', $variantId)
                ->selectRaw('COALESCE(SUM(on_hand_delta), 0) AS on_hand_sum')
                ->selectRaw('COALESCE(SUM(reserved_delta), 0) AS reserved_sum')
                ->selectRaw('COUNT(*) AS movement_count')
                ->first(),
        ]);

        $onHandSum = (int) $sums->on_hand_sum;
        $reservedSum = (int) $sums->reserved_sum;
        $movementCount = (int) $sums->movement_count;

        if ($level === null) {
            $this->assertSame(
                0,
                $movementCount,
                'Projeksiyon satırı yok ama ledger hareket taşıyor: '.
                "variant={$variantId}",
            );

            return;
        }

        $this->assertSame(
            $onHandSum,
            $level->on_hand,
            "on_hand projeksiyonu ledger toplamından sapmış (variant={$variantId}). ".
            "Ledger Σ on_hand_delta={$onHandSum}, projeksiyon on_hand={$level->on_hand}. ".
            'Projeksiyonda GREATEST/LEAST/clamp kullanılmış olabilir — yasaktır.',
        );

        $this->assertSame(
            $reservedSum,
            $level->reserved,
            "reserved projeksiyonu ledger toplamından sapmış (variant={$variantId}). ".
            "Ledger Σ reserved_delta={$reservedSum}, projeksiyon reserved={$level->reserved}.",
        );

        // available generated stored kolondur; türetimi de doğrulanır.
        $this->assertSame(
            $onHandSum - $reservedSum,
            $level->available,
            'available = on_hand − reserved eşitliği bozulmuş.',
        );
    }

    /**
     * Kiracının TÜM stok satırları için eşitlik.
     *
     * Çok-SKU yazan yollarda (sipariş, transfer) tek satır doğrulaması
     * yetmez: kilit sırası hatası yalnızca bazı satırları bozabilir.
     */
    protected function assertLedgerMatchesProjectionForTenant(string $tenantId): void
    {
        $rows = TenantContext::runAsSystem(fn () => DB::table('inventory_levels')
            ->where('tenant_id', $tenantId)
            ->get(['warehouse_id', 'variant_id']));

        foreach ($rows as $row) {
            $this->assertLedgerMatchesProjection(
                $tenantId,
                $row->warehouse_id,
                $row->variant_id,
            );
        }

        // Projeksiyon satırı hiç yaratılmamış ama hareketi olan kombinasyonlar
        // yukarıdaki döngüde görünmez; ayrıca taranır.
        $orphans = TenantContext::runAsSystem(fn () => DB::table('inventory_movements as m')
            ->where('m.tenant_id', $tenantId)
            ->whereNotExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('inventory_levels as l')
                ->whereColumn('l.tenant_id', 'm.tenant_id')
                ->whereColumn('l.warehouse_id', 'm.warehouse_id')
                ->whereColumn('l.variant_id', 'm.variant_id'))
            ->count());

        $this->assertSame(
            0,
            $orphans,
            'Projeksiyon satırı olmayan stok hareketi var — ledger ve projeksiyon '.
            'aynı transaction içinde yazılmamış olabilir.',
        );
    }
}
