<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Identity\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stripe olayını yerel aboneliğe uygular.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 4, §4 · subscriptions.
 *
 * DEĞİŞMEZ KURAL — ABONELİK DURUMUNUN TEK GERÇEK KAYNAĞI STRIPE'TIR:
 *   Ödeme başarılı mı, dönem ne zaman bitiyor, iptal edildi mi —
 *   bunları biz bilemeyiz, Stripe bilir. Yerelde tahmin yürütülmez;
 *   durum yalnızca webhook ile yazılır. Panelden "abone oldu" yazmak
 *   ödeme alınmadan kota açardı.
 *
 * DEĞİŞMEZ KURAL — TEKRAR İKİNCİ ABONELİK AÇMAZ:
 *   Stripe olayları EN AZ BİR KEZ gönderilir. Çıpa
 *   `subscriptions.external_ref` kısmi tekilliğidir ve arama HER ZAMAN
 *   onun üzerinden yapılır. `updateOrCreate` bu yüzden `external_ref`
 *   ile anahtarlanır.
 *
 * DEĞİŞMEZ KURAL — İPTAL SİLMEZ, DAMGALAR:
 *   `cancelled` yazılır ve satır DURUR. Silinseydi "geçen yıl hangi
 *   plandaydı, ne zaman ayrıldı" sorusu cevapsız kalırdı ve kısmi
 *   tekillik zaten yeni aboneliğe yer açıyor.
 *
 * DEĞİŞMEZ KURAL — KİRACI VE PLAN METADATA'DAN OKUNUR:
 *   Stripe bizim kimliklerimizi bilmez; checkout oturumu açılırken
 *   `metadata` ile taşınır. Metadata eksikse olay YOK SAYILIR ve
 *   uydurma bir kiracıya abonelik yazılmaz.
 *
 * Bağlam: webhook isteği KİRACISIZ gelir. Yazma `runAsSystem` altında
 * yapılır ve `tenant_id` AÇIKÇA verilir.
 */
