<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Identity\Enums\TenantRole;
use App\Domain\Identity\Exceptions\TeamException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Ekip üyesinin rolünü değiştir / üyeyi çıkar (S7) — kiracı bağlamında.
 *
 * SON SAHİP KORUNUR: kiracıda en az bir sahip kalmalı. Kalmasaydı abonelik
 * ve ekip ekranlarına kimse giremez, hesap sahipsiz kalırdı ve bunu yalnız
 * platform yöneticisi veritabanından düzeltebilirdi. Kontrol sahip
 * satırları KİLİTLENEREK yapılır: iki sahip aynı anda birbirini düşürürse
 * ikisi de "öbürü kalıyor" sanıp geçerdi.
 *
 * Üyelik satırı kiracı kimliğiyle birlikte aranır — başka kiracının
 * üyesine dokunulamaz (404).
 */
final class ChangeMemberRole
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function change(string $userId, TenantRole $role): void
    {
        DB::transaction(function () use ($userId, $role): void {
            $member = $this->lockMember($userId);
            $current = TenantRole::fromStored($member->role);

            if ($current === $role) {
                return;
            }

            if ($current === TenantRole::OWNER) {
                $this->guardLastOwner();
            }

            DB::table('tenant_users')->where('id', $member->id)->update(['role' => $role->value, 'updated_at' => now()]);

            $this->audit->run(
                action: AuditAction::MEMBER_ROLE_CHANGED,
                subjectType: 'user',
                subjectId: $userId,
                changes: ['from' => $current->value, 'to' => $role->value],
            );
        });
    }

    public function remove(string $userId): void
    {
        DB::transaction(function () use ($userId): void {
            $member = $this->lockMember($userId);

            if (TenantRole::fromStored($member->role) === TenantRole::OWNER) {
                $this->guardLastOwner();
            }

            DB::table('tenant_users')->where('id', $member->id)->delete();

            $this->audit->run(
                action: AuditAction::MEMBER_REMOVED,
                subjectType: 'user',
                subjectId: $userId,
                changes: ['role' => $member->role],
            );
        });
    }

    private function lockMember(string $userId): object
    {
        $member = DB::table('tenant_users')
            ->where('tenant_id', TenantContext::idOrFail())
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        abort_if($member === null, 404);

        return $member;
    }

    private function guardLastOwner(): void
    {
        $owners = DB::table('tenant_users')
            ->where('tenant_id', TenantContext::idOrFail())
            ->where('role', TenantRole::OWNER->value)
            ->lockForUpdate()
            // PostgreSQL toplam fonksiyonuyla FOR UPDATE kabul etmez:
            // satırlar kilitlenerek ÇEKİLİR, sonra sayılır.
            ->pluck('id')
            ->count();

        if ($owners <= 1) {
            throw new TeamException(__('Hesabın son sahibi çıkarılamaz ya da rolü düşürülemez. Önce başka birini sahip yap.'));
        }
    }
}
