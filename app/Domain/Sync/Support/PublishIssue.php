<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Sync\Enums\PublishReason;

/**
 * Yayın doğrulamasının bulduğu tek bir eksik — kod + bağlam.
 *
 * `subject` makine okunur bağlamdır (öznitelik adı, varyant SKU'su);
 * sunum `reason->label()` ile kurulur.
 */
final readonly class PublishIssue
{
    public function __construct(
        public PublishReason $reason,
        public ?string $subject = null,
        public ?string $variantId = null,
    ) {}

    public function describe(): string
    {
        return $this->subject === null
            ? $this->reason->label()
            : $this->reason->label().' ('.$this->subject.')';
    }
}
