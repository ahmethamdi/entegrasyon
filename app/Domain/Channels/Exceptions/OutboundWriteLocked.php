<?php

declare(strict_types=1);

namespace App\Domain\Channels\Exceptions;

use App\Domain\Channels\Enums\OutboundWrite;
use RuntimeException;

/** Kanal türü canlı doğrulama kapısında; yazma izni açılamaz (ADR-010). */
final class OutboundWriteLocked extends RuntimeException
{
    public const REASON = 'CHANNEL_VERIFICATION_PENDING';

    public function __construct(public readonly OutboundWrite $write, public readonly string $channelTypeCode)
    {
        parent::__construct("{$channelTypeCode}: ".self::REASON);
    }
}
