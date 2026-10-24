<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Enums;

/**
 * Tedarikçi akışının KARARLI sebep kodları — S5 (ADR-012).
 *
 * Veritabanına KOD yazılır, metin `label()` ile sunumda çevrilir (TD-37
 * deseni tekrarlanmaz). Kodlar yeniden adlandırılmaz. Uzak sunucunun ham
 * hata metni `supplier_sync_runs.error_detail`'de ayrı durur.
 */
enum SupplierReason: string
{
    /** Akış indirilemedi (ağ, zaman aşımı, HTTP hata kodu). */
    case SUPPLIER_FETCH_FAILED = 'SUPPLIER_FETCH_FAILED';

    /** Adres güvenlik kuralına takıldı (iç ağ, https dışı, yasak port). */
    case SUPPLIER_URL_BLOCKED = 'SUPPLIER_URL_BLOCKED';

    /** Akış boyut sınırını aştı; işlenmedi. */
    case SUPPLIER_FEED_TOO_LARGE = 'SUPPLIER_FEED_TOO_LARGE';

    /** XML iyi biçimli değil ya da güvensiz yapı (DOCTYPE/varlık) içeriyor. */
    case SUPPLIER_XML_INVALID = 'SUPPLIER_XML_INVALID';

    /** Eşleme eksik/geçersiz (kayıt yolu ya da kimlik alanı yok). */
    case SUPPLIER_MAPPING_INVALID = 'SUPPLIER_MAPPING_INVALID';

    /** Seçilen kayıt düğümü akışta hiç bulunamadı. */
    case SUPPLIER_NO_RECORDS = 'SUPPLIER_NO_RECORDS';

    /** Kaydın kimlik alanı boş. */
    case SUPPLIER_IDENTITY_MISSING = 'SUPPLIER_IDENTITY_MISSING';

    /** Aynı kimlik akışta birden çok kez geçiyor; hiçbiri uygulanmadı. */
    case SUPPLIER_DUPLICATE_IDENTITY = 'SUPPLIER_DUPLICATE_IDENTITY';

    /** SKU ve barkod farklı ürünleri gösteriyor; tahmin edilmedi. */
    case SUPPLIER_MATCH_AMBIGUOUS = 'SUPPLIER_MATCH_AMBIGUOUS';

    /** Eşleşen varyant başka bir tedarikçi kaydına bağlı. */
    case SUPPLIER_VARIANT_ALREADY_BOUND = 'SUPPLIER_VARIANT_ALREADY_BOUND';

    /** SKU başka bir ürün/varyantta kullanılıyor; ürün açılamadı. */
    case SUPPLIER_SKU_CONFLICT = 'SUPPLIER_SKU_CONFLICT';

    /** Barkod başka bir varyantta kullanılıyor; ürün açılamadı. */
    case SUPPLIER_BARCODE_CONFLICT = 'SUPPLIER_BARCODE_CONFLICT';

    /** Yeni ürün için SKU yok. */
    case SUPPLIER_SKU_MISSING = 'SUPPLIER_SKU_MISSING';

    /** Yeni ürün için ad yok. */
    case SUPPLIER_NAME_MISSING = 'SUPPLIER_NAME_MISSING';

    /** Eşleşen ürün yok ve kaynakta "yeni ürün aç" kapalı. */
    case SUPPLIER_NOT_MATCHED = 'SUPPLIER_NOT_MATCHED';

    /** Plan ürün sınırı doldu; kalan yeni ürünler açılmadı. */
    case SUPPLIER_QUOTA_REACHED = 'SUPPLIER_QUOTA_REACHED';

    /** Fiyat alanı boş/yok. */
    case SUPPLIER_PRICE_MISSING = 'SUPPLIER_PRICE_MISSING';

    /** Fiyat sayı değil ya da negatif. */
    case SUPPLIER_PRICE_INVALID = 'SUPPLIER_PRICE_INVALID';

    /** Fiyat 0; satış fiyatı olarak kullanılmadı. */
    case SUPPLIER_PRICE_ZERO = 'SUPPLIER_PRICE_ZERO';

    /** Alış fiyatı boş/yok — satış fiyatı uydurulmadı. */
    case SUPPLIER_COST_MISSING = 'SUPPLIER_COST_MISSING';

    /** Alış fiyatı sayı değil ya da negatif. */
    case SUPPLIER_COST_INVALID = 'SUPPLIER_COST_INVALID';

    /** Alış fiyatı 0 — maliyet ve türetilen fiyat yazılmadı. */
    case SUPPLIER_COST_ZERO = 'SUPPLIER_COST_ZERO';

