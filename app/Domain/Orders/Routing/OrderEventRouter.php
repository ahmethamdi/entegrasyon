<?php

declare(strict_types=1);

namespace App\Domain\Orders\Routing;

use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Actions\ApplyOrderCancellation;
use App\Domain\Orders\Actions\ApplyOrderReturn;
use App\Domain\Orders\Actions\IngestChannelOrder;
use App\Domain\Orders\Actions\UpdateFulfillment;
use App\Domain\Orders\Actions\UpdateOrderSnapshot;
use App\Domain\Orders\Enums\OrderEventType;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Support\CancellationEvent;
use App\Domain\Orders\Support\CancelledLine;
use App\Domain\Orders\Support\FulfillmentEvent;
use App\Domain\Orders\Support\OrderSnapshotEvent;
use App\Domain\Orders\Support\ReturnedLine;
use App\Domain\Orders\Support\ReturnEvent;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sipariş olaylarını tipine göre AYRI yollara dağıtır.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · Sipariş olayı yönlendirme, §1 · Karar 24.
 *
 * DEĞİŞMEZ KURAL:
 *   orders üzerindeki UNIQUE(channel_connection_id, external_id) kısıtı
 *   YALNIZCA yeni sipariş almak için kullanılır. Güncelleme, iptal, iade ve
 *   kargo olayları bu yola girerse ON CONFLICT DO NOTHING dalına düşer ve
 *   SESSİZCE YUTULUR — iptaller hiçbir zaman işlenmez ve stok geri gelmez.
 *
 *   Bedeli burada açık tip dağıtımı yapmaktır; kabul edilmiştir.
 *
 * SIRA BAĞIMSIZLIĞI: güncelleme olayı, oluşturma olayından ÖNCE gelebilir
 * (kanal sırayı garanti etmez). resolveOrder() siparişi bulamazsa olay
 * yoksayılmaz — ileride kanaldan tam sipariş çekilip önce alınması gerekir.
 * O yol adapter'ın fetchOrder yeteneğine bağlı olduğu için şimdilik açıkça
 * ertelenmiş durumda ve log'a düşer.
 */
final class OrderEventRouter
{
    public function __construct(
        private readonly AdapterRegistry $adapters = new AdapterRegistry,
        private readonly IngestChannelOrder $ingestOrder = new IngestChannelOrder,
        private readonly ApplyOrderCancellation $applyCancellation = new ApplyOrderCancellation,
        private readonly ApplyOrderReturn $applyReturn = new ApplyOrderReturn,
        // §13 · Faz 3 — bu ikisi olmadan UPDATED ve FULFILLED olayları
        // yalnızca log'lanıyordu ve sessizce düşüyordu.
        private readonly UpdateOrderSnapshot $updateSnapshot = new UpdateOrderSnapshot,
        private readonly UpdateFulfillment $updateFulfillment = new UpdateFulfillment,
    ) {}

    public function route(InboxMessage $message): void
    {
        $connection = $message->connection;

        if ($connection === null) {
            throw new RuntimeException("Inbox mesajı {$message->id} için bağlantı bulunamadı.");
        }

        $adapter = $this->adapters->for($connection);

        if (! $adapter instanceof SupportsOrders) {
            Log::warning('inbox.channel_does_not_support_orders', [
                'message' => $message->id,
                'channel' => $connection->channel_type_code,
            ]);

            return;                     // yetenek yok — mesaj işlenmiş sayılır
        }

        $normalized = $adapter->parseOrderEvent($message);

        if ($normalized === null) {
            // Adapter bu mesajı sipariş olayı olarak tanımadı (ürün güncelleme
            // webhook'u olabilir). Mesaj işlenmiş sayılır; aksi halde kurtarma
            // taraması onu sonsuza kadar yeniden dener.
            Log::info('inbox.not_an_order_event', ['message' => $message->id]);

            return;
        }

        $type = OrderEventType::tryFrom($normalized->type);

        if ($type === null) {
            Log::warning('inbox.unknown_order_event_type', [
                'message' => $message->id,
                'type' => $normalized->type,
            ]);

            return;
        }

        match ($type) {
            // YENİ SİPARİŞ — tekillik kısıtı yalnızca burada anlamlı.
            OrderEventType::CREATED => $this->handleCreated($normalized, $message),

            // MEVCUT SİPARİŞ — önce bulunur, sonra olay uygulanır.
            OrderEventType::CANCELLED => $this->handleCancelled($normalized, $message),
            OrderEventType::RETURNED => $this->handleReturned($normalized, $message),

            // Güncelleme ve kargo: sipariş anlık görüntüsünü tazeler, stok
            // hareketi ÜRETMEZ (§4). Mal satışta zaten düşülmüştür;
            // hareket üretselerdi aynı satış iki kez düşülürdü.
            OrderEventType::UPDATED => $this->handleUpdated($normalized, $message),
            OrderEventType::FULFILLED => $this->handleFulfilled($normalized, $message),

            // Bizim ürettiğimiz denetim olayı kanaldan gelmez.
            OrderEventType::OVERSELL_DETECTED => null,
        };
    }

    private function handleCreated(NormalizedOrderEvent $normalized, InboxMessage $message): void
    {
        $incoming = OrderPayloadMapper::toIncomingOrder(
            $normalized,
            $message->channel_connection_id,
            $message->id,
        );

        $this->ingestOrder->run($incoming, $this->defaultWarehouseId($message->tenant_id));
    }

