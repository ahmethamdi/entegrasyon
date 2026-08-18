<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Orders\Enums\OrderEventType;
use App\Domain\Orders\Models\Fulfillment;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderEvent;
use App\Domain\Orders\Support\FulfillmentEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Kargo bildirimini kaydeder — STOK HAREKETİ ÜRETMEZ.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · fulfillments, §6 · Yönlendirme,
 * §13 · Faz 3.
 *
 * DEĞİŞMEZ KURAL — KARGO STOK HAREKETİ ÜRETMEZ (§4):
 *   Mal SATIŞTA zaten düşülmüştür. Kargo yalnızca teslim durumunu izler;
 *   hareket üretseydi aynı satış iki kez düşülür ve bakiye KALICI olarak
 *   bozulurdu.
 *
 * DEĞİŞMEZ KURAL — PAKET BAŞINA TEK SATIR, DURUM İLERLER:
 *   Tekillik `(order_id, external_id)` üzerinedir. Paket önce `shipped`,
 *   sonra `delivered` olur; her olay YENİ satır açsaydı panelde tek kargo
 *   iki kez görünür ve hangisinin güncel olduğu belirsiz kalırdı.
 *
 * DEĞİŞMEZ KURAL — ÇOK PAKETLİ SİPARİŞ AYRI SATIRLAR TAŞIR:
 *   Trendyol bir siparişi birden çok pakete böler ve her paket kendi
 *   durumunu taşır. Tek satıra sıkıştırılsaydı ikinci paket birincinin
 *   durumunu ezer ve satıcı yarısı teslim olmuş siparişi "tamamen
 *   teslim" sanırdı.
 *
 * DEĞİŞMEZ KURAL — NULL "DEĞİŞMEDİ" DEMEKTİR:
 *   `delivered` olayı `shipped_at` taşımaz; boş değer yazılsaydı kargoya
 *   veriliş anı KAYBOLURDU.
 *
 * DÜRÜST SINIR — BU YOL BUGÜN KANALDAN TETİKLENMİYOR:
 *   Hiçbir normalizer `fulfilled` tipi ÜRETMİYOR: Woo kargoyu ayrı bir
 *   webhook olarak göndermiyor ve Trendyol'da kargo §14 gereği KAPSAM
 *   DIŞI (`SupportsFulfillment` uygulanmaz). Sınıf `OrderEventRouter`'a
 *   bağlıdır ve doğrudan çağrıldığında doğru çalışır — testleri bunu
 *   doğrular — ama router dalını ve paket bazlı çıpayı sınayan bir
 *   davranış testi YAZILAMAZ, çünkü o olayı üreten bir kaynak yok.
 *   Mutasyon bu iki noktada hayatta kalır ve KALMALIDIR; sahte test
 *   yazmak, var olmayan bir akışı varmış gibi gösterirdi. Kanal kargo
 *   bildirimi göndermeye başladığında ilk iş normalizer'a `fulfilled`
 *   tipini ve `payload['fulfillment']` bloğunu eklemektir.
 */
final class UpdateFulfillment
{
    public function run(FulfillmentEvent $event): ?Fulfillment
    {
        $tenantId = TenantContext::idOrFail();

        return DB::transaction(function () use ($event, $tenantId): ?Fulfillment {
            $order = Order::query()->find($event->orderId);

            if ($order === null) {
                return null;
            }

            $fulfillment = $this->upsert($event, $order, $tenantId);

            $this->recordEvent($event, $order, $tenantId);

            return $fulfillment;
        });
    }

    /**
     * Paketi yazar veya var olanı İLERLETİR.
     *
     * Kimliksiz bildirim de KAYDEDİLİR: kanal kimlik vermeyebilir ve
     * bildirimi düşürmek kargo bilgisini tamamen kaybettirirdi. Tekillik
     * kısıtı NULL'ları kapsamaz, bu yüzden satır yazılabilir.
     */
    private function upsert(FulfillmentEvent $event, Order $order, string $tenantId): Fulfillment
    {
        $existing = $event->externalId === null
            ? null
            : Fulfillment::query()
                ->where('order_id', $order->id)
                ->where('external_id', $event->externalId)
                ->first();

        // NULL "değişmedi" demektir: `delivered` olayı `shipped_at`
        // taşımaz ve boş değer yazılsaydı kargoya veriliş anı kaybolurdu.
        $changes = array_filter([
            'carrier' => $event->carrier,
            'tracking_number' => $event->trackingNumber,
            'status' => $event->status,
            'shipped_at' => $event->shippedAt,
            'delivered_at' => $event->deliveredAt,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        if ($existing !== null) {
            $existing->forceFill($changes)->save();

            return $existing;
        }

        return Fulfillment::query()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'external_id' => $event->externalId,
            ...$changes,
        ]);
    }

    /**
     * Denetim olayı — kargo da `order_events` üzerinde iz bırakır.
     *
     * Çıpa paket kimliğini taşır: aynı siparişin iki paketi aynı
     * `external_ref` ile yazılsaydı ikincisi sessizce yutulurdu.
     */
    private function recordEvent(FulfillmentEvent $event, Order $order, string $tenantId): void
    {
        $now = now();

        $externalRef = $event->externalId === null
            ? null
            : $event->externalId.':'.($event->status ?? '');

        if ($externalRef === null) {
            OrderEvent::create([
                'tenant_id' => $tenantId,
                'order_id' => $order->id,
                'type' => OrderEventType::FULFILLED,
                'external_ref' => null,
                'payload' => $event->payload,
                'occurred_at' => $event->occurredAt ?? $now,
                'source' => 'channel',
                'inbox_message_id' => $event->inboxMessageId,
            ]);

            return;
        }

        DB::table('order_events')->insertOrIgnore([
            'id' => OrderEvent::generateUuidV7(),
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'type' => OrderEventType::FULFILLED->value,
            'external_ref' => $externalRef,
            'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR),
            'occurred_at' => $event->occurredAt ?? $now,
            'source' => 'channel',
            'inbox_message_id' => $event->inboxMessageId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
