<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\SetChannelPrice;
use App\Domain\Catalog\Models\PriceOverride;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Identity\Models\AuditLog;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Actions\OpenSyncOperation;
use App\Domain\Sync\Actions\RequestResync;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\PriceBatchBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kanal başına fiyat (7 Eki, kullanıcı kararı "A").
 *
 * Etsy mağazası USD, ürün TL: rakam olduğu gibi gitse 199,90 TL'lik ürün
 * $199.90 olurdu. Satıcı ürünün Kanallar sayfasından o kanal için ayrı fiyat
 * girer; `listings.channel_price` ilan açarken, fiyat turunda ve mutabakatta
 * TEK KAYNAKTAN (`Listing::effectivePrice`) okunur.
 *
 * `PriceOverride`'dan AYRIDIR: override "kanaldaki kalsın, GÖNDERME"
 * demektir; kanal fiyatı ise GÖNDERİLEN fiyattır.
 */
final class ChannelPriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        // Ekran ve uç nokta Etsy'nin saklanmış mağaza birimini okur; ağa
        // çıkarsa test yanlış yoldan geçiyordur.
        Http::preventStrayRequests();
    }

    // ───────────────────────────────────────────────────────────── gönderim

    /**
     * ⚠️ FİYAT TURU KANAL FİYATINI GÖNDERİR, üstü çizili fiyatı GÖNDERMEZ.
     *
     * Varyantın karşılaştırma fiyatı varyantın biriminde (TL); USD kanal
     * fiyatının yanında "eski fiyat 249,90" diye görünürdü.
     */
    #[Test]
    public function the_price_batch_carries_the_channel_price_without_compare_at(): void
    {
        [$tenant, , $variant] = $this->context();
        $connection = $this->etsy($tenant);

        $this->asTenant($tenant, function () use ($tenant, $variant, $connection): void {
            $listing = $this->listing($variant, $connection, channelPrice: '5.00');

            $operation = app(OpenSyncOperation::class)->run(listing: $listing, domain: SyncDomain::PRICE, eventVersion: 2);
            $batch = DB::transaction(fn () => app(PriceBatchBuilder::class)->build($operation));

            $this->assertSame('5.00', $batch->items[0]['price'], 'Varyant fiyatı gitti, kanal fiyatı değil.');
            $this->assertNull($batch->items[0]['compare_at_price']);
            $this->assertSame($tenant->id, $listing->tenant_id);
        });
    }

    /** Kanal fiyatı yoksa her şey eskisi gibi: varyant fiyatı + birimi. */
    #[Test]
    public function without_a_channel_price_the_variant_price_applies(): void
    {
        [$tenant, , $variant] = $this->context();
        $connection = $this->etsy($tenant);

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            $listing = $this->listing($variant, $connection);

            $this->assertSame('199.90', $listing->effectivePrice());
            $this->assertSame('249.90', $listing->effectiveCompareAtPrice());
            $this->assertSame('TRY', $listing->effectiveCurrency());
        });
    }

    // ───────────────────────────────────────────────────────────── eylem

    /**
     * ⚠️ KAYDETMEK ESKİ "KANALDAKİ KALSIN" KARARINI SİLER ve yayındaysa gönderir.
     *
     * Override kalsaydı `PriceBatchBuilder` listing'i atlar, satıcının yeni
     * fiyatı HİÇ gitmezdi — panel ise "kaydedildi" derdi. Resync olmasaydı
     * fiyat ancak ürün fiyatı bir gün değişince giderdi.
     */
    #[Test]
    public function saving_drops_the_override_records_audit_and_requests_a_resync(): void
    {
        [$tenant, $user, $variant] = $this->context();
        $connection = $this->etsy($tenant);

        $this->asTenant($tenant, function () use ($tenant, $user, $variant, $connection): void {
            $listing = $this->listing($variant, $connection);
            PriceOverride::query()->create([
                'tenant_id' => $tenant->id,
                'listing_id' => $listing->id,
                'channel_price' => '5.00',
                'our_price' => '199.90',
                'accepted_at' => now(),
                'expires_at' => null,
            ]);

            $changed = app(SetChannelPrice::class)->run($listing, '5', 'usd', $user->id);

            $this->assertTrue($changed);
            $fresh = Listing::query()->findOrFail($listing->id);
            $this->assertSame('5.00', (string) $fresh->channel_price);
            $this->assertSame('USD', $fresh->channel_price_currency);
            $this->assertSame(0, PriceOverride::query()->where('listing_id', $listing->id)->count(), 'Override silinmedi: yeni fiyat hiç gitmezdi.');

            $event = OutboxEvent::query()->where('event_type', 'ListingResyncRequested')->sole();
            $this->assertSame(SyncDomain::PRICE->value, $event->payload['domain']);
            $this->assertSame(RequestResync::REASON_CHANNEL_PRICE_CHANGED, $event->payload['reason']);

            $log = AuditLog::query()->where('action', AuditAction::CHANNEL_PRICE_SET->value)->sole();
            $this->assertEquals(['old' => null, 'new' => '5.00 USD'], $log->changes);

            // Aynı değer: değişiklik yok, ikinci olay yok.
            $this->assertFalse(app(SetChannelPrice::class)->run($fresh, '5.00', 'USD', $user->id));
            $this->assertSame(1, OutboxEvent::query()->where('event_type', 'ListingResyncRequested')->count());
        });
    }

    /** Yayında olmayan ilan için resync İSTENMEZ — fiyat ilan açılırken gider. */
    #[Test]
    public function a_draft_listing_is_not_resynced(): void
    {
        [$tenant, $user, $variant] = $this->context();
        $connection = $this->etsy($tenant);

        $this->asTenant($tenant, function () use ($user, $variant, $connection): void {
            $listing = $this->listing($variant, $connection, lifecycle: 'draft');

            app(SetChannelPrice::class)->run($listing, '5.00', 'USD', $user->id);

            $this->assertSame(0, OutboxEvent::query()->where('event_type', 'ListingResyncRequested')->count());
        });
    }

    /** Kaldırmak ürün fiyatına döndürür; birim de temizlenir. */
    #[Test]
    public function removing_the_channel_price_falls_back_to_the_variant(): void
    {
        [$tenant, $user, $variant] = $this->context();
        $connection = $this->etsy($tenant);

        $this->asTenant($tenant, function () use ($user, $variant, $connection): void {
            $listing = $this->listing($variant, $connection, channelPrice: '5.00');

            app(SetChannelPrice::class)->run($listing, null, 'USD', $user->id);

            $fresh = Listing::query()->findOrFail($listing->id);
            $this->assertNull($fresh->channel_price);
            $this->assertNull($fresh->channel_price_currency);
            $this->assertSame('199.90', $fresh->effectivePrice());
        });
    }

    // ───────────────────────────────────────────────────────────── uç nokta

    /**
     * ⚠️ BİRİM İSTEKTEN DEĞİL KANALDAN ALINIR.
     *
     * İstekten alınsaydı satıcıya "USD" gösterilip "TRY" saklanabilirdi;
     * koruma o zaman yanlış fiyatı geçirirdi.
     */
    #[Test]
    public function the_endpoint_stores_the_price_in_the_channel_currency(): void
    {
        [$tenant, $user, $variant, $product] = $this->context();
        $connection = $this->etsy($tenant);
        $listing = $this->asTenant($tenant, fn () => $this->listing($variant, $connection));

        $this->actingAs($user)
            ->put("/products/{$product->id}/listings/{$listing->id}/price", ['price' => '12.5', 'currency' => 'TRY'])
            ->assertRedirect("/products/{$product->id}/channels")
            ->assertSessionHasNoErrors();

        $fresh = $this->asTenant($tenant, fn () => Listing::query()->findOrFail($listing->id));
        $this->assertSame('12.50', (string) $fresh->channel_price);
        $this->assertSame('USD', $fresh->channel_price_currency);

        // Boş gönderim kaldırır.
        $this->actingAs($user)
            ->put("/products/{$product->id}/listings/{$listing->id}/price", ['price' => null])
            ->assertSessionHasNoErrors();
        $this->assertNull($this->asTenant($tenant, fn () => Listing::query()->findOrFail($listing->id))->channel_price);
    }

    /** Sıfır ya da metin fiyat kabul edilmez. */
    #[Test]
    public function an_invalid_price_is_refused(): void
    {
        [$tenant, $user, $variant, $product] = $this->context();
        $connection = $this->etsy($tenant);
        $listing = $this->asTenant($tenant, fn () => $this->listing($variant, $connection));

        foreach (['0', '-3', 'abc'] as $bad) {
            $this->actingAs($user)
                ->put("/products/{$product->id}/listings/{$listing->id}/price", ['price' => $bad])
                ->assertSessionHasErrors('price');
        }

        $this->assertNull($this->asTenant($tenant, fn () => Listing::query()->findOrFail($listing->id))->channel_price);
    }

    /** Başka ürünün ya da başka kiracının ilanı bu yoldan değiştirilemez. */
    #[Test]
    public function another_products_listing_is_not_found(): void
    {
        [$tenant, $user, , $product] = $this->context();
        [$otherTenant, , $otherVariant] = $this->context();
        $connection = $this->etsy($tenant);
        $otherConnection = $this->etsy($otherTenant);

        $sibling = $this->asTenant($tenant, function () use ($tenant, $connection): Listing {
            $other = Product::factory()->create(['tenant_id' => $tenant->id]);
            $variant = Variant::factory()->create(['tenant_id' => $tenant->id, 'product_id' => $other->id]);

            return $this->listing($variant, $connection);
        });
        $foreign = $this->asTenant($otherTenant, fn () => $this->listing($otherVariant, $otherConnection));

        foreach ([[$tenant, $sibling], [$otherTenant, $foreign]] as [$owner, $listing]) {
            $this->actingAs($user)
                ->put("/products/{$product->id}/listings/{$listing->id}/price", ['price' => '9.99'])
                ->assertNotFound();

            $this->assertNull($this->asTenant($owner, fn () => Listing::query()->findOrFail($listing->id))->channel_price);
        }
    }

    // ───────────────────────────────────────────────────────────── ekran

    /**
     * Ekran kanalın birimini ve "kanal fiyatı girilmeden gönderilmez"
     * durumunu taşır — satıcı fiyatın neden gitmediğini oradan görür.
     */
    #[Test]
    public function the_screen_flags_a_listing_that_needs_a_channel_price(): void
    {
        [$tenant, $user, $variant, $product] = $this->context();
        $connection = $this->etsy($tenant);
        $listing = $this->asTenant($tenant, fn () => $this->listing($variant, $connection));

        $this->actingAs($user)
            ->get("/products/{$product->id}/channels")
            ->assertInertia(fn ($page) => $page
                ->component('Products/Channels')
                ->where('product.currency', 'TRY')
                ->where('channels', function ($channels) use ($listing, $connection): bool {
                    $etsy = collect($channels)->firstWhere('connectionId', $connection->id);

                    return $etsy['currency'] === 'USD'
                        && $etsy['prices'][0]['listingId'] === $listing->id
                        && $etsy['prices'][0]['variantPrice'] === '199.90'
                        && $etsy['prices'][0]['needsChannelPrice'] === true;
                }));

        // Kanal fiyatı girilince uyarı kalkar.
        $this->asTenant($tenant, fn () => $listing->forceFill(['channel_price' => '5.00', 'channel_price_currency' => 'USD'])->save());

        $this->actingAs($user)
            ->get("/products/{$product->id}/channels")
            ->assertInertia(fn ($page) => $page->where('channels', function ($channels) use ($connection): bool {
                $price = collect($channels)->firstWhere('connectionId', $connection->id)['prices'][0];

                return $price['channelPrice'] === '5.00' && $price['needsChannelPrice'] === false;
            }));
    }

    // ──────────────────────────────────────────────────────── yardımcılar

    /** @return array{0: Tenant, 1: User, 2: Variant, 3: Product} */
    private function context(): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Kanal fiyatı '.uniqid(), owner: $user);

        [$product, $variant] = $this->asTenant($tenant, function () use ($tenant): array {
            $product = Product::factory()->create(['tenant_id' => $tenant->id, 'content_version' => 1]);
            $variant = Variant::factory()->create([
                'tenant_id' => $tenant->id,
                'product_id' => $product->id,
                'price' => '199.90',
                'compare_at_price' => '249.90',
                'currency' => 'TRY',
            ]);

            return [$product, $variant];
        });

        return [$tenant, $user, $variant, $product];
    }

    /** Mağaza birimi USD olarak SAKLANMIŞ Etsy bağlantısı — ağa çıkmaz. */
    private function etsy(Tenant $tenant): ChannelConnection
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'etsy'], [
            'name' => 'Etsy',
            'kind' => 'marketplace',
            'adapter_class' => EtsyAdapter::class,
            'supports_webhooks' => false,
            'is_active' => true,
        ]));

        return $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_type_code' => 'etsy',
            'external_account_id' => 'etsy-'.uniqid(),
            'status' => 'active',
            'health_status' => 'healthy',
            'settings' => [
                EtsyAdapter::SHOP_ID_KEY => '777',
                EtsyAdapter::SHOP_CURRENCY_KEY => 'USD',
            ],
        ]));
    }

    private function listing(
        Variant $variant,
        ChannelConnection $connection,
        ?string $channelPrice = null,
        string $lifecycle = 'live',
    ): Listing {
        return Listing::factory()->create([
            'channel_connection_id' => $connection->id,
            'variant_id' => $variant->id,
            'external_id' => (string) random_int(1000, 999999),
            'external_parent_id' => '9001',
            'lifecycle_status' => $lifecycle,
            'channel_price' => $channelPrice,
            'channel_price_currency' => $channelPrice === null ? null : 'USD',
        ]);
    }
}
