<?php

declare(strict_types=1);

namespace App\Domain\Orders\Support;

/**
 * Kanaldan gelen sipariş satırı.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · order_lines.
 *
 * variantId NULL olabilir: kanaldaki SKU kataloğumuzla eşleşmemiş demektir.
 * Satır yine kaydedilir ve PENDING kalır — sipariş kaybetmek, stok
 * tutarsızlığından daha kötüdür.
 */
final readonly class IncomingOrderLine
{
    public function __construct(
        public string $externalLineId,
        public string $sku,
        public string $title,
        public int $quantity,
        public ?string $variantId = null,
        public string $unitPrice = '0',
        public string $lineTotal = '0',
    ) {}
}
