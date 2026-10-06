<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Models\CategoryMapping;
use App\Domain\Channels\Models\ChannelCategory;
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
 * Etsy taslak ilan yaratma — 7 Eki 2026'da Etsy OAS'a göre yeniden yazıldı.
 *
 * Önceki hâl gerçek mağazada HİÇ çalışamazdı (zorunlu price/quantity yok,
 * taxonomy_id = iç kategori, beyanlar boş, kargo profili yok, gövde JSON,
 * güncelleme mağazasız yola). Testler o hataları tek tek kilitler.
 */
final class EtsyListingCreateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Taslak gövdesi form biçiminde gider ve zorunluların hepsini taşır;
     * kategori EŞLEŞTİRMEDEN çözülür, iç kimlik gitmez. Kargo profili
     * mağazada tekse o seçilir.
     */
    #[Test]
    public function a_draft_is_created_with_every_required_field_as_a_form(): void
    {
        $this->fakeEtsy();

        $result = $this->adapter()->createListing($this->payload());

        $this->assertTrue($result->successful, (string) $result->errorMessage);

        $create = $this->sent('POST', '/shops/777/listings');
        $this->assertTrue($create->isForm(), 'Etsy bu uç noktada form bekler.');
        $this->assertEquals([
            'title' => 'Masaj Kremi',
            'description' => 'Rahatlatıcı krem',
            'quantity' => '1',
            'price' => '199.9',
            'who_made' => 'i_did',
            'when_made' => 'made_to_order',
            'taxonomy_id' => '1234',
            'type' => 'physical',
            'shipping_profile_id' => '555',
            'readiness_state_id' => '888',
        ], $create->data());
    }

    /**
     * Yaratma yanıtı envanter taşımaz: kimlik envanter okumasından alınır,
     * tek varyantın boş SKU'suna bizim SKU'muz yazılır.
     */
    #[Test]
    public function the_identity_comes_from_the_inventory_and_our_sku_is_claimed(): void
    {
        $this->fakeEtsy();

        $result = $this->adapter()->createListing($this->payload());

        $this->assertSame('9001', $result->data['external_parent_id']);
        $this->assertSame('6001', $result->data['external_id'], 'PUT sonrası okunan product_id.');
        $this->assertSame(['offering_id' => '7001'], $result->data['channel_metadata']);

        $put = $this->sent('PUT', '/listings/9001/inventory');
        $this->assertSame('KREM-01', $put->data()['products'][0]['sku']);
    }

    /** Beyan yoksa ilan AÇILMAZ ve Etsy'ye istek gitmez — uydurulmaz. */
    #[Test]
    public function legal_declarations_are_never_invented(): void
    {
        $this->fakeEtsy();
        $this->settings([EtsyAdapter::WHO_MADE_KEY => null]);

        $result = $this->adapter()->createListing($this->payload());

        $this->assertTrue($result->failed());
        $this->assertStringContainsString('kim yaptı', (string) $result->errorMessage);
        Http::assertNothingSent();
    }

    /** Kategori Etsy'de eşleştirilmemişse ilan açılmaz. */
    #[Test]
    public function an_unmapped_category_is_refused(): void
    {
        $this->fakeEtsy();
        $this->asSystem(fn () => CategoryMapping::query()->delete());

        $this->assertTrue($this->adapter()->createListing($this->payload())->failed());
        Http::assertNothingSent();
    }

    /** Birden çok kargo profili varsa tahmin edilmez; ayardan okunur. */
    #[Test]
    public function several_shipping_profiles_require_a_setting(): void
    {
        $this->fakeEtsy(profiles: 2);

        $this->assertTrue($this->adapter()->createListing($this->payload())->failed());

        $this->settings([EtsyAdapter::SHIPPING_PROFILE_KEY => '556']);
        $this->assertTrue($this->adapter()->createListing($this->payload())->successful);
        $this->assertSame('556', (string) $this->sent('POST', '/shops/777/listings')->data()['shipping_profile_id']);
    }

    /**
     * Güncelleme MAĞAZA altındaki yola, form olarak gider ve DURUM taşımaz —
     * yayındaki ilan başlık düzeltmesiyle satıştan düşmemeli.
     */
    #[Test]
    public function an_update_goes_to_the_shop_path_without_a_state(): void
    {
        $this->fakeEtsy();
        $this->asSystem(fn () => $this->listing->forceFill(['external_parent_id' => '9001', 'external_id' => '6001'])->save());

        $this->assertTrue($this->adapter()->updateListing($this->payload())->successful);

        $patch = $this->sent('PATCH', '/shops/777/listings/9001');
        $this->assertTrue($patch->isForm());
        $this->assertArrayNotHasKey('state', $patch->data());
        $this->assertSame('1234', (string) $patch->data()['taxonomy_id']);
    }

    // ─────────────────────────────────────────────────────── yardımcılar

    private Tenant $tenant;

    private ChannelConnection $connection;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'etsy'],
            ['name' => 'Etsy', 'kind' => 'marketplace', 'adapter_class' => EtsyAdapter::class, 'supports_webhooks' => false, 'is_active' => true],
        ));

        $this->tenant = (new CreateTenant)->run(name: 'Etsy Yaratma '.uniqid(), owner: User::factory()->create());

        $category = $this->asSystem(fn (): ChannelCategory => ChannelCategory::query()->create([
            'channel_type_code' => 'etsy', 'taxonomy_version' => 'v1', 'external_id' => '1234',
            'name' => 'Masaj', 'path' => 'Bakım > Masaj', 'is_leaf' => true,
        ]));

        $this->asTenant($this->tenant, function () use ($category): void {
            $this->connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'etsy',
                'external_account_id' => '777',
                'status' => 'active',
                'settings' => [
                    EtsyAdapter::SHOP_ID_KEY => '777',
                    EtsyAdapter::WHO_MADE_KEY => 'i_did',
                    EtsyAdapter::WHEN_MADE_KEY => 'made_to_order',
                ],
            ]);

            app(CredentialVault::class)->store($this->connection, ['access_token' => '12345.token', 'refresh_token' => '12345.refresh']);

            CategoryMapping::query()->create([
                'tenant_id' => $this->tenant->id, 'internal_category_id' => 'krem', 'channel_type_code' => 'etsy',
                'channel_category_id' => $category->id, 'taxonomy_version' => 'v1', 'confidence' => 100, 'mapped_by' => 'user',
            ]);

            $product = Product::factory()->create(['title' => 'Masaj Kremi', 'description' => '<p>Rahatlatıcı krem</p>', 'internal_category_id' => 'krem']);
            $variant = Variant::factory()->create(['product_id' => $product->id, 'sku' => 'KREM-01', 'price' => '199.90']);

            $this->listing = Listing::factory()->create([
                'channel_connection_id' => $this->connection->id,
                'variant_id' => $variant->id,
                'external_id' => null,
                'external_parent_id' => null,
            ]);
        });
    }

    /** @param array<string, string|null> $changes */
    private function settings(array $changes): void
    {
        $this->asSystem(fn () => $this->connection->forceFill([
            'settings' => array_filter([...$this->connection->settings, ...$changes], static fn ($v) => $v !== null),
        ])->save());
    }

    private function fakeEtsy(int $profiles = 1): void
    {
        Http::fake(function (Request $r) use ($profiles) {
            $url = $r->url();

            return match (true) {
                str_contains($url, '/shipping-profiles') => Http::response(['results' => array_map(
                    static fn (int $i): array => ['shipping_profile_id' => 554 + $i],
                    range(1, $profiles),
                )], 200),
                str_contains($url, '/readiness-state-definitions') => Http::response(['results' => [['readiness_state_id' => 888, 'readiness_state' => 'made_to_order']]], 200),
                $r->method() === 'GET' && str_contains($url, '/inventory') => Http::response(['products' => [
                    ['product_id' => 6000, 'sku' => '', 'property_values' => [], 'offerings' => [['offering_id' => 7000, 'quantity' => 1, 'is_enabled' => true, 'price' => ['amount' => 19990, 'divisor' => 100]]]],
                ]], 200),
                $r->method() === 'PUT' && str_contains($url, '/inventory') => Http::response(['products' => [
                    ['product_id' => 6001, 'sku' => 'KREM-01', 'offerings' => [['offering_id' => 7001, 'quantity' => 1]]],
                ]], 200),
                str_contains($url, '/images') => Http::response(['count' => 0, 'results' => []], 200),
                default => Http::response(['listing_id' => 9001, 'state' => 'draft'], 201),
            };
        });
    }

    private function sent(string $method, string $path): Request
    {
        $match = collect(Http::recorded())
            ->map(static fn (array $pair): Request => $pair[0])
            ->filter(static fn (Request $r): bool => $r->method() === $method && str_contains($r->url(), $path))
            ->last();

        $this->assertNotNull($match, "{$method} {$path} gönderilmedi.");

        return $match;
    }

    private function adapter(): EtsyAdapter
    {
        $this->asSystem(fn () => $this->connection->refresh());

        return $this->asTenant($this->tenant, fn (): EtsyAdapter => new EtsyAdapter(
            $this->connection,
            new ChannelHttpClient($this->connection, app(CredentialVault::class), app(PayloadRedactor::class)),
        ));
    }

    private function payload(): ListingPayload
    {
        return $this->asTenant($this->tenant, function (): ListingPayload {
            $listing = $this->listing->fresh(['variant.product']);

            return new ListingPayload(
                listing: $listing,
                title: $listing->variant->product->title,
                description: $listing->variant->product->description,
                categoryId: $listing->variant->product->internal_category_id,
            );
        });
    }
}
