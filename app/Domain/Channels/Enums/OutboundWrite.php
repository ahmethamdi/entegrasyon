<?php

declare(strict_types=1);

namespace App\Domain\Channels\Enums;

use App\Domain\Sync\Enums\PublishReason;
use App\Domain\Sync\Enums\SyncDomain;

/**
 * Bağlantının kanala YAZMA izinleri — ADR-010.
 *
 * KİMLİK DOĞRULANDI ≠ DIŞARI YAZMAYA YETKİLİ. Bağlantının var olması,
 * kimlik bilgisinin geçerli olması, adapter'ın yeteneği ve kanal türünün
 * `is_active` bayrağı bu izinlerin HİÇBİRİNİ vermez; her biri bağlantı
 * satırındaki kendi kolonunda, açık bir eylemle açılır.
 *
 * Okuma (sağlık, kategori, içe aktarma, onay takibi, ilan arama) bu
 * izinlere bağlı DEĞİLDİR.
 */
enum OutboundWrite: string
{
    case PRODUCT_PUBLISH = 'product_publish';
    case PRICE_SYNC = 'price_sync';
    case STOCK_SYNC = 'stock_sync';

    /** `channel_connections` kolonu. */
    public function column(): string
    {
        return match ($this) {
            self::PRODUCT_PUBLISH => 'product_publish_enabled',
            self::PRICE_SYNC => 'price_sync_enabled',
            self::STOCK_SYNC => 'stock_sync_enabled',
        };
    }

    /** Engellenen operasyonun kararlı sebep kodu (`listing_sync_states.reason_code`). */
    public function reason(): PublishReason
    {
        return match ($this) {
            self::PRODUCT_PUBLISH => PublishReason::PRODUCT_PUBLISHING_DISABLED,
            self::PRICE_SYNC => PublishReason::PRICE_SYNC_DISABLED,
            self::STOCK_SYNC => PublishReason::STOCK_SYNC_DISABLED,
        };
    }

    /** İznin koruduğu senkron alanı. */
    public function domain(): SyncDomain
    {
        return match ($this) {
            self::PRODUCT_PUBLISH => SyncDomain::CONTENT,
            self::PRICE_SYNC => SyncDomain::PRICE,
            self::STOCK_SYNC => SyncDomain::INVENTORY,
        };
    }

    public function label(): string
    {
        return __(match ($this) {
            self::PRODUCT_PUBLISH => 'Ürün yayını',
            self::PRICE_SYNC => 'Fiyat gönderimi',
            self::STOCK_SYNC => 'Stok gönderimi',
        });
    }
}
