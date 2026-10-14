<?php

declare(strict_types=1);

namespace App\Domain\BulkEdit\Support;

use App\Domain\BulkEdit\Enums\BulkEditReason;
use RuntimeException;

/** Dosya düzeyinde ret — hiçbir satır yazılmaz. */
final class BulkEditFileException extends RuntimeException
{
    public function __construct(string $detail, public readonly bool $tooMany = false)
    {
        parent::__construct($detail);
    }

    public function reason(): BulkEditReason
    {
        return $this->tooMany ? BulkEditReason::BULK_TOO_MANY_ROWS : BulkEditReason::BULK_FILE_INVALID;
    }
}
