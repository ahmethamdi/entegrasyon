<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Shopify;

use App\Domain\Sync\Support\ListingPayload;
use App\Domain\Sync\Support\RemoteListing;
use App\Domain\Sync\Support\RemoteProduct;
use DateTimeImmutable;

/**
 * Kanonik listing ↔ Shopify ürün biçimi dönüşümü.
 *
 * V3.0 · §06.4 · v2.2 §7 (Adapter klasör yapısı · Mapper).
 *
 * Dönüşüm adapter'ın İÇİNDE durur: çekirdek "şunu gönder" der, "şu JSON'u
 * gönder" demez. Kanal alan adları değiştiğinde yalnızca bu dosya değişir.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ÜÇ KİMLİK — ÜÇÜ DE KALICI
 * ─────────────────────────────────────────────────────────────────────
 *   Product        gid://shopify/Product/123        → external_parent_id
 *     ProductVariant  gid://shopify/ProductVariant/456 → external_id
 *       InventoryItem   gid://shopify/InventoryItem/789 → channel_metadata
 *
 * `external_id` = VARIANT gid, product DEĞİL: bizde listing satırı VARYANT
 * başınadır (`UNIQUE(channel_connection_id, variant_id)`). Product gid
 * yazılsaydı üç varyantlı bir ürünün üç listing satırı AYNI `external_id`'yi
 * taşır ve `UNIQUE(channel_connection_id, external_id)` ikincisini
 * REDDEDERDİ (§07 · Etsy'de aynı tuzak).
 *
 * `inventory_item_gid` NEDEN AYRI SAKLANIR: stok yazma
 * `inventorySetOnHandQuantities` mutation'ı **variant gid'i KABUL ETMEZ**,
 * `inventoryItemId` ister. Her stok itmesinde variant → inventory item
 * çevrimi için ek bir GraphQL sorgusu atmak stok yolunu İKİ KATINA çıkarır
 * — ve o yol projenin en kritik yoludur (`inventory:high`, 45 sn). Kimlik
 * listing yaratılırken BİR KEZ okunur ve donar (§06.4).
 */
final class ShopifyProductMapper
{
    /**
     * `productSet` mutation'ının girdisi.
     *
     * TEK MUTATION, TEK ÇAĞRI: Shopify `productSet` ile ürünü ve
     * varyantlarını birlikte yazar. Ayrı `productCreate` +
     * `productVariantsBulkCreate` çağrılsaydı ara başarısızlıkta ürün
     * yaratılmış ama varyantı olmayan bir kabuk kalırdı — eBay'in üç adımlı
     * zincirindeki tuzağın aynısı (§13.2) ve Shopify'da buna gerek YOKTUR.
     *
     * @return array<string, mixed>
     */
    public static function toProductSetInput(ListingPayload $payload): array
    {
        $variant = $payload->listing->variant;

        $input = [
            'title' => $payload->title,
            // Yayın durumu: kanalda GÖRÜNÜR olmalı. Shopify'da onay süreci
            // YOKTUR (§04) — ürün ACTIVE yazıldığı anda canlıdır.
            'status' => 'ACTIVE',
        ];

        if ($payload->description !== null) {
            $input['descriptionHtml'] = $payload->description;
        }

        // Shopify'da kategori ZORUNLU DEĞİLDİR ve `product_type` serbest
        // metindir; taksonomi arayüzü bu yüzden HİÇ uygulanmaz (§04).
        // Satıcı bir iç kategori tanımladıysa olduğu gibi taşınır.
        if ($payload->categoryId !== null) {
            $input['productType'] = $payload->categoryId;
        }

        if ($variant !== null) {
            // SKU VARYANTTA YAŞAR, üründe değil. Shopify'ın veri modelinde
            // satılabilir birim ProductVariant'tır ve stok/fiyat oraya
            // bağlanır.
            $input['variants'] = [[
                'sku' => $variant->sku,
                // Fiyat STRING taşınır — para float taşımaz (yuvarlama
                // kuruş kayması üretir). `decimal(12,2)` PHP'ye zaten
                // string döner; (float) dönüşümü YAPILMAZ.
                'price' => (string) $variant->price,
                // Stok BURADA GÖNDERİLMEZ ve bu bilinçlidir: içerik
                // aktarımı stoğa dokunmaz (v2.2 · katalog kuralı).
                // Stok kendi domainindedir ve `PushInventory` üzerinden
                // mutlak değerle gider; burada yazılsaydı içerik
                // düzenlemesi her seferinde stoğu da ezerdi.
                'inventoryItem' => ['tracked' => true],
            ]];
        }

        // Kanala özgü öznitelikler kanonik alanları EZMEZ; çakışma olursa
        // kanonik kazanır, yoksa panelde görünen ile gönderilen ayrışır.
        return [...$payload->attributes, ...$input];
    }