    private function handleCancelled(NormalizedOrderEvent $normalized, InboxMessage $message): void
    {
        $order = $this->resolveOrder($normalized, $message);

        if ($order === null) {
            return;
        }

        $lines = OrderPayloadMapper::toAffectedLines($normalized, $order);

        if ($lines === []) {
            Log::warning('inbox.cancellation_without_matching_lines', ['message' => $message->id]);

            return;
        }

        $this->applyCancellation->run(new CancellationEvent(
            orderId: $order->id,
            externalRef: $normalized->externalRef,
            lines: array_map(
                static fn (array $pair): CancelledLine => new CancelledLine($pair['line_id'], $pair['quantity']),
                $lines,
            ),
            payload: $normalized->payload,
            occurredAt: $normalized->occurredAt,
            inboxMessageId: $message->id,
        ));
    }

    private function handleReturned(NormalizedOrderEvent $normalized, InboxMessage $message): void
    {
        $order = $this->resolveOrder($normalized, $message);

        if ($order === null) {
            return;
        }

        $lines = OrderPayloadMapper::toAffectedLines($normalized, $order);

        if ($lines === []) {
            Log::warning('inbox.return_without_matching_lines', ['message' => $message->id]);

            return;
        }

        $this->applyReturn->run(new ReturnEvent(
            orderId: $order->id,
            externalRef: $normalized->externalRef,
            lines: array_map(
                static fn (array $pair): ReturnedLine => new ReturnedLine($pair['line_id'], $pair['quantity']),
                $lines,
            ),
            payload: $normalized->payload,
            occurredAt: $normalized->occurredAt,
            inboxMessageId: $message->id,
        ));
    }

    /**
     * Siparişi external_id ile bulur.
     *
     * Bulunamazsa güncelleme, oluşturma olayından ÖNCE gelmiş demektir.
     * Doğru çözüm kanaldan tam siparişi çekip önce almaktır; o yol adapter'ın
     * sipariş çekme yeteneğine bağlı ve henüz yazılmadı. Olay SESSİZCE
     * yutulmuyor — uyarı düşüyor ki eksik görünür kalsın.
     */
    private function resolveOrder(NormalizedOrderEvent $normalized, InboxMessage $message): ?Order
    {
        $order = Order::query()
            ->where('channel_connection_id', $message->channel_connection_id)
            ->where('external_id', $normalized->externalOrderId)
            ->first();

        if ($order === null) {
            Log::warning('inbox.order_not_found_for_event', [
                'message' => $message->id,
                'external_order_id' => $normalized->externalOrderId,
                'type' => $normalized->type,
            ]);
        }

        return $order;
    }

    /**
     * Sipariş anlık görüntüsünü tazeler — STOK HAREKETİ ÜRETMEZ (§4).
     *
     * Mal satışta zaten düşülmüştür. Bu yol hareket üretseydi aynı satış
     * iki kez düşülür ve bakiye KALICI olarak bozulurdu.
     */
    private function handleUpdated(NormalizedOrderEvent $normalized, InboxMessage $message): void
    {
        $order = $this->resolveOrder($normalized, $message);

        if ($order === null) {
            return;
        }

        $payload = $normalized->payload;

        $this->updateSnapshot->run(new OrderSnapshotEvent(
            orderId: $order->id,
            externalRef: $normalized->externalRef,
            status: isset($payload['status']) ? (string) $payload['status'] : null,
            financialStatus: isset($payload['financial_status'])
                ? (string) $payload['financial_status']
                : null,
            payload: $payload,
            occurredAt: $normalized->occurredAt,
            inboxMessageId: $message->id,
        ));
    }

    /**
     * Kargo bildirimini kaydeder — STOK HAREKETİ ÜRETMEZ (§4).
     *
     * Kargo yalnızca teslim durumunu izler.
     */
    private function handleFulfilled(NormalizedOrderEvent $normalized, InboxMessage $message): void
    {
        $order = $this->resolveOrder($normalized, $message);

        if ($order === null) {
            return;
        }

        $payload = $normalized->payload;

        /** @var array<string, mixed> $shipment */
        $shipment = is_array($payload['fulfillment'] ?? null) ? $payload['fulfillment'] : [];

        $this->updateFulfillment->run(new FulfillmentEvent(
            orderId: $order->id,
            externalId: isset($shipment['external_id']) ? (string) $shipment['external_id'] : null,
            carrier: isset($shipment['carrier']) ? (string) $shipment['carrier'] : null,
            trackingNumber: isset($shipment['tracking_number'])
                ? (string) $shipment['tracking_number']
                : null,
            status: isset($shipment['status'])
                ? (string) $shipment['status']
                : (isset($payload['status']) ? (string) $payload['status'] : null),
            payload: $payload,
            occurredAt: $normalized->occurredAt,
            inboxMessageId: $message->id,
        ));
    }

    private function defaultWarehouseId(string $tenantId): string
    {
        $id = DB::table('warehouses')
            ->where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->value('id');

        if ($id === null) {
            throw new RuntimeException("Kiracı {$tenantId} için varsayılan depo yok.");
        }

        return (string) $id;
    }
}
