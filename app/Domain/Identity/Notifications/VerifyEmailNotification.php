<?php

declare(strict_types=1);

namespace App\Domain\Identity\Notifications;

use App\Support\Mail\MailGreeting;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * E-posta doğrulama postası — alıcının dilinde (B4).
 *
 * Bağlantı imzalı ve süreli (`verification.verify`, Laravel'in
 * `verificationUrl()`'i APP_URL'den kurar). Metin `__()` ile çevrilir:
 * anahtar Türkçe metindir, İngilizcesi `lang/en.json`'da.
 */
final class VerifyEmailNotification extends VerifyEmail
{
    public function toMail($notifiable): MailMessage
    {
        $minutes = (int) config('auth.verification.expire', 60);

        // Metin `__()` ile; dil alıcının tercihidir (`User::preferredLocale`).
        return (new MailMessage)
            ->subject(__('E-posta adresini doğrula'))
            ->greeting(MailGreeting::welcome($notifiable))
            ->line(__('Hesabını kullanmaya başlamak için e-posta adresini doğrula.'))
            ->action(__('E-postamı doğrula'), $this->verificationUrl($notifiable))
            ->line(__('Bu bağlantı :minutes dakika geçerlidir.', ['minutes' => $minutes]))
            ->line(__('Bu hesabı sen açmadıysan bu e-postayı yok sayabilirsin.'))
            ->salutation('34Pazar');
    }
}
