<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Sync\Models\Listing;

/**
 * Kanala gönderilecek listing içeriği.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · SupportsCatalog.
 *
 * Kanonik model ile kanal formatı arasındaki dönüşüm ADAPTER'ın Mapper'ında
 * yapılır; bu nesne kanonik tarafı taşır. Adapter'a "şunu gönder" der,
 * "şu JSON'u gönder" demez.
 */
final readonly class ListingPayload
{
    /**
     * @param  array<string, mixed>  $attributes  Kanala özgü öznitelikler
     */
    public function __construct(
        public Listing $listing,
        public string $title,
        public ?string $description = null,
        public ?string $categoryId = null,
        public array $attributes = [],
        public int $version = 0,
    ) {}
}
