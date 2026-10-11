<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Hepsiburada\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Support\ChannelImages;
use App\Domain\Channels\Exceptions\ListingNotPublishable;
use App\Domain\Channels\Models\CategoryMapping;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Support\CatalogRequirements;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\Ean13;
use App\Domain\Sync\Enums\PublishReason;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\CategoryTreeSnapshot;
use App\Domain\Sync\Support\ListingPayload;
use App\Domain\Sync\Support\ListingProjection;
use App\Domain\Sync\Support\PublishIssue;
use DateTimeImmutable;
use Illuminate\Http\Client\Response;

/**
 * Hepsiburada KATALOG (MPOP) — taksonomi, yayın doğrulaması, ürün yükleme
 * ve durum okuma (S3).
 *
 * KAYNAK: developers.hepsiburada.com "Katalog Ürün Giriş Önemli Bilgiler"
 * (11 Eki 2026, portalın genel belge API'sinden okundu) +
 * `docs/hepsiburada-openapi/katalog.json`. GERÇEK HESAPLA SINANMADI.
 *
 * KİMLİK: satıcının `merchantSku`'su (BÜYÜK HARF, boşluksuz) — gönderim
 * anında bilinir. Aynı `merchantSku` ile yeniden yükleme HB'de GÜNCELLEME
 * sayılır ("Ürün bilgileri eksik / Eşleşen / Görev açılmış" durumlarında);
 * katalog sürecindeki ya da satışa hazır üründe HB reddeder ("Can not
 * update product in matched or in catalog progress status"). HB kodu
 * (`hbSku`) onaydan sonra öğrenilir ve listing kimliği ona geçer
 * (mevcut stok/fiyat/içe aktarma yolları `hbSku` ile çalışır).
 *
 * VARYANT: her varyant AYRI bir öğe; aynı `VaryantGroupID` (ürün SKU'su)
 * HB'de tek varyantlı ürün olarak gruplar. Varyantın KENDİ seçenek
 * değerleri, KENDİ barkodu, fiyatı, stoğu ve görselleri gider.
 *
 * ⚠️ ONAYDAN SONRA ÜRÜN GÖNDERİLEN FİYAT/STOKLA OTOMATİK SATIŞA AÇILIR
 * (belge). Fiyat/stok varyantın kanonik değeridir (`ListingProjection`).
 */
final class HepsiburadaCatalog
{
    /**
     * Ürünün kendi alanlarından doldurulan temel öznitelikler (belge: istek
     * dosyası tablosu). Kategori yanıtındaki gerçek kimlikleri DOĞRULANMADI;
     * farklı gelirse zorunlu öznitelik "değer gerekli" diye görünür —
     * tahminle doldurulmaz.
     */
    public const AUTO_FILLED = [
        'merchantSku', 'VaryantGroupID', 'Barcode', 'UrunAdi', 'UrunAciklamasi', 'Marka',
        'tax_vat_rate', 'price', 'stock', 'Image1', 'Image2', 'Image3', 'Image4', 'Image5', 'Video1',
    ];

    public const MAX_IMAGES = 5;

    public const VAT_RATES = [0, 1, 10, 20];

    public const SETTING_VAT_RATE = 'vat_rate';

    private const CATEGORY_PAGE_SIZE = 2000;

    private const VALUE_PAGE_SIZE = 1000;

    private const MAX_PAGES = 50;

    /** @param array<string, string> $headers */
    public function __construct(
        private readonly ChannelHttpClient $client,
        private readonly string $host,
        private readonly string $merchantId,
        private readonly array $headers,
        private readonly array $settings,
    ) {}

    // ───────────────────────────────────────────── taksonomi

    public function fetchCategoryTree(): CategoryTreeSnapshot
    {
        $flat = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->client->get($this->host.'/api/categories/get-all-categories', [
                'leaf' => 'true', 'status' => 'ACTIVE', 'available' => 'true',
                'page' => $page, 'size' => self::CATEGORY_PAGE_SIZE,
            ], headers: $this->headers);

            $response->throw();

            foreach ((array) ($response->json('data') ?? []) as $row) {
                if (! is_array($row) || ! isset($row['categoryId'])) {
                    continue;
                }

                $paths = array_values(array_filter((array) ($row['paths'] ?? []), 'is_string'));

                $flat[] = [
                    'external_id' => (string) $row['categoryId'],
                    'parent_external_id' => isset($row['parentCategoryId']) ? (string) $row['parentCategoryId'] : null,
                    'name' => (string) ($row['displayName'] ?? $row['name'] ?? ''),
                    'path' => $paths === [] ? (string) ($row['name'] ?? '') : implode(' > ', $paths),
                    // Yalnız `leaf && available` istendi; ürün yalnız bunlara açılır.
                    'is_leaf' => (bool) ($row['leaf'] ?? true),
                ];
            }

            if ((bool) ($response->json('last') ?? true) || $page + 1 >= (int) ($response->json('totalPages') ?? 1)) {
                break;
            }
        }

