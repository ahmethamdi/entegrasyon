<?php

declare(strict_types=1);

namespace App\Domain\Orders\Support;

use InvalidArgumentException;

/** İptal edilen tek kalem. */
final readonly class CancelledLine
{
    public function __construct(
        public string $orderLineId,
        public int $quantity,
    ) {
        if ($quantity <= 0) {
            throw new InvalidArgumentException(
                "İptal miktarı pozitif olmalıdır, {$quantity} verildi."
            );
        }
    }
}
