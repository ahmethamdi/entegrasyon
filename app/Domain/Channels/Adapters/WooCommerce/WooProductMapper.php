<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\WooCommerce;

use App\Domain\Sync\Support\ListingPayload;
use App\Domain\Sync\Support\RemoteListing;
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
