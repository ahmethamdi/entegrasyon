<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Domain\Catalog\Models\OptionValue;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductChannelAttributeValue;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Models\VariantOption;
use App\Domain\Channels\Models\AttributeMapping;
use App\Domain\Channels\Models\AttributeValueMapping;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelCategoryAttribute;
use App\Domain\Sync\Enums\PublishReason;
use App\Domain\Sync\Support\PublishIssue;
use Illuminate\Support\Collection;

/**
 * Bir ürünün bir kanal kategorisindeki ÖZNİTELİK gereksinimleri (S3).
 *
 * İKİ AYRI KAYNAK, karıştırılmaz:
 *   · VARYANT BELİRLEYİCİ (Beden, Renk): varyantın seçeneği →
 *     `attribute_mappings` (seçenek tanımı ↔ kanal özniteliği, kategori
 *     başına) → `attribute_value_mappings` (seçenek değeri ↔ kanal değeri).
 *     Her varyant KENDİ değerini taşır; kardeşinkini değil.
 *   · BETİMLEYİCİ (Garanti, Menşei): ürün düzeyinde
 *     `product_channel_attribute_values`.
 *
 * Gereksinimler kanalın taksonomisinden gelir (`is_required`,
 * `is_variant_defining`, `allowed_values`); kanalın kendi alanlarıyla
 * doldurulan temel öznitelikler (başlık, marka, fiyat…) `$autoFilled` ile
 * dışarıda bırakılır. Eşlenemeyen zorunlu öznitelik TAHMİN EDİLMEZ —
 * eksik olarak bildirilir ve ürün gönderilmez.
 *
 * Yan etkisizdir; yalnız okur.
 */
final class CatalogRequirements
{
    /** @var Collection<int, ChannelCategoryAttribute> */
    private Collection $attributes;

    /** @var array<string, string> external_attribute_id → option_definition_id */
    private array $mappedDefinitions = [];

    /** @var Collection<int, AttributeValueMapping> */
    private Collection $valueMappings;

    /** @var array<string, list<VariantOption>> variant_id → seçenekleri */
    private array $optionsByVariant = [];

    /** @var array<string, string> option_value_id → değer metni */
    private array $optionLabels = [];

    /** @var array<string, ProductChannelAttributeValue> */
    private array $productValues = [];

    /**
     * @param  list<string>  $autoFilled  kanalın kendi alanlarıyla doldurulan öznitelik kimlikleri
     */
    public function __construct(
        private readonly Product $product,
        private readonly ChannelCategory $category,
        private readonly array $autoFilled = [],
    ) {
        $this->product->loadMissing('variants');

        $this->attributes = ChannelCategoryAttribute::query()
            ->where('channel_category_id', $category->id)
            ->orderBy('name')
            ->get();

        foreach (AttributeMapping::query()->where('channel_category_id', $category->id)->get() as $mapping) {
            $this->mappedDefinitions[(string) $mapping->external_attribute_id] = (string) $mapping->option_definition_id;
        }

        $variantIds = $product->variants->pluck('id')->all();

        $options = VariantOption::query()->whereIn('variant_id', $variantIds)->get();

        foreach ($options as $option) {
            $this->optionsByVariant[$option->variant_id][] = $option;
        }

        $valueIds = $options->pluck('option_value_id')->unique()->values()->all();

        $this->optionLabels = OptionValue::query()->whereIn('id', $valueIds)->pluck('value', 'id')->map(fn ($v) => (string) $v)->all();

        $this->valueMappings = AttributeValueMapping::query()->whereIn('option_value_id', $valueIds)->get();

        foreach (ProductChannelAttributeValue::query()
            ->where('product_id', $product->id)
            ->where('channel_category_id', $category->id)
            ->get() as $value) {
            $this->productValues[(string) $value->external_attribute_id] = $value;
        }
    }

    public function attribute(string $externalId): ?ChannelCategoryAttribute
    {
        return $this->attributes->first(fn (ChannelCategoryAttribute $a): bool => (string) $a->external_attribute_id === $externalId);
    }

    /**
     * Öznitelik eksikleri — ürünün TÜM varyantları için.
     *
     * @return list<PublishIssue>
     */
    public function issues(): array
    {
        $issues = [];

        foreach ($this->attributes as $attribute) {
            $id = (string) $attribute->external_attribute_id;

            if (in_array($id, $this->autoFilled, true)) {
                continue;
            }

            if ($attribute->is_variant_defining) {
                $definition = $this->mappedDefinitions[$id] ?? null;

                if ($definition === null) {
                    if ($attribute->is_required) {
                        $issues[] = new PublishIssue(PublishReason::ATTRIBUTE_MAPPING_REQUIRED, $attribute->name);
                    }

                    continue;
                }

                foreach ($this->product->variants as $variant) {
                    $resolved = $this->variantValue($variant, $attribute);

                    if ($resolved === null) {
                        $issues[] = new PublishIssue(
                            $this->optionOf($variant, $definition) === null
                                ? PublishReason::INVALID_VARIANT
                                : PublishReason::ATTRIBUTE_VALUE_MAPPING_REQUIRED,
                            $attribute->name.' · '.$variant->sku,
                            $variant->id,
                        );
                    }
                }

                continue;
            }

            if ($attribute->is_required && $this->descriptiveValue($attribute) === null) {
                $issues[] = new PublishIssue(PublishReason::ATTRIBUTE_VALUE_REQUIRED, $attribute->name);
            }
        }

        // ÇOK VARYANTLI ÜRÜNDE KARDEŞLER AYIRT EDİLEBİLMELİ: kanalda aynı
        // grup altında iki varyant aynı (ya da boş) varyant değerleriyle
        // açılırsa alıcı hangisini aldığını seçemez; depoda yanlış ürün çıkar.
        if ($this->product->variants->count() > 1) {
            $seen = [];

            foreach ($this->product->variants as $variant) {
                $key = json_encode(array_map(
                    static fn (array $v): string => $v['attribute']->external_attribute_id.'='.($v['value_id'] ?? $v['label']),
                    $this->variantAttributes($variant),
                ));

                if ($key === '[]' || isset($seen[$key])) {
                    $issues[] = new PublishIssue(PublishReason::INVALID_VARIANT, $variant->sku, $variant->id);
                }

                $seen[$key] = true;
            }
        }

        return $this->unique($issues);
    }

