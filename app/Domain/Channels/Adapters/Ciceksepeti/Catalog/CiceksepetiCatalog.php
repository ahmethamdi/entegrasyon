<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Ciceksepeti\Catalog;

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
 * ÇİÇEKSEPETİ KATALOG — taksonomi ayrıştırma, yayın doğrulaması, `POST
 * /Products` ve `PUT /Products` öğeleri (S10). HTTP adapter'dadır; bu sınıf
 * yan etkisizdir.
 *
 * KAYNAK: ciceksepeti.dev "Ürünlerin alınması/yüklenmesi", "Ürünlerin
 * güncellenmesi", "Kategori", "Kategori özellik" (8 Eki 2026 okundu, API
 * notları §5). GERÇEK HESAPLA SINANMADI.
 *
 * KİMLİK: `stockCode` = varyant SKU'su (içe aktarma ve stok/fiyat da
 * bununla). GRUP: `mainProductCode` = ürün SKU'su (varyantlar aynı değeri
 * alır). Barkod isteğe bağlı (3–50 karakter).
 *
 * MARKA VE KDV ALANI YOK (belge). Öznitelikler `Attributes[{Id, ValueId,
 * TextLength}]` — değer HEP kimlikle; "Kişiselleştirilebilir Özellik"
 * (müşteriden yazı alınan ürün) taksonomiye alınmaz.
 *
 * GÜNCELLEME: `PUT /Products` açmayla aynı zorunlu alanlar + `isActive`;
 * belgeye göre öznitelik ve barkod API'den DEĞİŞTİRİLEMEZ — gönderilen
 * değerler açılıştakilerle aynı kalır (DOĞRULANMADI: farklı değerin
 * reddedilip reddedilmediği).
 */
final class CiceksepetiCatalog
{
    public const MAX_NAME_LENGTH = 255;

    /** Belge 20.000 ve 7.750 diye çelişkili — küçük değer (API notları §5, tuzak 10). */
    public const MAX_DESCRIPTION_LENGTH = 7750;

    public const MIN_BARCODE_LENGTH = 3;

    public const MAX_BARCODE_LENGTH = 50;

    public const SETTING_DELIVERY_TYPE = 'ciceksepeti_delivery_type';

    public const SETTING_DELIVERY_MESSAGE_TYPE = 'ciceksepeti_delivery_message_type';

    /** `deliveryType`: 1 servis aracı, 2 kargo, 3 ikisi. */
    public const DELIVERY_TYPES = [
        '1' => 'Servis aracıyla',
        '2' => 'Kargoyla',
        '3' => 'Servis aracı ve kargo',
    ];

    /** `deliveryMessageType` — teslim süresi (belgedeki kod listesi). */
    public const DELIVERY_MESSAGE_TYPES = [
        '1' => 'Çiçek servisi',
        '4' => 'Aynı gün',
        '18' => '1–2 gün',
        '5' => '1–3 gün',
        '6' => '1–5 gün',
        '7' => '1–7 gün',
        '13' => '3–5 gün',
        '19' => '1–10 iş günü',
    ];

