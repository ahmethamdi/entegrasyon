<?php

declare(strict_types=1);

namespace App\Domain\Billing\Routing;

use App\Domain\Billing\Actions\SyncSubscriptionFromShopify;
use App\Domain\Billing\Support\ShopifyBilling;
use App\Domain\Messaging\Models\InboxMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shopify faturalama olayları — sipariş ve yaşam döngüsü yönlendiricilerinden
 * ÖNCE sorulur (`ProcessInboxMessage`). Aynı inbox hattı, aynı imza ve
 * tekilleştirme; ikinci bir olay sistemi DEĞİL (ChannelLifecycleRouter
 * gerekçesiyle aynı).
 *
 * MODÜL SINIRI: abonelik Billing domain'inindir; kanal yaşam döngüsü
 * yönlendiricisine konsaydı Channels, Billing modeline yazardı.
 *
 * ⚠️ `app_subscriptions/update` BURADA TÜKETİLMEZSE sipariş yoluna düşer:
 * `ShopifyOrderNormalizer` bilinmeyen konuyu `updated` sayar ve abonelik
 * kimliğini sipariş kimliği sanardı (`app/uninstalled`'ın eski hatası).
 */
final class ShopifyBillingRouter
{
    public function __construct(
        private readonly SyncSubscriptionFromShopify $sync,
        private readonly ShopifyBilling $billing,
    ) {}

    /** `true` = tüketildi, sonraki yönlendiricilere GİTMEZ. */
    public function route(InboxMessage $message): bool
    {
        $connection = $message->connection;

        if ($connection === null || $connection->channel_type_code !== 'shopify') {
            return false;
        }

        $topic = mb_strtolower(trim((string) ($message->event_type ?? '')));
        $payload = is_array($message->payload) ? $message->payload : [];

        if ($topic === 'app/uninstalled') {
            // Shopify kaldırmada aboneliği bitirir; yerelde de kapanır.
            // TÜKETİLMEZ: yaşam döngüsü yönlendiricisi erişimi kapatacak.
            $closed = $this->sync->cancelForConnection($connection->id);
            Log::info('billing.shopify.uninstalled', ['connection' => $connection->id, 'closed' => $closed]);

            return false;
        }

        if ($topic !== 'app_subscriptions/update') {
            return false;
        }

        $sub = is_array($payload['app_subscription'] ?? null) ? $payload['app_subscription'] : [];
        $id = $sub['admin_graphql_api_id'] ?? null;
        $status = $sub['status'] ?? null;

        if (! is_string($id) || ! is_string($status)) {
            Log::warning('billing.shopify.webhook_incomplete', ['message' => $message->id]);

            return true;
        }

        // Gövde dönem sonunu taşımaz; aktifte Shopify'dan okunur. Okunamazsa
        // durum yine yazılır — tarih bir sonraki olayda gelir.
        $periodEnd = null;

        if (strtoupper($status) === 'ACTIVE') {
            try {
                $periodEnd = $this->billing->fetch($connection, $id)['currentPeriodEnd'] ?? null;
            } catch (Throwable $e) {
                Log::warning('billing.shopify.period_fetch_failed', ['id' => $id, 'error' => $e->getMessage()]);
            }
        }

        $this->sync->apply($id, $status, $periodEnd);

        return true;
    }
}
