<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Kayıt — kullanıcı ve ilk kiracı birlikte doğar.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · faz 1.1.
 *
 * KULLANICI VE KİRACI TEK TRANSACTION'DA:
 *   Kullanıcı yaratılıp kiracı yaratılamazsa ortada kiracısız bir hesap
 *   kalırdı; o hesap panele giremez ve kayıt da tekrarlanamaz (e-posta
 *   tekil). İkisi birlikte ya olur ya olmaz.
 *
 * Varsayılan depoyu `CreateTenant` garanti eder — "en az bir varsayılan
 * depo" kuralı DB kısıtıyla zorlanmaz, o action ile sağlanır (§5).
 */
final class RegisteredUserController extends Controller
{
    public function create(): InertiaResponse
    {
        return Inertia::render('Auth/Register');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'company' => ['required', 'string', 'max:255'],
        ]);

        // Doğrulama YARATMADAN ÖNCE biter: yarım kiracı bırakılmaz.
        $user = DB::transaction(function () use ($validated): User {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],   // model 'hashed' cast eder
            ]);

            (new CreateTenant)->run(
                name: $validated['company'],
                owner: $user,
            );

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->intended('/');
    }
}
