<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Enums;

/**
 * Depo akışının KARARLI sebep kodları.
 *
 * Veritabanına KOD yazılır, sunum metni değil (TD-37 deseni tekrarlanmaz):
 * metin `label()` ile çalışma anında çevrilir. Kodlar ileride işlem
 * merkezinin (Action Center) olay türleri olacak — yeniden adlandırılmaz.
 */
enum FulfillmentReason: string
{
    /** Siparişin kanonik durumu bu işleme izin vermiyor. */
    case INVALID_ORDER_STATE = 'INVALID_ORDER_STATE';

    /** Siparişin kanalı bu işlemi desteklemiyor (ör. etiket yalnız Trendyol). */
    case CHANNEL_UNSUPPORTED = 'CHANNEL_UNSUPPORTED';

    /** Kanal bağlantısı aktif değil ya da devre kesici açık. */
    case CHANNEL_UNAVAILABLE = 'CHANNEL_UNAVAILABLE';

    /** Paketin kargo firması ortak etiket sürecinde değil (yalnız TEX / Aras). */
    case CARGO_PROVIDER_UNSUPPORTED = 'CARGO_PROVIDER_UNSUPPORTED';

    /** Satıcı hesabının ortak etiket yetkisi yok (Trendyol COMMON_LABEL_NOT_ALLOWED). */
    case COMMON_LABEL_NOT_AUTHORIZED = 'COMMON_LABEL_NOT_AUTHORIZED';

    /** Pakete henüz kargo takip numarası atanmamış. */
    case TRACKING_NUMBER_MISSING = 'TRACKING_NUMBER_MISSING';

    /** Paket kanalda bulunamadı. */
    case SHIPMENT_NOT_FOUND = 'SHIPMENT_NOT_FOUND';

    /** Etiket istendi ama kanal henüz üretmedi. */
    case LABEL_NOT_READY = 'LABEL_NOT_READY';

    /** Etiket isteği kanalda başarısız oldu. */
    case LABEL_CREATION_FAILED = 'LABEL_CREATION_FAILED';

    /** Kanal bu biçimde etiket döndürmedi / desteklenmeyen biçim. */
    case LABEL_FORMAT_UNSUPPORTED = 'LABEL_FORMAT_UNSUPPORTED';

    /** Siparişin kesilmiş bir faturası yok. */
    case INVOICE_DOCUMENT_MISSING = 'INVOICE_DOCUMENT_MISSING';

    /** Fatura kesiliyor; belge henüz hazır değil. */
    case INVOICE_PENDING = 'INVOICE_PENDING';

    /** Fatura kesilemedi. */
    case INVOICE_FAILED = 'INVOICE_FAILED';

    /** e-fatura hesabı (Paraşüt) bağlı değil ya da kullanılamıyor. */
    case INVOICE_ACCOUNT_MISSING = 'INVOICE_ACCOUNT_MISSING';

    /** "Yalnız muhasebeye işle" kipi: e-belge yok, indirilecek dosya yok. */
    case INVOICE_BOOKS_ONLY = 'INVOICE_BOOKS_ONLY';

    /** Planın bu ayki fatura hakkı doldu — satır açılmadı. */
    case INVOICE_QUOTA_EXCEEDED = 'INVOICE_QUOTA_EXCEEDED';

    /** Kalem katalogdaki bir varyanta eşlenmemiş — toplama belirsiz olabilir. */
    case VARIANT_UNMATCHED = 'VARIANT_UNMATCHED';

    /** Kullanıcıya gösterilecek metin (çevrilir; anahtar Türkçe metnin kendisi). */
    public function label(): string
    {
        return __(match ($this) {
            self::INVALID_ORDER_STATE => 'Siparişin durumu bu işleme izin vermiyor.',
            self::CHANNEL_UNSUPPORTED => 'Bu kanalın siparişinde bu işlem desteklenmiyor.',
            self::CHANNEL_UNAVAILABLE => 'Kanal bağlantısı şu an kullanılamıyor; biraz sonra yeniden deneyin.',
            self::CARGO_PROVIDER_UNSUPPORTED => 'Paketin kargo firması ortak etiket sürecinde değil (yalnız Trendyol Express ve Aras).',
            self::COMMON_LABEL_NOT_AUTHORIZED => 'Trendyol hesabınızın ortak etiket yetkisi yok; kategori sorumlunuzla görüşün.',
            self::TRACKING_NUMBER_MISSING => 'Pakete henüz kargo takip numarası atanmamış.',
            self::SHIPMENT_NOT_FOUND => 'Paket kanalda bulunamadı.',
            self::LABEL_NOT_READY => 'Etiket istendi, kanal henüz hazırlamadı.',
            self::LABEL_CREATION_FAILED => 'Etiket oluşturulamadı.',
            self::LABEL_FORMAT_UNSUPPORTED => 'Kanal etiketi desteklenmeyen bir biçimde döndürdü.',
            self::INVOICE_DOCUMENT_MISSING => 'Siparişin kesilmiş faturası yok.',
            self::INVOICE_PENDING => 'Fatura kesiliyor; belge henüz hazır değil.',
            self::INVOICE_FAILED => 'Fatura kesilemedi; sipariş ekranından yeniden deneyin.',
            self::INVOICE_ACCOUNT_MISSING => 'e-fatura hesabı bağlı değil.',
            self::INVOICE_BOOKS_ONLY => 'Fatura yalnız muhasebeye işlendi; indirilecek e-belge yok.',
            self::INVOICE_QUOTA_EXCEEDED => 'Bu ayki fatura kotan doldu; daha fazla fatura için planını yükselt.',
            self::VARIANT_UNMATCHED => 'Kalem katalogdaki bir varyanta eşlenmemiş; kanal verisi gösteriliyor.',
        });
    }

    /** Satıcının bir şey yapması mı gerekiyor (yoksa kalıcı hata/bekleme mi)? */
    public function requiresAction(): bool
    {
        return match ($this) {
            self::LABEL_NOT_READY, self::INVOICE_PENDING, self::CHANNEL_UNAVAILABLE => false,
            self::LABEL_CREATION_FAILED, self::SHIPMENT_NOT_FOUND, self::LABEL_FORMAT_UNSUPPORTED => false,
            default => true,
        };
    }
}
