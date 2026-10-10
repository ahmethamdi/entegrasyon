<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exceptions;

use App\Domain\Channels\Contracts\CarriesRetryAfter;
use App\Domain\Sync\Enums\ErrorClass;
use RuntimeException;

/**
 * Entegratör isteği başarısız — SINIFIYLA birlikte.
 *
 * Sınıf iş tarafında `RetryPolicy`'ye gider: kalıcı (VALIDATION,
 * AUTHENTICATION) yeniden denenmez ve satıcıya entegratörün metniyle
 * gösterilir; geçici olanlar beklenip yeniden denenir.
 */
final class InvoiceProviderException extends RuntimeException implements CarriesRetryAfter
{
    public function __construct(
        public readonly ErrorClass $class,
        string $message,
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfter;
    }
}
