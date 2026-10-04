<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Parola sıfırlama (B4) — bağlantı isteme ve yeni parola belirleme.
 *
 * Önceden bu akış HİÇ yoktu: parolasını unutan satıcı hesabına — ve
 * kanallarına giden senkrona — erişimi kalıcı olarak kaybederdi.
 *
 * DEĞİŞMEZ KURAL — YANIT KAYITLI E-POSTAYI SIZDIRMAZ:
 *   "Bu adresle hesap yok" demek, formu kayıtlı satıcı listesini çıkaran
 *   bir sorgu aracına çevirirdi. Bağlantı istendiğinde yanıt HER DURUMDA
 *   aynıdır.
 *
 * DEĞİŞMEZ KURAL — SIFIRLAMA ÖTEKİ OTURUMLARI KAPATIR:
 *   Parolası çalınan satıcı sıfırlama yapar; saldırganın açık oturumu
 *   (ve "beni hatırla" çerezi) yaşamaya devam etseydi sıfırlama hiçbir
 *   şeyi korumazdı. `remember_token` yenilenir, veritabanı oturumları silinir.
 */
final class PasswordResetController extends Controller
{
    public function request(): InertiaResponse
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    public function email(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        // Sonuç BİLEREK okunmaz. Broker'ın "bekleyin" (RESET_THROTTLED)
        // durumu bile yalnız KAYITLI adreste oluşur; onu göstermek de
        // kayıt bilgisi sızdırırdı. Posta bombasını IP sınırı
        // (`throttle:password-reset`) ve broker'ın e-posta başı sınırı keser.
        Password::sendResetLink(['email' => mb_strtolower($validated['email'])]);

        return back()->with('status', __(Password::RESET_LINK_SENT));
    }

    public function edit(Request $request, string $token): InertiaResponse
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            [
                'email' => mb_strtolower($validated['email']),
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $validated['token'],
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,            // model 'hashed' cast eder
                    'remember_token' => Str::random(60),
                ])->save();

                // Açık oturumlar kapanır (sınıf başlığı). Oturum sürücüsü
                // veritabanı değilse tablo yoktur; o durumda remember_token
                // yenilemesi yine "beni hatırla" çerezini öldürür.
                if (config('session.driver') === 'database') {
                    DB::table((string) config('session.table', 'sessions'))
                        ->where('user_id', $user->getKey())
                        ->delete();
                }

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // Geçersiz/süresi dolmuş bağlantı ve bilinmeyen e-posta AYNI
            // mesajı alır — ikisini ayırmak yine kayıt bilgisi sızdırırdı.
            throw ValidationException::withMessages(['email' => __(Password::INVALID_TOKEN)]);
        }

        return redirect()->route('login')->with('success', __($status));
    }
}
