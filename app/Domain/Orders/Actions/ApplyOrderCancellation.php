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

            // (2) TÜM varyantlar TEK sorguda, sabit sırada. Yalnız stoğu
            //     DÜŞÜLMÜŞ satırlar: eşleşmemiş veya açılış stoğunda sayılmış
            //     satıra stok geri verilmez, yalnız sayacı ilerler.
            $variantIds = array_values(array_unique(array_map(
                static fn (array $pair): string => $pair['line']->variant_id,
                array_filter($lines, static fn (array $pair): bool => $pair['line']->stockWasDeducted()),
            )));

            if ($variantIds !== []) {
                $warehouse = $warehouseId ?? $this->defaultWarehouseId($tenantId);
                $this->lockInventoryRows->run($warehouse, $variantIds);
            }

            // (3) Satır başına hareket; anahtar OLAY + SATIR kimliğinden.
            foreach ($lines as $pair) {
                /** @var OrderLine $line */
                $line = $pair['line'];
                $quantity = $pair['quantity'];

                // ⚠️ EŞLEŞMEMİŞ SATIRIN SAYACI DA İLERLER. İlerlemeseydi satır
                // sonradan eşleştiğinde (ResolveUnmatchedOrderLines) iptal
                // edilmiş adet de satış diye düşülürdü.
                if (! $line->stockWasDeducted()) {
                    $line->forceFill([
                        'quantity_cancelled' => $line->quantity_cancelled + $quantity,
                    ])->save();

                    continue;
                }

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

        // EKLENDİ Mİ, sayıyla bilinir. Olay ile etkisi AYNI transaction'dadır:
        // satır zaten varsa önceki çağrı commit etmiştir, etkisi de yazılmıştır.
        // Hareket varlığına bakmak YETMEZ — yalnız sayaç ilerleten (eşleşmemiş
        // satır) iptal hareket bırakmaz ve tekrar gelişi sayacı ikinci kez
        // ilerletirdi.
        $inserted = DB::table('order_events')->insertOrIgnore([
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

        if ($inserted === 0) {
            return null;
        }

        return OrderEvent::query()
            ->where('order_id', $order->id)
            ->where('type', OrderEventType::CANCELLED->value)
            ->where('external_ref', $event->externalRef)
            ->firstOrFail();
    }

    /** @return list<array{line: OrderLine, quantity: int}> */
    private function resolveLines(CancellationEvent $event, Order $order): array
    {
        $ids = array_map(
            static fn (CancelledLine $line): string => $line->orderLineId,
            $event->lines,
        );

        // Satırlar KİLİTLENİR (stok satırlarından ÖNCE — ResolveUnmatchedOrderLines
        // ile aynı sıra): eşzamanlı eşleştirme satırı okuyup iptali görmeden
        // tam miktarı düşemesin.
        $lines = OrderLine::query()
            ->where('order_id', $order->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        // Satır başına TOPLANIR: iki ham kalem aynı satıra eşleşebilir ve
        // kırpma toplam üzerinden yapılmalıdır.
        $requested = [];

        foreach ($event->lines as $cancelled) {
            $requested[$cancelled->orderLineId] = ($requested[$cancelled->orderLineId] ?? 0) + $cancelled->quantity;
        }

        $resolved = [];

        foreach ($requested as $lineId => $quantity) {
            $line = $lines->get($lineId);

            if ($line === null) {
                continue;
            }

            // ⚠️ İPTAL "SİPARİŞİN GERİ KALANI"DIR — KALANA KIRPILIR.
            //
            // Kanal iptalde satırın TAM miktarını gönderir (Shopify
            // `orders/cancelled` → `line_items.quantity`). Önce 1 adet iade
            // edilmiş 3 adetlik satırda iptal 3 adet isterdi: 3 + 1 > 3,
            // CHECK kısıtı patlar, iptal HİÇ uygulanmaz ve kalan 2 adet
            // stoğa geri gelmezdi.
            $room = $line->quantity - $line->quantity_cancelled - $line->quantity_returned;
            $quantity = min($quantity, $room);

            if ($quantity > 0) {
                $resolved[] = ['line' => $line, 'quantity' => $quantity];
            }
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
