<?php

declare(strict_types=1);

namespace App\Support\Site;

use Carbon\CarbonImmutable;

/**
 * Tek blog yazısı — Markdown dosyasından ayrıştırılmış, salt okunur.
 *
 * `html` güvenilir HTML'dir: ayrıştırıcı ham HTML girdisini SİLER
 * (`html_input: strip`), bu yüzden görünüm `{!! !!}` ile basabilir.
 */
final readonly class BlogPost
{
    /** @param list<string> $tags */
    public function __construct(
        public string $slug,
        public string $title,
        public string $description,
        public string $html,
        public CarbonImmutable $publishedAt,
        public ?CarbonImmutable $updatedAt = null,
        public array $tags = [],
        public int $readingMinutes = 1,
        public ?string $image = null,
        public int $wordCount = 0,
    ) {}

    /** Son değişiklik tarihi — site haritası ve JSON-LD `dateModified` için. */
    public function modifiedAt(): CarbonImmutable
    {
        return $this->updatedAt ?? $this->publishedAt;
    }
}
