<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B4 · parola sıfırlama + kimlik uçlarının hız sınırı.
 *
 * Önceden sıfırlama akışı HİÇ yoktu ve kayıt sınırsızdı.
 */
final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    /** Kayıtlı adrese Türkçe sıfırlama postası gider. */
    #[Test]
    public function a_reset_link_is_mailed_to_a_registered_address(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'satici@example.com']);

        $this->post('/forgot-password', ['email' => 'Satici@Example.com'])
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use ($user): bool {
            $mail = $n->toMail($user);

            return $mail->subject === 'Parola sıfırlama isteği'
                && str_contains($mail->actionUrl, '/reset-password/')
                && str_starts_with($mail->actionUrl, (string) config('app.url'));
        });
    }

    /**
     * YANIT KAYIT BİLGİSİ SIZDIRMAZ: kayıtsız adres de AYNI yanıtı alır.
     */
    #[Test]
    public function an_unknown_address_gets_the_same_answer(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'var@example.com']);

        $known = $this->post('/forgot-password', ['email' => 'var@example.com']);
        $unknown = $this->post('/forgot-password', ['email' => 'yok@example.com']);

        $known->assertSessionHasNoErrors();
        $unknown->assertSessionHasNoErrors();
        $this->assertSame(session('status'), __(Password::RESET_LINK_SENT));

        // Kayıtlı adrese İKİNCİ istek broker'ca bekletilir — bu bile yanıta
        // yansımaz (bekletme yalnız kayıtlı adreste olur).
        $this->post('/forgot-password', ['email' => 'var@example.com'])->assertSessionHasNoErrors();
    }

    /** Geçerli jetonla parola değişir, beni-hatırla jetonu yenilenir. */
    #[Test]
    public function a_valid_token_resets_the_password(): void
    {
        $user = User::factory()->create(['email' => 'satici@example.com', 'remember_token' => 'eski-jeton']);
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'satici@example.com',
            'password' => 'yepyeni-parola-123',
            'password_confirmation' => 'yepyeni-parola-123',
        ])->assertRedirect('/login')->assertSessionHas('success');

        $user->refresh();
        $this->assertTrue(Hash::check('yepyeni-parola-123', $user->password));
        $this->assertNotSame('eski-jeton', $user->remember_token, 'Açık "beni hatırla" oturumları ölmeli.');

        $this->post('/login', ['email' => 'satici@example.com', 'password' => 'yepyeni-parola-123'])
            ->assertRedirect('/');
    }

    /** Sahte jeton parolayı değiştirmez. */
    #[Test]
    public function an_invalid_token_changes_nothing(): void
    {
        $user = User::factory()->create(['email' => 'satici@example.com']);
        $before = $user->password;

        $this->post('/reset-password', [
            'token' => 'sahte',
            'email' => 'satici@example.com',
            'password' => 'yepyeni-parola-123',
            'password_confirmation' => 'yepyeni-parola-123',
        ])->assertSessionHasErrors('email');

        $this->assertSame($before, $user->refresh()->password);
    }

    /** Sıfırlama sayfası jetonu forma taşır. */
    #[Test]
    public function the_reset_page_renders_with_the_token(): void
    {
        $this->get('/reset-password/abc123?email=satici@example.com')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/ResetPassword')
                ->where('token', 'abc123')
                ->where('email', 'satici@example.com'));

        $this->get('/forgot-password')->assertOk();
    }

    /** Sıfırlama isteği IP başına sınırlıdır (posta bombası). */
    #[Test]
    public function reset_requests_are_rate_limited(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $i) {
            $this->post('/forgot-password', ['email' => "kisi{$i}@example.com"])->assertRedirect();
        }

        $this->post('/forgot-password', ['email' => 'kisi6@example.com'])->assertStatus(429);
    }

    /** Kayıt IP başına sınırlıdır (toplu sahte kiracı). */
    #[Test]
    public function registration_is_rate_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post('/register', [
                'name' => "Kişi {$i}",
                'email' => "kayit{$i}@example.com",
                'password' => 'cok-gizli-parola',
                'password_confirmation' => 'cok-gizli-parola',
                'company' => "Şirket {$i}",
            ]);
            $this->post('/logout');
        }

        $this->post('/register', [
            'name' => 'Altıncı',
            'email' => 'kayit6@example.com',
            'password' => 'cok-gizli-parola',
            'password_confirmation' => 'cok-gizli-parola',
            'company' => 'Altıncı Şirket',
        ])->assertStatus(429);

        $this->assertSame(5, User::query()->count());
    }
}
