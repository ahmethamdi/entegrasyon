<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Identity\Enums\TenantRole;
use App\Domain\Identity\Exceptions\TeamException;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantInvitation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\TeamInvitationNotification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Ekibe davet (S7) — kiracı bağlamında, yalnız sahip çağırır (rota kapısı).
 *
 * AYNI ADRESE YENİDEN DAVET = YENİLEME: bekleyen davet silinir ve yenisi
 * yazılır (yeni belirteç, yeni süre, yeni rol). Eski bağlantı bu anda ölür;
 * iki geçerli bağlantı bırakılsaydı eski roldeki davet de kabul edilebilirdi.
 *
 * KOTA yenilemede yeniden sayılmaz — koltuk zaten tutulmuştu.
 *
 * Sahip rolüyle davet YOK: sahiplik ekibe katılmış birine rol değişimiyle
 * verilir. Davetle verilseydi henüz hesabı bile olmayan biri aboneliği
 * yönetebilir hâle gelirdi.
 */
final class InviteMember
{
    public function __construct(
        private readonly EnforceQuota $quota,
        private readonly RecordAuditLog $audit,
    ) {}

    public function run(User $inviter, string $email, TenantRole $role): TenantInvitation
    {
        $email = mb_strtolower(trim($email));

        if ($role === TenantRole::OWNER) {
            throw new TeamException(__('Sahip rolü davetle verilmez; kişi ekibe katıldıktan sonra rolünü değiştirebilirsin.'));
        }

        $tenantId = TenantContext::idOrFail();

        $alreadyMember = DB::table('tenant_users')
            ->join('users', 'users.id', '=', 'tenant_users.user_id')
            ->where('tenant_users.tenant_id', $tenantId)
            ->whereRaw('lower(users.email) = ?', [$email])
            ->exists();

        if ($alreadyMember) {
            throw new TeamException(__('Bu kişi zaten ekipte.'));
        }

        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($inviter, $email, $role, $token): TenantInvitation {
            $existing = TenantInvitation::query()->pending()->whereRaw('lower(email) = ?', [$email])->first();

            if ($existing === null || ! $existing->isUsable()) {
                $existing?->delete();
                $this->quota->check(QuotaMetric::USERS);
            } else {
                $existing->delete();
            }

            $invitation = TenantInvitation::query()->create([
                'email' => $email,
                'role' => $role->value,
                'token_hash' => TenantInvitation::hashToken($token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(TenantInvitation::TTL_DAYS),
            ]);

            $this->audit->run(
                action: AuditAction::MEMBER_INVITED,
                subjectType: 'tenant_invitation',
                subjectId: $invitation->id,
                changes: ['email' => $email, 'role' => $role->value],
            );

            return $invitation;
        });

        $tenantName = (string) TenantContext::runAsSystem(
            fn () => Tenant::query()->whereKey($tenantId)->value('name'),
        );

        Notification::route('mail', $email)->notify(new TeamInvitationNotification(
            token: $token,
            tenantName: $tenantName,
            inviterName: (string) $inviter->name,
            role: $role,
            expiresAt: $invitation->expires_at,
        ));

        return $invitation;
    }
}
