<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Orders\Enums\OrderEventType;
use App\Domain\Orders\Enums\StockStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderEvent;
use App\Domain\Orders\Models\OrderLine;
use App\Domain\Orders\Support\IncomingOrder;
use App\Domain\Orders\Support\IncomingOrderLine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kanal siparişini alır ve stoğu düşürür — tek transaction.
 *
 * Mimari Karar Dokümanı v2.2 · §5 · Sipariş alımı, §1 · Kararlar 07, 09, 24.
 *
 * SORUMLULUK SINIRI (§5):
 *   YAPAR:   transaction açar · domain kaydını yazar · TÜM varyantları TEK
 *            sorguda kilitler · satır başına ApplyMovement çağırır · commit eder
 *   YAPMAZ:  ledger yazmaz, bakiye hesaplamaz — o ApplyMovement'ın işi
 *
 * DEĞİŞMEZ KURALLAR:
 *   - Sipariş ASLA reddedilmez veya geri alınmaz. Pazaryeri onu kabul etmiştir;
 *     bu otoriter gerçektir. Stok yetmese bile bakiye negatife düşer ve satır
 *     OVERSOLD işaretlenir.
 *   - Kilit TEK sorguda, ORDER BY variant_id ile alınır (LockInventoryRows).
 *   - Transaction içinde HİÇBİR HTTP çağrısı yok: kilit süresi ağ gecikmesine
 *     bağlanamaz. Hedef kilit süresi < 50 ms.
 *   - Deadlock (40P01) geçici hatadır; 3 kez yeniden denenir.
 *
 * Bu sınıf YALNIZCA order.created yolu içindir. Güncelleme, iptal ve iade
 * mevcut siparişi bulur ve order_events üzerinden işlenir (Karar 24) — hepsi
 * tek yola girseydi güncellemeler ON CONFLICT DO NOTHING dalına düşer ve
 * sessizce yutulurdu.
 */
final class IngestChannelOrder
{
    public function __construct(
        private readonly ApplyMovement $applyMovement = new ApplyMovement,
        private readonly LockInventoryRows $lockInventoryRows = new LockInventoryRows,
    ) {}

    public function run(IncomingOrder $incoming, string $warehouseId): Order
    {
        $tenantId = TenantContext::idOrFail();

        return DB::transaction(function () use ($incoming, $warehouseId, $tenantId): Order {

            // (1) Sipariş IDEMPOTENT yazılır.
            //     (channel_connection_id, external_id) tekilliği çıpadır:
            //     aynı sipariş ikinci kez geldiğinde stok ikinci kez düşmez.
            [$order, $isNew] = $this->recordOrder($incoming, $tenantId);

            if (! $isNew) {
                return $order;              // bu sipariş zaten alınmış
            }

            $lines = $this->recordLines($order, $incoming, $tenantId);

            // (2) TÜM varyantlar TEK sorguda, variant_id sırasıyla kilitlenir.
            //     Eşzamanlı ikinci sipariş burada BEKLER.
            $variantIds = $incoming->variantIds();

            if ($variantIds === []) {
                return $order;              // hiçbir satır eşleşmedi, stok yok
            }

            $levels = $this->lockInventoryRows->run($warehouseId, $variantIds);

            // (3) Satır başına hareket. Kilit zaten alınmış durumda.
            foreach ($lines as $line) {
                if (! $line->isStockable()) {
                    continue;               // eşleşmemiş SKU — PENDING kalır
                }

                $this->applyLine($order, $line, $levels[$line->variant_id], $warehouseId, $incoming);
            }

            return $order->refresh();
        }, attempts: 3);
    }

