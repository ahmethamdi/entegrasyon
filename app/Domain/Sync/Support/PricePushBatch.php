<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

/**
 * Kanala gönderilecek fiyat yükü.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · SupportsPricing.
 *
 * Fiyatlar MUTLAK değerdir ve string taşınır: float para birimi için
 * güvenilir değildir, yuvarlama hataları kuruş kayması üretir.
 */
final readonly class PricePushBatch
{
    /** @param list<array{listing_id: string, external_id: string, price: string, compare_at_price?: string|null, version: int}> $items */
    public function __construct(
        public string $channelConnectionId,
        public array $items,
    ) {}

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
