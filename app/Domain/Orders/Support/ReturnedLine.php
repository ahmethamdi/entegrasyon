<?php

declare(strict_types=1);

namespace App\Domain\Orders\Support;

use InvalidArgumentException;

/**
 * İade edilen tek kalem.
 *
 * Mimari Karar Dokümanı v2.2 · §5.
 *
 * Miktar POZİTİFTİR; yön hareket türünden gelir (RETURN → +on_hand).
 */
final readonly class ReturnedLine
{
    public function __construct(
        public string $orderLineId,
        public int $quantity,
    ) {
        if ($quantity <= 0) {
            throw new InvalidArgumentException(
                "İade miktarı pozitif olmalıdır, {$quantity} verildi."
            );
        }
    }
}
