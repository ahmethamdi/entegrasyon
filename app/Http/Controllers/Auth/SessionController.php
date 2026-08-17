<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Giriş ve çıkış.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · faz 1.1, §11 · Güvenlik.
 *
 * OTURUM KİMLİĞİ GİRİŞTE YENİLENİR:
 *   Oturum sabitleme saldırısında saldırgan kurbanın tarayıcısına bilinen
 *   bir oturum kimliği yerleştirir ve kurban giriş yaptıktan sonra aynı
 *   kimlikle oturuma girer. `regenerate()` bunu keser.
 *
 * DENEME SINIRI: parola deneme saldırısı e-posta + IP başına sınırlanır.
 * Yalnızca IP'ye bakmak paylaşılan ağlardaki meşru kullanıcıları keser;
 * yalnızca e-postaya bakmak saldırganın IP değiştirerek devam etmesine
 * izin verirdi.
 */
final class SessionController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function create(): InertiaResponse
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = $this->throttleKey($request, $validated['email']);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => __('Çok fazla deneme. :seconds saniye sonra tekrar deneyin.', [
                    'seconds' => RateLimiter::availableIn($throttleKey),
                ]),
            ]);
        }

        if (! Auth::attempt($validated, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            // Hangi alanın yanlış olduğu SÖYLENMEZ: "böyle bir e-posta yok"
            // demek, saldırgana geçerli hesapları saydırır.
            throw ValidationException::withMessages([
                'email' => __('Bu bilgilerle eşleşen bir hesap bulunamadı.'),
            ]);
        }

        RateLimiter::clear($throttleKey);

        // OTURUM KİMLİĞİ BURADA YENİLENMEZ ÇÜNKÜ ZATEN YENİLENDİ:
        // Auth::attempt() → SessionGuard::login() içinde
        // `session->regenerate(true)` çağrılıyor. İkinci bir çağrı
        // eklemek, satırın yük taşıdığı izlenimi verir ve ileride
        // "gereksiz" diye silinirse gerçek korumanın nerede olduğu
        // kaybolur. Garantiyi AuthenticationTest doğruluyor.

        return redirect()->intended('/');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        // Oturum tamamen bırakılır: kiracı kimliği de dahil hiçbir şey kalmaz.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        TenantContext::clear();

        return redirect()->route('login');
    }

    private function throttleKey(Request $request, string $email): string
    {
        return 'login:'.mb_strtolower($email).'|'.$request->ip();
    }
}
