<?php

declare(strict_types=1);

namespace Tests\Unit\Channels;

use App\Domain\Channels\Adapters\Shopify\ShopifyProductMapper;
use App\Domain\Channels\Adapters\WooCommerce\WooProductMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Kanal gövdesinden görsel adresleri (A15).
 */
final class ImportedImageUrlsTest extends TestCase
{
    /** Woo `images[]` `position` sırasıyla okunur; boş `src` atlanır. */
    #[Test]
    public function woo_images_follow_their_position(): void
    {
        $product = WooProductMapper::toRemoteProduct([
            'id' => 1,
            'sku' => 'W-1',
            'images' => [
                ['src' => 'https://w.x/2.jpg', 'position' => 1],
                ['src' => '', 'position' => 2],
                ['src' => 'https://w.x/1.jpg', 'position' => 0],
            ],
        ]);

        $this->assertSame(['https://w.x/1.jpg', 'https://w.x/2.jpg'], $product->images);
    }

    /**
     * Shopify: varyantın kendi görseli ÖNCE, sonra ürünün; tekrar yok;
     * görüntüsü olmayan medya (video) atlanır.
     */
    #[Test]
    public function shopify_variant_media_comes_first(): void
    {
        $product = ShopifyProductMapper::toRemoteProduct([
            'id' => 'gid://shopify/ProductVariant/1',
            'sku' => 'S-1',
            'media' => ['nodes' => [['image' => ['url' => 'https://s.x/kirmizi.jpg']]]],
            'product' => [
                'title' => 'Tişört',
                'media' => ['nodes' => [
                    ['image' => ['url' => 'https://s.x/ana.jpg']],
                    [],
                    ['image' => ['url' => 'https://s.x/kirmizi.jpg']],
                ]],
            ],
        ]);

        $this->assertSame(['https://s.x/kirmizi.jpg', 'https://s.x/ana.jpg'], $product->images);
    }
}