    /**
     * Siparişi idempotent yazar.
     *
     * insertOrIgnore + okuma kullanılıyor, istisna yakalamaya güvenilmiyor:
     * PostgreSQL'de tekillik ihlali transaction'ı kirletir ve sonraki her
     * sorgu hata verir. ON CONFLICT DO NOTHING bunu baştan engeller.
     *
     * @return array{0: Order, 1: bool} [sipariş, yeni mi]
     */
    private function recordOrder(IncomingOrder $incoming, string $tenantId): array
    {
        $now = now();

        DB::table('orders')->insertOrIgnore([
            'id' => Order::generateUuidV7(),
            'tenant_id' => $tenantId,
            'channel_connection_id' => $incoming->channelConnectionId,
            'external_id' => $incoming->externalId,
            'external_number' => $incoming->externalNumber,
            'status' => $incoming->status,
            'financial_status' => $incoming->financialStatus,
            'currency' => $incoming->currency,
            'subtotal' => $incoming->subtotal,
            'shipping_total' => $incoming->shippingTotal,
            'tax_total' => $incoming->taxTotal,
            'grand_total' => $incoming->grandTotal,
            'placed_at' => $incoming->placedAt ?? $now,
            'customer_ref' => json_encode($incoming->customerRef, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $order = Order::query()
            ->where('channel_connection_id', $incoming->channelConnectionId)
            ->where('external_id', $incoming->externalId)
            ->firstOrFail();

        // Bu çağrı satırı yarattıysa henüz hiç satırı ve olayı yoktur.
        $isNew = ! OrderEvent::query()
            ->where('order_id', $order->id)
            ->where('type', OrderEventType::CREATED->value)
            ->exists();

        if ($isNew) {
            OrderEvent::create([
                'tenant_id' => $tenantId,
                'order_id' => $order->id,
                'type' => OrderEventType::CREATED,
                'external_ref' => $incoming->externalId,
                'payload' => ['lines' => count($incoming->lines)],
                'occurred_at' => $incoming->placedAt ?? $now,
                'source' => 'webhook',
                'inbox_message_id' => $incoming->inboxMessageId,
            ]);
        }

        return [$order, $isNew];
    }

    /**
     * Sipariş satırlarını yazar.
     *
     * @return Collection<int, OrderLine>
     */
    private function recordLines(Order $order, IncomingOrder $incoming, string $tenantId): Collection
    {
        $now = now();

        $rows = array_map(fn (IncomingOrderLine $line): array => [
            'id' => OrderLine::generateUuidV7(),
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'variant_id' => $line->variantId,
            'external_line_id' => $line->externalLineId,
            'sku' => $line->sku,
            'title' => $line->title,
            'quantity' => $line->quantity,
            'quantity_cancelled' => 0,
            'quantity_returned' => 0,
            'quantity_fulfilled' => 0,
            'unit_price' => $line->unitPrice,
            'line_total' => $line->lineTotal,
            'stock_status' => StockStatus::PENDING->value,
            'created_at' => $now,
            'updated_at' => $now,
        ], $incoming->lines);

        DB::table('order_lines')->insertOrIgnore($rows);

        return OrderLine::query()->where('order_id', $order->id)->get();
    }

    /**
     * Tek satırın stoğunu düşürür ve sonucu işaretler.
     *
     * available_before KİLİTLİ satırdan okunur; kilit alınmadan okunsaydı
     * OVERSOLD kararı yarış durumunda yanlış verilirdi.
     */
    private function applyLine(
        Order $order,
        OrderLine $line,
        InventoryLevel $level,
        string $warehouseId,
        IncomingOrder $incoming,
    ): void {
        $availableBefore = $level->available;

        $this->applyMovement->run(
            warehouseId: $warehouseId,
            variantId: $line->variant_id,
            type: MovementType::SALE,
            quantity: $line->quantity,
            idempotencyKey: MovementKey::sale($line->id),
            sourceType: 'order_line',
            sourceId: $line->id,
            channelConnectionId: $incoming->channelConnectionId,
        );

        // Sipariş satırı işaretlenir. SİPARİŞ ASLA GERİ ALINMAZ.
        $status = StockStatus::forAvailability($availableBefore, $line->quantity);

        $line->forceFill([
            'stock_status' => $status->value,
            'stock_applied_at' => now(),
        ])->save();

        // Fazla satışta AYRICA denetim olayı. Bu olay bizim ürettiğimizdir ve
        // external_ref taşımaz — kısmi tekillik indeksi onu kapsamaz.
        if ($status->isOversold()) {
            OrderEvent::create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'order_line_id' => $line->id,
                'type' => OrderEventType::OVERSELL_DETECTED,
                'quantity' => $line->quantity,
                'external_ref' => null,
                'payload' => [
                    'variant_id' => $line->variant_id,
                    'available_before' => $availableBefore,
                    'available_after' => $availableBefore - $line->quantity,
                ],
                'occurred_at' => now(),
                'source' => 'system',
            ]);
        }
    }
}
