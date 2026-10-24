<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Support;

/**
 * Bir varyantın satılabilir stoğu — standart varyantta seviye satırının
 * kendisi, sette bileşenlerden türetilmiş değer (`SellableStock`).
 *
 * `available` KIRPILMAMIŞTIR (fazla satışta negatif); kanala giden değer
 * `outbound()` ile kırpılır — `OutboundQuantity` kuralı.
 */
final readonly class StockView
{
    /**
     * @param  bool  $known  standart varyantta seviye satırı var mı; sette bileşeni var mı
     * @param  string|null  $limitingVariantId  sette stoğu sınırlayan bileşen
     */
    public function __construct(
        public int $available,
        public int $version,
        public bool $known,
        public ?string $limitingVariantId = null,
    ) {}

    public function outbound(): int
    {
        return OutboundQuantity::fromAvailable($this->available);
    }
}
