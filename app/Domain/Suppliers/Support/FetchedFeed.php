<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Support;

/**
 * İndirilmiş akış — geçici dosya. İşi biten `discard()` çağırır.
 */
final class FetchedFeed
{
    public function __construct(
        public readonly string $path,
        public readonly int $bytes,
        public readonly bool $truncated,
        public readonly int $status,
        public readonly string $contentType,
    ) {}

    public function discard(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