    /**
     * `productSet` yanıtından kalıcı kimlikler.
     *
     * ÜÇÜ DE ÇIKARILIR ve `AdapterResult` ile çekirdeğe taşınır; adapter
     * veritabanına YAZMAZ (v2.2 · "adapter yan etkisizdir").
     *
     * VARYANT BULUNAMAZSA `external_id` YAZILMAZ. Boş dize yazılsaydı
     * sonraki tur "bu listing kanalda var" sanır ve update çağırır; Shopify
     * boş gid'i tanımaz, `userErrors` döner ve o hata KALICIDIR — listing
     * "düzeltilemez" damgasıyla ölür.
     *
     * @param  array<string, mixed>  $product  `productSet.product` bloğu
     * @return array<string, mixed> `AdapterResult::success()` verisi
     */
    public static function toIdentityResult(array $product, ?string $shopDomain = null): array
    {
        $productGid = isset($product['id']) ? (string) $product['id'] : null;
        $variant = self::firstVariant($product);

        $variantGid = isset($variant['id']) ? (string) $variant['id'] : null;
        $inventoryItemGid = isset($variant['inventoryItem']['id'])
            ? (string) $variant['inventoryItem']['id']
            : null;

        $data = [];

        if ($variantGid !== null && $variantGid !== '') {
            $data['external_id'] = $variantGid;
        }

        if ($productGid !== null && $productGid !== '') {
            $data['external_parent_id'] = $productGid;

            // Satıcının panelde tıklayacağı adres. Admin adresi seçildi
            // (storefront değil): satıcı ürünü DÜZENLEMEK için tıklar ve
            // storefront adresi taslak üründe 404 döner.
            if ($shopDomain !== null && $shopDomain !== '') {
                $numericId = self::numericIdFrom($productGid);

                if ($numericId !== null) {
                    $data['external_url'] = "https://{$shopDomain}/admin/products/{$numericId}";
                }
            }
        }

        // ⚠️ STOK YAZMA HEDEFİ — kaybedilirse stok bir daha gönderilemez.
        if ($inventoryItemGid !== null && $inventoryItemGid !== '') {
            $data['channel_metadata'] = ['inventory_item_gid' => $inventoryItemGid];
        }

        return $data;
    }

