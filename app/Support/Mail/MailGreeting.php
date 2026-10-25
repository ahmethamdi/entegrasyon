<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * Posta selamlaması — ad varsa "Merhaba Ayşe,", yoksa "Merhaba!".
 *
 * Ad yokken "Merhaba," yazmak cümleyi yarım bırakır (virgülden sonra kimse
 * yok). Davet postasının alıcısı henüz kullanıcı değildir, adı bilinmez;
 * kayıt formu da adı zorunlu tutmayabilir. İkisi de buradan geçer ki bütün
 * postalar aynı kuralla selamlasın.
 *
 * Yalnız İLK ad: "Merhaba Ahmet Hamdi Kilic," resmî ve uzun okunur.
 */
final class MailGreeting
{
    public static function hello(object $notifiable): string
    {
        $name = self::firstName($notifiable);

        return $name === null ? __('Merhaba!') : __('Merhaba :name,', ['name' => $name]);
    }

    public static function welcome(object $notifiable): string
    {
        $name = self::firstName($notifiable);

        return $name === null ? __('Hoş geldin!') : __('Hoş geldin :name,', ['name' => $name]);
    }

    private static function firstName(object $notifiable): ?string
    {
        $full = trim((string) data_get($notifiable, 'name', ''));

        if ($full === '') {
            return null;
        }

        return preg_split('/\s+/u', $full)[0] ?: null;
    }
}
