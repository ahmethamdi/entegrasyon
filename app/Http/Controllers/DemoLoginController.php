<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Support\DemoAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * S12 · `/demo` — tek tıkla salt okunur demo paneli.
 *
 * Oturum açık gerçek bir kullanıcı demoya ATILMAZ: kendi hesabından
 * habersizce çıkarılıp başka bir kiracıya düşmesi kafa karıştırırdı.
 */
final class DemoLoginController
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(config('entegrasyon.demo.enabled'), 404);

        $demo = DemoAccount::user();
        abort_if($demo === null, 404);

        if ($request->user() !== null && $request->user()->isNot($demo)) {
            return redirect('/panel');
        }

        Auth::login($demo);
        $request->session()->regenerate();
        $request->session()->forget('tenant_id');

        return redirect('/panel');
    }
}
