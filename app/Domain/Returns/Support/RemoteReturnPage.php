<?php

declare(strict_types=1);

namespace App\Domain\Returns\Support;

final readonly class RemoteReturnPage
{
    /**
     * @param  list<RemoteReturn>  $returns
     */
    public function __construct(
        public array $returns,
        public int $page,
        public bool $hasMore,
    ) {}
}
