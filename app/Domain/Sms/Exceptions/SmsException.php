<?php

declare(strict_types=1);

namespace App\Domain\Sms\Exceptions;

use App\Domain\Sync\Enums\ErrorClass;
use RuntimeException;

/**
 * SMS sağlayıcısından gelen hata — SINIFIYLA.
 *
 * `providerCode` sağlayıcının kendi kodudur (Netgsm `REJECTED_HEADER`,
 * `invalid_credentials` …); mesaj satıcıya gösterilebilir, şifre taşımaz.
 */
final class SmsException extends RuntimeException
{
    public function __construct(
        public readonly ErrorClass $class,
        string $message,
        public readonly ?string $providerCode = null,
    ) {
        parent::__construct($message);
    }
}
