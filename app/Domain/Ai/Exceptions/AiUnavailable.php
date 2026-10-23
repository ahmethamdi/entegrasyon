<?php

declare(strict_types=1);

namespace App\Domain\Ai\Exceptions;

use RuntimeException;

/**
 * Model çağrısı yapılamadı (ağ, zaman aşımı, 429, 5xx, kimlik).
 *
 * Satıcıya kısa, Türkçe bir cümle gösterilir; ayrıntı günlüğe ve
 * `ai_usage.error`'a yazılır. Kota HARCANMAZ: hiç öneri üretilmedi.
 */
final class AiUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'error')
    {
        parent::__construct($message);
    }
}
