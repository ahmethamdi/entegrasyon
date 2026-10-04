<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\ListingPayload;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WooCommerce'e görsel gönderimi (A15).
 *
 * Woo her `src`'yi yeniden indirir ve medya kütüphanesinde kopya açar;
 * görseller yalnızca SET DEĞİŞTİYSE gönderilir. Boş liste galeriyi
 * sileceği için bizde görsel yoksa alan hiç yazılmaz.
 */
final class WooImagesTest extends TestCase
{
    use RefreshDatabase;

    /** Yaratmada görseller sırasıyla gider ve özet saklanır. */
    #[Test]
    public function images_are_sent_on_create_and_their_hash_is_kept(): void
    {
        [$tenant, $connection, $listing] = $this->scenario(externalId: null);
        $this->image($tenant, $listing, 'https://cdn.x/1.jpg', 0);
        $this->image($tenant, $listing, 'https://cdn.x/2.jpg', 1);

        Http::fake(['*' => Http::response(['id' => 55, 'permalink' => 'https://shop/p/55'], 201)]);

        $result = $this->asTenant($tenant, fn () => $this->adapter($connection)->createListing($this->payload($listing)));

        Http::assertSent(fn (Request $r): bool => ($r->data()['images'] ?? null) === [
            ['src' => 'https://cdn.x/1.jpg', 'position' => 0],
            ['src' => 'https://cdn.x/2.jpg', 'position' => 1],
        ]);
        $this->assertSame(sha1("https://cdn.x/1.jpg\nhttps://cdn.x/2.jpg"), $result->data['channel_metadata']['images_hash']);
    }

    /**
     * ⚠️ SET DEĞİŞMEDİYSE GÖRSEL GÖNDERİLMEZ — değiştiyse gider.
     */
    #[Test]
    public function unchanged_images_are_not_resent(): void
    {
        [$tenant, $connection, $listing] = $this->scenario(
            externalId: '55',
            metadata: ['images_hash' => sha1('https://cdn.x/1.jpg')],
        );
        $this->image($tenant, $listing, 'https://cdn.x/1.jpg', 0);

        Http::fake(['*' => Http::response(['id' => 55], 200)]);

        $this->asTenant($tenant, fn () => $this->adapter($connection)->updateListing($this->payload($listing)));

        Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT' && ! array_key_exists('images', $r->data()));

        // Yeni görsel eklendi: set değişti, gönderilir.
        $this->image($tenant, $listing, 'https://cdn.x/2.jpg', 1);

        $this->asTenant($tenant, fn () => $this->adapter($connection)->updateListing($this->payload($listing)));

        Http::assertSent(fn (Request $r): bool => count($r->data()['images'] ?? []) === 2);
    }

    /**
     * ⚠️ BU MAĞAZADAN GELEN GÖRSEL ONA GERİ GİTMEZ ve bizde başka görsel
     * yoksa `images` HİÇ yazılmaz — boş dizi Woo galerisini silerdi.
     */
    #[Test]
    public function images_imported_from_this_store_are_not_sent_back(): void
    {
        [$tenant, $connection, $listing] = $this->scenario(externalId: '55');
        $this->image($tenant, $listing, 'https://shop.example.com/wp-content/a.jpg', 0, source: $connection->id);

        Http::fake(['*' => Http::response(['id' => 55], 200)]);

        $this->asTenant($tenant, fn () => $this->adapter($connection)->updateListing($this->payload($listing)));

        Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT' && ! array_key_exists('images', $r->data()));
    }

    /** İç kategori ADI Woo'ya `id: 0` olarak gitmez. */
    #[Test]
    public function a_non_numeric_category_is_not_sent(): void
    {
        [$tenant, $connection, $listing] = $this->scenario(externalId: '55');

        Http::fake(['*' => Http::response(['id' => 55], 200)]);

        $this->asTenant($tenant, fn () => $this->adapter($connection)->updateListing(
            new ListingPayload(listing: $listing, title: 'Ürün', categoryId: 'kadin-elbise'),
        ));

        Http::assertSent(fn (Request $r): bool => ! array_key_exists('categories', $r->data()));
    }

    // ──────────────────────────────────────────────────────── yardımcılar

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array{0: Tenant, 1: ChannelConnection, 2: Listing}
     */
    private function scenario(?string $externalId, ?array $metadata = null): array
    {
        $tenant = (new CreateTenant)->run(name: 'Woo görsel '.uniqid(), owner: User::factory()->create());

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'woocommerce'], [
            'name' => 'WooCommerce',
            'kind' => 'store',
            'adapter_class' => WooCommerceAdapter::class,
            'is_active' => true,
        ]));

        return $this->asTenant($tenant, function () use ($tenant, $externalId, $metadata): array {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'woocommerce',
                'external_account_id' => 'shop.example.com',
                'settings' => ['base_url' => 'https://shop.example.com/wp-json/wc/v3/'],
            ]);

            $listing = Listing::factory()->create([
                'channel_connection_id' => $connection->id,
                'variant_id' => Variant::factory()->create()->id,
                'external_id' => $externalId,
                'channel_metadata' => $metadata,
            ]);

            return [$tenant, $connection, $listing->load('variant')];
        });
    }

    private function image(Tenant $tenant, Listing $listing, string $url, int $position, ?string $source = null): void
    {
        $this->asTenant($tenant, fn () => ProductImage::query()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $listing->variant->product_id,
            'source_connection_id' => $source,
            'storage_path' => $url,
            'position' => $position,
        ]));
    }

    private function payload(Listing $listing): ListingPayload
    {
        return new ListingPayload(listing: $listing, title: 'Ürün');
    }

    private function adapter(ChannelConnection $connection): WooCommerceAdapter
    {
        return new WooCommerceAdapter(
            connection: $connection,
            client: new ChannelHttpClient(
                connection: $connection,
                vault: app(CredentialVault::class),
                redactor: app(PayloadRedactor::class),
            ),
        );
    }
}
