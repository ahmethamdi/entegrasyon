<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\Subscription;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Identity\Models\Tenant;
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
    public function __construct(private readonly RecordAuditLog $audit) {}

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

    /**
     * @param  string|null  $connectionId  Webhook'tan gelince ZORUNLU (P0-5):
     *                                     abonelik yalnız bu bağlantının
     *                                     aboneliğiyse işlenir. Önceden kimlik
     *                                     tüm kiracılarda aranıyordu.
     */
    public function apply(string $subscriptionId, string $shopifyStatus, ?DateTimeInterface $currentPeriodEnd = null, ?string $connectionId = null): ?Subscription
    {
        $status = self::STATUS_MAP[strtoupper($shopifyStatus)] ?? null;

        if ($status === null) {
            Log::warning('billing.shopify.unknown_status', ['id' => $subscriptionId, 'status' => $shopifyStatus]);

            return null;
        }

        return TenantContext::runAsSystem(fn (): ?Subscription => DB::transaction(function () use ($subscriptionId, $status, $currentPeriodEnd, $connectionId): ?Subscription {
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

            // BAŞKA MAĞAZANIN ABONELİĞİ İŞLENMEZ (P0-5).
            if ($connectionId !== null && $subscription->channel_connection_id !== $connectionId) {
                Log::warning('billing.shopify.subscription_connection_mismatch', [
                    'id' => $subscriptionId,
                    'connection' => $connectionId,
                ]);

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

            $before = $subscription->status;

            $subscription->forceFill([
                'status' => $status,
                'started_at' => $status === 'active' ? ($subscription->started_at ?? now()) : $subscription->started_at,
                'current_period_end' => $currentPeriodEnd ?? $subscription->current_period_end,
                'cancelled_at' => in_array($status, ['cancelled', 'expired'], true)
                    ? ($subscription->cancelled_at ?? now())
                    : null,
            ])->save();

            // S14 · kiracının plan kolonu da güncellenir (Stripe yolunun aynısı):
            // panel başlığı ve raporlar onu okur; kota yine abonelikten okunur.
            $this->syncTenantPlan($subscription->tenant_id, $status === 'active' ? $subscription->plan_code : null);

            if ($before !== $status && in_array($status, ['active', 'cancelled', 'expired'], true)) {
                $this->audit->run(
                    action: $status === 'active' ? AuditAction::SUBSCRIPTION_ACTIVATED : AuditAction::SUBSCRIPTION_CANCELLED,
                    subjectType: 'subscription',
                    subjectId: $subscription->id,
                    changes: ['provider' => 'shopify', 'plan' => $subscription->plan_code, 'old_status' => $before, 'ref' => $subscription->external_ref],
                    tenantId: $subscription->tenant_id,
                );
            }

            return $subscription;
        }));
    }

    /** Aktifse planı yazar; değilse kiracının yaşayan aboneliği kalmadıysa ücretsize döner. */
    private function syncTenantPlan(string $tenantId, ?string $activePlan): void
    {
        if ($activePlan !== null) {
            Tenant::query()->whereKey($tenantId)->update(['plan_code' => $activePlan]);

            return;
        }

        $stillLive = Subscription::query()->where('tenant_id', $tenantId)->whereIn('status', Subscription::ACTIVE_STATUSES)->exists();
        if (! $stillLive) {
            Tenant::query()->whereKey($tenantId)->update(['plan_code' => EnforceQuota::DEFAULT_PLAN_CODE]);
        }
    }

    /** Uygulama kaldırıldı: Shopify o mağazadaki aboneliği bitirir. */
    public function cancelForConnection(string $connectionId): int
    {
        return TenantContext::runAsSystem(function () use ($connectionId): int {
            $tenantIds = Subscription::query()
                ->where('provider', 'shopify')
                ->where('channel_connection_id', $connectionId)
                ->whereIn('status', [...Subscription::LIVE_STATUSES, 'pending'])
                ->pluck('tenant_id')->unique();

            $closed = Subscription::query()
                ->where('provider', 'shopify')
                ->where('channel_connection_id', $connectionId)
                ->whereIn('status', [...Subscription::LIVE_STATUSES, 'pending'])
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            foreach ($tenantIds as $tenantId) {
                $this->syncTenantPlan((string) $tenantId, null);
            }

            return $closed;
        });
    }
}
