<?php

declare(strict_types=1);

namespace App\Domain\Returns\Support;

use DateTimeImmutable;

/** Kanalın iade talebi — ham olgu. */
final readonly class RemoteReturn
{
    /**
     * @param  list<RemoteReturnItem>  $items
     */
    public function __construct(
        public string $externalClaimId,
        public array $items,
        public ?string $orderNumber = null,
        public ?string $packageId = null,
        public ?string $claimType = null,
        public ?DateTimeImmutable $claimedAt = null,
        public ?DateTimeImmutable $modifiedAt = null,
    ) {}
}