    private const PERSONALIZABLE_TYPE = 'Kişiselleştirilebilir Özellik';

    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings) {}

    // ───────────────────────────────────────────── taksonomi

    /**
     * `GET /Categories` — iç içe ağaç (`id`, `name`, `parentCategoryId`,
     * `subCategories`). Yalnız EN ALT kırılım kabul edilir.
     *
     * @param  array<string, mixed>|list<mixed>  $body
     */
    public static function tree(array $body): CategoryTreeSnapshot
    {
        $flat = [];
        $nodes = array_is_list($body) ? $body : (array) ($body['categories'] ?? $body['Categories'] ?? []);
        self::flatten($nodes, null, [], $flat);

        $fingerprint = array_map(static fn (array $c): string => $c['external_id'].'|'.($c['parent_external_id'] ?? '').'|'.$c['name'], $flat);
        sort($fingerprint);

        return new CategoryTreeSnapshot($flat, 'ciceksepeti-'.substr(hash('sha256', implode("\n", $fingerprint)), 0, 16), new DateTimeImmutable);
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
            $name = trim((string) ($node['name'] ?? ''));
            $children = is_array($node['subCategories'] ?? null) ? $node['subCategories'] : [];
            $current = [...$path, $name];

            $into[] = [
                'external_id' => $id,
                'parent_external_id' => $parentId,
                'name' => $name,
                'path' => implode(' > ', $current),
                'is_leaf' => $children === [],
            ];

            if ($children !== []) {
                self::flatten($children, $id, $current, $into);
            }
        }
    }

    /**
     * `GET /Categories/{id}/attributes` — `categoryAttributes[{attributeId,
     * attributeName, required, varianter, type, attributeValues[{id, name}]}]`.
     *
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    public static function attributes(array $body): array
    {
        $out = [];

        foreach ((array) ($body['categoryAttributes'] ?? []) as $row) {
            if (! is_array($row) || ! isset($row['attributeId'])) {
                continue;
            }

            // Kişiye özel ürün özelliği müşteriden gelir; satıcının değeri yok.
            if (trim((string) ($row['type'] ?? '')) === self::PERSONALIZABLE_TYPE) {
                continue;
            }

            $out[] = [
                'external_attribute_id' => (string) $row['attributeId'],
                'name' => (string) ($row['attributeName'] ?? $row['attributeId']),
                'is_required' => ($row['required'] ?? false) === true,
                'is_variant_defining' => ($row['varianter'] ?? false) === true,
                'data_type' => 'enum',
                'allowed_values' => array_values(array_filter(array_map(
                    static fn (mixed $v): ?array => is_array($v) && isset($v['id']) ? ['id' => (string) $v['id'], 'label' => (string) ($v['name'] ?? '')] : null,
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

        if ($title === '' || mb_strlen($title) > self::MAX_NAME_LENGTH) {
            $issues[] = new PublishIssue(PublishReason::TITLE_INVALID, $product->sku);
        }

        if (trim(strip_tags((string) $product->description)) === '') {
            $issues[] = new PublishIssue(PublishReason::DESCRIPTION_REQUIRED, $product->sku);
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

        if ($this->deliveryType() === null) {
            $issues[] = new PublishIssue(PublishReason::CHANNEL_SETTING_REQUIRED, self::SETTING_DELIVERY_TYPE);
        }

        if ($this->deliveryMessageType() === null) {
            $issues[] = new PublishIssue(PublishReason::CHANNEL_SETTING_REQUIRED, self::SETTING_DELIVERY_MESSAGE_TYPE);
        }

        return $issues;
    }

    /** @return list<PublishIssue> */
    private function variantIssues(Variant $variant): array
    {
        $issues = [];
        $sku = trim((string) $variant->sku);
        $barcode = trim((string) $variant->barcode);

        if ($sku === '') {
            $issues[] = new PublishIssue(PublishReason::SKU_INVALID, $variant->sku, $variant->id);
        }

        if ($barcode !== '' && (mb_strlen($barcode) < self::MIN_BARCODE_LENGTH || mb_strlen($barcode) > self::MAX_BARCODE_LENGTH)) {
            $issues[] = new PublishIssue(PublishReason::BARCODE_INVALID, $variant->sku, $variant->id);
        }

        if (ChannelImages::urlsFor($variant, 'ciceksepeti') === []) {
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
     * Tek varyantın `POST /Products` öğesi. `isActive` yalnız güncellemede.
     *
     * @return array<string, mixed>
     */
    public function item(ListingPayload $payload, bool $forUpdate = false): array
    {
        [$variant, $product, $category, $requirements] = $this->resolve($payload);
        $listing = $payload->listing;

        $issues = [...$this->variantIssues($variant), ...$this->settingIssues(), ...array_filter(
            $requirements->issues(),
            static fn (PublishIssue $i): bool => $i->variantId === null || $i->variantId === $variant->id,
        )];

        $title = trim($payload->title);
        $description = trim((string) ($payload->description ?? $product->description));

        if ($title === '' || mb_strlen($title) > self::MAX_NAME_LENGTH) {
            $issues[] = new PublishIssue(PublishReason::TITLE_INVALID, $product->sku);
        }

        // Uzun açıklama KIRPILMAZ: yarım cümleyle biten metin fark edilmeden yayına çıkardı.
        if (trim(strip_tags($description)) === '' || mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            $issues[] = new PublishIssue(PublishReason::DESCRIPTION_REQUIRED, $product->sku);
        }

        if ($issues !== []) {
            throw new ListingNotPublishable(implode(' ', array_map(static fn (PublishIssue $i): string => $i->describe(), $issues)), $issues[0]->reason);
        }

        $sale = (float) ListingProjection::salePrice($listing);

        if ($sale <= 0) {
            throw ListingNotPublishable::because(PublishReason::PRICE_INVALID, $variant->sku);
        }

        $list = (float) ListingProjection::listPrice($listing);
        $discount = $list > 0 ? ($list - $sale) / $list : 0.0;
        $barcode = trim((string) $variant->barcode);

        return array_filter([
            'productName' => $title,
            'mainProductCode' => trim((string) $product->sku),
            'stockCode' => trim((string) $variant->sku),
            'categoryId' => self::numeric((string) $category->external_id),
            'description' => $description,
            'deliveryType' => (int) $this->deliveryType(),
            'deliveryMessageType' => (int) $this->deliveryMessageType(),
            'stockQuantity' => ListingProjection::quantity($listing),
            'salesPrice' => round($sale, 2),
            // Liste-satış farkı %1–%80 dışında kanal reddeder (tuzak 9): o
            // aralıkta değilse liste fiyatı GÖNDERİLMEZ.
            'listPrice' => $discount >= 0.01 && $discount <= 0.80 ? round($list, 2) : null,
            'barcode' => $barcode !== '' ? $barcode : null,
            'images' => ChannelImages::urlsFor($variant, 'ciceksepeti'),
            'Attributes' => array_map(
                static fn (array $r): array => [
                    'Id' => self::numeric((string) $r['attribute']->external_attribute_id),
                    'ValueId' => self::numeric((string) $r['value_id']),
                    'TextLength' => 0,
                ],
                array_values(array_filter(
                    [...$requirements->descriptiveAttributes(), ...$requirements->variantAttributes($variant)],
                    static fn (array $r): bool => $r['value_id'] !== null,
                )),
            ),
            'isActive' => $forUpdate ? true : null,
        ], static fn (mixed $v): bool => $v !== null);
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
            ->where('channel_type_code', 'ciceksepeti')
            ->first();

        $category = $mapping === null ? null : ChannelCategory::query()->find($mapping->channel_category_id);

        if ($category === null) {
            throw ListingNotPublishable::because(PublishReason::CATEGORY_MAPPING_REQUIRED, (string) $product->internal_category_id);
        }

        return [$variant, $product, $category, new CatalogRequirements($product, $category)];
    }

    public function deliveryType(): ?string
    {
        $raw = trim((string) ($this->settings[self::SETTING_DELIVERY_TYPE] ?? ''));

        return isset(self::DELIVERY_TYPES[$raw]) ? $raw : null;
    }

    public function deliveryMessageType(): ?string
    {
        $raw = trim((string) ($this->settings[self::SETTING_DELIVERY_MESSAGE_TYPE] ?? ''));

        return isset(self::DELIVERY_MESSAGE_TYPES[$raw]) ? $raw : null;
    }

    private static function numeric(string $id): int|string
    {
        return ctype_digit($id) ? (int) $id : $id;
    }
}
