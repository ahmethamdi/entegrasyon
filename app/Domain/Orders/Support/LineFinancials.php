<?php

declare(strict_types=1);

namespace App\Domain\Orders\Support;

use App\Support\Money\Money;
use Throwable;

/**
 * Kanal adapter'larının kalem finans görüntüsünü kurduğu ortak yardımcı (ADR-002).
 *
 * KURAL: yalnız kanalın VERDİĞİ değer ya da ondan aritmetikle çıkan değer
 * yazılır; tahmin yok. Okunamayan/bozuk tutar görüntüyü düşürür, SİPARİŞİ
 * DÜŞÜRMEZ (alım asla bir para alanı yüzünden başarısız olmaz).
 *
 * Türetmeler: brüt + net → indirim (negatif çıkarsa yazılmaz); brüt + indirim
 * → net; KDV dahil net + oran → KDV tutarı.
 */
final class LineFinancials
{
    /**
     * @param  array<string, mixed>  $source  kanalın ham para alanları (tanı)
     * @return array<string, mixed> kanonik kalem anahtarları (`gross_line_total` …)
     */
    public static function from(
        mixed $gross = null,
        mixed $discount = null,
        mixed $net = null,
        mixed $vatRate = null,
        mixed $vatAmount = null,
        ?bool $includesVat = null,
        array $source = [],
    ): array {
        $gross = self::amount($gross);
        $discount = self::amount($discount);
        $net = self::amount($net);
        $rate = self::amount($vatRate);
        $vat = self::amount($vatAmount);

        try {
            if ($discount === null && $gross !== null && $net !== null) {
                $diff = Money::subtract($gross, $net);
                $discount = Money::toMinor($diff) >= 0 ? $diff : null;
            }

            if ($net === null && $gross !== null && $discount !== null) {
                $net = Money::subtract($gross, $discount);
            }

            if ($vat === null && $net !== null && $rate !== null && $includesVat === true) {
                $vat = Money::vatIncluded($net, $rate);
            }
        } catch (Throwable) {
            // Türetme yapılamadı — yalnız kanalın verdiği kalır.
        }

        return array_filter([
            'gross_line_total' => $gross,
            'discount_total' => $discount,
            'net_line_total' => $net,
            'amounts_include_vat' => $includesVat,
            'vat_rate' => $rate,
            'vat_amount' => $vat,
            'financials_source' => array_filter($source, static fn (mixed $v): bool => $v !== null) ?: null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /** Birim × adet, güvenli. */
    public static function times(mixed $unit, int $quantity): ?string
    {
        $unit = self::amount($unit);

        try {
            return $unit === null ? null : Money::multiply($unit, $quantity);
        } catch (Throwable) {
            return null;
        }
    }

    private static function amount(mixed $value): ?string
    {
        try {
            return Money::normalizeOrNull($value);
        } catch (Throwable) {
            return null;
        }
    }
}
