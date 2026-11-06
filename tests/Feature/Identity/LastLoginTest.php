<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Son giriş zamanı (6 Kas 2026).
 *
 * `users.last_login_at` admin panelinde okunuyordu ama hiçbir kod
 * yazmıyordu — canlıda 0 dolu satır. Dinleyici `Login` olayında yazar;
 * bu dosya üç giriş yolunu ve `updated_at`'e dokunulmadığını sınar.
 */
final class LastLoginTest extends TestCase
{
    use RefreshDatabase;

    /** Parolalı giriş son giriş zamanını yazar, updated_at'e dokunmaz. */
    #[Test]
    public function a_password_login_records_the_time(): void
    {
        $user = $this->user();
        $updatedAt = DB::table('users')->where('id', $user->id)->value('updated_at');

        $this->travel(5)->minutes();

        $this->post('/login', ['email' => $user->email, 'password' => 'gizli-parola-123'])
            ->assertRedirect('/panel');

        $row = DB::table('users')->where('id', $user->id)->first();
        $this->assertNotNull($row->last_login_at);
        $this->assertEqualsWithDelta(now()->timestamp, strtotime((string) $row->last_login_at), 2);
        $this->assertSame($updatedAt, $row->updated_at, 'Giriş profilin updated_at zamanını ezmemeli.');
    }

    /** "Beni hatırla" ile giriş ve sonra yalnız çerezle geri geliş de giriştir. */
    #[Test]
    public function a_remembered_login_and_the_cookie_comeback_record_the_time(): void
    {
        $user = $this->user();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'gizli-parola-123',
            'remember' => true,
        ])->assertRedirect('/panel');

        $this->assertNotNull(DB::table('users')->where('id', $user->id)->value('last_login_at'));

        // Ertesi gün: oturum yok, yalnız "beni hatırla" çerezi.
        $recaller = Auth::guard('web')->getRecallerName();
        $this->assertNotNull($response->getCookie($recaller, false), 'Beni hatırla çerezi verilmedi.');

        DB::table('users')->where('id', $user->id)->update(['last_login_at' => null]);
        // Test çekirdeği oturum deposunu istekler arasında paylaşır; temizlenmezse
        // kullanıcı oturumdan tanınır ve çerez yolu hiç sınanmazdı.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->travel(1)->days();

        $this->withCookie($recaller, (string) $response->getCookie($recaller)->getValue())
            ->get('/panel');

        $this->assertTrue(Auth::guard('web')->viaRemember(), 'Kullanıcı çerezle geri gelmedi.');
        $this->assertNotNull(DB::table('users')->where('id', $user->id)->value('last_login_at'));
    }

    /** Yanlış parola son giriş zamanı yazmaz. */
    #[Test]
    public function a_failed_login_records_nothing(): void
    {
        $user = $this->user();

        $this->post('/login', ['email' => $user->email, 'password' => 'yanlis'])->assertSessionHasErrors('email');

        $this->assertNull(DB::table('users')->where('id', $user->id)->value('last_login_at'));
    }

    private function user(): User
    {
        $user = User::factory()->create(['password' => Hash::make('gizli-parola-123')]);
        (new CreateTenant)->run(name: 'Şirket '.uniqid(), owner: $user);

        return $user;
    }
}
