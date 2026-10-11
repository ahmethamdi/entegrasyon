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
    /**
     * Girdi isteğe bağlı olarak kanalın verdiği KALICI kimliği
     * (`external_id`, ör. HB `hbSku`) ve birleştirilecek `channel_metadata`'yı
     * taşıyabilir (S3).
     *
     * @param  array<string, array{status: string, reason?: string|null, external_id?: string, channel_metadata?: array<string, mixed>}>  $statusesByExternalId
     */
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
