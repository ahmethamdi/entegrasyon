<?php

declare(strict_types=1);

namespace App\Domain\BulkEdit\Enums;

/**
 * Excel toplu düzenlemenin KARARLI sebep kodları — S5.
 * Kod saklanır, metin `label()` ile çevrilir.
 */
enum BulkEditReason: string
{
    /** Dosya okunamadı / XLSX değil / beklenen başlık yok. */
    case BULK_FILE_INVALID = 'BULK_FILE_INVALID';

    /** Satır sınırı aşıldı. */
    case BULK_TOO_MANY_ROWS = 'BULK_TOO_MANY_ROWS';

    /** Satırda ne kimlik ne SKU var. */
    case BULK_IDENTITY_MISSING = 'BULK_IDENTITY_MISSING';

    /** Kimlik/SKU bu hesapta yok (başka hesabın kimliği de buraya düşer). */
    case BULK_VARIANT_UNKNOWN = 'BULK_VARIANT_UNKNOWN';

    /** Kimlik ve SKU farklı ürünleri gösteriyor — tahmin edilmez. */
    case BULK_ID_SKU_MISMATCH = 'BULK_ID_SKU_MISMATCH';

    /** Aynı ürün dosyada birden çok satırda. */
    case BULK_DUPLICATE_ROW = 'BULK_DUPLICATE_ROW';

    /** Hücrede formül var; değer olarak sayı yazılmalı. */
    case BULK_FORMULA_NOT_ALLOWED = 'BULK_FORMULA_NOT_ALLOWED';

    /** Stok tam sayı ve 0 ya da daha büyük olmalı. */
    case BULK_STOCK_INVALID = 'BULK_STOCK_INVALID';

    /** Fiyat sıfırdan büyük, en çok iki ondalıklı bir sayı olmalı. */
    case BULK_PRICE_INVALID = 'BULK_PRICE_INVALID';

    /** Dışa aktarımdan sonra stok değişti (ör. satış); satır uygulanmadı. */
    case BULK_STOCK_CHANGED_SINCE_EXPORT = 'BULK_STOCK_CHANGED_SINCE_EXPORT';

    /** Dışa aktarımdan sonra fiyat değişti; satır uygulanmadı. */
    case BULK_PRICE_CHANGED_SINCE_EXPORT = 'BULK_PRICE_CHANGED_SINCE_EXPORT';

    /** Önizlemeden sonra stok/fiyat değişti; satır uygulanmadı. */
    case BULK_CHANGED_SINCE_PREVIEW = 'BULK_CHANGED_SINCE_PREVIEW';

    /** Bu yükleme zaten uygulandı ya da uygulanıyor. */
    case BULK_ALREADY_APPLIED = 'BULK_ALREADY_APPLIED';

    /** Satır uygulanırken beklenmeyen hata. */
    case BULK_ROW_FAILED = 'BULK_ROW_FAILED';

    public function label(): string
    {
        return __(match ($this) {
            self::BULK_FILE_INVALID => 'Dosya okunamadı ya da şablon değil',
            self::BULK_TOO_MANY_ROWS => 'Dosyada çok fazla satır var',
            self::BULK_IDENTITY_MISSING => 'Satırda kimlik ve SKU yok',
            self::BULK_VARIANT_UNKNOWN => 'Ürün bulunamadı',
            self::BULK_ID_SKU_MISMATCH => 'Kimlik ve SKU farklı ürünleri gösteriyor',
            self::BULK_DUPLICATE_ROW => 'Aynı ürün birden çok satırda',
            self::BULK_FORMULA_NOT_ALLOWED => 'Hücrede formül var; sayı yazın',
            self::BULK_STOCK_INVALID => 'Stok tam sayı ve 0 ya da büyük olmalı',
            self::BULK_PRICE_INVALID => 'Fiyat sıfırdan büyük bir sayı olmalı',
            self::BULK_STOCK_CHANGED_SINCE_EXPORT => 'Dışa aktarımdan sonra stok değişti',
            self::BULK_PRICE_CHANGED_SINCE_EXPORT => 'Dışa aktarımdan sonra fiyat değişti',
            self::BULK_CHANGED_SINCE_PREVIEW => 'Önizlemeden sonra değişti',
            self::BULK_ALREADY_APPLIED => 'Bu dosya zaten uygulandı',
            self::BULK_ROW_FAILED => 'Satır uygulanamadı',
        });
    }
}
