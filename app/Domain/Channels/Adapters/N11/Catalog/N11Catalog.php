<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\N11\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Support\ChannelImages;
use App\Domain\Channels\Exceptions\ListingNotPublishable;
use App\Domain\Channels\Models\CategoryMapping;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelCategoryAttribute;
use App\Domain\Channels\Support\CatalogRequirements;
use App\Domain\Sync\Enums\PublishReason;
use App\Domain\Sync\Support\CategoryTreeSnapshot;
use App\Domain\Sync\Support\ListingPayload;
use App\Domain\Sync\Support\ListingProjection;
use App\Domain\Sync\Support\PublishIssue;
use DateTimeImmutable;

/**
 * N11 KATALOG — taksonomi ayrıştırma, yayın doğrulaması ve
 * `product-create` öğesi (S3). HTTP adapter'dadır; bu sınıf yan etkisizdir.
 *
 * KAYNAK: developer.n11.com "Ürün Yükleme", "Kategori Ağacı Listeleme",
 * "Kategori Özellikleri Listeleme", "Ürün Bilgisi Güncelleme" (11 Eki 2026
 * okundu). N11'in TEST ORTAMI YOK — GERÇEK HESAPLA SINANMADI.
 *
 * KİMLİK: `stockCode` = varyant SKU'su (satıcıya ait, tekil; N11 çakışan
 * stockCode'u reddeder). GRUP: `productMainId` = ürün SKU'su — "Varyantlı
 * ürün açmak için … tüm değeri için aynı değer" (belge). Her varyant KENDİ
 * stockCode, fiyat, stok, barkod, görsel ve varyant özniteliğini taşır.
 *
 * MARKA AYRI SERVİS DEĞİL: özellik `id: 1` ("Marka"). Ürün markası
 * kanalın listesinde birebir varsa `valueId`, yoksa ve özellik serbest
 * değer kabul ediyorsa `customValue`; ikisi de değilse yayın engellenir.
 */
final class N11Catalog
{
    public const BRAND_ATTRIBUTE_ID = '1';

    public const VAT_RATES = [0, 1, 10, 20];

    public const MIN_TITLE_LENGTH = 15;

    public const MAX_STOCK_CODE_LENGTH = 255;

    public const MAX_QUANTITY = 999_999;

    public const MAX_IMAGES = 8;

    public const SETTING_VAT_RATE = 'vat_rate';

    public const SETTING_SHIPMENT_TEMPLATE = 'shipment_template';

