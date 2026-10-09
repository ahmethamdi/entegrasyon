<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\ChannelPriceRule;
use App\Domain\Catalog\Models\PriceCampaign;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Support\ActiveCampaigns;
use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Actions\RequestResync;
use App\Domain\Sync\Models\Listing;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Süreli kampanyalar — tarih aralığında seçili ürünlere, seçili kanallarda indirim.
 *
 * Kampanya fiyatı SAKLANMAZ, `Listing::effectivePrice()` saatle hesaplar;
 * zamanlayıcının işi başlangıç/bitişte kanala yeniden göndermektir.
 */
final class PriceCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-10-09 10:00:00', 'UTC'));
    }

    // ─────────────────────────────────────────────────────────── hesap

    /** Yürürlükteki yüzde kampanyası fiyatı düşürür; normal fiyat üstü çizili gider. */
    #[Test]
    public function an_active_percent_campaign_discounts_and_strikes_through_the_normal_price(): void
    {
        [$tenant, $variant, $connection] = $this->context(compareAt: null);
        $this->campaign($tenant, [$variant], [$connection], 'percent', '20');

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            $listing = $this->listing($variant, $connection);

            $this->assertSame('159.92', $listing->effectivePrice());
            $this->assertSame('199.90', $listing->effectiveCompareAtPrice());
        });
    }

    /** Varyantın kendi üstü çizili fiyatı normal fiyattan yüksekse o kalır. */
    #[Test]
    public function a_higher_variant_compare_at_wins_over_the_normal_price(): void
    {
        [$tenant, $variant, $connection] = $this->context(compareAt: '249.90');
        $this->campaign($tenant, [$variant], [$connection], 'percent', '20');

        $this->asTenant($tenant, fn () => $this->assertSame('249.90', $this->listing($variant, $connection)->effectiveCompareAtPrice()));
    }

    /** "Üstü çizili gösterme" seçiliyse normal fiyat karşılaştırma olarak gitmez. */
    #[Test]
    public function without_compare_at_the_normal_price_is_not_struck_through(): void
    {
        [$tenant, $variant, $connection] = $this->context(compareAt: null);
        $this->campaign($tenant, [$variant], [$connection], 'percent', '20', showCompareAt: false);

        $this->asTenant($tenant, fn () => $this->assertNull($this->listing($variant, $connection)->effectiveCompareAtPrice()));
    }

    /**
     * Kampanya kanalın NORMAL fiyatına (kural dahil) uygulanır, sonra kuralın
     * yuvarlaması: 199,90 → +%15 ,90 → 229,90 → %20 → 183,92 → ,90 yukarı → 184,90.
     */
    #[Test]
    public function the_campaign_applies_on_top_of_the_channel_rule(): void
    {
        [$tenant, $variant, $connection] = $this->context(compareAt: null);
        $this->asTenant($tenant, fn () => ChannelPriceRule::query()->create([
            'tenant_id' => $tenant->id, 'channel_connection_id' => $connection->id,
            'markup_percent' => '15', 'markup_amount' => '0', 'rounding' => 'x90', 'min_margin_percent' => null,
        ]));
        $this->campaign($tenant, [$variant], [$connection], 'percent', '20');

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            $listing = $this->listing($variant, $connection);

            $this->assertSame('184.90', $listing->effectivePrice());
            $this->assertSame('229.90', $listing->effectiveCompareAtPrice());
        });
    }

    /** Planlanan, biten ve iptal edilen kampanya fiyata dokunmaz. */
    #[Test]
    public function campaigns_outside_their_window_do_not_apply(): void
    {
        [$tenant, $variant, $connection] = $this->context();
        $this->campaign($tenant, [$variant], [$connection], 'percent', '20', starts: now()->addHour(), ends: now()->addDay());
        $this->campaign($tenant, [$variant], [$connection], 'percent', '30', starts: now()->subDays(2), ends: now()->subMinute());
        $cancelled = $this->campaign($tenant, [$variant], [$connection], 'percent', '40');
        $this->asTenant($tenant, fn () => $cancelled->forceFill(['cancelled_at' => now()])->save());
        app(ActiveCampaigns::class)->forget($tenant->id);

        $this->asTenant($tenant, fn () => $this->assertSame('199.90', $this->listing($variant, $connection)->effectivePrice()));
    }

    /** Seçilmeyen kanal ve seçilmeyen varyant etkilenmez. */
    #[Test]
    public function only_selected_channels_and_variants_are_discounted(): void
    {
        [$tenant, $variant, $connection] = $this->context();
        $otherConnection = $this->connection($tenant);
        $otherVariant = $this->asTenant($tenant, fn () => Variant::factory()->create([
            'tenant_id' => $tenant->id, 'product_id' => $variant->product_id, 'price' => '50.00', 'currency' => 'TRY',
        ]));
        $this->campaign($tenant, [$variant], [$connection], 'percent', '20');

        $this->asTenant($tenant, function () use ($variant, $otherVariant, $connection, $otherConnection): void {
            $this->assertSame('199.90', $this->listing($variant, $otherConnection)->effectivePrice());
            $this->assertSame('50.00', $this->listing($otherVariant, $connection)->effectivePrice());
        });
    }

    /**
     * ⚠️ TUTAR İNDİRİMİ FARKLI PARA BİRİMİNDE UYGULANMAZ; YÜZDE UYGULANIR.
     *
     * "50 TL indirim" $12.90'lık USD Etsy fiyatından 50 düşseydi ilan sıfıra
     * inerdi.
     */
    #[Test]
    public function an_amount_discount_skips_a_foreign_currency_price_but_percent_applies(): void
    {
        [$tenant, $variant, $connection] = $this->context();
        $amount = $this->campaign($tenant, [$variant], [$connection], 'amount', '5');

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            $this->assertSame('12.90', $this->listing($variant, $connection, channelPrice: '12.90', currency: 'USD')->effectivePrice());
        });

        $this->asTenant($tenant, fn () => $amount->forceFill(['cancelled_at' => now()])->save());
        $this->campaign($tenant, [$variant], [$connection], 'percent', '10');

        $this->asTenant($tenant, function () use ($variant): void {
            $listing = Listing::query()->where('variant_id', $variant->id)->sole();
            // 12,90 × 0,90 = 11,61 — elle fiyatta yuvarlama yok.
            $this->assertSame('11.61', $listing->effectivePrice());
        });
    }

    /** Aynı ilana iki kampanya düşerse EN DÜŞÜK fiyat kazanır — sorgu sırasına kalmaz. */
    #[Test]
    public function overlapping_campaigns_pick_the_lowest_price(): void
    {
        [$tenant, $variant, $connection] = $this->context();
        $this->campaign($tenant, [$variant], [$connection], 'percent', '10');
        $this->campaign($tenant, [$variant], [$connection], 'amount', '50');
        $this->campaign($tenant, [$variant], [$connection], 'percent', '15');

        $this->asTenant($tenant, fn () => $this->assertSame('149.90', $this->listing($variant, $connection)->effectivePrice()));
    }

    /** Zarar koruması kampanya fiyatını da denetler. */
    #[Test]
    public function the_floor_guards_campaign_prices(): void
    {
        [$tenant, $variant, $connection] = $this->context(cost: '170.00');
        $this->asTenant($tenant, fn () => ChannelPriceRule::query()->create([
            'tenant_id' => $tenant->id, 'channel_connection_id' => $connection->id,
            'markup_percent' => '0', 'markup_amount' => '0', 'rounding' => 'none', 'min_margin_percent' => '0',
        ]));
        $this->campaign($tenant, [$variant], [$connection], 'percent', '20');

        $this->asTenant($tenant, fn () => $this->assertNotNull($this->listing($variant, $connection)->priceFloorViolation()));
    }

    // ─────────────────────────────────────────────── oluşturma

    /**
     * Başlangıcı geçmiş kampanya kaydedilince HEMEN başlar: canlı ilanların
     * fiyatı yeniden gönderilir, başlangıç işareti konur. Taslak ilan gitmez.
     */
    #[Test]
    public function creating_an_active_campaign_pushes_live_listings_at_once(): void
    {
        [$tenant, $variant, $connection, $user] = $this->context();
        $live = $this->asTenant($tenant, fn () => $this->listing($variant, $connection));
        $draftVariant = $this->asTenant($tenant, fn () => Variant::factory()->create(['tenant_id' => $tenant->id, 'product_id' => $variant->product_id, 'price' => '10', 'currency' => 'TRY']));
        $this->asTenant($tenant, fn () => $this->listing($draftVariant, $connection, lifecycle: 'draft'));

        $this->actingAs($user)->post('/campaigns', $this->payload($variant, $connection, starts: '2026-10-09T12:00'))
            ->assertRedirect('/campaigns')
            ->assertSessionHasNoErrors();

        $this->asTenant($tenant, function () use ($live): void {
            $campaign = PriceCampaign::query()->sole();
            $this->assertSame('active', $campaign->status());
            $this->assertNotNull($campaign->start_pushed_at);

            $event = OutboxEvent::query()->where('event_type', 'ListingResyncRequested')->sole();
            $this->assertSame($live->id, $event->payload['listing_id']);
            $this->assertSame(RequestResync::REASON_CAMPAIGN_CHANGED, $event->payload['reason']);
        });
    }

    /**
     * ⚠️ TARİH KİRACININ SAAT DİLİMİNDE GİRİLİR. İstanbul'da "1 Kasım 00:00"
     * UTC 31 Ekim 21:00'dir; UTC sanılsaydı kampanya 3 saat geç başlardı.
     * Gelecekteki kampanya hiçbir şey göndermez.
     */
    #[Test]
    public function dates_are_entered_in_the_tenant_timezone_and_scheduled_campaigns_push_nothing(): void
    {
        [$tenant, $variant, $connection, $user] = $this->context();
        $this->asTenant($tenant, fn () => $this->listing($variant, $connection));

        $this->actingAs($user)->post('/campaigns', $this->payload($variant, $connection, starts: '2026-11-01T00:00', ends: '2026-11-30T23:59'))
            ->assertSessionHasNoErrors();

        $this->asTenant($tenant, function (): void {
            $campaign = PriceCampaign::query()->sole();
            $this->assertSame('2026-10-31 21:00:00', $campaign->starts_at->utc()->format('Y-m-d H:i:s'));
            $this->assertSame('scheduled', $campaign->status());
            $this->assertSame(0, OutboxEvent::query()->where('event_type', 'ListingResyncRequested')->count());
        });
    }

    /** %100 ve üstü, ters tarih, başka kiracının bağlantısı reddedilir. */
    #[Test]
    public function invalid_campaigns_are_rejected(): void
    {
        [$tenant, $variant, $connection, $user] = $this->context();
        [, , $foreignConnection] = $this->context();

        $this->actingAs($user)->post('/campaigns', [...$this->payload($variant, $connection), 'discount_value' => '100'])
            ->assertSessionHasErrors('discount_value');
        $this->actingAs($user)->post('/campaigns', $this->payload($variant, $connection, starts: '2026-10-20T10:00', ends: '2026-10-19T10:00'))
            ->assertSessionHasErrors('ends_at');
        $this->actingAs($user)->post('/campaigns', [...$this->payload($variant, $connection), 'connection_ids' => [$foreignConnection->id]])
            ->assertSessionHasErrors('connection_ids.0');

        $this->assertSame(0, $this->asTenant($tenant, fn () => PriceCampaign::query()->count()));
    }

    // ─────────────────────────────────────────────── zamanlayıcı ve iptal

    /**
     * Başlangıç gelince tur fiyatları gönderir, ikinci tur TEKRAR göndermez;
     * bitişte bir kez daha gönderir (fiyat normale döner).
     */
    #[Test]
    public function the_tick_pushes_once_at_start_and_once_at_end(): void
    {
        [$tenant, $variant, $connection] = $this->context();
        $this->asTenant($tenant, fn () => $this->listing($variant, $connection));
        $campaign = $this->campaign($tenant, [$variant], [$connection], 'percent', '20', starts: now()->addHour(), ends: now()->addHours(3));

        $this->artisan('campaigns:tick')->assertSuccessful();
        $this->assertSame(0, $this->resyncCount($tenant), 'Başlamamış kampanya gönderildi.');

        $this->travel(61)->minutes();
        $this->artisan('campaigns:tick')->assertSuccessful();
        $this->artisan('campaigns:tick')->assertSuccessful();
        $this->assertSame(1, $this->resyncCount($tenant), 'Başlangıç ya hiç ya da iki kez gönderildi.');

        $this->travel(2)->hours();
        $this->artisan('campaigns:tick')->assertSuccessful();
        $this->artisan('campaigns:tick')->assertSuccessful();
        $this->assertSame(2, $this->resyncCount($tenant), 'Bitiş ya hiç ya da iki kez gönderildi.');

        $this->assertNotNull($this->asTenant($tenant, fn () => PriceCampaign::query()->findOrFail($campaign->id)->end_pushed_at));
    }

    /** Yürürlükteki kampanya iptal edilince fiyatlar normale döner; planlanan iptal hiçbir şey göndermez. */
    #[Test]
    public function cancelling_reverts_an_active_campaign_and_a_scheduled_one_sends_nothing(): void
    {
        [$tenant, $variant, $connection, $user] = $this->context();
        $this->asTenant($tenant, fn () => $this->listing($variant, $connection));
        $active = $this->campaign($tenant, [$variant], [$connection], 'percent', '20');
        $this->asTenant($tenant, fn () => $active->forceFill(['start_pushed_at' => now()])->save());
        $scheduled = $this->campaign($tenant, [$variant], [$connection], 'percent', '20', starts: now()->addDay(), ends: now()->addDays(2));

        $this->actingAs($user)->post("/campaigns/{$scheduled->id}/cancel")->assertRedirect();
        $this->assertSame(0, $this->resyncCount($tenant));

        $this->actingAs($user)->post("/campaigns/{$active->id}/cancel")->assertRedirect();
        $this->assertSame(1, $this->resyncCount($tenant));

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            app(ActiveCampaigns::class)->forget(Listing::query()->firstOrFail()->tenant_id);
            $this->assertSame('199.90', Listing::query()->where('variant_id', $variant->id)->where('channel_connection_id', $connection->id)->sole()->effectivePrice());
        });

        // Bitmiş/iptal edilmiş kampanya tur tarafından başlatılmaz.
        $this->artisan('campaigns:tick')->assertSuccessful();
        $this->assertSame(1, $this->resyncCount($tenant));
    }

    /** Ürün araması kiracıya kapalı; liste ve form sayfaları açılır. */
    #[Test]
    public function screens_open_and_product_search_is_tenant_scoped(): void
    {
        [, , , $user] = $this->context(title: 'Antep Fıstığı');
        [, , , $stranger] = $this->context(title: 'Gizli Ürün');

        $this->actingAs($user)->get('/campaigns')->assertOk()->assertInertia(fn ($p) => $p->component('Campaigns/Index'));
        $this->actingAs($user)->get('/campaigns/create')->assertOk()->assertInertia(fn ($p) => $p->component('Campaigns/Create')->has('connections', 1));

        $this->actingAs($user)->getJson('/campaigns/products?q=fıst')->assertOk()->assertJsonCount(1)->assertJsonPath('0.title', 'Antep Fıstığı');
        $this->actingAs($user)->getJson('/campaigns/products?q=Gizli')->assertOk()->assertJsonCount(0);
        $this->actingAs($stranger)->getJson('/campaigns/products?q=Antep')->assertOk()->assertJsonCount(0);
    }

    // ─────────────────────────────────────────────── yardımcılar

    /** @return array{0: Tenant, 1: Variant, 2: ChannelConnection, 3: User} */
    private function context(?string $cost = null, ?string $compareAt = null, string $title = 'Krem'): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Kampanya '.uniqid(), owner: $user);

        $variant = $this->asTenant($tenant, function () use ($tenant, $cost, $compareAt, $title): Variant {
            $product = Product::factory()->create(['tenant_id' => $tenant->id, 'title' => $title, 'content_version' => 1]);

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
            'name' => 'Etsy', 'kind' => 'marketplace', 'adapter_class' => EtsyAdapter::class,
            'supports_webhooks' => false, 'is_active' => true,
        ]));

        return $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_type_code' => 'etsy',
            'external_account_id' => 'etsy-'.uniqid(),
            'status' => 'active',
            'health_status' => 'healthy',
            'settings' => [EtsyAdapter::SHOP_ID_KEY => '777', EtsyAdapter::SHOP_CURRENCY_KEY => 'TRY'],
        ]));
    }

    /**
     * @param  list<Variant>  $variants
     * @param  list<ChannelConnection>  $connections
     */
    private function campaign(
        Tenant $tenant,
        array $variants,
        array $connections,
        string $type,
        string $value,
        ?CarbonInterface $starts = null,
        ?CarbonInterface $ends = null,
        bool $showCompareAt = true,
    ): PriceCampaign {
        $campaign = $this->asTenant($tenant, function () use ($tenant, $variants, $connections, $type, $value, $starts, $ends, $showCompareAt): PriceCampaign {
            $campaign = PriceCampaign::query()->create([
                'tenant_id' => $tenant->id,
                'name' => 'Test '.uniqid(),
                'discount_type' => $type,
                'discount_value' => $value,
                'starts_at' => $starts ?? now()->subHour(),
                'ends_at' => $ends ?? now()->addDay(),
                'show_compare_at' => $showCompareAt,
            ]);

            foreach ($variants as $variant) {
                DB::table('price_campaign_variants')->insert(['price_campaign_id' => $campaign->id, 'variant_id' => $variant->id, 'tenant_id' => $tenant->id]);
            }

            foreach ($connections as $connection) {
                DB::table('price_campaign_connections')->insert(['price_campaign_id' => $campaign->id, 'channel_connection_id' => $connection->id, 'tenant_id' => $tenant->id]);
            }

            return $campaign;
        });

        app(ActiveCampaigns::class)->forget($tenant->id);

        return $campaign;
    }

    /** @return array<string, mixed> */
    private function payload(Variant $variant, ChannelConnection $connection, string $starts = '2026-10-09T12:00', string $ends = '2026-10-20T12:00'): array
    {
        return [
            'name' => 'Kasım indirimi',
            'discount_type' => 'percent',
            'discount_value' => '20',
            'starts_at' => $starts,
            'ends_at' => $ends,
            'show_compare_at' => true,
            'product_ids' => [$variant->product_id],
            'connection_ids' => [$connection->id],
        ];
    }

    private function listing(
        Variant $variant,
        ChannelConnection $connection,
        ?string $channelPrice = null,
        string $currency = 'TRY',
        string $lifecycle = 'live',
    ): Listing {
        return Listing::factory()->create([
            'channel_connection_id' => $connection->id,
            'variant_id' => $variant->id,
            'external_id' => (string) random_int(1000, 999999),
            'external_parent_id' => '9001',
            'lifecycle_status' => $lifecycle,
            'channel_price' => $channelPrice,
            'channel_price_currency' => $channelPrice === null ? null : $currency,
        ]);
    }

    private function resyncCount(Tenant $tenant): int
    {
        return $this->asTenant($tenant, fn (): int => OutboxEvent::query()->where('event_type', 'ListingResyncRequested')->count());
    }
}
