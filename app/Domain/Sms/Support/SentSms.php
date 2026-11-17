<?php

declare(strict_types=1);

namespace App\Domain\Sms\Support;

/** Sağlayıcının kabul ettiği gönderim. */
final readonly class SentSms
{
    /** @param  list<string>  $rejectedReasons */
    public function __construct(
        public string $jobId,
        public int $accepted,
        public int $rejected,
        public array $rejectedReasons = [],
    ) {}
}
