<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Sync\Support\CategoryTreeSnapshot;

/**
 * Kategori ağacı yeteneği — pazaryerlerine özgü.
 *
 * Mimari Karar Dokümanı v2.2 · §7, §14.
 *
 * WooCommerce bunu UYGULAMAZ: mağaza kanalında kategori serbesttir.
 * Trendyol uygular; kategori ve zorunlu öznitelikler olmadan ürün açılamaz.
 *
 * taxonomyVersion() kategori ağacı değiştiğinde eşleşmelerin yeniden
 * doğrulanmasını sağlar; bu alan olmadan ağaç değişince tüm eşleşmeler elle
 * yenilenirdi.
 */
interface SupportsTaxonomy
{
    public function fetchCategoryTree(): CategoryTreeSnapshot;

    /** @return array<string, mixed> */
    public function fetchCategoryAttributes(string $categoryId): array;

    public function taxonomyVersion(): string;
}
