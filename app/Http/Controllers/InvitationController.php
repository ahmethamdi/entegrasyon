<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Actions\AcceptInvitation;
use App\Domain\Identity\Exceptions\TeamException;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * S7 · davet bağlantısı (`/invitations/{token}`, `signed:relative`).
 *
 * Kiracı bağlamı DIŞINDADIR: davet edilen henüz üye değil.
 *
 *   giriş yapmamış → davet sayfası; belirteç oturuma yazılır ki kayıt
 *                    formu firma sormadan bu ekibe katılsın, giriş sonrası
 *                    da `intended` bu bağlantıya döner
 *   giriş yapmış   → e-posta eşleşirse kabul, kiracı oturumda seçilir
 */
final class InvitationController extends Controller
{
    public const SESSION_KEY = 'pending_invitation';

    public function show(Request $request, string $token, AcceptInvitation $accept): InertiaResponse|RedirectResponse
    {
        $invitation = AcceptInvitation::findByToken($token);

        if ($invitation === null || ! $invitation->isUsable()) {
            return Inertia::render('Auth/Invitation', ['state' => 'invalid']);
        }

        $user = $request->user();

        if ($user === null) {
            $request->session()->put(self::SESSION_KEY, $token);
            $request->session()->put('url.intended', $request->fullUrl());

            return Inertia::render('Auth/Invitation', [
                'state' => 'guest',
                'tenant' => $this->tenantName($invitation->tenant_id),
                'email' => $invitation->email,
                'role' => $invitation->roleEnum()->label(),
                'hasAccount' => User::query()->whereRaw('lower(email) = ?', [mb_strtolower($invitation->email)])->exists(),
            ]);
        }

        try {
            $accept->run($token, $user);
        } catch (TeamException $e) {
            return Inertia::render('Auth/Invitation', [
                'state' => 'mismatch',
                'message' => $e->getMessage(),
            ]);
        }

        $request->session()->forget(self::SESSION_KEY);
        $request->session()->put('tenant_id', $invitation->tenant_id);

        return redirect()->route('dashboard')->with('success', __(':tenant ekibine katıldın.', [
            'tenant' => $this->tenantName($invitation->tenant_id),
        ]));
    }

    /** Oturumda bekleyen, hâlâ geçerli davet (kayıt formu için). */
    public static function pending(Request $request): ?array
    {
        $token = $request->session()->get(self::SESSION_KEY);

        if (! is_string($token) || $token === '') {
            return null;
        }

        $invitation = AcceptInvitation::findByToken($token);

        if ($invitation === null || ! $invitation->isUsable()) {
            return null;
        }

        return [
            'token' => $token,
            'email' => $invitation->email,
            'tenant' => TenantContext::runAsSystem(fn () => (string) Tenant::query()->whereKey($invitation->tenant_id)->value('name')),
            'tenantId' => $invitation->tenant_id,
        ];
    }

    private function tenantName(string $tenantId): string
    {
        return (string) TenantContext::runAsSystem(fn () => Tenant::query()->whereKey($tenantId)->value('name'));
    }
}
