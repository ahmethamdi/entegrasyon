<?php

declare(strict_types=1);

namespace App\Domain\Finance\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Tek adetlik satışın katkısı (KDV dahil) — saf, yan etkisiz.
 *
 *   katkı      = fiyat − fiyat × komisyon% − kargo − paketleme − maliyet
 *   zararsız   = (maliyet + kargo + paketleme) / (1 − komisyon%)
 *   hedef      = (maliyet + kargo + paketleme) / (1 − komisyon% − hedef%)
 *
 * Kargo ve paketleme SİPARİŞ başıdır; burada siparişte tek adet varsayılır
 * (en kötü durum — çok adetli siparişte adet başına düşen pay küçülür).
 * Önerilen fiyatlar kuruşa YUKARI yuvarlanır: aşağı yuvarlama tam sınırda
 * bir kuruş zarar bırakırdı.
 *
 * Bilinmeyen girdi NULL döner; sıfır sayılmaz.
 */
final class UnitEconomics
{
    /**
     * @return array{contribution: ?string, margin: ?float, breakEven: ?string, target: ?string}
     */
    public static function of(?string $price, ?string $cost, ?string $commissionPercent, string $perOrder, ?string $targetPercent): array
    {
        $out = ['contribution' => null, 'margin' => null, 'breakEven' => null, 'target' => null];

        if ($cost === null || $commissionPercent === null) {
            return $out;
        }

        $rate = BigDecimal::of($commissionPercent)->dividedBy(100, 6, RoundingMode::HALF_UP);
        $fixed = BigDecimal::of($cost)->plus(BigDecimal::of($perOrder));

        if ($price !== null) {
            $p = BigDecimal::of($price);
            $contribution = $p->minus($p->multipliedBy($rate))->minus($fixed)->toScale(2, RoundingMode::HALF_UP);
            $out['contribution'] = (string) $contribution;
            $out['margin'] = $p->isPositive()
                ? (float) (string) $contribution->multipliedBy(100)->dividedBy($p, 1, RoundingMode::HALF_UP)
                : null;
        }

        $out['breakEven'] = self::priceFor($fixed, $rate);

        if ($targetPercent !== null) {
            $out['target'] = self::priceFor($fixed, $rate->plus(BigDecimal::of($targetPercent)->dividedBy(100, 6, RoundingMode::HALF_UP)));
        }

        return $out;
    }

    /** fixed / (1 − oran); oran ≥ 1 ise hiçbir fiyat yetmez → NULL. */
    private static function priceFor(BigDecimal $fixed, BigDecimal $rate): ?string
    {
        $divisor = BigDecimal::one()->minus($rate);

        if (! $divisor->isPositive()) {
            return null;
        }

        return (string) $fixed->dividedBy($divisor, 2, RoundingMode::UP);
    }
}
