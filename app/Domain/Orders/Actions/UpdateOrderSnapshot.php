<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Orders\Enums\OrderEventType;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderEvent;
use App\Domain\Orders\Support\OrderSnapshotEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Sipariş anlık görüntüsünü tazeler — STOK HAREKETİ ÜRETMEZ.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · orders, §6 · Yönlendirme,
 * §1 · Karar 24, §13 · Faz 3.
 *
 * KAPATILAN BOŞLUK: `OrderEventRouter` bu olayı bugüne kadar YALNIZCA
 * LOG'LUYORDU. Faz 2'de sipariş yoklaması yazıldıktan sonra boşluk CANLI
 * hale geldi: Trendyol siparişi `Shipped`'a geçtiğinde olay inbox'a
 * yazılıyor, işleniyor ve sessizce düşüyordu — panel siparişi sonsuza
 * kadar "Created" gösterirdi.
 *
 * DEĞİŞMEZ KURAL — STOK HAREKETİ ÜRETİLMEZ:
 *   Mal SATIŞTA zaten düşülmüştür. Bu yol hareket üretseydi aynı satış
 *   iki kez düşülür ve bakiye KALICI olarak bozulurdu. Stok yalnızca
 *   iptal ve iade yollarından geçer.
 *
 * DEĞİŞMEZ KURAL — KALEMLERE DOKUNULMAZ:
 *   Kalem değişikliği stok demektir. Kanalın gönderdiği kalem listesi
 *   burada uygulansaydı sessizce stok tutarsızlığı üretirdi. Kalem
 *   değişimi ancak iptal/iade olayı olarak gelirse işlenir.
 *
 * DEĞİŞMEZ KURAL — NULL "DEĞİŞMEDİ" DEMEKTİR, "BOŞALT" DEĞİL:
 *   Kanal her olayda tüm alanları göndermez. Boş değerin mevcut veriyi
 *   ezmesi GERİ ALINAMAZ bilgi kaybıdır.
 */
final class UpdateOrderSnapshot
{
    public function run(OrderSnapshotEvent $event): ?Order
    {
        $tenantId = TenantContext::idOrFail();

        return DB::transaction(function () use ($event, $tenantId): ?Order {
            $order = Order::query()->find($event->orderId);

            if ($order === null) {
                return null;
            }

            // Denetim kaydı ÖNCE: idempotency çıpası odur. Zaten yazılmışsa
            // bu güncelleme daha önce uygulanmıştır ve tekrarı anlamsızdır.
            $isNew = $this->recordEvent($event, $order, $tenantId);

            if (! $isNew) {
                return $order;
            }

            $changes = array_filter([
                'status' => $event->status,
                'financial_status' => $event->financialStatus,
            ], static fn (?string $value): bool => $value !== null && $value !== '');

            if ($changes !== []) {
                $order->forceFill($changes)->save();
            }

            return $order;
        });
    }

    /**
     * Denetim olayını yazar; YENİ yazıldıysa true döner.
     *
     * `external_ref` üzerindeki kısmi tekillik aynı güncellemenin ikinci
     * kez işlenmesini engeller — yoklama pencere örtüşmesi nedeniyle aynı
     * durumu tekrar tekrar görür.
     */
    private function recordEvent(OrderSnapshotEvent $event, Order $order, string $tenantId): bool
    {
        $now = now();

        if ($event->externalRef === null) {
            OrderEvent::create([
                'tenant_id' => $tenantId,
                'order_id' => $order->id,
                'type' => OrderEventType::UPDATED,
                'external_ref' => null,
                'payload' => $event->payload,
                'occurred_at' => $event->occurredAt ?? $now,
                'source' => 'channel',
                'inbox_message_id' => $event->inboxMessageId,
            ]);

            return true;
        }

        $id = OrderEvent::generateUuidV7();

        // insertOrIgnore: PostgreSQL'de tekillik ihlali transaction'ı
        // kirletir ve sonraki her sorgu hata verir.
        DB::table('order_events')->insertOrIgnore([
            'id' => $id,
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'type' => OrderEventType::UPDATED->value,
            'external_ref' => $event->externalRef,
            'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR),
            'occurred_at' => $event->occurredAt ?? $now,
            'source' => 'channel',
            'inbox_message_id' => $event->inboxMessageId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // "Bu satır yeni mi" sorusu ZAMAN DAMGASIYLA cevaplanmaz —
        // damgalar saniye hassasiyetlidir. Kendi ürettiğimiz uuid geri
        // geldiyse INSERT gerçekten bu çağrıda oldu demektir.
        $written = DB::table('order_events')
            ->where('order_id', $order->id)
            ->where('type', OrderEventType::UPDATED->value)
            ->where('external_ref', $event->externalRef)
            ->value('id');

        return (string) $written === $id;
    }
}
