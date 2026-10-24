<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Inventory\Support\SellableStock;
use App\Domain\Orders\Enums\OrderEventType;
use App\Domain\Orders\Enums\StockStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderEvent;
use App\Domain\Orders\Models\OrderLine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Eşleşmemiş sipariş satırlarını SONRADAN varyanta bağlar ve stoğu düşer.
 *
 * KAPATILAN BOŞLUK (A12): sipariş geldiğinde SKU katalogda yoksa satır
 * `variant_id = NULL / PENDING` kaydedilir (OrderPayloadMapper). SKU sonradan
 * kataloğa girdiğinde (ürün yaratma, içe aktarma, SKU düzeltme) satırı
 * bağlayan HİÇBİR yol yoktu — stok sonsuza kadar düşülmez, bakiye satılmış
 * adet kadar fazla görünür ve kanallara fazla stok gider.
 *
 * NE DÜŞÜLÜR:
 *   - Miktar `effectiveQuantity()` — eşleşmeden önce gelen iptal/iade
 *     sayaçları (ApplyOrderCancellation/Return artık eşleşmemiş satırda da
 *     ilerletir) düşülmez.
 *   - Hareket anahtarı `MovementKey::sale(satır)` — alımdakiyle AYNI; satır
 *     bir kez satılır, iki yol aynı satırı iki kez düşemez.
 *
 * NE DÜŞÜLMEZ (SKIPPED):
 *   - Sipariş, varyant kataloğa girmeden ÖNCE verilmişse. O varyantın
 *     açılış stoğu satıştan SONRA yazıldı; kanaldan içe aktarıldıysa kanal
 *     bu satışı çoktan düşmüştür. Düşmek aynı satışı iki kez sayardı —
 *     OrderEventRouter::adoptMissedOrder'ın `connected_at` kuralıyla aynı
 *     gerekçe. Fazla satış, eksik stoktan daha pahalıdır (pazaryeri cezası).
 *   - Satır eşleşmeden önce tamamen iptal/iade edilmişse.
 *
 * KİLİT SIRASI: önce sipariş SATIRLARI (id sırasıyla), sonra STOK satırları
 * (LockInventoryRows). İptal ve iade de aynı sırayı izler; eşzamanlı iptal
 * satırı okuyup sayacı ilerletmeden burada tam miktar düşülemez.
 */
final class ResolveUnmatchedOrderLines
{
    /** Tur başına en fazla satır — tarama bir kiracıda takılı kalmasın. */
    public const BATCH = 500;

    public function __construct(
        private readonly ApplyMovement $applyMovement = new ApplyMovement,
        private readonly LockInventoryRows $lockInventoryRows = new LockInventoryRows,
    ) {}

    /**
     * Geçerli kiracıda eşleşebilen satırları bağlar.
     *
     * @return int bağlanan satır sayısı (stoğu düşülen + SKIPPED)
     */
    public function run(int $limit = self::BATCH): int
    {
        $tenantId = TenantContext::idOrFail();

        // KİRACI FİLTRESİ AÇIKÇA YAZILIR: DB::table() global scope'a tabi değil.
        $candidates = DB::table('order_lines')
            ->join('variants', function ($join): void {
                $join->on('variants.tenant_id', '=', 'order_lines.tenant_id')
                    ->on('variants.sku', '=', 'order_lines.sku');
            })
            ->where('order_lines.tenant_id', $tenantId)
            ->whereNull('order_lines.variant_id')
            ->where('order_lines.stock_status', StockStatus::PENDING->value)
            ->orderBy('order_lines.order_id')
            ->orderBy('order_lines.id')
            ->limit($limit)
            ->get(['order_lines.id', 'order_lines.order_id', 'variants.id AS variant_id']);

        $resolved = 0;

        foreach ($candidates->groupBy('order_id') as $orderId => $rows) {
            $resolved += $this->resolveOrder(
                (string) $orderId,
                $rows->pluck('variant_id', 'id')->all(),
                $tenantId,
            );
        }

        return $resolved;
    }

