<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Orders\Models\Fulfillment;

/**
 * Kargo bildirimi yeteneği.
 *
 * Mimari Karar Dokümanı v2.2 · §7.
 *
 * Trendyol bunu UYGULAMAZ: ayrı kargo entegrasyonu gerektirir ve kapsam dışı.
 */
interface SupportsFulfillment
{
    public function pushFulfillment(Fulfillment $fulfillment): AdapterResult;

    /** @return array<string, string> */
    public function fetchCarriers(): array;
}
