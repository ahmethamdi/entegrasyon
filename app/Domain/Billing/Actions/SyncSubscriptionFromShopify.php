<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\Subscription;
use App\Support\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shopify abonelik durumunu YEREL kayda işler — tek yazma kapısı.
 *
 * Yerel satır abonelik oluşturulurken `pending` açılır (hangi plan, hangi
 * mağaza bizde kalsın diye); durum yalnız Shopify'dan OKUNANLA değişir
 * (dönüş adresi ve `app_subscriptions/update` webhook'u). Satıcının
 * tarayıcısından gelen parametreye güvenilmez.
 *
 * AKTİF OLAN TEK KALIR: Shopify yeni aboneliği onaylatınca eskisini kendi
 * iptal eder; yerelde de aynı kiracının diğer Shopify abonelikleri
 * kapatılır. Kapatılmasaydı iki "aktif" satır kotada yanlış planı
 * gösterebilirdi.
 *
 * Bağlam: webhook KİRACISIZ gelir → `runAsSystem`, kiracı satırdan.
 */
final class SyncSubscriptionFromShopify
{
    /** Shopify AppSubscriptionStatus → yerel durum (TEK kaynak). */
    public const STATUS_MAP = [
        'PENDING' => 'pending',
        'ACCEPTED' => 'pending',
        'ACTIVE' => 'active',
        'FROZEN' => 'past_due',
        'DECLINED' => 'cancelled',
        'CANCELLED' => 'cancelled',
        'EXPIRED' => 'expired',
    ];

    public function apply(string $subscriptionId, string $shopifyStatus, ?DateTimeInterface $currentPeriodEnd = null): ?Subscription
    {
        $status = self::STATUS_MAP[strtoupper($shopifyStatus)] ?? null;

        if ($status === null) {
            Log::warning('billing.shopify.unknown_status', ['id' => $subscriptionId, 'status' => $shopifyStatus]);

            return null;
        }

        return TenantContext::runAsSystem(fn (): ?Subscription => DB::transaction(function () use ($subscriptionId, $status, $currentPeriodEnd): ?Subscription {
            $subscription = Subscription::query()
                ->where('provider', 'shopify')
                ->where('external_ref', $subscriptionId)
                ->lockForUpdate()
                ->first();

            if ($subscription === null) {
                // Bizim açmadığımız abonelik (ör. başka sürümden) — uydurma
                // kiracıya yazılmaz.
                Log::warning('billing.shopify.unknown_subscription', ['id' => $subscriptionId]);

                return null;
            }

            // ÖNCE ESKİLER KAPANIR: `UNIQUE(tenant_id) WHERE status IN
            // ('active','trialing')` kısmi indeksi — yeni satır önce aktif
            // olsaydı kısıt ihlali, işlem geri alınır, abonelik açılmazdı.
            if ($status === 'active') {
                Subscription::query()
                    ->where('tenant_id', $subscription->tenant_id)
                    ->where('provider', 'shopify')
                    ->whereKeyNot($subscription->getKey())
                    ->whereIn('status', [...Subscription::LIVE_STATUSES, 'pending'])
                    ->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            }

            $subscription->forceFill([
                'status' => $status,
                'started_at' => $status === 'active' ? ($subscription->started_at ?? now()) : $subscription->started_at,
                'current_period_end' => $currentPeriodEnd ?? $subscription->current_period_end,
                'cancelled_at' => in_array($status, ['cancelled', 'expired'], true)
                    ? ($subscription->cancelled_at ?? now())
                    : null,
            ])->save();

            return $subscription;
        }));
    }

    /** Uygulama kaldırıldı: Shopify o mağazadaki aboneliği bitirir. */
    public function cancelForConnection(string $connectionId): int
    {
        return TenantContext::runAsSystem(fn (): int => Subscription::query()
            ->where('provider', 'shopify')
            ->where('channel_connection_id', $connectionId)
            ->whereIn('status', [...Subscription::LIVE_STATUSES, 'pending'])
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]));
    }
}
