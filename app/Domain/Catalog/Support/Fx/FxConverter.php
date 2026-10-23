<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support\Fx;

use App\Domain\Catalog\Support\PriceRuleCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * TL fiyatı kanal birimine çevirir — saf, yan etkisiz.
 *
 * SIRA: kur → kur yüzdesi → yuvarlama. TL fiyat kuralları (kanal farkı,
 * ,90, kampanya) ÇEVİRİDEN ÖNCE uygulanmış gelir (`Listing`).
 *
 * Bölme `BigDecimal` ile kuruşa YARIM YUKARI; yüzde baz puanla (TL kuralıyla
 * aynı); yuvarlama DAİMA YUKARI. Float kullanılsaydı 1.000 TL / 35,1234
 * makineye göre 28,47 ya da 28,48 çıkar, mutabakat her turda bir kuruşluk
 * sahte çakışma görürdü.
 */
final class FxConverter
{
    /**
     * @param  string  $price  kaynak birimde fiyat ("1299.90")
     * @param  string  $rate  1 birim hedef para = kaç kaynak birim ("35.123400")
     * @param  string  $markupPercent  "8.00"
     */
    public static function convert(string $price, string $rate, string $markupPercent, string $rounding): string
    {
        $converted = BigDecimal::of($price)->dividedBy(BigDecimal::of($rate), 2, RoundingMode::HALF_UP);

        $minor = PriceRuleCalculator::toMinor((string) $converted);
        $basisPoints = PriceRuleCalculator::toMinor($markupPercent);
        $minor = intdiv($minor * (10000 + $basisPoints) + 5000, 10000);

        return PriceRuleCalculator::fromMinor(PriceRuleCalculator::roundMinor(max($minor, 0), $rounding));
    }
}
