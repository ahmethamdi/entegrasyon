<?php

declare(strict_types=1);

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Para aritmetiği — tek kaynak (mimari denetim ADR-002, 06 §8).
 *
 * KURAL: para İKİLİ KAYAN NOKTAYA HİÇ GİRMEZ. Kolonlar `decimal`, PHP'ye
 * string döner; hesap kuruş TAM SAYISI ya da `BigDecimal` üzerinden yapılır.
 * `(float) "19.90" * 100` IEEE-754'te 1989.9999… olabilir ve bir kuruş
 * kaybettirir — bu sınıfın varlık sebebi budur.
 *
 * Yuvarlama: yarım yukarı (HALF_UP) — Türk vergi mevzuatı ve pazaryeri
 * hesap özetlerinin kullandığı kural.
 */
final class Money
{
    /** "19.90" / "19.9" / 19 → 1990. Geçersiz girdi istisna fırlatır (sessiz 0 yok). */
    public static function toMinor(string|int|BigDecimal $amount, int $scale = 2): int
    {
        return self::decimal($amount)
            ->toScale($scale, RoundingMode::HALF_UP)
            ->withPointMovedRight($scale)
            ->toBigInteger()
            ->toInt();
    }

    /** 1990 → "19.90". */
    public static function fromMinor(int $minor, int $scale = 2): string
    {
        return (string) BigDecimal::ofUnscaledValue($minor, $scale);
    }

    /** Ondalık metne normalleştirir: "19.9" → "19.90". */
    public static function normalize(string|int|BigDecimal $amount, int $scale = 2): string
    {
        return (string) self::decimal($amount)->toScale($scale, RoundingMode::HALF_UP);
    }

    /** Boş/null → null; aksi hâlde normalleştirilmiş metin. Kanaldan gelen isteğe bağlı alanlar için. */
    public static function normalizeOrNull(mixed $amount, int $scale = 2): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        if (is_float($amount)) {
            // JSON'dan float gelen kanal tutarı (Trendyol): ikili gösterimin
            // kısa ondalık yazımı alınır, sonra ondalığa çevrilir.
            $amount = rtrim(rtrim(sprintf('%.6F', $amount), '0'), '.');
        }

        if (! is_string($amount) && ! is_int($amount)) {
            return null;
        }

        return self::normalize($amount, $scale);
    }

    /** a × oran% (oran ondalık metin, ör. "20"), sonuç kuruşa yuvarlanır. */
    public static function percentOf(string $amount, string $rate, int $scale = 2): string
    {
        return (string) self::decimal($amount)
            ->multipliedBy(self::decimal($rate))
            ->dividedBy(100, $scale, RoundingMode::HALF_UP);
    }

    /**
     * KDV DAHİL tutarın içindeki KDV: brüt × oran / (100 + oran), kuruşa.
     *
     * Pazaryeri tutarları KDV dahildir; KDV'yi tutardan ayırmanın tek doğru
     * yolu budur (brüt × %20 DEĞİL).
     */
    public static function vatIncluded(string $gross, string $rate, int $scale = 2): string
    {
        $r = self::decimal($rate);

        if ($r->isZero()) {
            return self::normalize('0', $scale);
        }

        return (string) self::decimal($gross)
            ->multipliedBy($r)
            ->dividedBy($r->plus(100), $scale, RoundingMode::HALF_UP);
    }

    public static function add(string ...$amounts): string
    {
        $sum = BigDecimal::zero();

        foreach ($amounts as $amount) {
            $sum = $sum->plus(self::decimal($amount));
        }

        return (string) $sum->toScale(2, RoundingMode::HALF_UP);
    }

    public static function subtract(string $a, string $b): string
    {
        return (string) self::decimal($a)->minus(self::decimal($b))->toScale(2, RoundingMode::HALF_UP);
    }

    /** Birim × adet, kuruşa. */
    public static function multiply(string $amount, int $quantity, int $scale = 2): string
    {
        return (string) self::decimal($amount)->multipliedBy($quantity)->toScale($scale, RoundingMode::HALF_UP);
    }

    /**
     * Tutarı ağırlıklara göre kuruşu kaybetmeden dağıtır (en büyük kalan).
     *
     * Sipariş düzeyi indirimi kalemlere bölmek için: Σ parçalar = toplam,
     * kuruş farkı ilk en büyük kalanlara gider.
     *
     * @param  list<int>  $weights  negatif olmayan ağırlıklar (ör. kalem kuruşları)
     * @return list<int> kuruş parçaları
     */
    public static function allocate(int $totalMinor, array $weights): array
    {
        $sum = array_sum($weights);

        if ($weights === [] || $sum <= 0) {
            throw new InvalidArgumentException('Dağıtım ağırlıkları pozitif toplam vermeli.');
        }

        $parts = [];
        $remainders = [];
        $allocated = 0;

        foreach ($weights as $i => $weight) {
            $share = intdiv($totalMinor * $weight, $sum);
            $parts[$i] = $share;
            $remainders[$i] = ($totalMinor * $weight) % $sum;
            $allocated += $share;
        }

        arsort($remainders);

        foreach (array_keys($remainders) as $i) {
            if ($allocated >= $totalMinor) {
                break;
            }

            $parts[$i]++;
            $allocated++;
        }

        return array_values($parts);
    }

    public static function decimal(string|int|BigDecimal $amount): BigDecimal
    {
        if ($amount instanceof BigDecimal) {
            return $amount;
        }

        $text = is_int($amount) ? (string) $amount : trim($amount);

        if ($text === '' || preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
            throw new InvalidArgumentException("Geçersiz para tutarı: '{$text}'.");
        }

        return BigDecimal::of($text);
    }
}
