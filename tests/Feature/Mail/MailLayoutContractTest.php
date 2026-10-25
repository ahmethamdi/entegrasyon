<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * SÖZLEŞME: müşteriye giden her posta 34Pazar düzeninden geçer.
 *
 * Yeni bir Mailable `markdown:` yerine `view:`/`html:` ile yazılırsa ya da
 * bir bildirim MailMessage'ı kendi görünümüne çevirirse posta markasız,
 * alt bilgisiz (destek, künye, "neden aldın") çıkar — sessizce. Bu test
 * onu adıyla kırar. Ayrıca kodda çıplak `Mail::html` / `Mail::raw` yalnız
 * 34Devs'e giden iç uyarılarda (izin listesi) kullanılabilir.
 */
final class MailLayoutContractTest extends TestCase
{
    /** 34Devs'in kendine giden düz metin uyarıları — müşteri görmez. */
    private const RAW_MAIL_ALLOWED = [
        'app/Support/Observability/OpsNotifier.php',
    ];

    #[Test]
    public function every_mailable_renders_through_the_markdown_layout(): void
    {
        $checked = 0;

        foreach ($this->appClasses() as $class => $file) {
            if (! is_subclass_of($class, Mailable::class)) {
                continue;
            }
            $checked++;
            $source = (string) file_get_contents($file);

            $this->assertMatchesRegularExpression('/new Content\(\s*markdown:/', $source, "{$class} `markdown:` ile 34Pazar düzenini kullanmalı");
            $this->assertDoesNotMatchRegularExpression('/\b(view|html|htmlString)\s*:/', $source, "{$class} düzeni atlayan görünüm kullanıyor");
        }

        $this->assertGreaterThan(0, $checked, 'tarama hiçbir Mailable bulamadı — test bir şey ölçmüyor');
    }

    #[Test]
    public function every_mail_notification_uses_mail_message_without_a_custom_view(): void
    {
        $checked = 0;

        foreach ($this->appClasses() as $class => $file) {
            if (! is_subclass_of($class, Notification::class) || ! method_exists($class, 'toMail')) {
                continue;
            }
            $checked++;
            $source = (string) file_get_contents($file);

            $this->assertStringContainsString('new MailMessage', $source, "{$class} MailMessage döndürmeli");
            $this->assertDoesNotMatchRegularExpression('/->\s*(view|markdown|template)\s*\(/', $source, "{$class} MailMessage'ı kendi görünümüne çeviriyor");
        }

        $this->assertGreaterThanOrEqual(4, $checked, 'doğrulama, sıfırlama, davet ve hoş geldin taranmalı');
    }

    #[Test]
    public function raw_mail_is_only_used_for_internal_alerts(): void
    {
        foreach ($this->appFiles() as $relative => $file) {
            if (in_array($relative, self::RAW_MAIL_ALLOWED, true)) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/Mail::(raw|html|plain)\s*\(/', (string) file_get_contents($file), "{$relative} düzeni atlayan çıplak posta gönderiyor");
        }
    }

    #[Test]
    public function the_34pazar_theme_is_configured_and_present(): void
    {
        $this->assertSame('34pazar', config('mail.markdown.theme'));
        $this->assertFileExists(resource_path('views/vendor/mail/html/themes/34pazar.css'));
        $this->assertFileDoesNotExist(resource_path('views/vendor/mail/html/themes/default.css'), 'Laravel varsayılan teması geri gelmemeli');
    }

    /** @return iterable<string, string> göreli yol => mutlak yol */
    private function appFiles(): iterable
    {
        $root = base_path();
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield ltrim(str_replace($root, '', $file->getPathname()), '/') => $file->getPathname();
            }
        }
    }

    /** @return iterable<class-string, string> */
    private function appClasses(): iterable
    {
        foreach ($this->appFiles() as $relative => $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($relative, strlen('app/')));
            if (class_exists($class) && ! (new ReflectionClass($class))->isAbstract()) {
                yield $class => $file;
            }
        }
    }
}