    /**
     * Tek siparişin satırları — tek transaction.
     *
     * @param  array<string, string>  $variantByLine  satır id → varyant id
     */
    private function resolveOrder(string $orderId, array $variantByLine, string $tenantId): int
    {
        return DB::transaction(function () use ($orderId, $variantByLine, $tenantId): int {
            $order = Order::query()->findOrFail($orderId);

            // (1) Satırlar kilitlenir ve YENİDEN okunur: aday sorgusundan bu
            //     yana başka bir tur bağlamış ya da iptal sayacı ilerlemiş
            //     olabilir.
            $lines = OrderLine::query()
                ->where('order_id', $orderId)
                ->whereIn('id', array_keys($variantByLine))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter(fn (OrderLine $line): bool => $line->variant_id === null
                    && $line->stock_status === StockStatus::PENDING);

            if ($lines->isEmpty()) {
                return 0;
            }

            $variantCreatedAt = Variant::query()
                ->whereIn('id', array_values($variantByLine))
                ->pluck('created_at', 'id');

            $toDeduct = [];

            foreach ($lines as $line) {
                $variantId = $variantByLine[$line->id];
                $createdAt = $variantCreatedAt[$variantId] ?? null;

                $soldBeforeCatalog = $order->placed_at !== null
                    && $createdAt !== null
                    && $order->placed_at->lt($createdAt);

                if ($soldBeforeCatalog || $line->effectiveQuantity() <= 0) {
                    $line->forceFill([
                        ...$this->costSnapshot($line, $variantId),
                        'variant_id' => $variantId,
                        'stock_status' => StockStatus::SKIPPED->value,
                    ])->save();

                    continue;
                }

                $toDeduct[] = [$line, $variantId];
            }

            if ($toDeduct !== []) {
                $warehouseId = $this->defaultWarehouseId($tenantId);

                // (2) TÜM varyantlar TEK sorguda, variant_id sırasıyla. Set
                //     bileşenlerine açılır (`IngestChannelOrder` ile aynı kural).
                $targets = SellableStock::targetsOf($tenantId, array_map(static fn (array $pair): string => $pair[1], $toDeduct));
                $levels = $this->lockInventoryRows->run($warehouseId, IngestChannelOrder::stockBearing($targets));

                foreach ($toDeduct as [$line, $variantId]) {
                    $this->deduct($order, $line, $variantId, $targets[$variantId] ?? [], $levels, $warehouseId);

                    // Aynı varyantta ikinci satır KİLİTLİ satırın güncel
                    // bakiyesini görmeli; önbellekteki model eskidir.
                    foreach ($targets[$variantId] ?? [] as $target) {
                        $levels[$target['variant_id']]->refresh();
                    }
                }
            }

            Log::info('orders.unmatched_lines_resolved', [
                'tenant' => $tenantId,
                'order' => $orderId,
                'lines' => $lines->count(),
                'deducted' => count($toDeduct),
            ]);

            return $lines->count();
        }, attempts: 3);
    }

    /** IngestChannelOrder::applyLine ile aynı sonuç — satış + işaret + fazla satış olayı. */
    /**
     * Sonradan eşleşen kalemin maliyet görüntüsü (ADR-002).
     *
     * Satış anındaki maliyet bilinemez; EŞLEŞME anındaki maliyet en iyi
     * yaklaşımdır ve `cost_captured_at` bunu açıkça kaydeder. Görüntü bir
     * kez yazılır (DB tetikleyicisi değişikliği reddeder).
     *
     * @return array<string, mixed>
     */
    private function costSnapshot(OrderLine $line, string $variantId): array
    {
        if ($line->unit_cost !== null) {
            return [];
        }

        $variant = Variant::query()->find($variantId, ['id', 'cost_price', 'currency']);

        if ($variant === null || $variant->cost_price === null) {
            return [];
        }

        return [
            'unit_cost' => (string) $variant->cost_price,
            'cost_currency' => (string) $variant->currency,
            'cost_captured_at' => now(),
        ];
    }

    /**
     * @param  list<array{variant_id: string, quantity: int}>  $targets
     * @param  array<string, InventoryLevel>  $levels
     */
    private function deduct(Order $order, OrderLine $line, string $variantId, array $targets, array $levels, string $warehouseId): void
    {
        $quantity = $line->effectiveQuantity();

        if ($targets === []) {
            return;                         // bileşensiz set — PENDING kalır
        }

        $bundle = $targets !== [['variant_id' => $variantId, 'quantity' => 1]];
        $availableBefore = null;

        foreach ($targets as $target) {
            $sets = (int) floor($levels[$target['variant_id']]->available / $target['quantity']);
            $availableBefore = $availableBefore === null ? $sets : min($availableBefore, $sets);
        }

        foreach ($targets as $target) {
            $this->applyMovement->run(
                warehouseId: $warehouseId,
                variantId: $target['variant_id'],
                type: MovementType::SALE,
                quantity: $quantity * $target['quantity'],
                idempotencyKey: $bundle ? MovementKey::component(MovementKey::sale($line->id), $target['variant_id']) : MovementKey::sale($line->id),
                sourceType: $bundle ? ApplyMovement::SOURCE_BUNDLE_COMPONENT : 'order_line',
                sourceId: $line->id,
                channelConnectionId: $order->channel_connection_id,
            );
        }

        $status = StockStatus::forAvailability($availableBefore, $quantity);

        $line->forceFill([
            'variant_id' => $variantId,
            'stock_status' => $status->value,
            'stock_applied_at' => now(),
            // Satış anı set görüntüsü — iptal/iade bunu okur.
            'bundle_components' => $bundle ? $targets : null,
            ...$this->costSnapshot($line, $variantId),
        ])->save();

        if ($status->isOversold()) {
            OrderEvent::create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'order_line_id' => $line->id,
                'type' => OrderEventType::OVERSELL_DETECTED,
                'quantity' => $quantity,
                'external_ref' => null,
                'payload' => [
                    'variant_id' => $variantId,
                    'available_before' => $availableBefore,
                    'available_after' => $availableBefore - $quantity,
                ],
                'occurred_at' => now(),
                'source' => 'system',
            ]);
        }
    }

    private function defaultWarehouseId(string $tenantId): string
    {
        $id = DB::table('warehouses')
            ->where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->value('id');

        if ($id === null) {
            throw new \RuntimeException("Kiracı {$tenantId} için varsayılan depo yok.");
        }

        return (string) $id;
    }
}
