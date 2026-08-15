<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Orders\Enums\OrderEventType;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderEvent;
use App\Domain\Orders\Models\OrderLine;
use App\Domain\Orders\Support\CancellationEvent;
use App\Domain\Orders\Support\CancelledLine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Çok kalemli iptal — stoğu geri getirir.
 *
 * Mimari Karar Dokümanı v2.2 · §5 · Çok kalemli iptal ve iade.
 *
 * ApplyOrderReturn ile AYNI İSKELET: olay kaydı önce, tek kilit sorgusu,
 * satır başına hareket. Tek fark hareket türü (CANCELLATION) ve ilerletilen
 * sayaç (quantity_cancelled).
 *
 * Fazla satış bu yolla düzelir: bakiye −1 iken sipariş kanalda iptal
 * edilirse CANCELLATION hareketi bakiyeyi 0'a döndürür ve ledger geçmişi
 * tutarlı kalır — eksik miktar hiçbir aşamada kaybolmaz.
 */
final class ApplyOrderCancellation
{
    public function __construct(
        private readonly ApplyMovement $applyMovement = new ApplyMovement,
        private readonly LockInventoryRows $lockInventoryRows = new LockInventoryRows,
    ) {}

    /** @return OrderEvent|null null = bu iptal zaten işlenmiş */
    public function run(CancellationEvent $event, ?string $warehouseId = null): ?OrderEvent
    {
        $tenantId = TenantContext::idOrFail();

        return DB::transaction(function () use ($event, $warehouseId, $tenantId): ?OrderEvent {

            $order = Order::query()->findOrFail($event->orderId);

            // (1) Olay kaydı ÖNCE — idempotency çıpası.
            $orderEvent = $this->recordEvent($event, $order, $tenantId);

            if ($orderEvent === null) {
                return null;
            }

            $lines = $this->resolveLines($event, $order);

            if ($lines === []) {
                return $orderEvent;
            }

            $warehouse = $warehouseId ?? $this->defaultWarehouseId($tenantId);

            // (2) TÜM varyantlar TEK sorguda, sabit sırada.
            $variantIds = array_values(array_unique(array_map(
                static fn (array $pair): string => $pair['line']->variant_id,
                $lines,
            )));

            $this->lockInventoryRows->run($warehouse, $variantIds);

            // (3) Satır başına hareket; anahtar OLAY + SATIR kimliğinden.
            foreach ($lines as $pair) {
                /** @var OrderLine $line */
                $line = $pair['line'];
                $quantity = $pair['quantity'];

                $this->applyMovement->run(
                    warehouseId: $warehouse,
                    variantId: $line->variant_id,
                    type: MovementType::CANCELLATION,
                    quantity: $quantity,
                    idempotencyKey: MovementKey::cancellationOf($orderEvent->id, $line->id),
                    sourceType: 'order_event',
                    sourceId: $orderEvent->id,
                    channelConnectionId: $order->channel_connection_id,
                );

                $line->forceFill([
                    'quantity_cancelled' => $line->quantity_cancelled + $quantity,
                ])->save();
            }

            return $orderEvent;
        }, attempts: 3);
    }

    private function recordEvent(CancellationEvent $event, Order $order, string $tenantId): ?OrderEvent
    {
        if ($event->externalRef === null) {
            return OrderEvent::create([
                'tenant_id' => $tenantId,
                'order_id' => $order->id,
                'type' => OrderEventType::CANCELLED,
                'external_ref' => null,
                'payload' => $event->payload,
                'occurred_at' => $event->occurredAt ?? now(),
                'source' => 'webhook',
                'inbox_message_id' => $event->inboxMessageId,
            ]);
        }

        $now = now();

        DB::table('order_events')->insertOrIgnore([
            'id' => OrderEvent::generateUuidV7(),
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'type' => OrderEventType::CANCELLED->value,
            'external_ref' => $event->externalRef,
            'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR),
            'occurred_at' => $event->occurredAt ?? $now,
            'source' => 'webhook',
            'inbox_message_id' => $event->inboxMessageId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $existing = OrderEvent::query()
            ->where('order_id', $order->id)
            ->where('type', OrderEventType::CANCELLED->value)
            ->where('external_ref', $event->externalRef)
            ->firstOrFail();

        $alreadyApplied = DB::table('inventory_movements')
            ->where('tenant_id', $tenantId)
            ->where('source_id', $existing->id)
            ->exists();

        return $alreadyApplied ? null : $existing;
    }

    /** @return list<array{line: OrderLine, quantity: int}> */
    private function resolveLines(CancellationEvent $event, Order $order): array
    {
        $ids = array_map(
            static fn (CancelledLine $line): string => $line->orderLineId,
            $event->lines,
        );

        $lines = OrderLine::query()
            ->where('order_id', $order->id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $resolved = [];

        foreach ($event->lines as $cancelled) {
            $line = $lines->get($cancelled->orderLineId);

            if ($line === null || ! $line->isStockable()) {
                continue;
            }

            $resolved[] = ['line' => $line, 'quantity' => $cancelled->quantity];
        }

        return $resolved;
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
