<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

/**
 * `SupportsOrderReconciliation` sayfası — `OrderPage`'in kardeşi.
 */
final readonly class RecoveredOrderPage
{
    /**
     * @param  list<RecoveredOrderEvent>  $events
     */
    public function __construct(
        public array $events,
        public ?string $nextCursor = null,
        public bool $hasMore = false,
    ) {}
}