    /**
     * Shopify varyant düğümünden İÇE AKTARILABİLİR ürün.
     *
     * V3.0 · §06 · slice 1.4 · v2.2 §13 · Faz 3 · madde 5.
     *
     * `toRemoteListing()` ile AYNI gövdeyi okur ama FARKLI soruyu
     * cevaplar: o "benim listemin kanaldaki hâli ne", bu "kanaldaki bu
     * ürünü kataloğuma nasıl yazarım". Çıpa bu yüzden gid değil **SKU**'dur.
     *
     * ⚠️ VARYANT DÜĞÜMÜNDEN OKUNUR, ÜRÜNDEN DEĞİL. Bizim kanonik modelimizde
     * satılabilir birim VARYANTTIR ve SKU orada yaşar; ürün düğümünden
     * okunsaydı çok varyantlı bir Shopify ürünü tek bir kanonik ürüne
     * çökerdi ve varyantların SKU'ları KAYBOLURDU.
     *
     * ⚠️ SKU BOŞSA `null` YAZILIR, UYDURULMAZ. Shopify'da SKU zorunlu
     * DEĞİLDİR. Kanal kimliğini (gid) SKU yapmak, satıcı aynı ürünü kendi
     * SKU'suyla yüklediğinde KOPYA ürün üretirdi ve iki satır ayrı ayrı
     * senkronlanırdı. `RemoteProduct::isImportable()` bu satırı eler ve
     * içe aktarma onu ADIYLA raporlar — sessizce düşmez (§13 · madde 5).
     *
     * ⚠️ FİYAT VARYANTIN `price` ALANINDAN OKUNUR. Shopify'da
     * `compareAtPrice` üstü çizili fiyattır; `price` gerçek satış
     * fiyatıdır ve kanonik alana yazılacak olan odur. Woo'da durum TERSTİR
     * (`regular_price` liste fiyatı, `price` indirimliyi taşır) — o kanalın
     * mapper'ındaki kural buraya KOPYALANMAZ.
     *
     * @param  array<string, mixed>  $variant
     */
    public static function toRemoteProduct(array $variant): RemoteProduct
    {
        $sku = isset($variant['sku']) ? trim((string) $variant['sku']) : '';
        $product = is_array($variant['product'] ?? null) ? $variant['product'] : [];

        return new RemoteProduct(
            // Kanal kimliği VARYANT gid'idir — `external_id` ile aynı çıpa.
            externalId: (string) ($variant['id'] ?? ''),
            sku: $sku !== '' ? $sku : null,
            title: isset($product['title']) ? (string) $product['title'] : null,
            // Fiyat STRING kalır — float dönüşümü kuruş kayması üretir.
            price: isset($variant['price']) && $variant['price'] !== ''
                ? (string) $variant['price']
                : null,
            quantity: isset($variant['inventoryQuantity']) && $variant['inventoryQuantity'] !== null
                ? (int) $variant['inventoryQuantity']
                : null,
            description: isset($product['descriptionHtml']) && $product['descriptionHtml'] !== ''
                ? (string) $product['descriptionHtml']
                : null,
            // Shopify'da marka `vendor` alanıdır ve ÜRÜN seviyesindedir.
            // Boş dize DEĞİL null: boş dize bir marka adı değildir ve
            // panel "markası var ama boş" gösterirdi.
            brand: self::nonEmptyString($product['vendor'] ?? null),
            barcode: self::nonEmptyString($variant['barcode'] ?? null),
            status: isset($product['status']) ? (string) $product['status'] : null,
            raw: $variant,
        );
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Shopify varyant düğümünden uzak gözlem.
     *
     * MUTABAKAT BUNU OKUR (§10 · üçüncü sürüm alanı). Stok `inventoryQuantity`
     * alanındadır ve konuma göre toplamdır; fiyat varyantın kendisindedir.
     *
     * @param  array<string, mixed>  $variant
     */
    public static function toRemoteListing(array $variant, ?string $shopDomain = null): RemoteListing
    {
        $productGid = isset($variant['product']['id']) ? (string) $variant['product']['id'] : null;

        return new RemoteListing(
            externalId: (string) ($variant['id'] ?? ''),
            title: isset($variant['product']['title'])
                ? (string) $variant['product']['title']
                : (isset($variant['title']) ? (string) $variant['title'] : null),
            quantity: isset($variant['inventoryQuantity']) && $variant['inventoryQuantity'] !== null
                ? (int) $variant['inventoryQuantity']
                : null,
            // Fiyat STRING kalır — float dönüşümü kuruş kayması üretir.
            price: isset($variant['price']) && $variant['price'] !== ''
                ? (string) $variant['price']
                : null,
            status: isset($variant['product']['status'])
                ? (string) $variant['product']['status']
                : null,
            url: $productGid !== null && $shopDomain !== null
                ? self::adminUrl($shopDomain, $productGid)
                : null,
            raw: $variant,
            observedAt: new DateTimeImmutable,
        );
    }

    /**
     * Yanıttaki ilk varyant düğümü.
     *
     * `productSet` varyantları `variants.nodes` altında döndürür. Bizde
     * listing varyant başına olduğu için TEK varyant gönderilir ve TEK
     * varyant beklenir.
     *
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private static function firstVariant(array $product): array
    {
        $nodes = $product['variants']['nodes'] ?? null;

        if (is_array($nodes) && isset($nodes[0]) && is_array($nodes[0])) {
            return $nodes[0];
        }

        return [];
    }

    private static function adminUrl(string $shopDomain, string $productGid): ?string
    {
        $numericId = self::numericIdFrom($productGid);

        return $numericId === null
            ? null
            : "https://{$shopDomain}/admin/products/{$numericId}";
    }

    /**
     * `gid://shopify/Product/123` → `123`.
     *
     * KİMLİK SAYIYA ÇEVRİLMEZ, yalnızca ADRES İÇİN son parça alınır.
     * Trendyol'daki "kimlik barkoddur ve sayıya çevrilmez" kuralının
     * aynısı: `(int)` dönüşümü gid'in tamamına uygulansaydı `0` çıkardı ve
     * istek yanlış ürüne giderdi. Saklanan değer HER ZAMAN tam gid'dir.
     */
    private static function numericIdFrom(string $gid): ?string
    {
        $parts = explode('/', $gid);
        $last = end($parts);

        return is_string($last) && $last !== '' && ctype_digit($last) ? $last : null;
    }
}
