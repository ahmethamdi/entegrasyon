<?php

declare(strict_types=1);

namespace App\Domain\Ai\Support;

use App\Domain\Catalog\Models\OptionDefinition;
use App\Domain\Catalog\Models\OptionValue;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\VariantOption;

/**
 * Modele giden ürün bilgisi — YALNIZ ÜRÜN VERİSİ (S8).
 *
 * KİŞİSEL VERİ YOK: müşteri, sipariş, satıcının iletişim bilgisi bu
 * sınıfa hiç girmez. Girdiler ürün satırı, varyant seçenekleri ve
 * HTTPS görsel adresleridir; başka bir kaynak eklemek bu kuralı bozar.
 *
 * `hash()` önerinin hangi içerikten üretildiğini söyler: ürün sonradan
 * değiştiyse öneri bayat sayılır.
 */
final class ProductBrief
{
    /** Görselli istekte modele giden en çok görsel. */
    public const MAX_IMAGES = 3;

    public static function text(Product $product): string
    {
        $lines = [
            'Ürün başlığı: '.$product->title,
            'Marka: '.($product->brand ?: '—'),
            'İç kategori: '.($product->internal_category_id ?: '—'),
            'SKU: '.$product->sku,
        ];

        foreach (self::options($product) as $name => $values) {
            $lines[] = "Seçenek {$name}: ".implode(', ', $values);
        }

        $lines[] = 'Açıklama:';
        $lines[] = trim(strip_tags((string) $product->description)) ?: '—';

        return implode("\n", $lines);
    }

    /**
     * Görsel blokları (URL kaynağı) — yalnız HTTPS.
     *
     * @return list<array<string, mixed>>
     */
    public static function images(Product $product): array
    {
        return ProductImage::query()
            ->where('product_id', $product->id)
            ->orderByRaw('CASE WHEN variant_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('position')
            ->get()
            ->map(fn (ProductImage $image): ?string => $image->publicUrl())
            ->filter(fn (?string $url): bool => $url !== null && str_starts_with($url, 'https://'))
            ->take(self::MAX_IMAGES)
            ->map(fn (string $url): array => ['type' => 'image', 'source' => ['type' => 'url', 'url' => $url]])
            ->values()
            ->all();
    }

    public static function hash(Product $product): string
    {
        return hash('sha256', json_encode([
            $product->title, $product->description, $product->brand, $product->internal_category_id,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, list<string>> seçenek adı → değerler */
    private static function options(Product $product): array
    {
        $product->loadMissing('variants');

        $rows = VariantOption::query()
            ->whereIn('variant_id', $product->variants->pluck('id')->all())
            ->get(['option_definition_id', 'option_value_id']);

        if ($rows->isEmpty()) {
            return [];
        }

        $names = OptionDefinition::query()->whereIn('id', $rows->pluck('option_definition_id')->unique()->all())->pluck('name', 'id');
        $values = OptionValue::query()->whereIn('id', $rows->pluck('option_value_id')->unique()->all())->pluck('value', 'id');

        $out = [];

        foreach ($rows as $row) {
            $name = (string) ($names[$row->option_definition_id] ?? '');
            $value = (string) ($values[$row->option_value_id] ?? '');

            if ($name !== '' && $value !== '' && ! in_array($value, $out[$name] ?? [], true)) {
                $out[$name][] = $value;
            }
        }

        return $out;
    }
}
