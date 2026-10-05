<?php

declare(strict_types=1);

namespace App\Domain\Identity\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Parola sıfırlama e-postası — alıcının dilinde (Türkçe/İngilizce).
 *
 * Laravel'in varsayılanı İngilizce anahtarlarla çalışır; Türkçe satıcıya
 * İngilizce posta giderdi. Anahtar Türkçe metnin kendisidir, İngilizcesi
 * `lang/en.json`'da.
 *
 * Adres `url()` ile kurulur: APP_URL üretimde panel adresidir. İsteğin
 * Host başlığı KULLANILMAZ — kullanılsaydı saldırgan sahte Host ile
 * kurbanın jetonunu kendi alan adına giden bir bağlantıya gömebilirdi.
 */
final class ResetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        // Metin `__()` ile; dil alıcının tercihidir (`User::preferredLocale`),
        // Laravel bildirimi göndermeden önce o dile geçer.
        return (new MailMessage)
            ->subject(__('Parola sıfırlama isteği'))
            ->greeting(__('Merhaba,'))
            ->line(__('Hesabınız için bir parola sıfırlama isteği aldık.'))
            ->action(__('Parolamı sıfırla'), $url)
            ->line(__('Bu bağlantı :minutes dakika geçerlidir.', ['minutes' => $minutes]))
            ->line(__('Bu isteği siz yapmadıysanız bu e-postayı yok sayabilirsiniz; parolanız değişmez.'))
            ->salutation('34Pazar');
    }
}
