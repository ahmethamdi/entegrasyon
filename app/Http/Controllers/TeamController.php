<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Exceptions\QuotaExceededException;
use App\Domain\Identity\Actions\ChangeMemberRole;
use App\Domain\Identity\Actions\InviteMember;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Identity\Enums\TenantRole;
use App\Domain\Identity\Exceptions\TeamException;
use App\Domain\Identity\Models\TenantInvitation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * S7 · Ayarlar → Ekip. Yalnız sahip (`team.manage`, `RoutePermissions`).
 *
 * ROTA MODEL BAĞLAMASI KULLANILMAZ — `SubstituteBindings` `tenant` ara
 * katmanından ÖNCE çalışır (bkz. routes/web.php). Kimlikler metin gelir,
 * kiracı kapsamında aranır; başka kiracının üyesi/daveti 404.
 */
final class TeamController extends Controller
{
    public function index(Request $request, EnforceQuota $quota): InertiaResponse
    {
        $members = DB::table('tenant_users')
            ->join('users', 'users.id', '=', 'tenant_users.user_id')
            ->where('tenant_users.tenant_id', TenantContext::idOrFail())
            ->orderBy('tenant_users.created_at')
            ->get(['users.id', 'users.name', 'users.email', 'tenant_users.role', 'tenant_users.accepted_at']);

        $invitations = TenantInvitation::query()->pending()->orderByDesc('created_at')->get();

        $roles = array_map(static fn (TenantRole $r): array => [
            'value' => $r->value,
            'label' => $r->label(),
            'description' => $r->description(),
        ], TenantRole::cases());

        return Inertia::render('Settings/Team', [
            'members' => $members->map(fn (object $m): array => [
                'id' => $m->id,
                'name' => $m->name,
                'email' => $m->email,
                'role' => TenantRole::fromStored($m->role)->value,
                'joinedAt' => $m->accepted_at,
                'isSelf' => $m->id === $request->user()->id,
            ])->values(),
            'invitations' => $invitations->map(fn (TenantInvitation $i): array => [
                'id' => $i->id,
                'email' => $i->email,
                'role' => $i->roleEnum()->value,
                'expiresAt' => $i->expires_at->toIso8601String(),
                'expired' => ! $i->isUsable(),
            ])->values(),
            'roles' => $roles,
            'seats' => [
                'used' => $quota->currentUsage(QuotaMetric::USERS),
                'limit' => $quota->remaining(QuotaMetric::USERS) === null
                    ? null
                    : $quota->currentUsage(QuotaMetric::USERS) + (int) $quota->remaining(QuotaMetric::USERS),
            ],
        ]);
    }

    public function invite(Request $request, InviteMember $invite): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in([TenantRole::ADMIN->value, TenantRole::WAREHOUSE->value, TenantRole::VIEWER->value])],
        ]);

        return $this->sendInvite($request, $invite, $data['email'], TenantRole::from($data['role']));
    }

    public function resend(Request $request, string $invitation, InviteMember $invite): RedirectResponse
    {
        $row = TenantInvitation::query()->pending()->whereKey($invitation)->firstOrFail();

        return $this->sendInvite($request, $invite, $row->email, $row->roleEnum());
    }

    public function cancel(string $invitation, RecordAuditLog $audit): RedirectResponse
    {
        $row = TenantInvitation::query()->pending()->whereKey($invitation)->firstOrFail();

        DB::transaction(function () use ($row, $audit): void {
            $row->delete();
            $audit->run(
                action: AuditAction::INVITATION_CANCELLED,
                subjectType: 'tenant_invitation',
                subjectId: $row->id,
                changes: ['email' => $row->email, 'role' => $row->role],
            );
        });

        return back()->with('success', __('Davet iptal edildi.'));
    }

    public function updateRole(Request $request, string $user, ChangeMemberRole $members): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(array_map(static fn (TenantRole $r): string => $r->value, TenantRole::cases()))],
        ]);

        try {
            $members->change($user, TenantRole::from($data['role']));
        } catch (TeamException $e) {
            return back()->withErrors(['member' => $e->getMessage()]);
        }

        return back()->with('success', __('Rol güncellendi.'));
    }

    public function remove(Request $request, string $user, ChangeMemberRole $members): RedirectResponse
    {
        try {
            $members->remove($user);
        } catch (TeamException $e) {
            return back()->withErrors(['member' => $e->getMessage()]);
        }

        // Kendini çıkaran sahip bu ekranı artık göremez.
        if ($user === $request->user()->id) {
            $request->session()->forget('tenant_id');

            return redirect()->route('dashboard');
        }

        return back()->with('success', __('Üye ekipten çıkarıldı.'));
    }

    private function sendInvite(Request $request, InviteMember $invite, string $email, TenantRole $role): RedirectResponse
    {
        try {
            $invite->run($request->user(), $email, $role);
        } catch (TeamException $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        } catch (QuotaExceededException $e) {
            return back()->withErrors(['email' => $e->userMessage()]);
        }

        return back()->with('success', __(':email adresine davet gönderildi.', ['email' => mb_strtolower(trim($email))]));
    }
}
