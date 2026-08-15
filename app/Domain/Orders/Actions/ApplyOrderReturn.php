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
use App\Domain\Orders\Support\ReturnedLine;
use App\Domain\Orders\Support\ReturnEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Çok kalemli iade — stoğu geri getirir.
 *
 * Mimari Karar Dokümanı v2.2 · §5 · Çok kalemli iptal ve iade, §1 · Karar 10.
 *
 * İSKELET (iptal, rezervasyon serbest bırakma ve transfer ile AYNI):
 *   (1) Olay kaydı ÖNCE — idempotency çıpası
 *   (2) TÜM varyantlar TEK sorguda, sabit sırada kilitlenir
 *   (3) Satır başına ApplyMovement
 * Tek fark hareket türü ve delta işaretidir.
 *
 * OLAY KAYDI NEDEN ÖNCE:
 *   order_events (order_id, type, external_ref) kısmi tekilliği bu akışın
 *   idempotency dayanağıdır. Aynı iade ikinci kez geldiğinde olay satırı
 *   çakışır, erken çıkılır ve HİÇBİR hareket oluşmaz. Hareket anahtarı da
 *   o olayın kimliğinden türetilir.
 *
 * KİLİT SIRASI:
 *   LockInventoryRows kullanılır ve o da ORDER BY variant_id uygular. Kanal
 *   kalemleri hangi sırada gönderirse göndersin gerçek kilit sırası aynıdır;
 *   ters sıralı bir iade ile düz sıralı bir sipariş deadlock üretmez (T9).
 */
final class ApplyOrderReturn
{
    public function __construct(
        private readonly ApplyMovement $applyMovement = new ApplyMovement,
        private readonly LockInventoryRows $lockInventoryRows = new LockInventoryRows,
    ) {}

    /** @return OrderEvent|null null = bu iade zaten işlenmiş */
    public function run(ReturnEvent $event, ?string $warehouseId = null): ?OrderEvent
    {
        $tenantId = TenantContext::idOrFail();

        return DB::transaction(function () use ($event, $warehouseId, $tenantId): ?OrderEvent {

            $order = Order::query()->findOrFail($event->orderId);

            // (1) Olay kaydı ÖNCE — idempotency çıpası.
            $orderEvent = $this->recordEvent($event, $order, $tenantId);

            if ($orderEvent === null) {
                return null;                    // bu olay zaten işlenmiş
            }

            $lines = $this->resolveLines($event, $order);

            if ($lines === []) {
                return $orderEvent;             // eşleşen stoklanabilir satır yok
            }

            $warehouse = $warehouseId ?? $this->defaultWarehouseId($tenantId);

            // (2) TÜM varyantlar TEK sorguda, sabit sırada kilitlenir.
            $variantIds = array_values(array_unique(array_map(
                static fn (array $pair): string => $pair['line']->variant_id,
                $lines,
            )));

            $this->lockInventoryRows->run($warehouse, $variantIds);

            // (3) Satır başına hareket. Anahtar OLAY + SATIR kimliğinden türer:
            //     tek olayda birden fazla kalem iade edilebilir ve her biri
            //     kendi hareketini almalıdır.
            foreach ($lines as $pair) {
                /** @var OrderLine $line */
                $line = $pair['line'];
                $quantity = $pair['quantity'];

                $this->applyMovement->run(
                    warehouseId: $warehouse,
                    variantId: $line->variant_id,
                    type: MovementType::RETURN,
                    quantity: $quantity,
                    idempotencyKey: MovementKey::returnOf($orderEvent->id, $line->id),
                    sourceType: 'order_event',
                    sourceId: $orderEvent->id,
                    channelConnectionId: $order->channel_connection_id,
                );

                // Sayaç ilerletilir; CHECK kısıtı toplamın miktarı aşmasını
                // veritabanı düzeyinde engeller.
                $line->forceFill([
                    'quantity_returned' => $line->quantity_returned + $quantity,
                ])->save();
            }

            return $orderEvent;
        }, attempts: 3);
    }

    /**
     * Olayı idempotent yazar.
     *
     * external_ref NULL ise tekillik indeksi kapsamaz ve her çağrı yeni olay
     * yaratır. Bu bilinçlidir: kanal olay kimliği vermiyorsa tekilleştirmeyi
     * inbox katmanı yapar, burada uydurma bir anahtar üretilmez.
     */
    private function recordEvent(ReturnEvent $event, Order $order, string $tenantId): ?OrderEvent
    {
        if ($event->externalRef === null) {
            return OrderEvent::create([
                'tenant_id' => $tenantId,
                'order_id' => $order->id,
                'type' => OrderEventType::RETURNED,
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
            'type' => OrderEventType::RETURNED->value,
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
            ->where('type', OrderEventType::RETURNED->value)
            ->where('external_ref', $event->externalRef)
            ->firstOrFail();

        // Hareketleri zaten yazılmışsa ikinci kez uygulanmaz.
        $alreadyApplied = DB::table('inventory_movements')
            ->where('tenant_id', $tenantId)
            ->where('source_id', $existing->id)
            ->exists();

        return $alreadyApplied ? null : $existing;
    }

    /**
     * İade satırlarını çözer — yalnızca stoklanabilir olanlar.
     *
     * @return list<array{line: OrderLine, quantity: int}>
     */
    private function resolveLines(ReturnEvent $event, Order $order): array
    {
        $lines = OrderLine::query()
            ->where('order_id', $order->id)
            ->whereIn('id', $event->orderLineIds())
            ->get()
            ->keyBy('id');

        $resolved = [];

        foreach ($event->lines as $returned) {
            /** @var ReturnedLine $returned */
            $line = $lines->get($returned->orderLineId);

            if ($line === null || ! $line->isStockable()) {
                continue;                       // eşleşmemiş SKU — stok yok
            }

            $resolved[] = ['line' => $line, 'quantity' => $returned->quantity];
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
