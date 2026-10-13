<?php

declare(strict_types=1);

namespace App\Domain\Questions\Support;

use DateTimeImmutable;

/**
 * Kanalın müşteri sorusu — ham olgu. Müşteri adı ve müşteri kimliği
 * TAŞINMAZ (destek akışı için gerekmez).
 */
final readonly class RemoteQuestion
{
    public function __construct(
        public string $externalId,
        public string $body,
        public ?string $channelStatus,
        public ?string $productRef = null,
        public ?string $productName = null,
        public ?DateTimeImmutable $askedAt = null,
        public ?DateTimeImmutable $expiresAt = null,
        public ?string $answerBody = null,
        public ?string $answerExternalId = null,
        public ?DateTimeImmutable $answeredAt = null,
        public ?string $rejectedAnswerExternalId = null,
        public ?string $rejectedAnswerReason = null,
    ) {}
}
