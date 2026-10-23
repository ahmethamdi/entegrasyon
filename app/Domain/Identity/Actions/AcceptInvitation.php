<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Identity\Exceptions\TeamException;
use App\Domain\Identity\Models\TenantInvitation;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Daveti kabul et (S7) — kiracı bağlamı YOK (kullanıcı henüz üye değil),
 * bu yüzden sorgular sistem bağlamında ve kiracı kimliği açıkça verilir.
 *
 * E-POSTA EŞLEŞMESİ ZORUNLU: bağlantı yalnız davetin gittiği adresin
 * sahibi tarafından kabul edilir. Bağlantıyı ele geçiren başka bir hesap
 * (ya da yanlış hesapla oturum açmış biri) kendini ekibe ekleyemez.
 *
 * Aynı belirteç İKİ KEZ kullanılamaz: satır kilitlenir, `accepted_at`
 * yazılır; ikinci istek kilit açılınca kapalı daveti görür.
 */
final class AcceptInvitation
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public static function findByToken(string $token): ?TenantInvitation
    {
        return TenantContext::runAsSystem(
            fn (): ?TenantInvitation => TenantInvitation::query()
                ->where('token_hash', TenantInvitation::hashToken($token))
                ->first(),
        );
    }

    public function run(string $token, User $user): TenantInvitation
    {
        return TenantContext::runAsSystem(fn (): TenantInvitation => DB::transaction(function () use ($token, $user): TenantInvitation {
            $invitation = TenantInvitation::query()
                ->where('token_hash', TenantInvitation::hashToken($token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null || ! $invitation->isUsable()) {
                throw new TeamException(__('Bu davet bağlantısı geçersiz ya da süresi dolmuş. Hesap sahibinden yeni davet iste.'));
            }

            if (strcasecmp($invitation->email, (string) $user->email) !== 0) {
                throw new TeamException(__('Bu davet :email adresine gönderildi. O adresle giriş yap.', ['email' => $invitation->email]));
            }

            TenantUser::query()->firstOrCreate(
                ['tenant_id' => $invitation->tenant_id, 'user_id' => $user->id],
                [
                    'role' => $invitation->role,
                    'invited_at' => $invitation->created_at,
                    'accepted_at' => now(),
                ],
            );

            $invitation->forceFill(['accepted_at' => now()])->save();

            // Davetin gittiği adresi açtı: adres doğrulanmış sayılır.
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            $this->audit->run(
                action: AuditAction::MEMBER_JOINED,
                subjectType: 'user',
                subjectId: $user->id,
                changes: ['email' => $invitation->email, 'role' => $invitation->role],
                userId: $user->id,
                tenantId: $invitation->tenant_id,
            );

            return $invitation;
        }));
    }
}
