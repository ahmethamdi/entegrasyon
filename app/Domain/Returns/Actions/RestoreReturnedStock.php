<?php

declare(strict_types=1);

namespace App\Domain\Returns\Actions;

use App\Domain\Orders\Actions\ApplyOrderReturn;
use App\Domain\Orders\Actions\TransitionOrderState;
use App\Domain\Orders\Enums\OrderEventType;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderEvent;
use App\Domain\Orders\Models\OrderLine;
use App\Domain\Orders\Support\ReturnedLine;
use App\Domain\Orders\Support\ReturnEvent;
use App\Domain\Returns\Enums\ReturnReason;
use App\Domain\Returns\Enums\ReturnStatus;
use App\Domain\Returns\Models\ReturnRequestLine;
use App\Domain\Returns\Support\ReturnInterpretation;
use Illuminate\Support\Facades\DB;

/**
 * Kabul edilmiş ve DEPOYA DÖNDÜĞÜ KANITLANMIŞ iade kaleminin stoğunu
 * geri getirir — S4.
 *
 * TETİK (hepsi birden):
 *   kanonik durum ACCEPTED · kanal S4 yolundan stoklar · fiziksel varış
 *   kanıtı (`receipt_proven_at`) · sipariş satırıyla kesin eşleşme
 * Talebin varlığı, müşteri isteği, para iadesi ya da durum metninde
 * "return" geçmesi STOK GETİRMEZ.
 *
 * EKONOMİK İADE KİMLİĞİ: hareket mevcut çekirdekten (`ApplyOrderReturn`,
 * değişmez envanter defteri) geçer ve olay çıpası kanal kalem kimliğinden
 * türer: `return-item:{bağlantı}:{external_item_id}`. Aynı iade yoklamada,
 * uzlaştırmada, tekrarda, farklı bir olay kimliğiyle gelse de AYNI olaya
 * düşer — `order_events (order_id, type, external_ref)` tekilliği ikinci
 * hareketi engeller. (Shopify B' hatasının tersi: anahtar olay satırına
 * değil, ekonomik kimliğe bağlı.)
 *
 * ADET: kalemin adedi kadar; `ApplyOrderReturn` satırda kalanla kırpar ve
 * CHECK kısıtı toplamın satır adedini aşmasını veritabanında engeller.
 * Kırpılırsa `RETURN_QUANTITY_CONFLICT` işaretlenir.
 */
final class RestoreReturnedStock
{
    public function __construct(
        private readonly ApplyOrderReturn $applyReturn = new ApplyOrderReturn,
        private readonly TransitionOrderState $transition = new TransitionOrderState,
    ) {}

    public static function economicRef(ReturnRequestLine $line): string
    {
        return "return-item:{$line->channel_connection_id}:{$line->external_item_id}";
    }

    /** @return string restock_status */
    public function run(ReturnRequestLine $line): string
    {
        return DB::transaction(function () use ($line): string {
            /** @var ReturnRequestLine $line */
            $line = ReturnRequestLine::query()->with('request')->lockForUpdate()->findOrFail($line->id);

            if ($line->restock_status === ReturnRequestLine::RESTOCK_RESTORED) {
                return $line->restock_status;
            }

            if ($line->statusEnum() !== ReturnStatus::ACCEPTED) {
                return $this->mark($line, ReturnRequestLine::RESTOCK_NOT_APPLICABLE, null);
            }

            if (! ReturnInterpretation::restocks($line->request->channel_type_code)) {
                return $this->mark($line, ReturnRequestLine::RESTOCK_BLOCKED, ReturnReason::RETURN_RESTOCK_UNSUPPORTED);
            }

            if ($line->match_status !== 'matched' || $line->order_line_id === null) {
                return $this->mark($line, ReturnRequestLine::RESTOCK_PENDING, $line->match_status === 'order_missing'
                    ? ReturnReason::RETURN_ORDER_NOT_FOUND
                    : ReturnReason::RETURN_MATCH_REQUIRED);
            }

            if ($line->receipt_proven_at === null) {
                return $this->mark($line, ReturnRequestLine::RESTOCK_PENDING, ReturnReason::RETURN_RECEIPT_UNPROVEN);
            }

            /** @var OrderLine $orderLine */
            $orderLine = OrderLine::query()->findOrFail($line->order_line_id);
            $order = Order::query()->findOrFail($orderLine->order_id);
            $ref = self::economicRef($line);

            // Satırın stoğu hiç düşülmemişse defterde geri alınacak bir şey
            // yok: ekran "stok döndü" DEMEMELİ.
            if (! $orderLine->stockWasDeducted()) {
                return $this->mark($line, ReturnRequestLine::RESTOCK_BLOCKED, ReturnReason::RETURN_STOCK_NOT_DEDUCTED);
            }

            $existing = OrderEvent::query()
                ->where('order_id', $order->id)
                ->where('type', OrderEventType::RETURNED->value)
                ->where('external_ref', $ref)
                ->first();

            if ($existing !== null) {
                // Bu ekonomik iade daha önce işlenmiş: yeni hareket YOK.
                $line->forceFill([
                    'restock_status' => ReturnRequestLine::RESTOCK_RESTORED,
                    'restock_reason_code' => ReturnReason::RETURN_STOCK_ALREADY_RESTORED->value,
                    'restock_order_event_id' => $existing->id,
                    'restocked_at' => $line->restocked_at ?? now(),
                ])->save();

                return ReturnRequestLine::RESTOCK_RESTORED;
            }

            $room = $orderLine->quantity - $orderLine->quantity_cancelled - $orderLine->quantity_returned;

            // Satırda iade edilebilecek birim kalmadı: hareket üretilmez,
            // "stok döndü" denmez — satır aksiyon bekler.
            if ($room <= 0) {
                return $this->mark($line, ReturnRequestLine::RESTOCK_BLOCKED, ReturnReason::RETURN_QUANTITY_CONFLICT);
            }

            $event = $this->applyReturn->run(new ReturnEvent(
                orderId: $order->id,
                externalRef: $ref,
                lines: [new ReturnedLine($orderLine->id, $line->quantity)],
                payload: ['source' => 'return_request', 'return_request_line_id' => $line->id, 'external_item_id' => $line->external_item_id],
                occurredAt: now(),
            ));

            $reason = null;

            if ($event === null) {
                // Bu ekonomik iade daha önce işlenmiş: yeni hareket YOK.
                $event = OrderEvent::query()
                    ->where('order_id', $order->id)
                    ->where('type', OrderEventType::RETURNED->value)
                    ->where('external_ref', $ref)
                    ->firstOrFail();
                $reason = ReturnReason::RETURN_STOCK_ALREADY_RESTORED;
            } elseif ($room < $line->quantity) {
                $reason = ReturnReason::RETURN_QUANTITY_CONFLICT;
            }

            $line->forceFill([
                'restock_status' => ReturnRequestLine::RESTOCK_RESTORED,
                'restock_reason_code' => $reason?->value,
                'restock_order_event_id' => $event->id,
                'restocked_at' => now(),
            ])->save();

            $this->transition->syncFromLines($order);

            return ReturnRequestLine::RESTOCK_RESTORED;
        }, attempts: 3);
    }

    private function mark(ReturnRequestLine $line, string $status, ?ReturnReason $reason): string
    {
        $line->forceFill(['restock_status' => $status, 'restock_reason_code' => $reason?->value])->save();

        return $status;
    }
}
