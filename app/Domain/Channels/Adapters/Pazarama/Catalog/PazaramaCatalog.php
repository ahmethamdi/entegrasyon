<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Pazarama\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Support\ChannelImages;
use App\Domain\Channels\Exceptions\ListingNotPublishable;
use App\Domain\Channels\Models\CategoryMapping;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Support\CatalogRequirements;
use App\Domain\Sync\Enums\PublishReason;
use App\Domain\Sync\Support\CategoryTreeSnapshot;
use App\Domain\Sync\Support\ListingPayload;
use App\Domain\Sync\Support\ListingProjection;
use App\Domain\Sync\Support\PublishIssue;
use DateTimeImmutable;

/**
 * PAZARAMA KATALOG — taksonomi ayrıştırma, yayın doğrulaması ve
 * `product/create` öğesi (S10). HTTP adapter'dadır; bu sınıf yan etkisizdir.
 *
 * KAYNAK: isortagim.pazarama.com "Ürün Ekleme (Create Product)", "Kategori
 * Listesi", "Kategori Özellik Bilgileri", "Marka(Brand)", "BatchId sorgu"
 * (24 Eki 2026 okundu; sayfalar Temmuz 2026'da güncellenmiş). GERÇEK
 * HESAPLA SINANMADI.
 *
 * KİMLİK: `Code` = BARKOD (zorunlu, istekte tekil) — içe aktarma ve
 * stok/fiyat da barkodla çalışır; barkodsuz varyant Pazarama'ya AÇILAMAZ.
 * `StockCode` = varyant SKU'su. GRUP: `GroupCode` = ürün SKU'su (belgedeki
 * `Nvarchar(10)` sınırı örneklerde uyulmuyor: 12 karakter ve 36 karakterlik
 * GUID örnekleri var — DOĞRULANMADI; sınır uygulanmadı).
 *
 * ÖZNİTELİK YALNIZ KİMLİKLE: `attributes[{attributeId, attributeValueId}]`
 * — serbest metin YOK. Liste dışı değer gönderilemez, eksik zorunlu
 * öznitelik tahmin edilmez (`CatalogRequirements`).
 *
 * GÜNCELLEME YOK: belgede ürün içeriği güncelleme ucu yok; onaylı üründe
 * yalnız stok/fiyat (mevcut uçlar) yürür.
 */
final class PazaramaCatalog
{
    public const VAT_RATES = [0, 1, 10, 20];

    public const MAX_NAME_LENGTH = 100;

    public const MAX_DISPLAY_NAME_LENGTH = 250;

    public const MAX_STOCK_CODE_LENGTH = 100;

    public const MAX_BARCODE_LENGTH = 500;

    public const SETTING_VAT_RATE = 'pazarama_vat_rate';