    /**
     * Varyantın KENDİ varyant belirleyici değerleri (eşlenmiş olanlar).
     *
     * @return list<array{attribute: ChannelCategoryAttribute, value_id: ?string, label: string}>
     */
    public function variantAttributes(Variant $variant): array
    {
        $out = [];

        foreach ($this->attributes as $attribute) {
            if (! $attribute->is_variant_defining || in_array((string) $attribute->external_attribute_id, $this->autoFilled, true)) {
                continue;
            }

            $resolved = $this->variantValue($variant, $attribute);

            if ($resolved !== null) {
                $out[] = ['attribute' => $attribute, ...$resolved];
            }
        }

        return $out;
    }

    /**
     * Ürün düzeyindeki betimleyici değerler.
     *
     * @return list<array{attribute: ChannelCategoryAttribute, value_id: ?string, label: string}>
     */
    public function descriptiveAttributes(): array
    {
        $out = [];

        foreach ($this->attributes as $attribute) {
            if ($attribute->is_variant_defining || in_array((string) $attribute->external_attribute_id, $this->autoFilled, true)) {
                continue;
            }

            $resolved = $this->descriptiveValue($attribute);

            if ($resolved !== null) {
                $out[] = ['attribute' => $attribute, ...$resolved];
            }
        }

        return $out;
    }

    /** @return array{value_id: ?string, label: string}|null */
    private function variantValue(Variant $variant, ChannelCategoryAttribute $attribute): ?array
    {
        $definition = $this->mappedDefinitions[(string) $attribute->external_attribute_id] ?? null;

        if ($definition === null) {
            return null;
        }

        $option = $this->optionOf($variant, $definition);

        if ($option === null) {
            return null;
        }

        $mapping = $this->valueMappings->first(fn (AttributeValueMapping $m): bool => $m->option_value_id === $option->option_value_id
            && (string) $m->external_attribute_id === (string) $attribute->external_attribute_id);

        if ($mapping !== null) {
            return ['value_id' => (string) $mapping->external_value_id, 'label' => (string) ($mapping->external_value_label ?? $this->optionLabels[$option->option_value_id] ?? '')];
        }

        $label = $this->optionLabels[$option->option_value_id] ?? '';

        // Kanal listesinde BİREBİR aynı etiket (büyük/küçük harf ve kenar
        // boşluğu hariç) varsa onun kimliği — benzerlik/tahmin YOK.
        $exact = self::exactValue($attribute, $label);

        if ($exact !== null) {
            return $exact;
        }

        // Serbest metin kabul eden öznitelikte seçeneğin kendi metni gider;
        // kabul etmeyende TAHMİN EDİLMEZ (eşleme gerekir).
        return $label !== '' && self::acceptsFreeText($attribute) ? ['value_id' => null, 'label' => $label] : null;
    }

    /** @return array{value_id: ?string, label: string}|null */
    private function descriptiveValue(ChannelCategoryAttribute $attribute): ?array
    {
        $value = $this->productValues[(string) $attribute->external_attribute_id] ?? null;

        if ($value === null) {
            return null;
        }

        if ($value->external_value_id !== null) {
            foreach ((array) $attribute->allowed_values as $allowed) {
                if ((string) ($allowed['id'] ?? '') === (string) $value->external_value_id) {
                    return ['value_id' => (string) $value->external_value_id, 'label' => (string) ($allowed['label'] ?? '')];
                }
            }

            // Kayıtlı kimlik kanalın güncel listesinde yok (taksonomi değişti).
            return null;
        }

        $custom = trim((string) $value->custom_value);

        return self::exactValue($attribute, $custom)
            ?? ($custom !== '' && self::acceptsFreeText($attribute) ? ['value_id' => null, 'label' => $custom] : null);
    }

    private function optionOf(Variant $variant, string $definitionId): ?VariantOption
    {
        foreach ($this->optionsByVariant[$variant->id] ?? [] as $option) {
            if ($option->option_definition_id === $definitionId) {
                return $option;
            }
        }

        return null;
    }

    /**
     * Kanalın değer listesinde birebir aynı etiket.
     *
     * @return array{value_id: string, label: string}|null
     */
    public static function exactValue(ChannelCategoryAttribute $attribute, string $label): ?array
    {
        $needle = mb_strtolower(trim($label));

        if ($needle === '') {
            return null;
        }

        foreach ((array) $attribute->allowed_values as $allowed) {
            if (mb_strtolower(trim((string) ($allowed['label'] ?? ''))) === $needle && (string) ($allowed['id'] ?? '') !== '') {
                return ['value_id' => (string) $allowed['id'], 'label' => (string) $allowed['label']];
            }
        }

        return null;
    }

    public static function acceptsFreeText(ChannelCategoryAttribute $attribute): bool
    {
        return $attribute->data_type === 'string' || (array) $attribute->allowed_values === [];
    }

    /**
     * @param  list<PublishIssue>  $issues
     * @return list<PublishIssue>
     */
    private function unique(array $issues): array
    {
        $out = [];

        foreach ($issues as $issue) {
            $out[$issue->reason->value.'|'.$issue->subject] = $issue;
        }

        return array_values($out);
    }
}
