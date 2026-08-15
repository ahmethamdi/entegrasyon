<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/** Pasif bir depo varsayılan yapılamaz. */
final class InactiveWarehouseException extends RuntimeException
{
    public static function cannotBeDefault(string $warehouseId): self
    {
        return new self("Pasif depo varsayılan yapılamaz: {$warehouseId}");
    }
}