    /** Akışın para birimi varyantınkiyle aynı değil; fiyat/maliyet yazılmadı. */
    case SUPPLIER_CURRENCY_MISMATCH = 'SUPPLIER_CURRENCY_MISMATCH';

    /** Stok alanı boş/yok — stok değişmedi (bilinmeyen ≠ sıfır). */
    case SUPPLIER_STOCK_MISSING = 'SUPPLIER_STOCK_MISSING';

    /** Eşleşen ürün bir SET — stoğu bileşenlerden türetilir, dosyadan yazılmaz. */
    case SUPPLIER_STOCK_BUNDLE = 'SUPPLIER_STOCK_BUNDLE';

    /** Stok tam sayı değil ya da negatif — stok değişmedi. */
    case SUPPLIER_STOCK_INVALID = 'SUPPLIER_STOCK_INVALID';

    /** Kayıt son tam turda akışta yoktu — stok DEĞİŞMEDİ. */
    case SUPPLIER_RECORD_MISSING = 'SUPPLIER_RECORD_MISSING';

    /** Tur yarıda kaldı (çökme/zaman aşımı); başarı damgası ilerlemedi. */
    case SUPPLIER_SYNC_INCOMPLETE = 'SUPPLIER_SYNC_INCOMPLETE';

    /** Bu kaynağın bir turu zaten çalışıyor. */
    case SUPPLIER_SYNC_ALREADY_RUNNING = 'SUPPLIER_SYNC_ALREADY_RUNNING';

    /** Kayıt işlenirken beklenmeyen hata. */
    case SUPPLIER_RECORD_FAILED = 'SUPPLIER_RECORD_FAILED';

    public function label(): string
    {
        return __(match ($this) {
            self::SUPPLIER_FETCH_FAILED => 'Akış indirilemedi',
            self::SUPPLIER_URL_BLOCKED => 'Adres güvenlik kuralına takıldı',
            self::SUPPLIER_FEED_TOO_LARGE => 'Akış boyut sınırını aşıyor',
            self::SUPPLIER_XML_INVALID => 'XML okunamadı',
            self::SUPPLIER_MAPPING_INVALID => 'Alan eşlemesi eksik',
            self::SUPPLIER_NO_RECORDS => 'Seçilen düğümde ürün bulunamadı',
            self::SUPPLIER_IDENTITY_MISSING => 'Kimlik alanı boş',
            self::SUPPLIER_DUPLICATE_IDENTITY => 'Aynı kimlik akışta birden çok kez var',
            self::SUPPLIER_MATCH_AMBIGUOUS => 'SKU ve barkod farklı ürünleri gösteriyor',
            self::SUPPLIER_VARIANT_ALREADY_BOUND => 'Ürün başka bir tedarikçi kaydına bağlı',
            self::SUPPLIER_SKU_CONFLICT => 'SKU başka bir üründe kullanılıyor',
            self::SUPPLIER_BARCODE_CONFLICT => 'Barkod başka bir üründe kullanılıyor',
            self::SUPPLIER_SKU_MISSING => 'Yeni ürün için SKU yok',
            self::SUPPLIER_NAME_MISSING => 'Yeni ürün için ad yok',
            self::SUPPLIER_NOT_MATCHED => 'Eşleşen ürün yok',
            self::SUPPLIER_QUOTA_REACHED => 'Plan ürün sınırı doldu',
            self::SUPPLIER_PRICE_MISSING => 'Fiyat yok',
            self::SUPPLIER_PRICE_INVALID => 'Fiyat geçersiz',
            self::SUPPLIER_PRICE_ZERO => 'Fiyat 0',
            self::SUPPLIER_COST_MISSING => 'Alış fiyatı yok',
            self::SUPPLIER_COST_INVALID => 'Alış fiyatı geçersiz',
            self::SUPPLIER_COST_ZERO => 'Alış fiyatı 0',
            self::SUPPLIER_CURRENCY_MISMATCH => 'Para birimi ürünle aynı değil',
            self::SUPPLIER_STOCK_MISSING => 'Stok bilgisi yok',
            self::SUPPLIER_STOCK_BUNDLE => 'Set ürün: stoğu bileşenlerinden hesaplanır',
            self::SUPPLIER_STOCK_INVALID => 'Stok geçersiz',
            self::SUPPLIER_RECORD_MISSING => 'Akışta artık yok',
            self::SUPPLIER_SYNC_INCOMPLETE => 'Güncelleme yarıda kaldı',
            self::SUPPLIER_SYNC_ALREADY_RUNNING => 'Bu kaynak zaten güncelleniyor',
            self::SUPPLIER_RECORD_FAILED => 'Kayıt işlenemedi',
        });
    }
}
