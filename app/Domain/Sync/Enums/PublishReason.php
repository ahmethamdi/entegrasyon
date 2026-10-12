<?php

declare(strict_types=1);

namespace App\Domain\Sync\Enums;

/**
 * Ürün yayınının KARARLI sebep kodları (S3).
 *
 * Veritabanına KOD yazılır (`listing_sync_states.reason_code`); metin
 * `label()` ile çalışma anında çevrilir. Kodlar ileride işlem merkezinin
 * (Action Center) olay türleridir — yeniden adlandırılmaz.
 */
enum PublishReason: string
{
    case CATEGORY_REQUIRED = 'CATEGORY_REQUIRED';
    case CATEGORY_MAPPING_REQUIRED = 'CATEGORY_MAPPING_REQUIRED';
    case ATTRIBUTE_MAPPING_REQUIRED = 'ATTRIBUTE_MAPPING_REQUIRED';
    case ATTRIBUTE_VALUE_MAPPING_REQUIRED = 'ATTRIBUTE_VALUE_MAPPING_REQUIRED';
    case ATTRIBUTE_VALUE_REQUIRED = 'ATTRIBUTE_VALUE_REQUIRED';
    case BRAND_REQUIRED = 'BRAND_REQUIRED';
    case BRAND_MAPPING_REQUIRED = 'BRAND_MAPPING_REQUIRED';
    case BARCODE_REQUIRED = 'BARCODE_REQUIRED';
    case BARCODE_INVALID = 'BARCODE_INVALID';
    case SKU_INVALID = 'SKU_INVALID';
    case IMAGE_REQUIRED = 'IMAGE_REQUIRED';
    case TITLE_INVALID = 'TITLE_INVALID';
    case DESCRIPTION_REQUIRED = 'DESCRIPTION_REQUIRED';
    case PRICE_INVALID = 'PRICE_INVALID';
    case VAT_RATE_INVALID = 'VAT_RATE_INVALID';
    case CURRENCY_UNSUPPORTED = 'CURRENCY_UNSUPPORTED';
    case INVALID_VARIANT = 'INVALID_VARIANT';
    case CHANNEL_SETTING_REQUIRED = 'CHANNEL_SETTING_REQUIRED';
    case LISTING_UPDATE_UNSUPPORTED = 'LISTING_UPDATE_UNSUPPORTED';
    case LISTING_REJECTED = 'LISTING_REJECTED';
    case LISTING_SYNC_FAILED = 'LISTING_SYNC_FAILED';
    case RATE_LIMITED = 'RATE_LIMITED';
    case REMOTE_STATE_UNKNOWN = 'REMOTE_STATE_UNKNOWN';
    case PRODUCT_PUBLISHING_DISABLED = 'PRODUCT_PUBLISHING_DISABLED';
    case PRICE_SYNC_DISABLED = 'PRICE_SYNC_DISABLED';
    case STOCK_SYNC_DISABLED = 'STOCK_SYNC_DISABLED';

    public function label(): string
    {
        return __(match ($this) {
            self::CATEGORY_REQUIRED => 'Ürüne iç kategori atanmamış.',
            self::CATEGORY_MAPPING_REQUIRED => 'Ürünün kategorisi bu kanalda eşleştirilmemiş.',
            self::ATTRIBUTE_MAPPING_REQUIRED => 'Kanalın zorunlu bir özniteliği eşleştirilmemiş.',
            self::ATTRIBUTE_VALUE_MAPPING_REQUIRED => 'Varyant seçeneğinin değeri kanal değerine eşleştirilmemiş.',
            self::ATTRIBUTE_VALUE_REQUIRED => 'Kanalın zorunlu bir özniteliği için ürün değeri girilmemiş.',
            self::BRAND_REQUIRED => 'Ürünün markası boş.',
            self::BRAND_MAPPING_REQUIRED => 'Marka kanalın marka listesinde bulunamadı.',
            self::BARCODE_REQUIRED => 'Varyantın barkodu yok.',
            self::BARCODE_INVALID => 'Varyantın barkodu geçerli bir EAN-13 değil.',
            self::SKU_INVALID => 'Varyantın SKU\'su kanal kurallarına uymuyor.',
            self::IMAGE_REQUIRED => 'Ürünün bu kanala gidebilecek HTTPS görseli yok.',
            self::TITLE_INVALID => 'Ürün başlığı kanal kurallarına uymuyor.',
            self::DESCRIPTION_REQUIRED => 'Ürün açıklaması boş.',
            self::PRICE_INVALID => 'Varyantın kanal fiyatı geçersiz.',
            self::VAT_RATE_INVALID => 'KDV oranı kanalın kabul ettiği değerlerden değil.',
            self::CURRENCY_UNSUPPORTED => 'Fiyat para birimi bu kanalda desteklenmiyor.',
            self::INVALID_VARIANT => 'Varyant yapısı kanal için geçersiz.',
            self::CHANNEL_SETTING_REQUIRED => 'Kanal bağlantısında yayın için gerekli bir ayar eksik.',
            self::LISTING_UPDATE_UNSUPPORTED => 'Bu kanalda ürün içeriği API ile güncellenemiyor.',
            self::LISTING_REJECTED => 'Kanal ürünü reddetti.',
            self::LISTING_SYNC_FAILED => 'Ürün kanala gönderilemedi.',
            self::RATE_LIMITED => 'Kanal istek sınırına ulaşıldı; otomatik yeniden denenecek.',
            self::REMOTE_STATE_UNKNOWN => 'Kanala gönderim sonucu belirsiz; tekrar göndermeden önce kanalda durumu kontrol edin.',
            self::PRODUCT_PUBLISHING_DISABLED => 'Bu bağlantıda ürün yayını kapalı; kanala hiçbir şey gönderilmedi.',
            self::PRICE_SYNC_DISABLED => 'Bu bağlantıda fiyat gönderimi kapalı; kanala hiçbir şey gönderilmedi.',
            self::STOCK_SYNC_DISABLED => 'Bu bağlantıda stok gönderimi kapalı; kanala hiçbir şey gönderilmedi.',
        });
    }
}
