<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

/**
 * Kanaldan okunan fiyat durumu — mutabakat girdisi.
 *
 * Mimari Karar Dokümanı v2.2 · §10.
 */
final readonly class RemotePriceSnapshot
{
    /** @param array<string, string> $pricesByExternalId */
    public function __construct(
        public array $pricesByExternalId,
        public ?\DateTimeImmutable $observedAt = null,
    ) {}

    public function priceFor(string $externalId): ?string
    {
        return $this->pricesByExternalId[$externalId] ?? null;
    }
}
