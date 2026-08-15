<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\PricePushBatch;
use App\Domain\Sync\Support\RemotePriceSnapshot;

/**
 * Fiyat senkronu yeteneği.
 *
 * Mimari Karar Dokümanı v2.2 · §7.
 *
 * Stok gibi fiyat da MUTLAK değer olarak gönderilir; yüzde indirim veya
 * delta gönderilmez. Aynı gerekçe: kaybolan veya iki kez işlenen bir istek
 * fiyatı kalıcı olarak kaydırırdı.
 */
interface SupportsPricing
{
    public function pushPrices(PricePushBatch $batch): AdapterResult;

    /** @param list<Listing> $listings */
    public function fetchPrices(array $listings): RemotePriceSnapshot;

    public function maxPriceBatchSize(): int;
}
