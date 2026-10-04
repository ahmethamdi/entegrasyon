<?php

declare(strict_types=1);

namespace App\Domain\Identity\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Parola sıfırlama e-postası — Türkçe.
 *
 * Laravel'in varsayılanı çeviri anahtarlarını `lang/tr.json`'dan okur;
 * dosya yokken satıcıya İngilizce posta giderdi. Metin burada sabittir.
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

        return (new MailMessage)
            ->subject('Parola sıfırlama isteği')
            ->greeting('Merhaba,')
            ->line('Hesabınız için bir parola sıfırlama isteği aldık.')
            ->action('Parolamı sıfırla', $url)
            ->line("Bu bağlantı {$minutes} dakika geçerlidir.")
            ->line('Bu isteği siz yapmadıysanız bu e-postayı yok sayabilirsiniz; parolanız değişmez.')
            ->salutation('Entegrasyon');
    }
}
