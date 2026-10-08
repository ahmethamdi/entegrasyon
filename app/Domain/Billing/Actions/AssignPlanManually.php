<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Identity\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Süper admin bir kiracıya planı elle atar (`provider = manual`).
 *
 * Kullanım: müşteriye özel plan satmak, ödemesi havale/fatura ile alınan
 * anlaşmalar, deneme süresi uzatmak.
 *
 * ⚠️ ÖDEME SAĞLAYICISININ AKTİF ABONELİĞİ VARSA REDDEDİLİR. Stripe ya da
 * Shopify aboneliği yanında elle plan açılsaydı iki aktif abonelik olurdu:
 * kota hangisini okuyacağını rastgele seçer (`first()`), müşteri de iki kez
 * ödeyebilirdi. Önce ödeme aboneliği iptal edilmeli.
 *
 * ESKİ ELLE ATAMA KAPATILIR (cancelled), silinmez: geçmiş denetimde kalır.
 *
 * BİTİŞ TARİHİ İSTEĞE BAĞLIDIR. Elle aboneliğin süresini hiçbir ödeme
 * bildirimi kapatmaz; tarih geçince `EnforceQuota` onu artık saymaz ve
 * kiracı varsayılan plana düşer.
 */
final class AssignPlanManually
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function run(Tenant $tenant, Plan $plan, ?CarbonInterface $endsAt, ?string $actorId): Subscription
    {
        return TenantContext::runFor($tenant->id, function () use ($tenant, $plan, $endsAt, $actorId): Subscription {
            return DB::transaction(function () use ($tenant, $plan, $endsAt, $actorId): Subscription {
                $active = Subscription::query()
                    ->whereIn('status', Subscription::LIVE_STATUSES)
                    ->lockForUpdate()
                    ->get();

                $paid = $active->first(fn (Subscription $s): bool => $s->provider !== 'manual');

                if ($paid !== null) {
                    throw ValidationException::withMessages([
                        'plan_code' => __('Bu müşterinin :provider üzerinden ödenen aktif aboneliği var; önce onu iptal et.', ['provider' => ucfirst((string) $paid->provider)]),
                    ]);
                }

                $previous = $active->first();

                foreach ($active as $old) {
                    $old->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
                }

                $subscription = Subscription::query()->create([
                    'tenant_id' => $tenant->id,
                    'plan_code' => $plan->code,
                    'status' => 'active',
                    'provider' => 'manual',
                    'started_at' => now(),
                    'current_period_end' => $endsAt,
                ]);

                // Kiracı satırındaki plan kodu da aynı kalsın (panel başlığı
                // ve eski raporlar onu okuyor).
                $tenant->forceFill(['plan_code' => $plan->code])->save();

                $this->audit->run(
                    action: AuditAction::PLAN_ASSIGNED_BY_ADMIN,
                    subjectType: 'subscription',
                    subjectId: $subscription->id,
                    changes: [
                        'old' => $previous?->plan_code,
                        'new' => $plan->code,
                        'ends_at' => $endsAt?->toDateString(),
                    ],
                    userId: $actorId,
                    tenantId: $tenant->id,
                );

                return $subscription;
            });
        });
    }
}
