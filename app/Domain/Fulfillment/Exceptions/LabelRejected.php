<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Exceptions;

use App\Domain\Fulfillment\Enums\FulfillmentReason;
use RuntimeException;

/**
 * Kanal etiket isteğini KESİN olarak reddetti (yeniden denemek sonucu
 * değiştirmez). Geçici hatalar (zaman aşımı, 5xx, 429) bu değildir —
 * onlar HTTP istisnası olarak yükselir ve `RetryPolicy` karar verir.
 */
final class LabelRejected extends RuntimeException
{
    public function __construct(public readonly FulfillmentReason $reason, string $detail = '')
    {
        parent::__construct($detail !== '' ? $detail : $reason->value);
    }
}
