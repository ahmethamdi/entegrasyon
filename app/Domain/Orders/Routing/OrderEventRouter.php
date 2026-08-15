<?php

declare(strict_types=1);

namespace App\Domain\Orders\Routing;

use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Actions\ApplyOrderCancellation;
use App\Domain\Orders\Actions\ApplyOrderReturn;
use App\Domain\Orders\Actions\IngestChannelOrder;
use App\Domain\Orders\Enums\OrderEventType;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Support\CancellationEvent;
use App\Domain\Orders\Support\CancelledLine;
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
            // hareketi ÜRETMEZ. UpdateOrderSnapshot ve UpdateFulfillment
            // henüz yazılmadı; olay yutulmasın diye açıkça log'lanıyor.
            OrderEventType::UPDATED,
            OrderEventType::FULFILLED => $this->handleUnimplemented($type, $normalized, $message),

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

    private function handleUnimplemented(
        OrderEventType $type,
        NormalizedOrderEvent $normalized,
        InboxMessage $message,
    ): void {
        Log::info('inbox.event_type_not_implemented', [
            'message' => $message->id,
            'type' => $type->value,
            'external_order_id' => $normalized->externalOrderId,
        ]);
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
