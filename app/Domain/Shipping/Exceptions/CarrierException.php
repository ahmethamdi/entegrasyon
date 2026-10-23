<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Exceptions;

use App\Domain\Channels\Contracts\CarriesRetryAfter;
use App\Domain\Sync\Enums\ErrorClass;
use RuntimeException;

/**
 * Kargo firması isteği başarısız — SINIFIYLA birlikte (fatura entegratörüyle aynı desen).
 *
 * Sınıf iş tarafında `RetryPolicy`'ye gider: kalıcı (VALIDATION,
 * AUTHENTICATION) yeniden denenmez ve satıcıya firmanın metniyle
 * gösterilir; geçici olanlar beklenip yeniden denenir. `carrierCode`
 * firmanın kendi hata kodudur (ör. Yurtiçi 82502) — eşleme testleri onu sınar.
 */
final class CarrierException extends RuntimeException implements CarriesRetryAfter
{
    public function __construct(
        public readonly ErrorClass $class,
        string $message,
        public readonly ?string $carrierCode = null,
    ) {
        parent::__construct($message);
    }

    public function retryAfterSeconds(): ?int
    {
        return null;
    }
}
