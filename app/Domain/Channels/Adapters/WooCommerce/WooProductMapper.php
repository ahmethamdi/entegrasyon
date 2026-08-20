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

        if ($payload->categoryId !== null) {
            // Woo'da taksonomi doğrulaması yoktur; kategori serbesttir.
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
            raw: $product,
        );
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
