<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

/**
 * Kanaldaki onay durumları.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · SupportsApprovalWorkflow, §14.
 *
 * Onay durumu listing lifecycle'ından AYRIDIR: ürün bizde "gönderildi" ama
 * kanalda "beklemede" veya "reddedildi" olabilir. Reddedilme sebebi
 * kullanıcıya gösterilmek zorundadır.
 */
final readonly class ApprovalStatusBatch
{
    /** @param array<string, array{status: string, reason?: string|null}> $statusesByExternalId */
    public function __construct(
        public array $statusesByExternalId,
        public ?\DateTimeImmutable $observedAt = null,
    ) {}

    /** @return array{status: string, reason?: string|null}|null */
    public function statusFor(string $externalId): ?array
    {
        return $this->statusesByExternalId[$externalId] ?? null;
    }
}
