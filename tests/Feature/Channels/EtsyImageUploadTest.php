<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Channels\Support\OutboundUrlGuard;
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
 * Etsy görsel yükleme (A15 · Etsy adımı) — 7 Eki 2026'ya kadar YOKTU:
 * 34Pazar'dan Etsy'ye hiç görsel gitmiyordu.
 *
 * Etsy görseli adresten almaz; dosya indirilir ve multipart yüklenir
 * (`POST /shops/{shop}/listings/{listing}/images`, alan `image` + `rank`).
 */
final class EtsyImageUploadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Yalnız GÖNDERİLMEMİŞ görsel yüklenir: kardeş varyantın yüklediği,
     * Etsy'den hariç tutulan ve bu mağazadan içe aktarılan görsel gitmez.
     * Sıra Etsy'deki mevcut görsellerden sonra başlar.
     */
    #[Test]
    public function only_images_not_yet_on_the_listing_are_uploaded(): void
    {
        $this->images([
            'https://cdn.example/kardes.jpg',
            'https://cdn.example/yeni.jpg',
            ['https://cdn.example/haric.jpg', 'excluded'],
            ['https://cdn.example/etsydengelen.jpg', 'source'],
        ]);
        $this->sibling(['https://cdn.example/kardes.jpg']);

        $this->fakeEtsy(existing: 3);

        $result = $this->adapter->updateListing($this->payload());

        $this->assertTrue($result->successful);
        $this->assertSame(['https://cdn.example/yeni.jpg'], $result->data['channel_metadata']['pushed_image_urls'] ?? null);

        $uploads = $this->uploads();
        $this->assertCount(1, $uploads);

        $parts = collect($uploads[0]->data())->keyBy('name');
        $this->assertSame('4', (string) $parts['rank']['contents'], 'Sıra mevcut 3 görselden sonra.');
        $this->assertSame('yeni.jpg', $parts['image']['filename']);
        $this->assertSame('JPEGVERI', (string) $parts['image']['contents']);
        $this->assertTrue($uploads[0]->hasHeader('x-api-key', 'key-abc:sir-xyz'));
        // ⚠️ JSON başlığı kalsaydı Etsy dosyayı görmez (ilk gerçek yükleme).
        $this->assertStringStartsWith('multipart/form-data', $uploads[0]->header('Content-Type')[0] ?? '');
        $this->assertStringContainsString('/shops/777/listings/9001/images', $uploads[0]->url());

        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), 'kardes.jpg'));
    }

    /** İlan 10 görselle doluysa hiçbir şey indirilmez ve yüklenmez. */
    #[Test]
    public function a_full_listing_receives_no_upload(): void
    {
        $this->images(['https://cdn.example/yeni.jpg']);
        $this->fakeEtsy(existing: 10);

        $result = $this->adapter->updateListing($this->payload());

        $this->assertTrue($result->successful);
        $this->assertArrayNotHasKey('pushed_image_urls', $result->data['channel_metadata'] ?? []);
        $this->assertSame([], $this->uploads());
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), 'cdn.example'));
    }

    /**
     * İndirme hatası ürünü düşürmez; başarısız adres listeye yazılmaz ve
     * sonraki turda yeniden denenir.
     */
    #[Test]
    public function a_failed_download_keeps_the_listing_and_retries_later(): void
    {
        $this->images(['https://cdn.example/kayip.jpg', 'https://cdn.example/yeni.jpg']);
        $this->fakeEtsy(existing: 0, missing: 'kayip.jpg');

        $result = $this->adapter->updateListing($this->payload());

        $this->assertTrue($result->successful);
        $this->assertSame(['https://cdn.example/yeni.jpg'], $result->data['channel_metadata']['pushed_image_urls']);
    }

    /**
     * ⚠️ İÇ AĞ ADRESİ İNDİRİLMEZ (SSRF): kanaldan gelen görsel adresi iç
     * ağa çözülürse istek hiç gitmez, ilan yine yazılır.
     */
    #[Test]
    public function an_image_on_an_internal_address_is_never_fetched(): void
    {
        $this->app->instance(OutboundUrlGuard::class, new OutboundUrlGuard(
            static fn (string $host): array => $host === 'ic.example' ? ['169.254.169.254'] : ['93.184.216.34'],
        ));

        $this->images(['https://ic.example/meta.jpg']);
        $this->fakeEtsy(existing: 0);

        $result = $this->adapter->updateListing($this->payload());

        $this->assertTrue($result->successful);
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), 'ic.example'));
        $this->assertSame([], $this->uploads());
    }

    // ─────────────────────────────────────────────────────── yardımcılar

    private Tenant $tenant;

    private ChannelConnection $connection;

    private Variant $variant;

    private Listing $listing;

    private EtsyAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'etsy'],
            ['name' => 'Etsy', 'kind' => 'marketplace', 'adapter_class' => EtsyAdapter::class, 'supports_webhooks' => false, 'is_active' => true],
        ));

        $this->tenant = (new CreateTenant)->run(name: 'Etsy Görsel '.uniqid(), owner: User::factory()->create());

        $this->asTenant($this->tenant, function (): void {
            $this->connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'etsy',
                'external_account_id' => '777',
                'status' => 'active',
                'settings' => [EtsyAdapter::SHOP_ID_KEY => '777'],
            ]);

            app(CredentialVault::class)->store($this->connection, ['access_token' => '12345.token', 'refresh_token' => '12345.refresh']);

            $product = Product::factory()->create();
            $this->variant = Variant::factory()->create(['product_id' => $product->id, 'sku' => 'TSH-M']);

            $this->listing = Listing::factory()->create([
                'channel_connection_id' => $this->connection->id,
                'variant_id' => $this->variant->id,
                'external_id' => '5001',
                'external_parent_id' => '9001',
            ]);

            $this->adapter = new EtsyAdapter(
                $this->connection,
                new ChannelHttpClient($this->connection, app(CredentialVault::class), app(PayloadRedactor::class)),
            );
        });
    }

    /** @param list<string|array{0: string, 1: string}> $images */
    private function images(array $images): void
    {
        $this->asTenant($this->tenant, function () use ($images): void {
            foreach ($images as $i => $image) {
                [$url, $flag] = is_array($image) ? $image : [$image, null];

                ProductImage::query()->create([
                    'tenant_id' => $this->tenant->id,
                    'product_id' => $this->variant->product_id,
                    'storage_path' => $url,
                    'position' => $i,
                    'excluded_channels' => $flag === 'excluded' ? ['etsy'] : null,
                    'source_connection_id' => $flag === 'source' ? $this->connection->id : null,
                ]);
            }
        });
    }

    /** Aynı Etsy ilanına bağlı başka varyantın listing satırı. */
    private function sibling(array $pushed): void
    {
        $this->asTenant($this->tenant, function () use ($pushed): void {
            $other = Variant::factory()->create(['product_id' => $this->variant->product_id, 'sku' => 'TSH-L']);

            Listing::factory()->create([
                'channel_connection_id' => $this->connection->id,
                'variant_id' => $other->id,
                'external_id' => '5002',
                'external_parent_id' => '9001',
                'channel_metadata' => ['pushed_image_urls' => $pushed],
            ]);
        });
    }

    private function fakeEtsy(int $existing, ?string $missing = null): void
    {
        Http::fake(function (Request $r) use ($existing, $missing) {
            $url = $r->url();

            return match (true) {
                $missing !== null && str_contains($url, $missing) => Http::response('yok', 404),
                str_contains($url, 'cdn.example') || str_contains($url, 'ic.example') => Http::response('JPEGVERI', 200, ['Content-Type' => 'image/jpeg']),
                $r->method() === 'POST' && str_contains($url, '/images') => Http::response(['listing_image_id' => 1], 201),
                $r->method() === 'GET' && str_contains($url, '/listings/9001/images') => Http::response(['count' => $existing, 'results' => []], 200),
                default => Http::response(['listing_id' => 9001, 'inventory' => ['products' => [
                    ['product_id' => 5001, 'sku' => 'TSH-M', 'offerings' => [['offering_id' => 7001, 'quantity' => 3]]],
                ]]], 200),
            };
        });
    }

    /** @return list<Request> */
    private function uploads(): array
    {
        return collect(Http::recorded())
            ->map(static fn (array $pair): Request => $pair[0])
            ->filter(static fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), '/images'))
            ->values()
            ->all();
    }

    private function payload(): ListingPayload
    {
        return $this->asTenant($this->tenant, fn (): ListingPayload => new ListingPayload(
            listing: $this->listing->load('variant'),
            title: 'Tişört',
            description: 'Pamuklu',
            categoryId: '3',
        ));
    }
}
