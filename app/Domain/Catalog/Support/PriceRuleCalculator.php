<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\ChannelPriceRule;

/**
 * Kanal fiyat kuralının hesabı — saf, yan etkisiz.
 *
 * ⚠️ PARA KURUŞ OLARAK (TAM SAYI) HESAPLANIR. Float ile 199,90 × 1,15
 * 229,88499… çıkar ve yuvarlama yönü makineye göre değişir; kanala giden
 * fiyat ile mutabakatın beklediği fiyat bir kuruş ayrışır ve her tur sahte
 * "fiyat çakışması" doğardı.
 *
 * Sıra: önce yüzde, sonra sabit tutar, en son yuvarlama. Yuvarlama DAİMA
 * YUKARIDIR: kural satıcının kâr payını korumak için var; aşağı yuvarlama
 * hesaplanan payı sessizce kırpardı.
 */
final class PriceRuleCalculator
{
    public static function apply(string $price, ChannelPriceRule $rule): string
    {
        $minor = self::toMinor($price);

        // Yüzde baz puan olarak (15,00 → 1500): kuruş × (10000 + bp) / 10000,
        // yarım kuruş yukarı.
        $basisPoints = self::toMinor((string) $rule->markup_percent);
        $minor = intdiv($minor * (10000 + $basisPoints) + 5000, 10000);

        $minor += self::toMinor((string) $rule->markup_amount);

        // Negatif sonuç kanala gitmez; sıfır fiyat zarar korumasında durur.
        $minor = max($minor, 0);

        $minor = match ($rule->rounding) {
            ChannelPriceRule::ROUNDING_WHOLE => self::ceilTo($minor, 0),
            ChannelPriceRule::ROUNDING_X90 => self::ceilTo($minor, 90),
            ChannelPriceRule::ROUNDING_X99 => self::ceilTo($minor, 99),
            default => $minor,
        };

        return self::fromMinor($minor);
    }

    /**
     * Zarar korumasının tabanı: maliyet × (1 + yüzde/100), YUKARI yuvarlı.
     */
    public static function floor(string $cost, string $minMarginPercent): string
    {
        $minor = self::toMinor($cost);
        $basisPoints = self::toMinor($minMarginPercent);

        // Yukarı yuvarla: taban bir kuruş aşağı kayarsa zarar koruması
        // tam sınırdaki zararlı fiyatı geçirirdi.
        return self::fromMinor(intdiv($minor * (10000 + $basisPoints) + 9999, 10000));
    }

    public static function toMinor(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function fromMinor(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    /** Kuruşu verilen kuruş sonuna (00/90/99) yukarı taşır; zaten oradaysa dokunmaz. */
    private static function ceilTo(int $minor, int $ending): int
    {
        $candidate = intdiv($minor, 100) * 100 + $ending;

        return $candidate >= $minor ? $candidate : $candidate + 100;
    }
}
