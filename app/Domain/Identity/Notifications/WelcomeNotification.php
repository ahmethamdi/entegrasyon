<?php

declare(strict_types=1);

namespace App\Domain\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Hoş geldin postası — e-posta doğrulandığı an BİR KEZ (SendWelcomeMail).
 *
 * İlk üç adım kurulum şeridiyle aynı sırada: kanal bağla → ürün çek →
 * ilk senkronu gör. Ücretsiz kurulum sözü fiyat sayfasındakiyle aynı
 * sınırda: yalnız ücretli paket. Destek kanalları alt bilgide.
 */
final class WelcomeNotification extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('34Pazar\'a hoş geldin'))
            ->greeting(__('Hoş geldin,'))
            ->line(__('Hesabın hazır. Stoğunu tek yerden yönetmeye üç adımda başlarsın:'))
            ->line(__('1. **Kanalını bağla:** Kanallar → Kanal ekle. Mağazanın API bilgilerini girersin, bağlantı denenip açılır.'))
            ->line(__('2. **Ürünlerini çek:** ürünler bağladığın kanaldan ya da CSV ile gelir, stok tek yerde toplanır.'))
            ->line(__('3. **İlk senkronu gör:** panelin üstündeki kurulum şeridi hangi adımda olduğunu gösterir.'))
            ->action(__('Panele git'), route('dashboard'))
            ->line(__('Ücretli pakette kurulumu ve ilk ürün aktarımını seninle birlikte, ücretsiz yapıyoruz.'))
            ->salutation('34Pazar');
    }
}
