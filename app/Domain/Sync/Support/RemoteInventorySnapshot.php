<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

/**
 * Kanaldan okunan stok durumu — mutabakatın karşılaştırma girdisi.
 *
 * Mimari Karar Dokümanı v2.2 · §10 · Reconciliation.
 *
 * Karşılaştırma max(available, 0) ile yapılır: kanonik available = −1 iken
 * kanaldaki 0 değeri DOĞRUDUR ve sürüklenme değildir. Bu kural burada değil,
 * karşılaştırmayı yapan CompareState içinde uygulanır; bu nesne yalnızca
 * kanalın söylediğini taşır.
 */
final readonly class RemoteInventorySnapshot
{
    /** @param array<string, int> $quantitiesByExternalId */
    public function __construct(
        public array $quantitiesByExternalId,
        /** Kanalın okuma anı; gecikmeli okuma sürüklenme sanılmasın. */
        public ?\DateTimeImmutable $observedAt = null,
    ) {}

    public function quantityFor(string $externalId): ?int
    {
        return $this->quantitiesByExternalId[$externalId] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->quantitiesByExternalId === [];
    }
}
