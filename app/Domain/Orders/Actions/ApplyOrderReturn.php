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
use Illuminate\Support\Collection;
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
                return $orderEvent;             // ilerletilecek satır yok
            }

            // (2) TÜM varyantlar TEK sorguda, sabit sırada kilitlenir. Yalnız
            //     stoğu DÜŞÜLMÜŞ satırlar: eşleşmemiş veya açılış stoğunda
            //     sayılmış satıra stok geri verilmez, yalnız sayacı ilerler.
            $variantIds = array_values(array_unique(array_map(
                static fn (array $pair): string => $pair['line']->variant_id,
                array_filter($lines, static fn (array $pair): bool => $pair['line']->stockWasDeducted()),
            )));

            if ($variantIds !== []) {
                $warehouse = $warehouseId ?? $this->defaultWarehouseId($tenantId);
                $this->lockInventoryRows->run($warehouse, $variantIds);
            }

            // (3) Satır başına hareket. Anahtar OLAY + SATIR kimliğinden türer:
            //     tek olayda birden fazla kalem iade edilebilir ve her biri
            //     kendi hareketini almalıdır.
            foreach ($lines as $pair) {
                /** @var OrderLine $line */
                $line = $pair['line'];
                $quantity = $pair['quantity'];

                // ⚠️ EŞLEŞMEMİŞ SATIRIN SAYACI DA İLERLER — iptaldeki gerekçe:
                // sonradan eşleşen satırda iade edilmiş adet satış sayılmasın.
                if (! $line->stockWasDeducted()) {
                    $line->forceFill([
                        'quantity_returned' => $line->quantity_returned + $quantity,
                    ])->save();

                    continue;
                }

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

        // EKLENDİ Mİ, sayıyla bilinir — ApplyOrderCancellation::recordEvent
        // ile aynı gerekçe: yalnız sayaç ilerleten iade hareket bırakmaz.
        $inserted = DB::table('order_events')->insertOrIgnore([
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

        if ($inserted === 0) {
            return null;
        }

        return OrderEvent::query()
            ->where('order_id', $order->id)
            ->where('type', OrderEventType::RETURNED->value)
            ->where('external_ref', $event->externalRef)
            ->firstOrFail();
    }

    /**
     * İade satırlarını çözer — yalnızca stoklanabilir olanlar.
     *
     * @return list<array{line: OrderLine, quantity: int}>
     */
    /**
     * Kümülatif hedefe ulaşmak için uygulanacak FARK.
     *
     * Satır `findOrFail` ile transaction içinde okunduğu için sayaç günceldir;
     * eşzamanlı iki iade aynı satırı kilit sırasıyla ilerletir.
     *
     * İptal edilmiş adet de düşülür: iade + iptal toplamı satır miktarını
     * aşamaz (CHECK kısıtı) — aşan fark DB hatası yerine kırpılır.
     */
    private function remainingTowards(OrderLine $line, int $target): int
    {
        $diff = $target - $line->quantity_returned;
        $room = $line->quantity - $line->quantity_cancelled - $line->quantity_returned;

        return min($diff, $room);
    }

    private function resolveLines(ReturnEvent $event, Order $order): array
    {
        // Satırlar stok satırlarından ÖNCE kilitlenir — ApplyOrderCancellation
        // ve ResolveUnmatchedOrderLines ile aynı sıra.
        $lines = OrderLine::query()
            ->where('order_id', $order->id)
            ->whereIn('id', $event->orderLineIds())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($event->cumulative) {
            return $this->resolveCumulative($event, $lines);
        }

        $requested = [];

        foreach ($event->lines as $returned) {
            /** @var ReturnedLine $returned */
            $requested[$returned->orderLineId] = ($requested[$returned->orderLineId] ?? 0) + $returned->quantity;
        }

        $resolved = [];

        foreach ($requested as $lineId => $quantity) {
            $line = $lines->get($lineId);

            if ($line === null) {
                continue;
            }

            // Kalana KIRPILIR: taşan iade (iptalden sonra gelen iade, kanalın
            // fazla bildirmesi) CHECK kısıtını patlatıp olaydaki ÖTEKİ
            // kalemleri de geri alırdı.
            $quantity = min($quantity, $line->quantity - $line->quantity_cancelled - $line->quantity_returned);

            if ($quantity > 0) {
                $resolved[] = ['line' => $line, 'quantity' => $quantity];
            }
        }

        return $resolved;
    }

    /**
     * Kümülatif mod — hedefler SATIR BAŞINA toplanır, sonra fark alınır.
     *
     * ⚠️ TOPLAMA FARKTAN ÖNCE: iki ham kalem aynı satıra eşleşebilir (biri
     * satır kimliğiyle, biri SKU ile). Fark kalem başına alınsaydı ikisi de
     * AYNI eski sayaçtan hesaplanır ve satır iki kez ilerlerdi.
     *
     * @param  Collection<string, OrderLine>  $lines
     * @return list<array{line: OrderLine, quantity: int}>
     */
    private function resolveCumulative(ReturnEvent $event, $lines): array
    {
        $targets = [];

        foreach ($event->lines as $returned) {
            $targets[$returned->orderLineId] = ($targets[$returned->orderLineId] ?? 0) + $returned->quantity;
        }

        $resolved = [];

        foreach ($targets as $lineId => $target) {
            $line = $lines->get($lineId);

            if ($line === null) {
                continue;
            }

            $quantity = $this->remainingTowards($line, $target);

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
