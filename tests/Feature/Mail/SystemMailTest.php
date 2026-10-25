<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Domain\Identity\Enums\TenantRole;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\ResetPasswordNotification;
use App\Domain\Identity\Notifications\TeamInvitationNotification;
use App\Domain\Identity\Notifications\VerifyEmailNotification;
use App\Domain\Identity\Notifications\WelcomeNotification;
use App\Mail\PaymentFailedMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sistem postaları 34Pazar düzeniyle çıkar (25 Ekim 2026).
 *
 * Her posta için: Türkçe konu, mutlak logo adresi, marka teması satır içinde,
 * düğme adresi, düz metin sürümü, alt bilgide destek ve künye, Laravel'in
 * İngilizce varsayılan kalıntısı YOK.
 */
final class SystemMailTest extends TestCase
{
    use RefreshDatabase;

    /** Laravel varsayılan şablonundan kalabilecek İngilizce kalıntılar. */
    private const ENGLISH_LEFTOVERS = ['Hello!', 'Regards', 'All rights reserved', 'having trouble clicking', 'Whoops', 'Laravel'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://34pazar.com']);
        app('url')->forceRootUrl('https://34pazar.com');
        app('url')->forceScheme('https');
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function notifications(): iterable
    {
        yield 'doğrulama' => ['verify', 'E-posta adresinizi doğrulayın', 'E-postamı doğrula'];
        yield 'şifre sıfırlama' => ['reset', 'Parola sıfırlama isteği', 'Parolamı sıfırla'];
        yield 'ekip daveti' => ['invite', "Atölye Nur sizi 34Pazar'a davet etti", 'Daveti kabul et'];
        yield 'hoş geldin' => ['welcome', "34Pazar'a hoş geldin", 'Panele git'];
    }

    #[Test]
    #[DataProvider('notifications')]
    public function notification_mails_use_the_34pazar_layout(string $kind, string $subject, string $button): void
    {
        $user = User::factory()->create(['email' => 'ayse@example.com']);
        $message = $this->notification($kind)->toMail($user);

        $this->assertInstanceOf(MailMessage::class, $message);
        $this->assertSame($subject, $message->subject);

        $html = (string) $message->render();
        $this->assertBranded($html);
        $this->assertStringContainsString($button, $html);
        $this->assertMatchesRegularExpression('#<a href="https://34pazar\.com/[^"]+" class="button button-#', $html, 'düğme mutlak 34pazar.com adresine gitmeli');

        $text = (string) app(Markdown::class)->renderText($message->markdown, $message->data());
        $this->assertStringContainsString($button, $text, 'düz metin sürümü düğme metnini taşımalı');
        $this->assertStringNotContainsString('<table', $text);
        $this->assertStringContainsString('WhatsApp', $text);
    }

    #[Test]
    public function the_payment_failed_mail_uses_the_34pazar_layout(): void
    {
        $mail = new PaymentFailedMail('Atölye Nur', '499,00 ₺', '28 Ekim 2026', 'https://invoice.stripe.com/i/test');

        $this->assertSame('34Pazar ödemen alınamadı (499,00 ₺)', $mail->envelope()->subject);
        $html = $mail->render();
        $this->assertBranded($html);
        $this->assertStringContainsString('href="https://invoice.stripe.com/i/test"', $html);
    }

    #[Test]
    public function an_english_speaking_user_gets_an_english_footer(): void
    {
        app()->setLocale('en');
        $html = (string) (new WelcomeNotification)->toMail(User::factory()->create())->render();

        $this->assertStringContainsString('Welcome to 34Pazar', (new WelcomeNotification)->toMail(User::factory()->create())->subject);
        $this->assertStringContainsString('every day', $html);
        $this->assertStringContainsString('Germany', $html);
    }

    private function assertBranded(string $html): void
    {
        $this->assertStringContainsString('src="https://34pazar.com/images/34pazar-logo.png"', $html, 'logo mutlak adresle');
        $this->assertStringContainsString('src="https://34pazar.com/images/34pazar-logo-beyaz.png"', $html, 'koyu tema logosu');
        $this->assertStringContainsString('background-color: #ce310d', $html, 'marka teması satır içinde');
        $this->assertStringContainsString('width="600"', $html);
        $this->assertStringContainsString((string) config('site.whatsapp_display'), $html);
        $this->assertStringContainsString('Korschenbroich', $html);
        $this->assertStringContainsString('Bu e-postayı 34Pazar hesabınla', $html, '"neden aldın" satırı');

        foreach (self::ENGLISH_LEFTOVERS as $leftover) {
            $this->assertStringNotContainsString($leftover, $html, "İngilizce kalıntı: {$leftover}");
        }
    }

    private function notification(string $kind): object
    {
        return match ($kind) {
            'verify' => new VerifyEmailNotification,
            'reset' => new ResetPasswordNotification('jeton'),
            'invite' => new TeamInvitationNotification('jeton', 'Atölye Nur', 'Ahmet Hamdi Kilic', TenantRole::WAREHOUSE, now()->addDays(7)),
            'welcome' => new WelcomeNotification,
        };
    }
}
