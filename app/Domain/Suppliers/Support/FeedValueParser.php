<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Support;

use App\Domain\Suppliers\Models\SupplierSource;

/**
 * Akıştaki metni sayıya çevirir — ondalık ayırıcı KAYNAK AYARIDIR.
 *
 * "12.500" Türk akışında on iki bin beş yüz, İngilizce akışta on iki
 * buçuktur; tahmin edilmez. Ayar önizlemede örnek değerlerle birlikte
 * gösterilir ve kullanıcı seçer.
 *
 * Para birimi simgesi/kodu ve boşluklar atılır ("1.299,90 TL",
 * "₺ 1299.90"). Negatif → geçersiz. Boş → eksik. Stok tam sayı olmalı:
 * "5,00" geçerli (5), "5,5" geçersiz.
 */
final class FeedValueParser
{
    public function __construct(private readonly string $numberFormat = SupplierSource::NUMBER_DOT) {}

    /** @param list<string>|null $values */
    public function money(?array $values): FieldValue
    {
        $raw = $this->first($values);

        if ($raw === null) {
            return FieldValue::missing();
        }

        $decimal = $this->decimal($raw);

        if ($decimal === null || bccomp($decimal, '0', 4) < 0) {
            return FieldValue::invalid($raw);
        }

        if (bccomp($decimal, '9999999999.99', 2) > 0) {
            return FieldValue::invalid($raw);
        }

        return FieldValue::of(self::round2($decimal), $raw);
    }

    /** @param list<string>|null $values */
    public function stock(?array $values): FieldValue
    {
        $raw = $this->first($values);

        if ($raw === null) {
            return FieldValue::missing();
        }

        $decimal = $this->decimal($raw);

        if ($decimal === null || bccomp($decimal, '0', 4) < 0) {
            return FieldValue::invalid($raw);
        }

        $whole = bcadd($decimal, '0', 0);

        if (bccomp($decimal, $whole, 4) !== 0 || bccomp($whole, '2000000000', 0) > 0) {
            return FieldValue::invalid($raw);
        }

        return FieldValue::of((int) $whole, $raw);
    }

    /** @param list<string>|null $values */
    public function first(?array $values): ?string
    {
        foreach ($values ?? [] as $value) {
            $value = trim($value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** Normalize edilmiş ondalık dize ("1299.90") ya da null. */
    public function decimal(string $raw): ?string
    {
        $text = preg_replace('/(?:TL|TRY|EUR|USD|₺|€|\$|\s|\x{00A0})/iu', '', $raw) ?? '';

        if ($text === '') {
            return null;
        }

        [$thousands, $decimalSep] = $this->numberFormat === SupplierSource::NUMBER_COMMA ? ['.', ','] : [',', '.'];

        $pattern = '/^-?(?:\d{1,3}(?:'.preg_quote($thousands, '/').'\d{3})+|\d+)(?:'.preg_quote($decimalSep, '/').'\d+)?$/';

        if (preg_match($pattern, $text) !== 1) {
            return null;
        }

        $normalized = str_replace([$thousands, $decimalSep], ['', '.'], $text);

        return $normalized;
    }

    private static function round2(string $decimal): string
    {
        // Yarım yukarı, kuruşa.
        $sign = str_starts_with($decimal, '-') ? '-' : '';

        return bcadd($decimal, $sign.'0.005', 2);
    }
}
