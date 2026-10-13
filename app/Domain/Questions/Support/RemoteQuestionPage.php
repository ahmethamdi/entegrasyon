<?php

declare(strict_types=1);

namespace App\Domain\Questions\Support;

final readonly class RemoteQuestionPage
{
    /**
     * @param  list<RemoteQuestion>  $questions
     */
    public function __construct(
        public array $questions,
        public int $page,
        public bool $hasMore,
    ) {}
}
