<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

/**
 * Yoklama ile çekilen bir sayfa ham sipariş.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · SupportsOrders.
 *
 * Ham gövde döner ve doğrudan inbox'a yazılır; ayrıştırma sonra yapılır.
 * Bu sıra bilinçlidir: ayrıştırma hatası siparişin kaybolmasına değil,
 * inbox satırının hata durumuna düşmesine yol açar ve yeniden işlenebilir.
 */
final readonly class OrderPage
{
    /** @param list<array<string, mixed>> $orders */
    public function __construct(
        public array $orders,
        public ?string $nextCursor = null,
        public bool $hasMore = false,
    ) {}

    public function count(): int
    {
        return count($this->orders);
    }

    public function isEmpty(): bool
    {
        return $this->orders === [];
    }
}