final class SyncSubscriptionFromStripe
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * Stripe durumlarını yerel durumlara çevirir.
     *
     * `canceled` → `cancelled`: Stripe Amerikan yazımını kullanır, biz
     * §4'ün yazımını. Eşleme burada TEK KAYNAKTIR; iki yerde yazılsaydı
     * biri değiştiğinde abonelik sessizce tanınmaz duruma düşerdi.
     */
    private const STATUS_MAP = [
        'active' => 'active',
        'trialing' => 'trialing',
        'past_due' => 'past_due',
        'unpaid' => 'past_due',
        'canceled' => 'cancelled',
        'incomplete' => 'past_due',
        'incomplete_expired' => 'expired',
        'paused' => 'past_due',
    ];

    /**
     * Ödeme tamamlandı — aboneliği açar veya günceller.
     *
     * @param  array<string, mixed>  $session
     */
    public function fromCheckoutSession(array $session): ?Subscription
    {
        $metadata = $session['metadata'] ?? [];

        $tenantId = $metadata['tenant_id'] ?? null;
        $planCode = $metadata['plan_code'] ?? null;
        $externalRef = $session['subscription'] ?? null;

        if (! is_string($tenantId) || ! is_string($planCode) || ! is_string($externalRef)) {
            // Metadata eksik: uydurma bir kiracıya abonelik YAZILMAZ.
            Log::warning('stripe.checkout_missing_metadata', [
                'session' => $session['id'] ?? null,
            ]);

            return null;
        }

        return TenantContext::runAsSystem(function () use (
            $tenantId,
            $planCode,
            $externalRef,
        ): ?Subscription {
            // Kiracı ve plan GERÇEKTEN var mı? Yoksa olay yok sayılır —
            // olmayan plana abonelik yazmak FK'yı ihlal eder ve 500
            // döndürürdü; Stripe da onu "bozuk uç nokta" sayardı.
            if (! Tenant::query()->whereKey($tenantId)->exists()) {
                Log::warning('stripe.checkout_unknown_tenant', ['tenant' => $tenantId]);

                return null;
            }

            if (! Plan::query()->whereKey($planCode)->exists()) {
                Log::warning('stripe.checkout_unknown_plan', ['plan' => $planCode]);

                return null;
            }

            $supersededRefs = [];

            $subscription = DB::transaction(function () use ($tenantId, $planCode, $externalRef, &$supersededRefs): Subscription {
                // AYNI KİRACININ ÖNCEKİ AKTİF ABONELİĞİ KAPATILIR.
                //
                // `UNIQUE(tenant_id) WHERE aktif` kısıtı iki aktif satıra
                // izin vermez; plan yükseltmede eskisi kapatılmasaydı
                // INSERT kısıta takılır ve ödeme alınmışken abonelik
                // AÇILMAZDI — en kötü hata biçimi.
                $superseded = Subscription::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('status', Subscription::LIVE_STATUSES)
                    ->where('external_ref', '!=', $externalRef);

                $supersededRefs = $superseded->clone()->whereNotNull('external_ref')->pluck('external_ref')->all();

                $superseded->update(['status' => 'cancelled', 'cancelled_at' => now()]);

                // Çıpa `external_ref`: tekrar gelen olay AYNI satırı
                // günceller, ikincisini AÇMAZ.
                $subscription = Subscription::withoutGlobalScopes()
                    ->firstOrNew(['external_ref' => $externalRef]);
                $wasActive = $subscription->exists && $subscription->status === 'active';

                $subscription->fill([
                    'tenant_id' => $tenantId,
                    'plan_code' => $planCode,
                    'status' => 'active',
                    'started_at' => $subscription->started_at ?? now(),
                ]);

                $subscription->save();

                // Kiracının güncel planı kolonda da tutulur (§4 ·
                // `tenants.plan_code`): kota okuması aboneliği gezmeden
                // de cevaplanabilsin diye DEĞİL — o hâlâ abonelikten
                // okunur — panelin ve raporun tek bakışta görmesi için.
                Tenant::query()->whereKey($tenantId)->update(['plan_code' => $planCode]);

                if (! $wasActive) {
                    $this->audit->run(
                        action: AuditAction::SUBSCRIPTION_ACTIVATED,
                        subjectType: 'subscription',
                        subjectId: $subscription->id,
                        changes: ['plan' => $planCode, 'ref' => $externalRef, 'replaced' => $supersededRefs],
                        tenantId: $tenantId,
                    );
                }

                return $subscription;
            });

            // ⚠️ YERELDE KAPATMAK YETMEZ — STRIPE'TA DA KESİLİR.
            //
            // Panel yaşayan abonelikte yeni checkout açmaz; ama iki sekmeden
            // iki ödeme tamamlanırsa (veya eski sürümden kalma ikinci
            // abonelik) burada iki canlı Stripe aboneliği olur ve yalnız
            // yerel satırı kapatmak satıcıyı her ay İKİ KEZ faturalardı.
            // Transaction DIŞINDA: ağ çağrısı kilit tutmaz; başarısızlık
            // aboneliği geri almaz ama SESSİZ de kalmaz.
            foreach ($supersededRefs as $ref) {
                $this->cancelAtProvider((string) $ref, $tenantId);
            }

            return $subscription;
        });
    }

    /** @param array{status: ?string, plan: ?string, cancel_at: ?string} $before */
    private function auditTransition(Subscription $subscription, array $before): void
    {
        $base = ['ref' => $subscription->external_ref, 'plan' => $subscription->plan_code];
        $write = fn (AuditAction $action, array $changes) => $this->audit->run(
            action: $action,
            subjectType: 'subscription',
            subjectId: $subscription->id,
            changes: $base + $changes,
            tenantId: $subscription->tenant_id,
        );

        if ($before['status'] !== 'cancelled' && $subscription->status === 'cancelled') {
            $write(AuditAction::SUBSCRIPTION_CANCELLED, ['old_status' => $before['status']]);

            return;
        }
        if ($before['plan'] !== $subscription->plan_code) {
            $write(AuditAction::SUBSCRIPTION_PLAN_CHANGED, ['old' => $before['plan'], 'new' => $subscription->plan_code]);
        }
        if ($before['cancel_at'] === null && $subscription->cancel_at !== null) {
            $write(AuditAction::SUBSCRIPTION_CANCEL_SCHEDULED, ['cancel_at' => $subscription->cancel_at->toIso8601String()]);
        }
    }

    private function cancelAtProvider(string $ref, string $tenantId): void
    {
        try {
            $this->gateway->cancelSubscription($ref);

            Log::warning('stripe.superseded_subscription_cancelled', ['tenant' => $tenantId, 'ref' => $ref]);
        } catch (Throwable $e) {
            Log::error('stripe.superseded_subscription_cancel_failed', [
                'tenant' => $tenantId,
                'ref' => $ref,
                'error' => $e->getMessage(),
            ]);
            report($e);
        }
    }

    /**
     * Abonelik güncellendi veya silindi — durumu ve dönemi yazar.
     *
     * @param  array<string, mixed>  $object
     */
    public function fromSubscriptionEvent(array $object, bool $deleted = false): ?Subscription
    {
        $externalRef = $object['id'] ?? null;

        if (! is_string($externalRef)) {
            return null;
        }

        return TenantContext::runAsSystem(function () use ($object, $externalRef, $deleted): ?Subscription {
            $subscription = Subscription::withoutGlobalScopes()
                ->where('external_ref', $externalRef)
                ->first();

            if ($subscription === null) {
                // Bilmediğimiz abonelik: sessizce yutulur. Hata dönmek
                // Stripe'ı yeniden denemeye ve sonunda uç noktayı devre
                // dışı bırakmaya iterdi.
                Log::info('stripe.subscription_not_found', ['ref' => $externalRef]);

                return null;
            }

            $status = $deleted
                ? 'cancelled'
                : (self::STATUS_MAP[$object['status'] ?? ''] ?? 'past_due');

            $before = ['status' => $subscription->status, 'plan' => $subscription->plan_code, 'cancel_at' => $subscription->cancel_at?->toIso8601String()];
            $subscription->status = $status;

            // PLAN DEĞİŞİKLİĞİ buradan gelir: panel aynı aboneliğin kalemini
            // değiştirir ve planı aboneliğin metadata'sına yazar. Bilinmeyen
            // plan kodu YAZILMAZ (FK) — mevcut plan korunur.
            $newPlan = $object['metadata']['plan_code'] ?? null;

            if (! $deleted
                && is_string($newPlan)
                && $newPlan !== $subscription->plan_code
                && Plan::query()->whereKey($newPlan)->exists()
            ) {
                $subscription->plan_code = $newPlan;
                Tenant::query()->whereKey($subscription->tenant_id)->update(['plan_code' => $newPlan]);
            }

            // NULL "DEĞİŞMEDİ" DEMEKTİR, "BOŞALT" DEĞİL — sipariş
            // güncelleme kuralının aynısı. Stripe her olayda tüm
            // alanları göndermez ve boş değerin mevcut veriyi ezmesi
            // GERİ ALINAMAZ.
            //
            // 2025-03-31 (basil) API sürümünden beri alan aboneliğin üstünde
            // DEĞİL, kalemdedir (`items.data[].current_period_end`). Yalnız
            // üst alan okunsaydı yeni webhook uç noktalarında yenileme
            // tarihi hiç yazılmazdı. İkisi de okunur.
            $periodEnd = $object['current_period_end']
                ?? $object['items']['data'][0]['current_period_end']
                ?? null;

            if (is_int($periodEnd)) {
                $subscription->current_period_end = now()->setTimestamp($periodEnd);
            }

            if ($status === 'cancelled' && $subscription->cancelled_at === null) {
                $subscription->cancelled_at = now();
            }

            // S14 · DÖNEM SONU İPTALİ. Stripe abonelik olayı nesnenin TAMAMINI
            // taşır: `cancel_at` anahtarı VARSA ve null ise iptal geri alınmıştır.
            // Eski API sürümleri yalnız `cancel_at_period_end` gönderir → dönem sonu.
            if (! $deleted && array_key_exists('cancel_at', $object)) {
                $cancelAt = $object['cancel_at'];
                if (! is_int($cancelAt) && ($object['cancel_at_period_end'] ?? false) === true && is_int($periodEnd)) {
                    $cancelAt = $periodEnd;
                }
                $subscription->cancel_at = is_int($cancelAt) ? now()->setTimestamp($cancelAt) : null;
            }
            if ($status === 'cancelled') {
                $subscription->cancel_at = null;
            }

            $subscription->save();

            $this->auditTransition($subscription, $before);

            return $subscription;
        });
    }
}
