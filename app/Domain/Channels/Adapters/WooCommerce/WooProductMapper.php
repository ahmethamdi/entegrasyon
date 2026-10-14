<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\WooCommerce;

use App\Domain\Sync\Support\ListingPayload;
use App\Domain\Sync\Support\RemoteListing;
use App\Domain\Sync\Support\RemoteProduct;
use DateTimeImmutable;

/**
 * Kanonik listing ↔ Woo ürün biçimi dönüşümü.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · Adapter klasör yapısı (Mapper).
 *
 * Dönüşüm adapter'ın İÇİNDE durur: çekirdek "şunu gönder" der, "şu JSON'u
 * gönder" demez. Kanal alan adları değiştiğinde yalnızca bu dosya değişir.
 */
final class WooProductMapper
{
    /**
     * Kanonik yükten Woo ürün gövdesi.
     *
     * @return array<string, mixed>
     */
    public static function toWooProduct(ListingPayload $payload): array
    {
        $variant = $payload->listing->variant;

        $product = [
            'name' => $payload->title,
            'type' => 'simple',
            'status' => 'publish',
            // manage_stock ZORUNLU: kapalıyken Woo stock_quantity alanını
            // sessizce yok sayar ve senkron başarılı görünürken hiçbir şey
            // değişmez.
            'manage_stock' => true,
        ];

        if ($payload->description !== null) {
            $product['description'] = $payload->description;
        }

        if ($variant !== null) {
            $product['sku'] = $variant->sku;
        }

        // Woo'da taksonomi doğrulaması yoktur; kategori serbesttir.
        // ⚠️ YALNIZCA SAYISAL KİMLİK GİDER: alan iç kategori ADINI taşır
        // ("kadin-elbise") ve `(int)` onu 0'a çevirip Woo'ya `id: 0`
        // gönderiyordu — sessiz çöp yazımı (A15'te bulundu).
        if ($payload->categoryId !== null && ctype_digit($payload->categoryId)) {
            $product['categories'] = [['id' => (int) $payload->categoryId]];
        }

        // Kanala özgü öznitelikler kanonik alanları EZMEZ; çakışma olursa
        // kanonik kazanır, yoksa panelde görünen ile gönderilen ayrışır.
        return [...$payload->attributes, ...$product];
    }

    /**
     * Woo ürün gövdesinden İÇE AKTARILABİLİR ürün.
     *
     * `toRemoteListing()` ile aynı gövdeyi okur ama FARKLI soruyu
     * cevaplar: o "benim listemin kanaldaki hâli ne", bu "kanaldaki bu
     * ürünü kataloğuma nasıl yazarım". Çıpa bu yüzden `id` değil `sku`
     * ve alanlar `CreateProduct`'ın imzasına göre seçilir.
     *
     * SKU BOŞSA `null` YAZILIR, uydurulmaz: Woo'da SKU zorunlu değildir
     * ve kanal kimliğini SKU yapmak satıcı aynı ürünü kendi SKU'suyla
     * yüklediğinde KOPYA ürün üretirdi.
     *
     * FİYAT `regular_price`'TAN OKUNUR, `price`'tan değil: `price`
     * indirim varsa indirimli değeri taşır ve içe aktarma satıcının liste
     * fiyatını KALICI olarak indirimli değere düşürürdü. İndirim
     * kampanyası biter, kanonik fiyat düşük kalır ve o fiyat sonraki
     * senkronda tüm kanallara YAYILIR.
     *
     * @param  array<string, mixed>  $product
     */
    public static function toRemoteProduct(array $product): RemoteProduct
    {
        $sku = isset($product['sku']) ? trim((string) $product['sku']) : '';

        return new RemoteProduct(
            externalId: (string) ($product['id'] ?? ''),
            sku: $sku !== '' ? $sku : null,
            title: isset($product['name']) ? (string) $product['name'] : null,
            price: isset($product['regular_price']) && $product['regular_price'] !== ''
                ? (string) $product['regular_price']
                : null,
            quantity: isset($product['stock_quantity']) && $product['stock_quantity'] !== null
                ? (int) $product['stock_quantity']
                : null,
            description: isset($product['description']) ? (string) $product['description'] : null,
            // Woo'da marka çekirdek alan DEĞİLDİR (eklenti taksonomisi);
            // yoksa null kalır ve içe aktarma onu boş geçer.
            brand: self::firstBrandName($product),
            barcode: null,
            status: isset($product['status']) ? (string) $product['status'] : null,
            images: self::imageUrls($product),
            raw: $product,
            // S5 · İÇE AKTARILAN ÜRÜN KANAL KİMLİĞİNE BAĞLANIR: bağ
            // yazılmasaydı sonraki gönderim Woo'da KOPYA ürün yaratır ve
            // stok/fiyat eşleşmesi SKU'ya kalırdı (Shopify'da 5 Eki'de
            // kapatılan açığın Woo eşi).
            listingIdentity: isset($product['id']) ? array_filter([
                'external_id' => (string) $product['id'],
                'external_url' => isset($product['permalink']) ? (string) $product['permalink'] : null,
            ], static fn (?string $v): bool => $v !== null && $v !== '') : [],
        );
    }

