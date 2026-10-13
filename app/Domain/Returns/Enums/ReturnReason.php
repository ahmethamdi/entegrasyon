<?php

declare(strict_types=1);

namespace App\Domain\Returns\Enums;

/**
 * İade akışının KARARLI sebep kodları — S4.
 *
 * Veritabanına KOD yazılır, metin `label()` ile sunumda çevrilir (TD-37
 * deseni tekrarlanmaz). Kodlar yeniden adlandırılmaz.
 */
enum ReturnReason: string
{
    /** Talep kalemi sipariş satırıyla güvenle eşlenemedi (belirsiz). */
    case RETURN_MATCH_REQUIRED = 'RETURN_MATCH_REQUIRED';

    /** Talebin siparişi henüz 34Pazar'da yok (sonra eşlenir). */
    case RETURN_ORDER_NOT_FOUND = 'RETURN_ORDER_NOT_FOUND';

    /** Kanal durumu tanınmadı; stok değişmez. */
    case RETURN_STATE_UNKNOWN = 'RETURN_STATE_UNKNOWN';

    /** Talebin şu anki durumu bu kararı almaya izin vermiyor. */
    case RETURN_ACTION_NOT_ALLOWED = 'RETURN_ACTION_NOT_ALLOWED';

    /** Bağlantıda "İade kararı" izni kapalı (ADR-010). */
    case RETURN_ACTION_DISABLED = 'RETURN_ACTION_DISABLED';

    /** Kanal karar vermeyi desteklemiyor ya da canlı doğrulanmadı. */
    case RETURN_ACTION_UNSUPPORTED = 'RETURN_ACTION_UNSUPPORTED';

    /** Bu kalem için karar zaten verildi / istek yolda. */
    case RETURN_ALREADY_PROCESSED = 'RETURN_ALREADY_PROCESSED';

    /** Karar gönderilmeden önce kanalda durum değişti (bayat ekran). */
    case RETURN_STATE_CHANGED = 'RETURN_STATE_CHANGED';

    /** Kanal kararı kesin olarak reddetti. */
    case RETURN_ACTION_REJECTED = 'RETURN_ACTION_REJECTED';

    /** İstek kanala ulaşmış olabilir, sonucu bilinmiyor; kör tekrar yapılmaz. */
    case RETURN_REMOTE_STATE_UNKNOWN = 'RETURN_REMOTE_STATE_UNKNOWN';

    /** Red sebebi eksik ya da desteklenmiyor. */
    case RETURN_REASON_REQUIRED = 'RETURN_REASON_REQUIRED';

    /** İade adedi satırda kalan adedi aşıyor; fazlası stoğa eklenmedi. */
    case RETURN_QUANTITY_CONFLICT = 'RETURN_QUANTITY_CONFLICT';

    /** Bu ekonomik iadenin stoğu zaten döndü. */
    case RETURN_STOCK_ALREADY_RESTORED = 'RETURN_STOCK_ALREADY_RESTORED';

    /** Kabul var ama ürünün depoya döndüğü kanıtlanmadı; stok eklenmedi. */
    case RETURN_RECEIPT_UNPROVEN = 'RETURN_RECEIPT_UNPROVEN';

    /** Satırın stoğu hiç düşülmemişti (eşleşmemiş/açılış); yalnız sayaç ilerler. */
    case RETURN_STOCK_NOT_DEDUCTED = 'RETURN_STOCK_NOT_DEDUCTED';

    /** Bu kanalda iade stoğu bu yoldan dönmez (canlı doğrulanmadı). */
    case RETURN_RESTOCK_UNSUPPORTED = 'RETURN_RESTOCK_UNSUPPORTED';

    /** Kanal bağlantısı aktif değil ya da devre kesici açık. */
    case RETURN_CHANNEL_UNAVAILABLE = 'RETURN_CHANNEL_UNAVAILABLE';

    /** Karar isteği başarısız (tekrar denemeler tükendi). */
    case RETURN_ACTION_FAILED = 'RETURN_ACTION_FAILED';

    public function label(): string
    {
        return __(match ($this) {
            self::RETURN_MATCH_REQUIRED => 'Sipariş kalemiyle eşleşme gerekli',
            self::RETURN_ORDER_NOT_FOUND => 'Sipariş henüz alınmadı',
            self::RETURN_STATE_UNKNOWN => 'Kanal durumu tanınmadı',
            self::RETURN_ACTION_NOT_ALLOWED => 'Bu durumda karar verilemez',
            self::RETURN_ACTION_DISABLED => 'İade kararı izni kapalı',
            self::RETURN_ACTION_UNSUPPORTED => 'Bu kanalda iade kararı desteklenmiyor',
            self::RETURN_ALREADY_PROCESSED => 'Karar zaten verildi',
            self::RETURN_STATE_CHANGED => 'Talep kanalda değişti, ekranı yenileyin',
            self::RETURN_ACTION_REJECTED => 'Kanal kararı reddetti',
            self::RETURN_REMOTE_STATE_UNKNOWN => 'Kararın kanala ulaşıp ulaşmadığı bilinmiyor',
            self::RETURN_REASON_REQUIRED => 'Geçerli bir red sebebi seçin',
            self::RETURN_QUANTITY_CONFLICT => 'İade adedi satırı aşıyor',
            self::RETURN_STOCK_ALREADY_RESTORED => 'Stok zaten geri eklendi',
            self::RETURN_RECEIPT_UNPROVEN => 'Ürünün depoya döndüğü kanıtlanmadı',
            self::RETURN_STOCK_NOT_DEDUCTED => 'Satırın stoğu düşülmemişti',
            self::RETURN_RESTOCK_UNSUPPORTED => 'Bu kanalda stok iadesi buradan yapılmaz',
            self::RETURN_CHANNEL_UNAVAILABLE => 'Kanal bağlantısı kullanılamıyor',
            self::RETURN_ACTION_FAILED => 'Karar gönderilemedi',
        });
    }
}
