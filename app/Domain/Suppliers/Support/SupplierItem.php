<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Support;

/**
 * Eşlemeden geçmiş TEK tedarikçi öğesi (bir varyant adayı).
 *
 * Metin alanları boşsa null; sayısal alanlar her zaman FieldValue
 * (durum + değer) — "yok" ile "0" ayrı kalır.
 */
final class SupplierItem
{
    /**
     * @param  list<string>  $images
     * @param  array<string, string>  $options  seçenek adı → değer
     * @param  array<string, list<string>>  $raw
     */
    public function __construct(
        public readonly ?string $key,
        public readonly ?string $groupKey,
        public readonly ?string $sku,
        public readonly ?string $barcode,
        public readonly ?string $name,
        public readonly ?string $description,
        public readonly ?string $brand,
        public readonly ?string $category,
        public readonly array $images,
        public readonly FieldValue $stock,
        public readonly FieldValue $cost,
        public readonly FieldValue $price,
        public readonly ?string $currency,
        public readonly array $options,
        public readonly array $raw,
    ) {}
}
