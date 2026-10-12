<?php

declare(strict_types=1);

namespace App\Domain\Channels\Exceptions;

use App\Domain\Channels\Enums\OutboundWrite;
use RuntimeException;

/**
 * Bağlantının bu yazma izni kapalı — ADR-010. Mesaj günlük içindir;
 * panel metni `$write->reason()->label()`'dan gelir.
 */
final class OutboundWriteDisabled extends RuntimeException
{
    public function __construct(public readonly OutboundWrite $write, string $connectionId)
    {
        parent::__construct("Bağlantı {$connectionId}: {$write->reason()->value}");
    }
}
