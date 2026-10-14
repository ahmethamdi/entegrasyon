<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Enums;

/**
 * Tedarikçinin söylediği sayısal değerin DURUMU — BİLİNMEYEN ≠ SIFIR.
 *
 *   present = geçerli, sıfırdan büyük değer
 *   zero    = tedarikçi AÇIKÇA 0 dedi (stokta geçerli bir değer; fiyatta
 *             satış/maliyet olarak kullanılmaz)
 *   missing = alan yok ya da boş — hiçbir yazma üretmez
 *   invalid = alan var ama sayı değil / negatif — hiçbir yazma üretmez
 */
enum FieldState: string
{
    case PRESENT = 'present';
    case ZERO = 'zero';
    case MISSING = 'missing';
    case INVALID = 'invalid';
}
