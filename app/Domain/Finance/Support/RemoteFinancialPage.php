<?php

declare(strict_types=1);

namespace App\Domain\Finance\Support;

final readonly class RemoteFinancialPage
{
    /**
     * @param  list<RemoteFinancialTransaction>  $transactions
     */
    public function __construct(
        public array $transactions,
        public int $page,
        public int $totalPages,
    ) {}

    public function hasMore(): bool
    {
        return $this->page + 1 < $this->totalPages;
    }
}
