<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductChannelAttributeValue;
use App\Domain\Channels\Models\CategoryMapping;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelCategoryAttribute;
use App\Domain\Channels\Support\CatalogRequirements;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Ürünün bir kanal kategorisindeki BETİMLEYİCİ öznitelik değerini yazar (S3).
 *
 * Kategori, ürünün iç kategorisinin BU kiracıda eşlendiği kanal kategorisi
 * olmalı (`category_mappings` kiracı scope'unda) — başka kiracının
 * eşlemesi ya da ilgisiz kategori kabul edilmez. Varyant belirleyici
 * öznitelik buraya YAZILMAZ (seçenek eşlemesinden gelir). Değer kanalın
 * listesinden bir kimlik ya da öznitelik serbest metin kabul ediyorsa
 * metin; ikisi de boşsa kayıt silinir.
 */
final class SaveProductChannelAttributeValue
{
    public function run(Product $product, string $channelCategoryId, string $externalAttributeId, ?string $valueId, ?string $customValue): ?ProductChannelAttributeValue
    {
        $mapped = CategoryMapping::query()
            ->where('internal_category_id', (string) $product->internal_category_id)
            ->where('channel_category_id', $channelCategoryId)
            ->exists();

        $category = $mapped ? ChannelCategory::query()->find($channelCategoryId) : null;

        if ($category === null) {
            throw ValidationException::withMessages(['channel_category_id' => __('Bu ürünün kategorisi bu kanal kategorisine eşlenmemiş.')]);
        }

        $attribute = ChannelCategoryAttribute::query()
            ->where('channel_category_id', $category->id)
            ->where('external_attribute_id', $externalAttributeId)
            ->first();

        if ($attribute === null || $attribute->is_variant_defining) {
            throw ValidationException::withMessages(['external_attribute_id' => __('Bu öznitelik ürün düzeyinde girilemez.')]);
        }

        $valueId = $valueId === null || trim($valueId) === '' ? null : trim($valueId);
        $customValue = $customValue === null || trim($customValue) === '' ? null : trim($customValue);

        $query = ProductChannelAttributeValue::query()
            ->where('product_id', $product->id)
            ->where('channel_category_id', $category->id)
            ->where('external_attribute_id', $externalAttributeId);

        if ($valueId === null && $customValue === null) {
            $query->delete();

            return null;
        }

        if ($valueId !== null) {
            $allowed = array_map(static fn (array $v): string => (string) ($v['id'] ?? ''), (array) $attribute->allowed_values);

            if (! in_array($valueId, $allowed, true)) {
                throw ValidationException::withMessages(['value_id' => __('Seçilen değer kanalın listesinde yok.')]);
            }

            $customValue = null;
        } elseif (! CatalogRequirements::acceptsFreeText($attribute)) {
            throw ValidationException::withMessages(['custom_value' => __('Bu öznitelik yalnız kanalın listesinden seçilebilir.')]);
        }

        return ProductChannelAttributeValue::query()->updateOrCreate(
            ['product_id' => $product->id, 'channel_category_id' => $category->id, 'external_attribute_id' => $externalAttributeId],
            ['tenant_id' => TenantContext::idOrFail(), 'external_value_id' => $valueId, 'custom_value' => $customValue],
        );
    }
}