    /**
     * Varyasyon → satılabilir birim (ADR-003). Kimlik VARYASYON kimliği,
     * grup kimliği ÜST ürün kimliği; ad/açıklama/marka üstten, fiyat/stok/
     * SKU/seçenekler varyasyondan. Stok yönetimi kapalı varyasyonda adet
     * BİLİNMEZ (null) — 0 sayılmaz.
     *
     * @param  array<string, mixed>  $parent
     * @param  array<string, mixed>  $variation
     */
    public static function variationToRemoteProduct(array $parent, array $variation): RemoteProduct
    {
        $sku = isset($variation['sku']) ? trim((string) $variation['sku']) : '';
        $options = [];

        foreach ((array) ($variation['attributes'] ?? []) as $attribute) {
            if (is_array($attribute) && trim((string) ($attribute['name'] ?? '')) !== '' && trim((string) ($attribute['option'] ?? '')) !== '') {
                $options[trim((string) $attribute['name'])] = trim((string) $attribute['option']);
            }
        }

        $image = is_array($variation['image'] ?? null) && is_string($variation['image']['src'] ?? null) && trim($variation['image']['src']) !== ''
            ? [trim($variation['image']['src'])]
            : [];

        $manages = ($variation['manage_stock'] ?? false) === true;

        return new RemoteProduct(
            externalId: (string) ($variation['id'] ?? ''),
            sku: $sku !== '' ? $sku : null,
            title: isset($parent['name']) ? (string) $parent['name'] : null,
            price: isset($variation['regular_price']) && $variation['regular_price'] !== ''
                ? (string) $variation['regular_price']
                : null,
            quantity: $manages && isset($variation['stock_quantity']) && $variation['stock_quantity'] !== null
                ? (int) $variation['stock_quantity']
                : null,
            description: isset($parent['description']) ? (string) $parent['description'] : null,
            brand: self::firstBrandName($parent),
            barcode: null,
            status: isset($variation['status']) ? (string) $variation['status'] : (isset($parent['status']) ? (string) $parent['status'] : null),
            images: array_values(array_unique([...$image, ...self::imageUrls($parent)])),
            raw: $variation,
            listingIdentity: array_filter([
                'external_id' => (string) ($variation['id'] ?? ''),
                'external_parent_id' => (string) ($parent['id'] ?? ''),
                'external_url' => isset($variation['permalink']) ? (string) $variation['permalink'] : null,
            ], static fn (?string $v): bool => $v !== null && $v !== ''),
            options: $options,
        );
    }

    /**
     * Woo `images[]` — `position` sırasıyla, yalnızca `src` dolu olanlar.
     *
     * @param  array<string, mixed>  $product
     * @return list<string>
     */
    private static function imageUrls(array $product): array
    {
        $images = array_values(array_filter(
            (array) ($product['images'] ?? []),
            static fn (mixed $image): bool => is_array($image) && is_string($image['src'] ?? null) && trim($image['src']) !== '',
        ));

        usort($images, static fn (array $a, array $b): int => (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0));

        return array_values(array_unique(array_map(static fn (array $image): string => trim($image['src']), $images)));
    }

    /**
     * Woo'nun `brands` taksonomisinden ilk marka adı.
     *
     * Eklentiye bağlıdır ve çoğu mağazada HİÇ YOKTUR; bulunamazsa `null`
     * döner. Boş dize DEĞİL `null`: boş dize bir marka adı değildir ve
     * kanonik alana yazılırsa panel "markası var ama boş" gösterirdi.
     *
     * @param  array<string, mixed>  $product
     */
    private static function firstBrandName(array $product): ?string
    {
        $brands = $product['brands'] ?? null;

        if (! is_array($brands)) {
            return null;
        }

        $first = $brands[0] ?? null;

        if (! is_array($first) || ! isset($first['name'])) {
            return null;
        }

        $name = trim((string) $first['name']);

        return $name !== '' ? $name : null;
    }

    /**
     * Woo ürün gövdesinden uzak gözlem.
     *
     * @param  array<string, mixed>  $product
     */
    public static function toRemoteListing(array $product): RemoteListing
    {
        return new RemoteListing(
            externalId: (string) ($product['id'] ?? ''),
            title: isset($product['name']) ? (string) $product['name'] : null,
            quantity: isset($product['stock_quantity']) ? (int) $product['stock_quantity'] : null,
            price: isset($product['regular_price']) ? (string) $product['regular_price'] : null,
            status: isset($product['status']) ? (string) $product['status'] : null,
            url: isset($product['permalink']) ? (string) $product['permalink'] : null,
            raw: $product,
            observedAt: new DateTimeImmutable,
        );
    }
}
