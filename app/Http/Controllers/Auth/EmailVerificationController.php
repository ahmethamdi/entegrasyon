<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * E-posta doğrulama (B4).
 *
 * Önceden herhangi bir adresle (başkasınınki dahil) hesap açılıp panel
 * kullanılabiliyordu: parola sıfırlama postası yanlış kişiye gider, sahte
 * kayıtlar ayırt edilemezdi. Doğrulanmamış hesap panele GİREMEZ
 * (`verified` ara katmanı) ve bu sayfaya yönlendirilir.
 */
final class EmailVerificationController extends Controller
{
    public function notice(Request $request): InertiaResponse|RedirectResponse
    {
        if ($request->user()?->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard'));
        }

        return Inertia::render('Auth/VerifyEmail', [
            'email' => $request->user()?->email,
            'status' => session('status'),
        ]);
    }

    /** İmzalı bağlantı — imza ve kullanıcı/hash eşleşmesini istek sınıfı denetler. */
    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->intended(route('dashboard'))->with('success', __('E-posta adresiniz doğrulandı.'));
    }

    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()?->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard'));
        }

        $request->user()?->sendEmailVerificationNotification();

        return back()->with('status', __('Doğrulama bağlantısı yeniden gönderildi.'));
    }
}
