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
 *
 * S4 · MÜŞTERİ HİZMETİ YAZMALARI ürün/fiyat/stok senkronundan AYRI bir
 * sınıftır: iade kararı müşterinin parasını, soru cevabı herkese açık
 * metni etkiler. İkisi ayrı izindir — riskleri farklı, biri açılınca öteki
 * açılmaz. Senkron alanı ve senkron sebep kodu yoktur (`isSync()` false).
 */
enum OutboundWrite: string
{
    case PRODUCT_PUBLISH = 'product_publish';
    case PRICE_SYNC = 'price_sync';
    case STOCK_SYNC = 'stock_sync';
    case RETURN_DECISION = 'return_decision';
    case QUESTION_ANSWER = 'question_answer';

    /** `channel_connections` kolonu. */
    public function column(): string
    {
        return match ($this) {
            self::PRODUCT_PUBLISH => 'product_publish_enabled',
            self::PRICE_SYNC => 'price_sync_enabled',
            self::STOCK_SYNC => 'stock_sync_enabled',
            self::RETURN_DECISION => 'return_decision_enabled',
            self::QUESTION_ANSWER => 'question_answer_enabled',
        };
    }

    /** Engellenen operasyonun kararlı sebep kodu (`listing_sync_states.reason_code`). */
    public function reason(): PublishReason
    {
        return match ($this) {
            self::PRODUCT_PUBLISH => PublishReason::PRODUCT_PUBLISHING_DISABLED,
            self::PRICE_SYNC => PublishReason::PRICE_SYNC_DISABLED,
            self::STOCK_SYNC => PublishReason::STOCK_SYNC_DISABLED,
            self::RETURN_DECISION, self::QUESTION_ANSWER => throw new \LogicException("{$this->value} bir senkron yazması değil."),
        };
    }

    /** İznin koruduğu senkron alanı. */
    public function domain(): SyncDomain
    {
        return match ($this) {
            self::PRODUCT_PUBLISH => SyncDomain::CONTENT,
            self::PRICE_SYNC => SyncDomain::PRICE,
            self::STOCK_SYNC => SyncDomain::INVENTORY,
            self::RETURN_DECISION, self::QUESTION_ANSWER => throw new \LogicException("{$this->value} bir senkron yazması değil."),
        };
    }

    public function label(): string
    {
        return __(match ($this) {
            self::PRODUCT_PUBLISH => 'Ürün yayını',
            self::PRICE_SYNC => 'Fiyat gönderimi',
            self::STOCK_SYNC => 'Stok gönderimi',
            self::RETURN_DECISION => 'İade kararı',
            self::QUESTION_ANSWER => 'Soru cevabı',
        });
    }

    /** Ürün/fiyat/stok senkronu mu (senkron alanı ve sebep kodu var mı). */
    public function isSync(): bool
    {
        return in_array($this, [self::PRODUCT_PUBLISH, self::PRICE_SYNC, self::STOCK_SYNC], true);
    }
}
