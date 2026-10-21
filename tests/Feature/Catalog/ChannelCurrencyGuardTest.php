<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\ChannelPriceRule;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Actions\OpenSyncOperation;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Jobs\PushListing;
use App\Domain\Sync\Jobs\PushPrices;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\ListingPayloadBuilder;
use App\Domain\Sync\Support\PriceBatchBuilder;
use App\Domain\Sync\Support\SyncResultRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Para birimi kapısı (21 Eki 2026) — kur çevirisi YOK.
 *
 * TRY katalog fiyatı USD/EUR kanalına olduğu gibi gitseydi 199,90 TL'lik
 * ürün 199,90 $ satılırdı ve kanal rakamı SESSİZCE kabul ederdi. Kanal
 * fiyatı o kanalın biriminde açıkça girilmemişse fiyat GİTMEZ; stok etkilenmez.
 */
final class ChannelCurrencyGuardTest extends TestCase
{
    use RefreshDatabase;

    private string $shopCurrency = 'USD';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    /** Farklı birimde fiyat turu kanala hiç istek atmaz; neden panelde görünür (TR + birimler). */
    #[Test]
    public function a_price_in_another_currency_is_blocked_and_explained(): void
    {
        [$tenant, $variant, $connection] = $this->context();

        $operation = $this->asTenant($tenant, function () use ($variant, $connection): SyncOperation {
            $listing = $this->listing($variant, $connection);

            return app(OpenSyncOperation::class)->run(listing: $listing, domain: SyncDomain::PRICE, eventVersion: 2);
        });

        (new PushPrices($operation->id, $tenant->id))->handle(
            app(PriceBatchBuilder::class),
            app(SyncResultRecorder::class),
            app(AdapterRegistry::class),
        );

        Http::assertNothingSent();

        $this->asTenant($tenant, function () use ($operation): void {
            $this->assertSame(SyncOperationStatus::DEAD, SyncOperation::query()->findOrFail($operation->id)->status);

            $state = ListingSyncState::query()->where('listing_id', $operation->entity_id)->where('domain', SyncDomain::PRICE->value)->sole();
            $this->assertSame('error_permanent', $state->status);
            $this->assertStringContainsString('Bu kanal USD, ürün fiyatınız TRY', (string) $state->last_error);
        });
    }

    /** Kanalın biriminde açıkça girilen kanal fiyatı gider. */
    #[Test]
    public function an_explicit_channel_price_in_the_channel_currency_goes_through(): void
    {
        [$tenant, $variant, $connection] = $this->context();

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            $listing = $this->listing($variant, $connection, channelPrice: '12.90', currency: 'USD');
            $operation = app(OpenSyncOperation::class)->run(listing: $listing, domain: SyncDomain::PRICE, eventVersion: 2);

            $batch = DB::transaction(fn () => app(PriceBatchBuilder::class)->build($operation));

            $this->assertSame('12.90', $batch->items[0]['price']);
            $this->assertSame([], $batch->blocked());
        });
    }

    /** Aynı birim ya da bilinmeyen kanal birimi eski davranışı korur. */
    #[Test]
    public function same_or_unknown_currency_is_not_blocked(): void
    {
        $this->shopCurrency = 'TRY';
        [$tenant, $variant, $connection] = $this->context();

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            $listing = $this->listing($variant, $connection);

            $this->assertNull($listing->currencyMismatch('TRY'));
            $this->assertNull($listing->currencyMismatch(null));
            $this->assertNotNull($listing->currencyMismatch('EUR'));
        });
    }

    /** İlan açma da fiyat taşır — o da durur. */
    #[Test]
    public function listing_push_is_blocked_in_another_currency(): void
    {
        [$tenant, $variant, $connection] = $this->context();

        $operation = $this->asTenant($tenant, function () use ($variant, $connection): SyncOperation {
            $listing = $this->listing($variant, $connection, lifecycle: 'draft', externalId: null);

            return app(OpenSyncOperation::class)->run(listing: $listing, domain: SyncDomain::CONTENT, eventVersion: 1);
        });

        (new PushListing($operation->id, $tenant->id))->handle(
            app(ListingPayloadBuilder::class),
            app(SyncResultRecorder::class),
            app(AdapterRegistry::class),
        );

        Http::assertNothingSent();
        $this->assertSame(SyncOperationStatus::DEAD, $this->asTenant($tenant, fn () => SyncOperation::query()->findOrFail($operation->id)->status));
    }

    private function context(?string $cost = null, ?string $compareAt = '249.90'): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Fiyat kuralı '.uniqid(), owner: $user);

        $variant = $this->asTenant($tenant, function () use ($tenant, $cost, $compareAt): Variant {
            $product = Product::factory()->create(['tenant_id' => $tenant->id, 'content_version' => 1]);

            return Variant::factory()->create([
                'tenant_id' => $tenant->id,
                'product_id' => $product->id,
                'price' => '199.90',
                'compare_at_price' => $compareAt,
                'cost_price' => $cost,
                'currency' => 'TRY',
            ]);
        });

        return [$tenant, $variant, $this->connection($tenant), $user];
    }

    private function connection(Tenant $tenant): ChannelConnection
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
            'settings' => [EtsyAdapter::SHOP_ID_KEY => '777', EtsyAdapter::SHOP_CURRENCY_KEY => $this->shopCurrency],
        ]));
    }

    private function rule(
        Tenant $tenant,
        ChannelConnection $connection,
        string $percent = '0',
        string $amount = '0',
        string $rounding = 'none',
        ?string $margin = null,
    ): void {
        $this->asTenant($tenant, fn () => ChannelPriceRule::query()->create([
            'tenant_id' => $tenant->id,
            'channel_connection_id' => $connection->id,
            'markup_percent' => $percent,
            'markup_amount' => $amount,
            'rounding' => $rounding,
            'min_margin_percent' => $margin,
        ]));
    }

    private function listing(
        Variant $variant,
        ChannelConnection $connection,
        ?string $channelPrice = null,
        string $currency = 'TRY',
        string $lifecycle = 'live',
        ?string $externalId = 'auto',
    ): Listing {
        return Listing::factory()->create([
            'channel_connection_id' => $connection->id,
            'variant_id' => $variant->id,
            'external_id' => $externalId === 'auto' ? (string) random_int(1000, 999999) : $externalId,
            'external_parent_id' => $externalId === null ? null : '9001',
            'lifecycle_status' => $lifecycle,
            'channel_price' => $channelPrice,
            'channel_price_currency' => $channelPrice === null ? null : $currency,
        ]);
    }
}
