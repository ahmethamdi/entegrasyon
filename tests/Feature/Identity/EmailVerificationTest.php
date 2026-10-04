<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B4 · e-posta doğrulama — doğrulanmamış hesap panele giremez.
 */
final class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    /** Kayıt Türkçe doğrulama postası gönderir ve panel kapalıdır. */
    #[Test]
    public function registration_sends_a_verification_mail_and_locks_the_panel(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Yeni Satıcı',
            'email' => 'yeni@example.com',
            'password' => 'cok-gizli-parola',
            'password_confirmation' => 'cok-gizli-parola',
            'company' => 'Yeni Şirket',
        ]);

        $user = User::query()->where('email', 'yeni@example.com')->firstOrFail();

        Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $n) use ($user): bool {
            return $n->toMail($user)->subject === 'E-posta adresinizi doğrulayın';
        });

        $this->get('/')->assertRedirect('/email/verify');
        $this->get('/products')->assertRedirect('/email/verify');
        $this->get('/email/verify')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/VerifyEmail')->where('email', 'yeni@example.com'));
    }

    /** İmzalı bağlantı hesabı doğrular ve panel açılır. */
    #[Test]
    public function the_signed_link_verifies_and_opens_the_panel(): void
    {
        $user = $this->unverifiedUser();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect('/');

        $this->assertTrue($user->refresh()->hasVerifiedEmail());
        $this->actingAs($user)->get('/')->assertOk();
    }

    /** İmzası bozuk bağlantı doğrulamaz. */
    #[Test]
    public function a_tampered_link_does_not_verify(): void
    {
        $user = $this->unverifiedUser();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->getKey(),
            'hash' => sha1('baska@example.com'),
        ]);

        $this->actingAs($user)->get($url)->assertForbidden();
        $this->actingAs($user)->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();

        $this->assertFalse($user->refresh()->hasVerifiedEmail());
    }

    /**
     * Hash'i doğru ama SÜRESİ DOLMUŞ bağlantı doğrulamaz — imza kontrolü
     * olmasaydı id + sha1(e-posta) tahmin edilerek doğrulama yapılabilirdi.
     */
    #[Test]
    public function an_expired_link_with_the_right_hash_does_not_verify(): void
    {
        $user = $this->unverifiedUser();

        $url = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertFalse($user->refresh()->hasVerifiedEmail());
    }

    /** Bağlantı yeniden istenebilir. */
    #[Test]
    public function the_link_can_be_resent(): void
    {
        Notification::fake();
        $user = $this->unverifiedUser();

        $this->actingAs($user)->post('/email/verification-notification')->assertSessionHas('status');

        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);
    }

    /** Doğrulanmış kullanıcı panele doğrudan girer. */
    #[Test]
    public function a_verified_user_goes_straight_to_the_panel(): void
    {
        $user = User::factory()->create();
        (new CreateTenant)->run(name: 'Doğrulanmış', owner: $user);

        $this->actingAs($user)->get('/')->assertOk();
        $this->actingAs($user)->get('/email/verify')->assertRedirect('/');
    }

    private function unverifiedUser(): User
    {
        $user = User::factory()->unverified()->create();
        (new CreateTenant)->run(name: 'Doğrulanmamış', owner: $user);

        return $user;
    }
}
