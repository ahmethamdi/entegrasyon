<?php

declare(strict_types=1);

namespace App\Domain\Sync\Enums;

/**
 * Senkron alanı — listing'in hangi yönü senkronlanıyor.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · listing_sync_states, §8.
 *
 * Alan ayrımı granülerliğin bir parçasıdır: bir varyantın stoğu değiştiğinde
 * yalnızca INVENTORY satırı kirlenir, içerik ve fiyat etkilenmez. Tek satırda
 * birleştirilseydi her stok değişimi içeriği de yeniden göndertirdi.
 */
enum SyncDomain: string
{
    case CONTENT = 'CONTENT';
    case PRICE = 'PRICE';
    case INVENTORY = 'INVENTORY';
    case MEDIA = 'MEDIA';

    /** Bu alanın operasyon türü — sync_operations.operation_type. */
    public function operationType(): string
    {
        return match ($this) {
            self::CONTENT => 'CONTENT_PUSH',
            self::PRICE => 'PRICE_PUSH',
            self::INVENTORY => 'INVENTORY_PUSH',
            self::MEDIA => 'MEDIA_PUSH',
        };
    }

    /**
     * Operasyon türünden alanı geri okur — `operationType()`'ın tersi.
     *
     * `sync_operations`'ta `domain` KOLONU YOKTUR; alan yalnızca
     * `operation_type` içinde yaşar. Ölü bir operasyonu yeniden denerken
     * domain'in oradan okunması ZORUNLUDUR: sabit `INVENTORY` yazılsaydı
     * ölü bir `PRICE_PUSH` için stok senkronu açılır, fiyat HİÇ gitmez ve
     * kullanıcı butona bastığı hâlde sorunun çözülmediğini görürdü.
     *
     * Tanınmayan tür NULL döner — istisna fırlatmaz. Çağıran (panel)
     * bunu "yeniden denenemez" diye ele alır; sessizce bir domain
     * seçmek yanlış alanı senkronlardı.
     */
    public static function fromOperationType(string $operationType): ?self
    {
        foreach (self::cases() as $domain) {
            if ($domain->operationType() === $operationType) {
                return $domain;
            }
        }

        return null;
    }

    /** İdempotency anahtarının alan öneki. */
    public function keyPrefix(): string
    {
        return match ($this) {
            self::CONTENT => 'content',
            self::PRICE => 'price',
            self::INVENTORY => 'inv',
            self::MEDIA => 'media',
        };
    }
}
