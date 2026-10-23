<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Support;

/**
 * Plaka kodu → il adı. WooCommerce Türkiye eyaletlerini `TR01`…`TR81`
 * kodlarıyla saklar (plaka sırası); kargo firması il ADINI ister.
 */
final class TurkishProvinces
{
    private const NAMES = [
        1 => 'Adana', 'Adıyaman', 'Afyonkarahisar', 'Ağrı', 'Amasya', 'Ankara', 'Antalya', 'Artvin', 'Aydın', 'Balıkesir',
        'Bilecik', 'Bingöl', 'Bitlis', 'Bolu', 'Burdur', 'Bursa', 'Çanakkale', 'Çankırı', 'Çorum', 'Denizli',
        'Diyarbakır', 'Edirne', 'Elazığ', 'Erzincan', 'Erzurum', 'Eskişehir', 'Gaziantep', 'Giresun', 'Gümüşhane', 'Hakkari',
        'Hatay', 'Isparta', 'Mersin', 'İstanbul', 'İzmir', 'Kars', 'Kastamonu', 'Kayseri', 'Kırklareli', 'Kırşehir',
        'Kocaeli', 'Konya', 'Kütahya', 'Malatya', 'Manisa', 'Kahramanmaraş', 'Mardin', 'Muğla', 'Muş', 'Nevşehir',
        'Niğde', 'Ordu', 'Rize', 'Sakarya', 'Samsun', 'Siirt', 'Sinop', 'Sivas', 'Tekirdağ', 'Tokat',
        'Trabzon', 'Tunceli', 'Şanlıurfa', 'Uşak', 'Van', 'Yozgat', 'Zonguldak', 'Aksaray', 'Bayburt', 'Karaman',
        'Kırıkkale', 'Batman', 'Şırnak', 'Bartın', 'Ardahan', 'Iğdır', 'Yalova', 'Karabük', 'Kilis', 'Osmaniye',
        'Düzce',
    ];

    /** `TR34` → İstanbul; kod değilse değeri aynen döndürür (satıcı adı yazmış olabilir). */
    public static function name(?string $value): string
    {
        $value = trim((string) $value);

        if (preg_match('/^TR(\d{2})$/', $value, $m) === 1) {
            return self::NAMES[(int) $m[1]] ?? $value;
        }

        return $value;
    }
}
