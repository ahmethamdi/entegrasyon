<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\VerifyEmailNotification;
use App\Support\Security\FormShield;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bot kalkanı — kayıt ve parola sıfırlama bağlantısı (Kasım 2026).
 *
 * 2–4 Kasım'da botlar yabancıların adresiyle beş kayıt açtı ve doğrulama
 * postalarımız o adreslere gitti. Bu dosya kalkanın üç sözünü sınar:
 * tuzağa düşen bot HİÇBİR ŞEY yaratmaz ve posta göndertmez ama başarı
 * görür; acele eden ya da damgasız gönderim kaydedilmez ve "yeniden
 * gönder" uyarısı alır; insan gibi davranan istemci geçer.
 */
final class FormShieldTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────── kayıt

    /** Tuzak dolu: kullanıcı, kiracı, posta YOK — ama yanıt başarı gibi. */
    #[Test]
    public function a_filled_honeypot_creates_nothing_but_looks_successful(): void
    {
        Notification::fake();
        Mail::fake();

        $this->post('/register', array_merge($this->shieldedForm('register', $this->registration()), [
            'website' => 'https://spam.example',
        ]))
            ->assertRedirect('/login')
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, $this->asSystem(fn () => Tenant::query()->count()));
        Notification::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    /** Sayfa açıldığı saniyede gönderim (botun izi) kaydedilmez. */
    #[Test]
    public function a_submission_faster_than_three_seconds_is_rejected(): void
    {
        Notification::fake();

        $this->post('/register', $this->registration() + [
            'website' => '',
            'ft' => FormShield::stamp('register', now()->subMilliseconds(500)->getTimestampMs()),
        ])->assertSessionHasErrors('ft');

        $this->assertSame(0, User::query()->count());
        Notification::assertNothingSent();
    }

    /** Acele eden İNSAN sessizce düşmez: aynı damgayla birkaç saniye sonra geçer. */
    #[Test]
    public function a_hasty_human_passes_on_the_second_try(): void
    {
        $data = $this->registration() + ['website' => '', 'ft' => FormShield::stamp('register')];

        $this->post('/register', $data)->assertSessionHasErrors('ft');
        $this->assertSame(0, User::query()->count());

        $this->travel(4)->seconds();

        $this->post('/register', $data)->assertRedirect('/panel');
        $this->assertSame(1, User::query()->count());
    }

    /** İmzası bozuk, damgası eksik ya da 24 saatten eski form kaydedilmez. */
    #[Test]
    public function a_forged_missing_or_expired_stamp_is_rejected(): void
    {
        $old = now()->subSeconds(10)->getTimestampMs();
        $cases = [
            'bozuk imza' => $old.'.'.str_repeat('A', 43),
            'imzasız' => (string) $old,
            'boş' => '',
            'sayı değil' => 'abc.def',
            '25 saatlik' => FormShield::stamp('register', now()->subHours(25)->getTimestampMs()),
            'başka formun damgası' => FormShield::stamp('password-reset', $old),
        ];

        // Her durum ayrı IP'den: kayıt saatte 5/IP ile sınırlı ve altıncı
        // deneme kalkana ulaşmadan 429 alırdı.
        $ip = 1;

        foreach ($cases as $label => $stamp) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$ip++])->post('/register', $this->registration() + ['website' => '', 'ft' => $stamp])
                ->assertSessionHasErrors('ft');

            $this->assertSame(0, User::query()->count(), "{$label} damgasıyla kayıt açıldı.");
        }

        // Damga alanı hiç gönderilmezse de aynı.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$ip])->post('/register', $this->registration())->assertSessionHasErrors('ft');
        $this->assertSame(0, User::query()->count());
    }

    /** İnsan gibi gönderim kaydı açar ve doğrulama postası gider. */
    #[Test]
    public function a_valid_submission_registers(): void
    {
        Notification::fake();

        $this->post('/register', $this->shieldedForm('register', $this->registration()))
            ->assertRedirect('/panel');

        $user = User::query()->where('email', 'yeni@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    /** Sayfalar damgayı prop olarak verir; damga o formda geçerlidir. */
    #[Test]
    public function the_pages_hand_out_a_stamp(): void
    {
        foreach (['/register' => 'Auth/Register', '/forgot-password' => 'Auth/ForgotPassword'] as $url => $component) {
            $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
                ->component($component)
                ->where('formStamp', fn ($stamp): bool => preg_match('/^\d{13}\.[A-Za-z0-9_-]{43}$/', (string) $stamp) === 1));
        }
    }

    // ─────────────────────────────────────────────────────────── parola sıfırlama

    /** Tuzak dolu: posta GİTMEZ, yanıt gerçek istekle aynıdır (sızdırmazlık). */
    #[Test]
    public function a_reset_honeypot_sends_nothing_and_answers_the_same(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'satici@example.com']);

        $this->post('/forgot-password', array_merge($this->shieldedForm('password-reset', ['email' => 'satici@example.com']), [
            'website' => 'x',
        ]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __(Password::RESET_LINK_SENT));

        Notification::assertNothingSent();
    }

    /** Çok hızlı ya da damgasız sıfırlama isteği posta göndertmez. */
    #[Test]
    public function a_hasty_or_unstamped_reset_request_sends_nothing(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'satici@example.com']);

        $this->post('/forgot-password', ['email' => 'satici@example.com', 'website' => '', 'ft' => FormShield::stamp('password-reset')])
            ->assertSessionHasErrors('ft');
        $this->post('/forgot-password', ['email' => 'satici@example.com'])
            ->assertSessionHasErrors('ft');

        Notification::assertNothingSent();
    }

    /** @return array<string, string> */
    private function registration(): array
    {
        return [
            'name' => 'Yeni Satıcı',
            'email' => 'yeni@example.com',
            'password' => 'cok-gizli-parola',
            'password_confirmation' => 'cok-gizli-parola',
            'company' => 'Yeni Şirket',
        ];
    }
}
