<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * S7 · birden çok firmanın üyesi olan kullanıcı firma değiştirir.
 *
 * Üyelik BURADA doğrulanır ve `EstablishTenantContext` sonraki istekte
 * YİNE doğrular: oturumdaki kimlik yalnız ipucudur.
 */
final class TenantSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['tenant_id' => ['required', 'uuid']]);

        $isMember = DB::table('tenant_users')
            ->join('tenants', 'tenants.id', '=', 'tenant_users.tenant_id')
            ->where('tenant_users.user_id', $request->user()->id)
            ->where('tenant_users.tenant_id', $data['tenant_id'])
            ->where('tenants.status', 'active')
            ->exists();

        abort_unless($isMember, 404);

        $request->session()->put('tenant_id', $data['tenant_id']);

        return redirect()->route('dashboard');
    }
}
