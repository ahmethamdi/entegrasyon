<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

/**
 * Kanala gönderilecek stok yükü.
 *
 * Mimari Karar Dokümanı v2.2 · §1 · Karar 25, §7 · SupportsInventory.
 *
 * Miktarlar MUTLAK ve KIRPILMIŞ gelir: yükü kuran taraf
 * OutboundQuantity::forChannel() uygulamış olmalıdır. Bu sınıf kırpma
 * YAPMAZ — kırpmanın tek meşru yeri OutboundQuantity'dir ve iki yerde
 * yapılması, birinin unutulduğu gün fark edilmeyen bir hata demektir.
 *
 * Gruplama InventoryBatchBuilder'ın işidir; bu nesne yalnızca taşıyıcıdır.
 */
final readonly class InventoryPushBatch
{
    /** @param list<InventoryPushItem> $items */
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

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(
            static fn (InventoryPushItem $item): array => $item->toArray(),
            $this->items,
        );
    }
}
