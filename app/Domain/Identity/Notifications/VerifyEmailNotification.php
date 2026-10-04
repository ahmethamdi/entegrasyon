<?php

declare(strict_types=1);

namespace App\Domain\Identity\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * E-posta doğrulama postası — Türkçe (B4).
 *
 * Bağlantı imzalı ve süreli (`verification.verify`, Laravel'in
 * `verificationUrl()`'i APP_URL'den kurar). Metin burada sabittir:
 * varsayılan çeviri anahtarları `lang/tr.json` olmadan İngilizce kalırdı.
 */
final class VerifyEmailNotification extends VerifyEmail
{
    public function toMail($notifiable): MailMessage
    {
        $minutes = (int) config('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject('E-posta adresinizi doğrulayın')
            ->greeting('Hoş geldiniz,')
            ->line('Hesabınızı kullanmaya başlamak için e-posta adresinizi doğrulayın.')
            ->action('E-postamı doğrula', $this->verificationUrl($notifiable))
            ->line("Bu bağlantı {$minutes} dakika geçerlidir.")
            ->line('Bu hesabı siz açmadıysanız bu e-postayı yok sayabilirsiniz.')
            ->salutation('Entegrasyon');
    }
}
