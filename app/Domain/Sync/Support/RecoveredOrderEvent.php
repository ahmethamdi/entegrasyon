<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

/**
 * Mutabakatla kanaldan okunan tek sipariş olayı — webhook gövdesi biçiminde.
 *
 * `eventType` adapter'ın webhook konusudur (Shopify `orders/create`);
 * normalizer onu webhook'la aynı yoldan çevirir.
 */
final readonly class RecoveredOrderEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventType,
        public string $eventId,
        public string $externalOrderId,
        public array $payload,
    ) {}
}
