<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\Subscription;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Identity\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * S14 · kiracıyı HEMEN ücretsiz plana düşürür (iade sonrası — kullanıcı
 * kararı 25 Eki: "14 gün iadede plan hemen ücretsize düşsün").
 *
 * VERİ SİLİNMEZ: ürünler, kanallar, siparişler yerinde kalır; ücretsiz
 * planın üstündeki kullanımda YENİ ekleme engellenir (`EnforceQuota`
 * "kota var olanı silmez" kuralı). Stok ve sipariş akışı durmaz.
 *
 * Sağlayıcıdaki aboneliği kesmek ÇAĞIRANIN işidir (iade yolunda
 * `cancelSubscription(prorate: false)`); bu sınıf yalnız yerel durumu yazar
 * ve iki kez çağrılsa da aynı sonucu verir.
 */
final class DowngradeToFree
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function run(string $tenantId, string $reason, ?string $actorId = null): void
    {
        TenantContext::runAsSystem(function () use ($tenantId, $reason, $actorId): void {
            DB::transaction(function () use ($tenantId, $reason, $actorId): void {
                $live = Subscription::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('status', Subscription::LIVE_STATUSES)
                    ->lockForUpdate()
                    ->get();

                $previous = $live->first()?->plan_code;

                foreach ($live as $subscription) {
                    $subscription->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_at' => null])->save();
                }

                $changed = Tenant::query()->whereKey($tenantId)
                    ->where('plan_code', '!=', EnforceQuota::DEFAULT_PLAN_CODE)
                    ->update(['plan_code' => EnforceQuota::DEFAULT_PLAN_CODE]);

                if ($live->isEmpty() && $changed === 0) {
                    return; // zaten ücretsizde — ikinci çağrı iz bırakmaz
                }

                $this->audit->run(
                    action: AuditAction::DOWNGRADED_TO_FREE,
                    subjectType: 'tenant',
                    subjectId: $tenantId,
                    changes: ['old' => $previous, 'new' => EnforceQuota::DEFAULT_PLAN_CODE, 'reason' => $reason],
                    userId: $actorId,
                    tenantId: $tenantId,
                );
            });
        });
    }
}
