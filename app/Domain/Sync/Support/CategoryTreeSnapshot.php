<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

/**
 * Kanalın kategori ağacı.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · SupportsTaxonomy, §14.
 *
 * version alanı taxonomy_version'a yazılır: ağaç değiştiğinde mevcut
 * eşleşmelerin yeniden doğrulanması gerekir. Bu alan olmadan kategori ağacı
 * değişince tüm eşleşmeler elle yenilenirdi.
 */
final readonly class CategoryTreeSnapshot
{
    /** @param list<array<string, mixed>> $categories */
    public function __construct(
        public array $categories,
        public string $version,
        public ?\DateTimeImmutable $fetchedAt = null,
    ) {}

    public function count(): int
    {
        return count($this->categories);
    }
}