        $fingerprint = array_map(static fn (array $c): string => $c['external_id'].'|'.($c['parent_external_id'] ?? '').'|'.$c['name'], $flat);
        sort($fingerprint);

        return new CategoryTreeSnapshot($flat, 'hb-'.substr(hash('sha256', implode("\n", $fingerprint)), 0, 16), new DateTimeImmutable);
    }

    /** @return list<array<string, mixed>> */
    public function fetchCategoryAttributes(string $categoryId): array
    {
        $response = $this->client->get($this->host.'/api/categories/'.rawurlencode($categoryId).'/attributes', headers: $this->headers);
        $response->throw();

        $out = [];

        foreach (['baseAttributes' => false, 'attributes' => false, 'variantAttributes' => true] as $group => $variantDefining) {
            foreach ((array) ($response->json("data.{$group}") ?? []) as $row) {
                if (! is_array($row) || ! isset($row['id'])) {
                    continue;
                }

                $isEnum = strtolower((string) ($row['type'] ?? '')) === 'enum';

                $out[] = [
                    'external_attribute_id' => (string) $row['id'],
                    'name' => (string) ($row['name'] ?? $row['id']),
                    'is_required' => (bool) ($row['mandatory'] ?? false),
                    'is_variant_defining' => $variantDefining,
                    'data_type' => $isEnum ? 'enum' : 'string',
                    'allowed_values' => $isEnum ? $this->fetchValues($categoryId, (string) $row['id']) : [],
                ];
            }
        }

        return $out;
    }

    /** @return list<array{id: string, label: string}> */
    private function fetchValues(string $categoryId, string $attributeId): array
    {
        $values = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->client->get(
                $this->host.'/api/categories/'.rawurlencode($categoryId).'/attribute/'.rawurlencode($attributeId).'/values',
                ['page' => $page, 'size' => self::VALUE_PAGE_SIZE],
                headers: $this->headers,
            );
            $response->throw();

            // Belge: veri modeli {id, value}; "value alanının kullanılması gerekir".
            $rows = (array) ($response->json('data') ?? []);
            $rows = isset($rows['content']) && is_array($rows['content']) ? $rows['content'] : $rows;

            foreach ($rows as $row) {
                if (is_array($row) && isset($row['value'])) {
                    $values[] = ['id' => (string) ($row['id'] ?? $row['value']), 'label' => (string) $row['value']];
                }
            }

            if (count($rows) < self::VALUE_PAGE_SIZE) {
                break;
            }
        }

        return $values;
    }

    // ───────────────────────────────────────────── doğrulama

    /** @return list<PublishIssue> */
    public function validate(Product $product, ChannelCategory $category): array
    {
        $issues = [];
        $product->loadMissing('variants');

        if (trim((string) $product->brand) === '') {
            $issues[] = new PublishIssue(PublishReason::BRAND_REQUIRED, $product->sku);
        }

        if (trim((string) $product->title) === '') {
            $issues[] = new PublishIssue(PublishReason::TITLE_INVALID, $product->sku);
        }

        // Belge: "Her üründe muhakkak bir açıklama olmalıdır."
        if (trim(strip_tags((string) $product->description)) === '') {
            $issues[] = new PublishIssue(PublishReason::DESCRIPTION_REQUIRED, $product->sku);
        }

        if (! in_array($this->vatRate(), self::VAT_RATES, true)) {
            $issues[] = new PublishIssue(PublishReason::VAT_RATE_INVALID, self::SETTING_VAT_RATE);
        }

        foreach ($product->variants as $variant) {
            foreach ($this->variantIssues($variant) as $issue) {
                $issues[] = $issue;
            }
        }

        return [...$issues, ...(new CatalogRequirements($product, $category, self::AUTO_FILLED))->issues()];
    }

    /** @return list<PublishIssue> */
    private function variantIssues(Variant $variant): array
    {
        $issues = [];

        // Belge: "Barkod 13 karakterden oluşup kendi içinde bir algoritmaya sahip olmalıdır."
        if (trim((string) $variant->barcode) === '') {
            $issues[] = new PublishIssue(PublishReason::BARCODE_REQUIRED, $variant->sku, $variant->id);
        } elseif (! Ean13::valid($variant->barcode)) {
            $issues[] = new PublishIssue(PublishReason::BARCODE_INVALID, $variant->sku, $variant->id);
        }

        // Belge/not: merchantSku büyük harf, boşluksuz, Türkçe karakter yok.
        if (! self::validMerchantSku((string) $variant->sku)) {
            $issues[] = new PublishIssue(PublishReason::SKU_INVALID, $variant->sku, $variant->id);
        }

        if (ChannelImages::urlsFor($variant, 'hepsiburada') === []) {
            $issues[] = new PublishIssue(PublishReason::IMAGE_REQUIRED, $variant->sku, $variant->id);
        }

        if ($variant->price === null || (float) $variant->price <= 0) {
            $issues[] = new PublishIssue(PublishReason::PRICE_INVALID, $variant->sku, $variant->id);
        }

        return $issues;
    }

    public static function validMerchantSku(string $sku): bool
    {
        return preg_match('/^[A-Za-z0-9._\-\/]+$/', $sku) === 1;
    }

    // ───────────────────────────────────────────── yükleme

    /**
     * Tek varyantın içe aktarma öğesi — eksik veri `ListingNotPublishable`.
     *
     * @return array<string, mixed>
     */
    public function item(ListingPayload $payload): array
    {
        $listing = $payload->listing;
        $listing->loadMissing('variant.product');
        $variant = $listing->variant;
        $product = $variant?->product;

        if ($variant === null || $product === null) {
            throw ListingNotPublishable::because(PublishReason::INVALID_VARIANT, (string) $listing->id);
        }

        $category = $this->category($product);
        $requirements = new CatalogRequirements($product, $category, self::AUTO_FILLED);

        $issues = [...$this->variantIssues($variant), ...array_filter(
            $requirements->issues(),
            static fn (PublishIssue $i): bool => $i->variantId === null || $i->variantId === $variant->id,
        )];

        if ($issues !== []) {
            throw new ListingNotPublishable(implode(' ', array_map(static fn (PublishIssue $i): string => $i->describe(), $issues)), $issues[0]->reason);
        }

        $price = ListingProjection::salePrice($listing);

        if ((float) $price <= 0) {
            throw ListingNotPublishable::because(PublishReason::PRICE_INVALID, $variant->sku);
        }

        $attributes = [
            'merchantSku' => self::merchantSku($variant),
            'VaryantGroupID' => strtoupper((string) $product->sku),
            'Barcode' => (string) $variant->barcode,
            'UrunAdi' => $payload->title,
            'UrunAciklamasi' => (string) ($payload->description ?? $product->description),
            'Marka' => trim((string) $product->brand),
            'tax_vat_rate' => (string) $this->vatRate(),
            // Belge: "Kuruş'lu fiyat girişinde virgül ile ayrılmalı … 14,50".
            'price' => number_format((float) $price, 2, ',', ''),
            'stock' => (string) ListingProjection::quantity($listing),
        ];

        foreach (array_slice(ChannelImages::urlsFor($variant, 'hepsiburada'), 0, self::MAX_IMAGES) as $i => $url) {
            $attributes['Image'.($i + 1)] = $url;
        }

        // Varyant belirleyiciler VARYANTIN KENDİ değerleri; betimleyiciler ürün düzeyi.
        foreach ([...$requirements->descriptiveAttributes(), ...$requirements->variantAttributes($variant)] as $resolved) {
            $attributes[(string) $resolved['attribute']->external_attribute_id] = $resolved['label'];
        }

        return [
            'categoryId' => (int) $category->external_id,
            'merchant' => $this->merchantId,
            'attributes' => $attributes,
        ];
    }

    /**
     * `POST /api/products/import` — JSON DOSYASI form-data (belge).
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function import(array $items): string
    {
        $response = $this->client->upload(
            $this->host.'/api/products/import',
            [],
            'file',
            (string) json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'integrator.json',
            headers: $this->headers,
        );

        $response->throw();
        $this->rejectUnsuccessful($response);

        $trackingId = $response->json('data.trackingId') ?? $response->json('trackingId');

        if (! is_string($trackingId) || $trackingId === '') {
            // 200 ama takip kodu yok: istek ALINMIŞ olabilir — belirsiz.
            throw new \RuntimeException('Hepsiburada ürün yüklemesi takip kodu döndürmedi.');
        }

        return $trackingId;
    }

    /**
     * `POST /api/products/check-product-status` — merchantSku → durum.
     *
     * @param  list<string>  $merchantSkus
     * @return array<string, string> MERCHANTSKU → productStatus
     */
    public function statuses(array $merchantSkus): array
    {
        $merchantSkus = array_values(array_unique(array_filter($merchantSkus)));

        if ($merchantSkus === []) {
            return [];
        }

        $out = [];

        foreach (array_chunk($merchantSkus, 100) as $chunk) {
            $response = $this->client->post(
                $this->host.'/api/products/check-product-status',
                [['merchant' => $this->merchantId, 'merchantSkuList' => $chunk]],
                headers: $this->headers,
            );
            $response->throw();

            foreach ((array) ($response->json('data') ?? []) as $merchantRow) {
                foreach ((array) ($merchantRow['skuStatusList'] ?? []) as $row) {
                    if (is_array($row) && isset($row['merchantSku'], $row['status'])) {
                        $out[strtoupper((string) $row['merchantSku'])] = strtoupper((string) $row['status']);
                    }
                }
            }
        }

        return $out;
    }

    /**
     * `GET /api/products/status/{trackingId}` — yüklemenin satır sonuçları.
     *
     * @return array<string, array{import: string, hb_sku: ?string, reasons: list<string>}> MERCHANTSKU →
     */
    public function trackingResults(string $trackingId): array
    {
        $response = $this->client->get(
            $this->host.'/api/products/status/'.rawurlencode($trackingId),
            ['page' => 0, 'size' => 1000],
            headers: $this->headers,
        );

        // Belge: 4000 "Trakıngıd bulunamadı" — süresi dolmuş/yanlış kod; uydurma.
        if ($response->status() === 404) {
            return [];
        }

        $response->throw();
        $out = [];

        foreach ((array) ($response->json('data') ?? []) as $row) {
            if (! is_array($row) || ! isset($row['merchantSku'])) {
                continue;
            }

            $reasons = [];

            foreach ((array) ($row['validationResults'] ?? []) as $v) {
                $reasons[] = trim(($v['attributeName'] ?? '').': '.($v['message'] ?? ''), ': ');
            }

            foreach ((array) ($row['importMessages'] ?? []) as $m) {
                if (strtoupper((string) ($m['severity'] ?? '')) === 'ERROR') {
                    $reasons[] = (string) ($m['message'] ?? '');
                }
            }

            foreach ((array) ($row['taskDetails'] ?? []) as $t) {
                if (isset($t['reason'])) {
                    $reasons[] = (string) $t['reason'];
                }
            }

            $out[strtoupper((string) $row['merchantSku'])] = [
                'import' => strtoupper((string) ($row['importStatus'] ?? '')),
                'hb_sku' => isset($row['hbSku']) && $row['hbSku'] !== '' ? (string) $row['hbSku'] : null,
                'reasons' => array_values(array_filter($reasons)),
            ];
        }

        return $out;
    }

    public static function merchantSku(Variant $variant): string
    {
        return strtoupper(trim((string) $variant->sku));
    }

    private function category(Product $product): ChannelCategory
    {
        $mapping = CategoryMapping::query()
            ->where('internal_category_id', (string) $product->internal_category_id)
            ->where('channel_type_code', 'hepsiburada')
            ->first();

        $category = $mapping === null ? null : ChannelCategory::query()->find($mapping->channel_category_id);

        if ($category === null) {
            throw ListingNotPublishable::because(PublishReason::CATEGORY_MAPPING_REQUIRED, (string) $product->internal_category_id);
        }

        return $category;
    }

    private function vatRate(): ?int
    {
        $raw = $this->settings[self::SETTING_VAT_RATE] ?? null;

        return is_numeric($raw) ? (int) $raw : null;
    }

    /** `success: false` gövdesi (ör. 3001 "Dosya içeriği geçersiz") kesin red. */
    private function rejectUnsuccessful(Response $response): void
    {
        if ($response->json('success') === false) {
            throw new ListingNotPublishable(
                'Hepsiburada yüklemeyi reddetti: '.mb_substr((string) ($response->json('message') ?? $response->json('code')), 0, 300),
                PublishReason::LISTING_SYNC_FAILED,
            );
        }
    }
}
