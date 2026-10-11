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
        // Satış anı finansal görüntüsü (ADR-002). NULL = kanal vermedi /
        // anlamı doğrulanmadı — 0 ile karıştırılmaz.
        public ?string $grossLineTotal = null,
        public ?string $discountTotal = null,
        public ?string $netLineTotal = null,
        public ?bool $amountsIncludeVat = null,
        public ?string $vatRate = null,
        public ?string $vatAmount = null,
        /** @var array<string, mixed> kanalın ham para alanları (kişisel veri yok) */
        public array $financialsSource = [],
    ) {}

    public function hasFinancials(): bool
    {
        return $this->netLineTotal !== null || $this->grossLineTotal !== null
            || $this->vatRate !== null || $this->vatAmount !== null || $this->financialsSource !== [];
    }
}
