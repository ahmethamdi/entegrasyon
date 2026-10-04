<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Channels\ProgrammableCatalogAdapter;
use Tests\TestCase;

/**
 * Görsel başına kanal seçimi — panel (A15).
 *
 * Varsayılan: her görsel her kanala gider. Satıcı bir görseli bir kanal
 * TÜRÜNDEN çıkarabilir; seçim ürünün içerik sürümünü artırır ki yeniden
 * gönderim "zaten güncel" diye elenmesin.
 */
final class ImageChannelSelectionTest extends TestCase
{
    use RefreshDatabase;

    /** Ekran görselleri, kanal türlerini ve kanal sınırını taşır. */
    #[Test]
    public function the_screen_carries_images_and_channel_limits(): void
    {
        [$tenant, $user, $product] = $this->context();
        $this->connection($tenant, 'trendyol', TrendyolAdapter::class);
        $this->connection($tenant, 'woocommerce', ProgrammableCatalogAdapter::class);
        $image = $this->image($tenant, $product, 'https://cdn.x/1.jpg', excluded: ['trendyol']);
        $this->image($tenant, $product, 'http://cdn.x/guvensiz.jpg', position: 1);

        $this->actingAs($user)
            ->get("/products/{$product->id}/channels")
            ->assertInertia(fn ($page) => $page
                ->component('Products/Channels')
                ->where('images.0.id', $image->id)
                ->where('images.0.url', 'https://cdn.x/1.jpg')
                ->where('images.0.excludedChannels', ['trendyol'])
                // HTTPS olmayan adres panelde "gitmez" olarak görünür.
                ->where('images.1.url', null)
                ->where('imageChannels', fn ($channels): bool => collect($channels)->keyBy('code')->map(
                    fn ($c) => $c['maxImages'],
                )->all() === ['trendyol' => 8, 'woocommerce' => null]));
    }

    /**
     * ⚠️ SEÇİM KAYDEDİLİR VE İÇERİK SÜRÜMÜ ARTAR.
     *
     * Sürüm artmasaydı yeniden gönderim "zaten güncel" diye elenir ve yeni
     * görsel seti kanala hiç gitmezdi. Aynı seçimi tekrar göndermek sürümü
     * artırmaz.
     */
    #[Test]
    public function toggling_saves_the_choice_and_bumps_the_content_version(): void
    {
        [$tenant, $user, $product] = $this->context();
        $this->connection($tenant, 'trendyol', TrendyolAdapter::class);
        $image = $this->image($tenant, $product, 'https://cdn.x/1.jpg');

        $this->actingAs($user)
            ->post("/products/{$product->id}/images/{$image->id}/channels", [
                'channel_type_code' => 'trendyol',
                'excluded' => true,
            ])->assertSessionHasNoErrors();

        $this->assertSame(['trendyol'], $this->fresh($tenant, $image)->excluded_channels);
        $this->assertSame(2, $this->version($tenant, $product));

        // Aynı seçim: sürüm DEĞİŞMEZ.
        $this->actingAs($user)->post("/products/{$product->id}/images/{$image->id}/channels", [
            'channel_type_code' => 'trendyol',
            'excluded' => true,
        ]);
        $this->assertSame(2, $this->version($tenant, $product));

        // Geri açılır: liste boşalınca NULL (= her kanala gider).
        $this->actingAs($user)->post("/products/{$product->id}/images/{$image->id}/channels", [
            'channel_type_code' => 'trendyol',
            'excluded' => false,
        ]);
        $this->assertNull($this->fresh($tenant, $image)->excluded_channels);
        $this->assertSame(3, $this->version($tenant, $product));
    }

    /** Bağlı olmayan kanal türü yazılamaz — serbest metin `excluded_channels`'a girmez. */
    #[Test]
    public function an_unconnected_channel_type_is_refused(): void
    {
        [$tenant, $user, $product] = $this->context();
        $this->connection($tenant, 'trendyol', TrendyolAdapter::class);
        $image = $this->image($tenant, $product, 'https://cdn.x/1.jpg');

        $this->actingAs($user)
            ->post("/products/{$product->id}/images/{$image->id}/channels", [
                'channel_type_code' => 'amazon',
                'excluded' => true,
            ])->assertSessionHasErrors('channel_type_code');

        $this->assertNull($this->fresh($tenant, $image)->excluded_channels);
    }

    /** Başka ürünün ya da başka kiracının görseli bu yoldan değiştirilemez. */
    #[Test]
    public function another_products_image_is_not_found(): void
    {
        [$tenant, $user, $product] = $this->context();
        [$otherTenant, , $otherProduct] = $this->context();
        $this->connection($tenant, 'trendyol', TrendyolAdapter::class);

        $sibling = $this->asTenant($tenant, fn () => Product::factory()->create(['tenant_id' => $tenant->id]));
        $siblingImage = $this->image($tenant, $sibling, 'https://cdn.x/kardes.jpg');
        $foreignImage = $this->image($otherTenant, $otherProduct, 'https://cdn.x/yabanci.jpg');

        foreach ([$siblingImage, $foreignImage] as $image) {
            $this->actingAs($user)
                ->post("/products/{$product->id}/images/{$image->id}/channels", [
                    'channel_type_code' => 'trendyol',
                    'excluded' => true,
                ])->assertNotFound();
        }
    }

    // ──────────────────────────────────────────────────────── yardımcılar

    /** @return array{0: Tenant, 1: User, 2: Product} */
    private function context(): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Görsel '.uniqid(), owner: $user);

        $product = $this->asTenant($tenant, function () use ($tenant): Product {
            $product = Product::factory()->create(['tenant_id' => $tenant->id, 'content_version' => 1]);
            Variant::factory()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id]);

            return $product;
        });

        return [$tenant, $user, $product];
    }

    private function connection(Tenant $tenant, string $code, string $adapter): ChannelConnection
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => $code], [
            'name' => ucfirst($code),
            'kind' => 'marketplace',
            'adapter_class' => $adapter,
            'is_active' => true,
        ]));

        return $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_type_code' => $code,
            'status' => 'active',
            'health_status' => 'healthy',
            'settings' => ['supplier_id' => '1'],
        ]));
    }

    /** @param list<string>|null $excluded */
    private function image(Tenant $tenant, Product $product, string $url, int $position = 0, ?array $excluded = null): ProductImage
    {
        return $this->asTenant($tenant, fn () => ProductImage::query()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'storage_path' => $url,
            'position' => $position,
            'excluded_channels' => $excluded,
        ]));
    }

    private function fresh(Tenant $tenant, ProductImage $image): ProductImage
    {
        return $this->asTenant($tenant, fn () => ProductImage::query()->findOrFail($image->id));
    }

    private function version(Tenant $tenant, Product $product): int
    {
        return $this->asTenant($tenant, fn () => (int) Product::query()->whereKey($product->id)->value('content_version'));
    }
}