    public const SETTING_PREPARING_DAY = 'preparing_day';

    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings) {}

    // ───────────────────────────────────────────── taksonomi

    /** @param array<string, mixed> $body `GET /cdn/categories` gövdesi */
    public static function tree(array $body): CategoryTreeSnapshot
    {
        $flat = [];
        self::flatten((array) ($body['categories'] ?? []), null, [], $flat);

        $fingerprint = array_map(static fn (array $c): string => $c['external_id'].'|'.($c['parent_external_id'] ?? '').'|'.$c['name'], $flat);
        sort($fingerprint);

        return new CategoryTreeSnapshot($flat, 'n11-'.substr(hash('sha256', implode("\n", $fingerprint)), 0, 16), new DateTimeImmutable);
    }

    /**
     * @param  list<mixed>  $nodes
     * @param  list<string>  $path
     * @param  list<array<string, mixed>>  $into
     */
    private static function flatten(array $nodes, ?string $parentId, array $path, array &$into): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node) || ! isset($node['id'])) {
                continue;
            }

            $id = (string) $node['id'];
            $name = (string) ($node['name'] ?? '');
            $children = is_array($node['subCategories'] ?? null) ? $node['subCategories'] : [];
            $current = [...$path, $name];

            $into[] = [
                'external_id' => $id,
                'parent_external_id' => $parentId,
                'name' => $name,
                'path' => implode(' > ', $current),
                // Belge: yalnız en alt kırılım (`subCategories: null`) kabul edilir.
                'is_leaf' => $children === [],
            ];

            if ($children !== []) {
                self::flatten($children, $id, $current, $into);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $body  `GET /cdn/category/{id}/attribute` gövdesi
     * @return list<array<string, mixed>>
     */
    public static function attributes(array $body): array
    {
        $out = [];

        foreach ((array) ($body['categoryAttributes'] ?? []) as $row) {
            if (! is_array($row) || ! isset($row['attributeId'])) {
                continue;
            }

            $out[] = [
                'external_attribute_id' => (string) $row['attributeId'],
                'name' => (string) ($row['attributeName'] ?? $row['attributeId']),
                'is_required' => (bool) ($row['isMandatory'] ?? false),
                'is_variant_defining' => (bool) ($row['isVariant'] ?? false),
                // isCustomValue=false → valueId zorunlu (liste bağlayıcı).
                'data_type' => ($row['isCustomValue'] ?? false) ? 'string' : 'enum',
                'allowed_values' => array_values(array_filter(array_map(
                    static fn (mixed $v): ?array => is_array($v) && isset($v['id']) ? ['id' => (string) $v['id'], 'label' => (string) ($v['value'] ?? '')] : null,
                    (array) ($row['attributeValues'] ?? []),
                ))),
            ];
        }

        return $out;
    }

    // ───────────────────────────────────────────── doğrulama

    /** @return list<PublishIssue> */
    public function validate(Product $product, ChannelCategory $category): array
    {
        $issues = [];
        $product->loadMissing('variants');

        if (mb_strlen(trim((string) $product->title)) < self::MIN_TITLE_LENGTH) {
            $issues[] = new PublishIssue(PublishReason::TITLE_INVALID, $product->sku);
        }

        if (trim(strip_tags((string) $product->description)) === '') {
            $issues[] = new PublishIssue(PublishReason::DESCRIPTION_REQUIRED, $product->sku);
        }

        $brand = $this->brand($product, $category);

        if ($brand instanceof PublishIssue) {
            $issues[] = $brand;
        }

        if (! in_array($this->intSetting(self::SETTING_VAT_RATE), self::VAT_RATES, true)) {
            $issues[] = new PublishIssue(PublishReason::VAT_RATE_INVALID, self::SETTING_VAT_RATE);
        }

        if (trim((string) ($this->settings[self::SETTING_SHIPMENT_TEMPLATE] ?? '')) === '') {
            $issues[] = new PublishIssue(PublishReason::CHANNEL_SETTING_REQUIRED, self::SETTING_SHIPMENT_TEMPLATE);
        }

        if (($this->intSetting(self::SETTING_PREPARING_DAY) ?? 0) < 1) {
            $issues[] = new PublishIssue(PublishReason::CHANNEL_SETTING_REQUIRED, self::SETTING_PREPARING_DAY);
        }

        foreach ($product->variants as $variant) {
            foreach ($this->variantIssues($variant) as $issue) {
                $issues[] = $issue;
            }
        }

        return [...$issues, ...(new CatalogRequirements($product, $category, [self::BRAND_ATTRIBUTE_ID]))->issues()];
    }

    /** @return list<PublishIssue> */
    private function variantIssues(Variant $variant): array
    {
        $issues = [];
        $sku = trim((string) $variant->sku);

        if ($sku === '' || mb_strlen($sku) > self::MAX_STOCK_CODE_LENGTH) {
            $issues[] = new PublishIssue(PublishReason::SKU_INVALID, $variant->sku, $variant->id);
        }

        if (ChannelImages::urlsFor($variant, 'n11') === []) {
            $issues[] = new PublishIssue(PublishReason::IMAGE_REQUIRED, $variant->sku, $variant->id);
        }

        if ($variant->price === null || (float) $variant->price <= 0) {
            $issues[] = new PublishIssue(PublishReason::PRICE_INVALID, $variant->sku, $variant->id);
        }

        if (self::currencyType((string) ($variant->currency ?? 'TRY')) === null) {
            $issues[] = new PublishIssue(PublishReason::CURRENCY_UNSUPPORTED, $variant->sku, $variant->id);
        }

        return $issues;
    }

    /**
     * Marka özelliği (id 1) — `{id, valueId, customValue}` ya da eksik.
     *
     * @return array{id: int, valueId: ?int, customValue: ?string}|PublishIssue|null null = kategoride marka özelliği yok
     */
    private function brand(Product $product, ChannelCategory $category): array|PublishIssue|null
    {
        $brand = trim((string) $product->brand);

        $attribute = ChannelCategoryAttribute::query()
            ->where('channel_category_id', $category->id)
            ->where('external_attribute_id', self::BRAND_ATTRIBUTE_ID)
            ->first();

        if ($attribute === null) {
            return $brand === '' ? new PublishIssue(PublishReason::BRAND_REQUIRED, $product->sku) : null;
        }

        if ($brand === '') {
            return new PublishIssue(PublishReason::BRAND_REQUIRED, $product->sku);
        }

        $exact = CatalogRequirements::exactValue($attribute, $brand);

        if ($exact !== null) {
            return ['id' => 1, 'valueId' => (int) $exact['value_id'], 'customValue' => null];
        }

        return CatalogRequirements::acceptsFreeText($attribute)
            ? ['id' => 1, 'valueId' => null, 'customValue' => $brand]
            : new PublishIssue(PublishReason::BRAND_MAPPING_REQUIRED, $brand);
    }

    // ───────────────────────────────────────────── yük

    /**
     * Tek varyantın `product-create` SKU öğesi (belge gövdesi). Fiyatlar
     * METİN (iki ondalık) — adapter gövdeyi sayıya çevirirken iki ondalığı
     * korur (`jsonWithTwoDecimals`).
     *
     * @return array<string, mixed>
     */
    public function createSku(ListingPayload $payload): array
    {
        [$variant, $product, $category, $requirements] = $this->resolve($payload);
        $listing = $payload->listing;

        $issues = [...$this->variantIssues($variant), ...array_filter(
            $requirements->issues(),
            static fn (PublishIssue $i): bool => $i->variantId === null || $i->variantId === $variant->id,
        )];

        $brand = $this->brand($product, $category);

        if ($brand instanceof PublishIssue) {
            $issues[] = $brand;
        }

        if (mb_strlen(trim($payload->title)) < self::MIN_TITLE_LENGTH) {
            $issues[] = new PublishIssue(PublishReason::TITLE_INVALID, $product->sku);
        }

        if ($issues !== []) {
            throw new ListingNotPublishable(implode(' ', array_map(static fn (PublishIssue $i): string => $i->describe(), $issues)), $issues[0]->reason);
        }

        $sale = (float) ListingProjection::salePrice($listing);

        if ($sale <= 0) {
            throw ListingNotPublishable::because(PublishReason::PRICE_INVALID, $variant->sku);
        }

        $list = max((float) ListingProjection::listPrice($listing), $sale);

        return array_filter([
            'title' => $payload->title,
            'description' => (string) ($payload->description ?? $product->description),
            'categoryId' => (int) $category->external_id,
            'currencyType' => self::currencyType((string) ($variant->currency ?? 'TRY')),
            'productMainId' => (string) $product->sku,
            'preparingDay' => $this->intSetting(self::SETTING_PREPARING_DAY),
            'shipmentTemplate' => (string) $this->settings[self::SETTING_SHIPMENT_TEMPLATE],
            'stockCode' => trim((string) $variant->sku),
            'barcode' => $variant->barcode !== null && trim($variant->barcode) !== '' ? trim($variant->barcode) : null,
            'quantity' => min(ListingProjection::quantity($listing), self::MAX_QUANTITY),
            'images' => array_map(
                static fn (string $url, int $i): array => ['url' => $url, 'order' => $i + 1],
                $urls = array_slice(ChannelImages::urlsFor($variant, 'n11'), 0, self::MAX_IMAGES),
                array_keys($urls),
            ),
            'attributes' => $this->attributesFor($variant, $requirements, is_array($brand) ? $brand : null),
            'salePrice' => number_format($sale, 2, '.', ''),
            'listPrice' => number_format($list, 2, '.', ''),
            'vatRate' => $this->intSetting(self::SETTING_VAT_RATE),
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * `product-update` öğesi — belge: güncellenebilenler açıklama, KDV,
     * hazırlık süresi, teslimat şablonu, varyant DIŞI öznitelikler. Başlık,
     * görsel, marka, renk/beden N11 tarafından yönetilir (gönderilmez).
     *
     * @return array<string, mixed>
     */
    public function updateSku(ListingPayload $payload): array
    {
        [$variant, $product, , $requirements] = $this->resolve($payload);

        $attributes = array_map(
            static fn (array $r): array => self::attributePayload($r),
            $requirements->descriptiveAttributes(),
        );

        return array_filter([
            'stockCode' => trim((string) $variant->sku),
            'description' => (string) ($payload->description ?? $product->description),
            'vatRate' => $this->intSetting(self::SETTING_VAT_RATE),
            'preparingDay' => $this->intSetting(self::SETTING_PREPARING_DAY),
            'shipmentTemplate' => $this->settings[self::SETTING_SHIPMENT_TEMPLATE] ?? null,
            'attributes' => $attributes === [] ? null : $attributes,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }

    /** @return array{0: Variant, 1: Product, 2: ChannelCategory, 3: CatalogRequirements} */
    private function resolve(ListingPayload $payload): array
    {
        $payload->listing->loadMissing('variant.product');
        $variant = $payload->listing->variant;
        $product = $variant?->product;

        if ($variant === null || $product === null) {
            throw ListingNotPublishable::because(PublishReason::INVALID_VARIANT, (string) $payload->listing->id);
        }

        $mapping = CategoryMapping::query()
            ->where('internal_category_id', (string) $product->internal_category_id)
            ->where('channel_type_code', 'n11')
            ->first();

        $category = $mapping === null ? null : ChannelCategory::query()->find($mapping->channel_category_id);

        if ($category === null) {
            throw ListingNotPublishable::because(PublishReason::CATEGORY_MAPPING_REQUIRED, (string) $product->internal_category_id);
        }

        return [$variant, $product, $category, new CatalogRequirements($product, $category, [self::BRAND_ATTRIBUTE_ID])];
    }

    /**
     * Marka + betimleyiciler (ürün) + varyantın KENDİ varyant değerleri.
     *
     * @param  array{id: int, valueId: ?int, customValue: ?string}|null  $brand
     * @return list<array{id: int|string, valueId: int|string|null, customValue: ?string}>
     */
    private function attributesFor(Variant $variant, CatalogRequirements $requirements, ?array $brand): array
    {
        $out = $brand === null ? [] : [$brand];

        foreach ([...$requirements->descriptiveAttributes(), ...$requirements->variantAttributes($variant)] as $resolved) {
            $out[] = self::attributePayload($resolved);
        }

        return $out;
    }

    /**
     * @param  array{attribute: ChannelCategoryAttribute, value_id: ?string, label: string}  $resolved
     * @return array{id: int|string, valueId: int|string|null, customValue: ?string}
     */
    private static function attributePayload(array $resolved): array
    {
        return [
            'id' => self::numeric((string) $resolved['attribute']->external_attribute_id),
            'valueId' => $resolved['value_id'] === null ? null : self::numeric($resolved['value_id']),
            'customValue' => $resolved['value_id'] === null ? $resolved['label'] : null,
        ];
    }

    public static function currencyType(string $iso): ?string
    {
        return match (strtoupper($iso)) {
            'TRY', 'TL' => 'TL',
            'USD' => 'USD',
            'EUR' => 'EUR',
            default => null,
        };
    }

    private function intSetting(string $key): ?int
    {
        $raw = $this->settings[$key] ?? null;

        return is_numeric($raw) ? (int) $raw : null;
    }

    private static function numeric(string $id): int|string
    {
        return ctype_digit($id) ? (int) $id : $id;
    }
}
