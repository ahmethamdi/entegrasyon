<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Support;

use App\Domain\Suppliers\Enums\SupplierReason;
use App\Domain\Suppliers\Models\SupplierSource;

/**
 * Kaynağın alan eşlemesi — akışın GERÇEK yapısından seçilir.
 *
 * `mapping` JSON:
 *   {
 *     "variant_path": "Varyantlar/Varyant" | null,
 *     "fields": {"key": "...", "group_key": "...", "sku": "...", "barcode": "...",
 *                "name": "...", "description": "...", "brand": "...", "category": "...",
 *                "images": "...", "stock": "...", "cost": "...", "price": "...", "currency": "..."},
 *     "options": [{"name": "Beden", "path": "Varyantlar/Varyant/Beden"}]
 *   }
 *
 * Zorunlu yalnız kayıt yolu ve KİMLİK (`key`). Diğer alanlar politikaya
 * göre gerekir (ör. `cost_markup` için `cost`) — bu denetim `problems()`te.
 */
final class FeedMapping
{
    public const FIELDS = [
        'key', 'group_key', 'sku', 'barcode', 'name', 'description', 'brand',
        'category', 'images', 'stock', 'cost', 'price', 'currency',
    ];

    public const MAX_OPTIONS = 3;

    public const MAX_IMAGES = 10;

    /**
     * @param  array<string, string>  $fields
     * @param  list<array{name: string, path: string}>  $options
     */
    private function __construct(
        public readonly ?string $recordPath,
        public readonly ?string $variantPath,
        public readonly array $fields,
        public readonly array $options,
        private readonly FeedValueParser $parser,
        private readonly string $defaultCurrency,
    ) {}

    public static function forSource(SupplierSource $source): self
    {
        $mapping = $source->mapping ?? [];
        $fields = [];

        foreach (self::FIELDS as $field) {
            $path = trim((string) ($mapping['fields'][$field] ?? ''), '/ ');

            if ($path !== '') {
                $fields[$field] = $path;
            }
        }

        $options = [];

        foreach (array_slice((array) ($mapping['options'] ?? []), 0, self::MAX_OPTIONS) as $option) {
            $name = trim((string) ($option['name'] ?? ''));
            $path = trim((string) ($option['path'] ?? ''), '/ ');

            if ($name !== '' && $path !== '') {
                $options[] = ['name' => $name, 'path' => $path];
            }
        }

        $variantPath = trim((string) ($mapping['variant_path'] ?? ''), '/ ');
        $recordPath = trim((string) $source->record_path, '/ ');

        return new self(
            recordPath: $recordPath !== '' ? $recordPath : null,
            variantPath: $variantPath !== '' ? $variantPath : null,
            fields: $fields,
            options: $options,
            parser: new FeedValueParser($source->number_format),
            defaultCurrency: strtoupper($source->currency),
        );
    }

    /**
     * Turu başlatmayı engelleyen eşleme sorunları (boşsa uygun).
     *
     * @return list<string>
     */
    public function problems(SupplierSource $source): array
    {
        $problems = [];

        if ($this->recordPath === null) {
            $problems[] = 'record_path';
        }

        if (! isset($this->fields['key'])) {
            $problems[] = 'key';
        }

        if ($source->price_policy === SupplierSource::PRICE_COST_MARKUP && ! isset($this->fields['cost'])) {
            $problems[] = 'cost';
        }

        if ($source->price_policy === SupplierSource::PRICE_FEED && ! isset($this->fields['price'])) {
            $problems[] = 'price';
        }

        if ($source->stock_policy === SupplierSource::STOCK_AUTHORITATIVE && ! isset($this->fields['stock'])) {
            $problems[] = 'stock';
        }

        return $problems;
    }

    public function assertUsable(SupplierSource $source): void
    {
        $problems = $this->problems($source);

        if ($problems !== []) {
            throw new SupplierFeedException(SupplierReason::SUPPLIER_MAPPING_INVALID, 'Eksik eşleme: '.implode(', ', $problems));
        }
    }

    /**
     * @param  array<string, list<string>>  $raw
     */
    public function item(array $raw): SupplierItem
    {
        $options = [];

        foreach ($this->options as $option) {
            $value = $this->text($raw, $option['path']);

            if ($value !== null) {
                $options[$option['name']] = $value;
            }
        }

        $images = [];

        foreach ($raw[$this->fields['images'] ?? "\0"] ?? [] as $url) {
            $url = trim($url);

            // Yalnız https: kanallara görsel indirme yolu https ister (ADR-012 · §8).
            if (preg_match('#^https://#i', $url) === 1 && strlen($url) <= 2000) {
                $images[] = $url;
            }
        }

        $currency = $this->text($raw, $this->fields['currency'] ?? null);
        $currency = $currency !== null ? strtoupper(mb_substr($currency, 0, 3)) : $this->defaultCurrency;

        if ($currency === 'TL') {
            $currency = 'TRY';
        }

        return new SupplierItem(
            key: $this->text($raw, $this->fields['key'] ?? null, 191),
            groupKey: $this->text($raw, $this->fields['group_key'] ?? null, 191),
            sku: $this->text($raw, $this->fields['sku'] ?? null, 191),
            barcode: $this->text($raw, $this->fields['barcode'] ?? null, 64),
            name: $this->text($raw, $this->fields['name'] ?? null, 500),
            description: $this->text($raw, $this->fields['description'] ?? null, 20000),
            brand: $this->text($raw, $this->fields['brand'] ?? null, 255),
            category: $this->text($raw, $this->fields['category'] ?? null, 255),
            images: array_slice(array_values(array_unique($images)), 0, self::MAX_IMAGES),
            stock: isset($this->fields['stock']) ? $this->parser->stock($raw[$this->fields['stock']] ?? null) : FieldValue::missing(),
            cost: isset($this->fields['cost']) ? $this->parser->money($raw[$this->fields['cost']] ?? null) : FieldValue::missing(),
            price: isset($this->fields['price']) ? $this->parser->money($raw[$this->fields['price']] ?? null) : FieldValue::missing(),
            currency: $currency,
            options: $options,
            raw: $raw,
        );
    }

    /**
     * @param  array<string, list<string>>  $raw
     */
    private function text(array $raw, ?string $path, int $max = 255): ?string
    {
        if ($path === null) {
            return null;
        }

        $value = $this->parser->first($raw[$path] ?? null);

        return $value === null ? null : mb_substr($value, 0, $max);
    }
}
