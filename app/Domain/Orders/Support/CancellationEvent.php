<?php

declare(strict_types=1);

namespace App\Domain\Orders\Support;

/**
 * Kanaldan gelen iptal olayı — çok kalemli olabilir.
 *
 * Mimari Karar Dokümanı v2.2 · §5, §1 · Karar 10.
 *
 * ReturnEvent ile aynı iskelet; tek fark hareket türü ve sayaç kolonu.
 */
final readonly class CancellationEvent
{
    /**
     * @param  list<CancelledLine>  $lines
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $orderId,
        public ?string $externalRef,
        public array $lines,
        public array $payload = [],
        public ?\DateTimeInterface $occurredAt = null,
        public ?string $inboxMessageId = null,
    ) {}
}
