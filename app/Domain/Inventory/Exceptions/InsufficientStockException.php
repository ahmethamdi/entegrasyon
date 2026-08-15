<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Inventory\Enums\MovementType;
use RuntimeException;

/**
 * Yetersiz stok — yalnızca BİZİM kararımız olan hareketlerde fırlatılır.
 *
 * Mimari Karar Dokümanı v2.2 · §1 · Karar 07, §5 · Hareket uygulama.
 *
 * RESERVATION ve TRANSFER_OUT kabul edilmeden önce doğrulanabilir; bunlar
 * reddedilir. SALE dış dünyada olmuş bitmiş bir olaydır ve ASLA bu istisnayı
 * üretmez — bakiye negatife düşer, sipariş kaydedilir.
 */
final class InsufficientStockException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $variantId,
        public readonly MovementType $type,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct($message);
    }

    public static function forMovement(
        MovementType $type,
        string $variantId,
        int $requested,
        int $available,
    ): self {
        return new self(
            "{$type->value} reddedildi: varyant {$variantId} için {$requested} adet istendi, ".
            "kullanılabilir {$available}.",
            $variantId,
            $type,
            $requested,
            $available,
        );
    }
}
