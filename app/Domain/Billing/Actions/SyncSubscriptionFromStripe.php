<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Identity\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

            return DB::transaction(function () use ($tenantId, $planCode, $externalRef): Subscription {
                // AYNI KİRACININ ÖNCEKİ AKTİF ABONELİĞİ KAPATILIR.
                //
                // `UNIQUE(tenant_id) WHERE aktif` kısıtı iki aktif satıra
                // izin vermez; plan yükseltmede eskisi kapatılmasaydı
                // INSERT kısıta takılır ve ödeme alınmışken abonelik
                // AÇILMAZDI — en kötü hata biçimi.
                Subscription::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('status', Subscription::ACTIVE_STATUSES)
                    ->where('external_ref', '!=', $externalRef)
                    ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

                // Çıpa `external_ref`: tekrar gelen olay AYNI satırı
                // günceller, ikincisini AÇMAZ.
                $subscription = Subscription::withoutGlobalScopes()
                    ->firstOrNew(['external_ref' => $externalRef]);

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

                return $subscription;
            });
        });
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

            $subscription->status = $status;

            // NULL "DEĞİŞMEDİ" DEMEKTİR, "BOŞALT" DEĞİL — sipariş
            // güncelleme kuralının aynısı. Stripe her olayda tüm
            // alanları göndermez ve boş değerin mevcut veriyi ezmesi
            // GERİ ALINAMAZ.
            if (isset($object['current_period_end']) && is_int($object['current_period_end'])) {
                $subscription->current_period_end = now()->setTimestamp($object['current_period_end']);
            }

            if ($status === 'cancelled' && $subscription->cancelled_at === null) {
                $subscription->cancelled_at = now();
            }

            $subscription->save();

            return $subscription;
        });
    }
}