    public const SETTING_DESI = 'pazarama_desi';

    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings) {}

    // ───────────────────────────────────────────── taksonomi

    /**
     * `GET /category/getCategoryTree` — DÜZ liste (`id`, `parentId`,
     * `parentCategories[]`, `name`, `leaf`). Yalnız `leaf: true`
     * kategorilere ürün açılır.
     *
     * @param  array<string, mixed>  $body
     */
    public static function tree(array $body): CategoryTreeSnapshot
    {
        $flat = [];

        foreach ((array) ($body['data'] ?? []) as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }

            $name = trim((string) ($row['displayName'] ?? $row['name'] ?? ''));
            $parents = array_values(array_filter(array_map(
                static fn (mixed $p): string => is_string($p) ? trim($p) : '',
                (array) ($row['parentCategories'] ?? []),
            )));
            $parent = trim((string) ($row['parentId'] ?? ''));

            $flat[] = [
                'external_id' => (string) $row['id'],
                'parent_external_id' => $parent !== '' && $parent !== '00000000-0000-0000-0000-000000000000' ? $parent : null,
                'name' => $name,
                // Örnekte `parentCategories` kategorinin KENDİSİYLE biter.
                'path' => $parents !== [] ? implode(' > ', $parents) : $name,
                'is_leaf' => ($row['leaf'] ?? false) === true,
            ];
        }

        $fingerprint = array_map(static fn (array $c): string => $c['external_id'].'|'.($c['parent_external_id'] ?? '').'|'.$c['name'], $flat);
        sort($fingerprint);

        return new CategoryTreeSnapshot($flat, 'pazarama-'.substr(hash('sha256', implode("\n", $fingerprint)), 0, 16), new DateTimeImmutable);
    }

    /**
     * `GET /category/getCategoryWithAttributes?Id=` — `data.attributes[{id,
     * name, isVariantable, isRequired, attributeValues[{id, value}]}]`.
     * Değerler hep KİMLİKLE gider: tür `enum`.
     *
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    public static function attributes(array $body): array
    {
        $out = [];

        foreach ((array) ($body['data']['attributes'] ?? []) as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }

            $out[] = [
                'external_attribute_id' => (string) $row['id'],
                'name' => (string) ($row['displayName'] ?? $row['name'] ?? $row['id']),
                'is_required' => ($row['isRequired'] ?? false) === true,
                'is_variant_defining' => ($row['isVariantable'] ?? false) === true,
                'data_type' => 'enum',
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

        $title = trim((string) $product->title);

        if ($title === '' || mb_strlen($title) > self::MAX_DISPLAY_NAME_LENGTH) {
            $issues[] = new PublishIssue(PublishReason::TITLE_INVALID, $product->sku);
        }

        if (trim(strip_tags((string) $product->description)) === '') {
            $issues[] = new PublishIssue(PublishReason::DESCRIPTION_REQUIRED, $product->sku);
        }

        if (trim((string) $product->brand) === '') {
            $issues[] = new PublishIssue(PublishReason::BRAND_REQUIRED, $product->sku);
        }

        foreach ($this->settingIssues() as $issue) {
            $issues[] = $issue;
        }

        foreach ($product->variants as $variant) {
            foreach ($this->variantIssues($variant) as $issue) {
                $issues[] = $issue;
            }
        }

        return [...$issues, ...(new CatalogRequirements($product, $category))->issues()];
    }

    /** @return list<PublishIssue> */
    private function settingIssues(): array
    {
        $issues = [];

        if (! in_array($this->vatRate(), self::VAT_RATES, true)) {
            $issues[] = new PublishIssue(PublishReason::VAT_RATE_INVALID, self::SETTING_VAT_RATE);
        }

        if (($this->desi() ?? 0) < 1) {
            $issues[] = new PublishIssue(PublishReason::CHANNEL_SETTING_REQUIRED, self::SETTING_DESI);
        }

        return $issues;
    }

    /** @return list<PublishIssue> */
    private function variantIssues(Variant $variant): array
    {
        $issues = [];
        $sku = trim((string) $variant->sku);
        $barcode = trim((string) $variant->barcode);

        if ($sku === '' || mb_strlen($sku) > self::MAX_STOCK_CODE_LENGTH) {
            $issues[] = new PublishIssue(PublishReason::SKU_INVALID, $variant->sku, $variant->id);
        }

        // Barkod Pazarama'da ÜRÜN KİMLİĞİDİR (`Code`, zorunlu).
        if ($barcode === '') {
            $issues[] = new PublishIssue(PublishReason::BARCODE_REQUIRED, $variant->sku, $variant->id);
        } elseif (mb_strlen($barcode) > self::MAX_BARCODE_LENGTH) {
            $issues[] = new PublishIssue(PublishReason::BARCODE_INVALID, $variant->sku, $variant->id);
        }

        if (ChannelImages::urlsFor($variant, 'pazarama') === []) {
            $issues[] = new PublishIssue(PublishReason::IMAGE_REQUIRED, $variant->sku, $variant->id);
        }

        if ($variant->price === null || (float) $variant->price <= 0) {
            $issues[] = new PublishIssue(PublishReason::PRICE_INVALID, $variant->sku, $variant->id);
        }

        if (! in_array(strtoupper((string) ($variant->currency ?? 'TRY')), ['TRY', 'TL'], true)) {
            $issues[] = new PublishIssue(PublishReason::CURRENCY_UNSUPPORTED, $variant->sku, $variant->id);
        }

        return $issues;
    }

    // ───────────────────────────────────────────── yük

    /**
     * Tek varyantın `product/create` öğesi (belge gövdesi). Marka kimliği
     * adapter'da (kanal araması) çözülür ve buraya verilir.
     *
     * @return array<string, mixed>
     */
    public function createItem(ListingPayload $payload, string $brandId): array
    {
        [$variant, $product, $category, $requirements] = $this->resolve($payload);
        $listing = $payload->listing;

        $issues = [...$this->variantIssues($variant), ...$this->settingIssues(), ...array_filter(
            $requirements->issues(),
            static fn (PublishIssue $i): bool => $i->variantId === null || $i->variantId === $variant->id,
        )];

        $title = trim($payload->title);

        if ($title === '' || mb_strlen($title) > self::MAX_DISPLAY_NAME_LENGTH) {
            $issues[] = new PublishIssue(PublishReason::TITLE_INVALID, $product->sku);
        }

        $description = trim((string) ($payload->description ?? $product->description));

        if (trim(strip_tags($description)) === '') {
            $issues[] = new PublishIssue(PublishReason::DESCRIPTION_REQUIRED, $product->sku);
        }

        if ($issues !== []) {
            throw new ListingNotPublishable(implode(' ', array_map(static fn (PublishIssue $i): string => $i->describe(), $issues)), $issues[0]->reason);
        }

        $sale = (float) ListingProjection::salePrice($listing);

        if ($sale <= 0) {
            throw ListingNotPublishable::because(PublishReason::PRICE_INVALID, $variant->sku);
        }

        $list = max((float) ListingProjection::listPrice($listing), $sale);

        return [
            // `Name` 100 karakter; uzun başlık `DisplayName`'de (250) tam kalır.
            // Kırpılan YALNIZ iç ad — vitrinde görünen ad kırpılmaz.
            'Name' => mb_substr($title, 0, self::MAX_NAME_LENGTH),
            'DisplayName' => $title,
            'Description' => $description,
            'BrandId' => $brandId,
            'Desi' => $this->desi(),
            'Code' => trim((string) $variant->barcode),
            'GroupCode' => trim((string) $product->sku),
            'StockCount' => ListingProjection::quantity($listing),
            'StockCode' => trim((string) $variant->sku),
            'VatRate' => $this->vatRate(),
            'ListPrice' => round($list, 2),
            'SalePrice' => round($sale, 2),
            'currencyType' => 'TRY',
            'CategoryId' => (string) $category->external_id,
            'images' => array_map(
                static fn (string $url): array => ['imageurl' => $url],
                ChannelImages::urlsFor($variant, 'pazarama'),
            ),
            'attributes' => array_map(
                static fn (array $r): array => [
                    'attributeId' => (string) $r['attribute']->external_attribute_id,
                    'attributeValueId' => (string) $r['value_id'],
                ],
                array_values(array_filter(
                    [...$requirements->descriptiveAttributes(), ...$requirements->variantAttributes($variant)],
                    // Serbest metin Pazarama'da YOK — kimliksiz değer gönderilmez.
                    static fn (array $r): bool => $r['value_id'] !== null,
                )),
            ),
            'deliveries' => [],
        ];
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
            ->where('channel_type_code', 'pazarama')
            ->first();

        $category = $mapping === null ? null : ChannelCategory::query()->find($mapping->channel_category_id);

        if ($category === null) {
            throw ListingNotPublishable::because(PublishReason::CATEGORY_MAPPING_REQUIRED, (string) $product->internal_category_id);
        }

        return [$variant, $product, $category, new CatalogRequirements($product, $category)];
    }

    public function vatRate(): ?int
    {
        $raw = $this->settings[self::SETTING_VAT_RATE] ?? null;

        return is_numeric($raw) ? (int) $raw : null;
    }

    public function desi(): ?int
    {
        $raw = $this->settings[self::SETTING_DESI] ?? null;

        return is_numeric($raw) ? (int) $raw : null;
    }
}
