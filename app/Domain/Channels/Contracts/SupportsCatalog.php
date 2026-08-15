<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\ListingPayload;
use App\Domain\Sync\Support\RemoteListing;

/**
 * Ürün listeleme yeteneği.
 *
 * Mimari Karar Dokümanı v2.2 · §7.
 *
 * SupportsProductMatching arayüzü YOKTUR: tek metot için ayrı arayüz yerine
 * findExistingListing() burada durur.
 */
interface SupportsCatalog
{
    public function createListing(ListingPayload $payload): AdapterResult;

    public function updateListing(ListingPayload $payload): AdapterResult;

    public function delist(Listing $listing): AdapterResult;

    /**
     * Kanalda zaten var olan ürünü bulur.
     *
     * Trendyol'da barkod eşleştirmesi, Woo'da SKU araması. Bu adım olmadan
     * mevcut ürünler yeniden yaratılır ve kanalda kopya listeler oluşur.
     */
    public function findExistingListing(Variant $variant): ?RemoteListing;

    /** Uzak durumu okur — mutabakat ve çakışma tespiti için. */
    public function fetchListing(Listing $listing): ?RemoteListing;
}
