<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\FetchExchangeRates;
use App\Domain\Catalog\Models\ChannelPriceRule;
use App\Domain\Catalog\Models\ExchangeRate;
use App\Domain\Catalog\Models\FxPricingRate;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Support\Fx\FxConverter;
use App\Domain\Catalog\Support\Fx\FxRates;
use App\Domain\Catalog\Support\Fx\TcmbBulletin;
use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Actions\OpenSyncOperation;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Jobs\PushPrices;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\PriceBatchBuilder;
use App\Domain\Sync\Support\SyncResultRecorder;
use App\Support\Observability\OpsWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S9 · otomatik kur çevirisi (TCMB döviz alış kuru).
 *
 * Sıra TEK yerde (`Listing`): TL fiyat kuralları → kur → kur payı → kur
 * yuvarlaması. Kur yoksa ya da bayatsa fiyat GİTMEZ; eski kurla ya da TL
 * rakamıyla sessizce gitmek yanlış fiyattan satış demekti.
 */
final class ExchangeRateConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-23 16:00', 'Europe/Istanbul'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Gerçek bülten (23 Eki 2026) okunur; JPY 100 birimliktir, boş alan null olur. */
    #[Test]
    public function the_real_tcmb_bulletin_is_parsed(): void
    {
        $bulletin = TcmbBulletin::parse($this->fixture());

        $this->assertSame('2026-10-23', $bulletin->date->format('Y-m-d'));
        $this->assertSame('2026/201', $bulletin->bulletinNo);

        $rates = collect($bulletin->rates)->keyBy('currency');
        $this->assertSame('55.4997', $rates['EUR']['forex_buying']);
        $this->assertSame('55.5997', $rates['EUR']['forex_selling']);
        $this->assertSame(100, $rates['JPY']['unit']);
        $this->assertNull($rates['XDR']['forex_selling']);
    }

    #[Test]
    public function a_broken_bulletin_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        TcmbBulletin::parse('<html>bakım</html>');
    }

    /** Kur → kur payı → yuvarlama; kuruş hesabı, yuvarlama daima yukarı. */
    #[Test]
    public function conversion_order_and_roundings(): void
    {
        // 1.299,90 / 55,4997 = 23,4217 → 23,42 · +%8 → 25,29 · ,99 → 25,99
        $this->assertSame('25.99', FxConverter::convert('1299.90', '55.4997', '8.00', ChannelPriceRule::ROUNDING_X99));
        $this->assertSame('25.29', FxConverter::convert('1299.90', '55.4997', '8.00', ChannelPriceRule::ROUNDING_NONE));
        $this->assertSame('25.49', FxConverter::convert('1299.90', '55.4997', '8.00', ChannelPriceRule::ROUNDING_X49));
        $this->assertSame('25.50', FxConverter::convert('1299.90', '55.4997', '8.00', ChannelPriceRule::ROUNDING_HALF));
        $this->assertSame('26.00', FxConverter::convert('1299.90', '55.4997', '8.00', ChannelPriceRule::ROUNDING_WHOLE));
        // Tam sınırda yuvarlama dokunmaz: 25,50 → 25,50; 25,49 → 25,49.
        $this->assertSame('25.50', FxConverter::convert('2550', '100', '0', ChannelPriceRule::ROUNDING_HALF));
        $this->assertSame('25.49', FxConverter::convert('2549', '100', '0', ChannelPriceRule::ROUNDING_X49));
        // Bölme yarım yukarı: 1 / 3 = 0,333… → 0,33; 2 / 3 → 0,67.
        $this->assertSame('0.33', FxConverter::convert('1', '3', '0', ChannelPriceRule::ROUNDING_NONE));
        $this->assertSame('0.67', FxConverter::convert('2', '3', '0', ChannelPriceRule::ROUNDING_NONE));
    }

    /** Çeviri açıksa TL fiyat kanal birimine çevrilip gider; TL kuralı ÖNCE uygulanır. */
    #[Test]
    public function the_converted_price_goes_through_the_price_batch(): void
    {
        $this->ingest($this->fixture());
        [$tenant, $variant, $connection] = $this->context();
        // TL kuralı: +%10, ,90 → 199,90 × 1,10 = 219,89 → 219,90 TL
        // kur: 219,90 / 49,5647 = 4,4366 → 4,44 · +%5 → 4,66 · ,99 → 4,99 USD
        $this->rule($tenant, $connection, percent: '10', rounding: 'x90', fx: ['USD', '5', 'x99', null]);

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            $listing = $this->listing($variant, $connection);
            $operation = app(OpenSyncOperation::class)->run(listing: $listing, domain: SyncDomain::PRICE, eventVersion: 2);

            $batch = DB::transaction(fn () => app(PriceBatchBuilder::class)->build($operation));

            $this->assertSame([], $batch->blocked());
            $this->assertSame('4.99', $batch->items[0]['price']);

            $fresh = Listing::query()->with('variant')->findOrFail($listing->id);
            $this->assertSame('USD', $fresh->effectiveCurrency());
            $this->assertSame('219.90', $fresh->sourceEffectivePrice());
            $this->assertSame(['sourcePrice' => '219.90', 'sourceCurrency' => 'TRY', 'price' => '4.99', 'currency' => 'USD'], array_intersect_key($fresh->fxBreakdown(), array_flip(['sourcePrice', 'sourceCurrency', 'price', 'currency'])));
        });
    }

    /** Üstü çizili fiyat da aynı kurla çevrilir; yuvarlama eşitlerse gitmez. */
    #[Test]
    public function the_compare_at_price_is_converted_too(): void
    {
        $this->ingest($this->fixture());
        [$tenant, $variant, $connection] = $this->context(compareAt: '399.90');
        $this->rule($tenant, $connection, fx: ['USD', '0', 'none', null]);

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            $listing = Listing::query()->with('variant')->findOrFail($this->listing($variant, $connection)->id);

            // 399,90 / 49,5647 = 8,0682 → 8,07 · 199,90 → 4,03
            $this->assertSame('8.07', $listing->effectiveCompareAtPrice());
            $this->assertSame('4.03', $listing->effectivePrice());
        });
    }

    /** Kur yoksa fiyat gitmez, kanala istek atılmaz, neden panelde yazar. */
    #[Test]
    public function without_a_rate_the_price_is_blocked(): void
    {
        [$tenant, $variant, $connection] = $this->context();
        $this->rule($tenant, $connection, fx: ['USD', '0', 'none', null]);

        $operation = $this->asTenant($tenant, fn () => app(OpenSyncOperation::class)->run(listing: $this->listing($variant, $connection), domain: SyncDomain::PRICE, eventVersion: 2));
        $this->runPushPrices($operation, $tenant);

        Http::assertNothingSent();
        $this->asTenant($tenant, function () use ($operation): void {
            $this->assertSame(SyncOperationStatus::DEAD, SyncOperation::query()->findOrFail($operation->id)->status);
            $state = ListingSyncState::query()->where('listing_id', $operation->entity_id)->where('domain', SyncDomain::PRICE->value)->sole();
            $this->assertStringContainsString('USD kuru henüz alınmadı', (string) $state->last_error);
        });
    }

    /** Son bülten 4 günden eskiyse kur bayattır: eski kurla fiyat gitmez. */
    #[Test]
    public function a_stale_rate_blocks_the_price(): void
    {
        $this->ingest($this->fixture());
        [$tenant, $variant, $connection] = $this->context();
        $this->rule($tenant, $connection, fx: ['USD', '0', 'none', null]);

        // Cuma bülteni Salı hâlâ geçerli (4 gün) …
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00', 'Europe/Istanbul'));
        $this->asTenant($tenant, function () use ($variant, $connection): void {
            app(FxRates::class)->forget();
            $listing = Listing::query()->with('variant')->findOrFail($this->listing($variant, $connection)->id);
            $this->assertNull($listing->fxBlockReason());
            $this->assertSame('4.03', $listing->effectivePrice());
        });

        // … Çarşamba bayat (5 gün).
        Carbon::setTestNow(Carbon::parse('2026-10-28 10:00', 'Europe/Istanbul'));
        $this->asTenant($tenant, function () use ($variant): void {
            app(FxRates::class)->forget();
            $listing = Listing::query()->with('variant')->where('variant_id', $variant->id)->firstOrFail();
            $this->assertStringContainsString('23.10.2026 tarihinden beri güncellenmedi', (string) $listing->priceBlockReason('USD'));
            $this->assertNull($listing->effectivePrice());
        });
    }

    /** Kur çevirisi yanlış birime ayarlandıysa (kanal USD, ayar EUR) fiyat yine durur. */
    #[Test]
    public function a_wrong_target_currency_is_still_blocked_by_the_currency_gate(): void
    {
        $this->ingest($this->fixture());
        [$tenant, $variant, $connection] = $this->context();
        $this->rule($tenant, $connection, fx: ['EUR', '0', 'none', null]);

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            $listing = Listing::query()->with('variant')->findOrFail($this->listing($variant, $connection)->id);
            $this->assertStringContainsString('Bu kanal USD', (string) $listing->priceBlockReason('USD'));
        });
    }

    /** En düşük fiyatın altındaki çevrilmiş fiyat gitmez. */
    #[Test]
    public function the_minimum_price_blocks_a_too_low_converted_price(): void
    {
        $this->ingest($this->fixture());
        [$tenant, $variant, $connection] = $this->context();
        $this->rule($tenant, $connection, fx: ['USD', '0', 'none', '5.00']);

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            $listing = Listing::query()->with('variant')->findOrFail($this->listing($variant, $connection)->id);
            $this->assertStringContainsString('en düşük fiyat 5.00 USD', (string) $listing->priceBlockReason('USD'));
        });
    }

    /** Zarar koruması çeviriden ÖNCEKİ TL fiyatla çalışmaya devam eder. */
    #[Test]
    public function the_cost_floor_still_applies_in_the_source_currency(): void
    {
        $this->ingest($this->fixture());
        [$tenant, $variant, $connection] = $this->context(cost: '190.00');
        $this->rule($tenant, $connection, margin: '10', fx: ['USD', '0', 'none', null]);

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            $listing = Listing::query()->with('variant')->findOrFail($this->listing($variant, $connection)->id);
            $this->assertStringContainsString('zarar koruması', (string) $listing->priceBlockReason('USD'));
        });
    }

    /**
     * Kâr eden TL fiyat, çevrilmiş rakam küçük diye DURDURULMAZ: 199,90 TL
     * maliyet 100 TL'nin %10 üstünde; 4,03 USD'yi TL tabanla (110) kıyaslamak
     * her yurt dışı ilanı durdururdu.
     */
    #[Test]
    public function a_profitable_price_is_not_blocked_because_the_converted_number_is_small(): void
    {
        $this->ingest($this->fixture());
        [$tenant, $variant, $connection] = $this->context(cost: '100.00');
        $this->rule($tenant, $connection, margin: '10', fx: ['USD', '0', 'none', null]);

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            $listing = Listing::query()->with('variant')->findOrFail($this->listing($variant, $connection)->id);
            $this->assertNull($listing->priceBlockReason('USD'));
            $this->assertSame('4.03', $listing->effectivePrice());
        });
    }

    /** Elle girilmiş kanal fiyatı zaten hedef birimdeyse çevrilmez. */
    #[Test]
    public function a_channel_price_in_the_target_currency_is_not_converted(): void
    {
        $this->ingest($this->fixture());
        [$tenant, $variant, $connection] = $this->context();
        $this->rule($tenant, $connection, fx: ['USD', '8', 'x99', null]);

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            $listing = Listing::query()->with('variant')->findOrFail($this->listing($variant, $connection, channelPrice: '12.90', currency: 'USD')->id);
            $this->assertFalse($listing->convertsCurrency());
            $this->assertSame('12.90', $listing->effectivePrice());
        });
    }

    /** Aynı bülten iki kez gelirse hiçbir şey değişmez; eşik altı fark kanala gitmez, eşik üstü gider. */
    #[Test]
    public function the_anchor_moves_only_beyond_the_threshold_and_then_resyncs(): void
    {
        [$tenant, $variant, $connection] = $this->context();
        $this->rule($tenant, $connection, fx: ['USD', '0', 'none', null]);
        $listingId = $this->asTenant($tenant, fn () => $this->listing($variant, $connection)->id);

        // İlk bülten: kur hiç yoktu → çapa kurulur ve durdurulmuş fiyat yeniden gönderilir.
        $first = $this->ingest($this->bulletin('10/23/2026', '49.5647'));
        $this->assertSame(['USD'], $first['anchored']);
        $this->assertSame(1, $first['resynced']);

        // Aynı bülten ikinci kez: çapa yerinde, resync yok.
        $again = $this->ingest($this->bulletin('10/23/2026', '49.5647'));
        $this->assertSame([], $again['anchored']);
        $this->assertSame(0, $again['resynced']);

        // %0,3 fark: çapa kalır, kanala istek gitmez.
        $small = $this->ingest($this->bulletin('10/26/2026', '49.7134'));
        $this->assertSame([], $small['anchored']);
        $this->assertSame(0, $small['resynced']);
        $this->assertSame('49.564700', (string) $this->asSystem(fn () => FxPricingRate::query()->findOrFail('USD')->rate));

        // %0,6 fark: çapa taşınır, ilan yeniden gönderilir.
        $big = $this->ingest($this->bulletin('10/27/2026', '49.8621'));
        $this->assertSame(['USD'], $big['anchored']);
        $this->assertSame(1, $big['resynced']);

        $events = $this->asSystem(fn () => OutboxEvent::query()->where('aggregate_id', $listingId)->get()->filter(fn ($e) => ($e->payload['reason'] ?? null) === 'exchange_rate_changed'));
        $this->assertCount(2, $events);
    }

    /** Bayat kur yenilenince durdurulmuş fiyatlar kendiliğinden geri gelir (eşik altı olsa da). */
    #[Test]
    public function a_stale_rate_coming_back_resyncs_even_without_a_big_move(): void
    {
        [$tenant, $variant, $connection] = $this->context();
        $this->rule($tenant, $connection, fx: ['USD', '0', 'none', null]);
        $this->asTenant($tenant, fn () => $this->listing($variant, $connection));

        $this->ingest($this->bulletin('10/16/2026', '49.5647'));
        Carbon::setTestNow(Carbon::parse('2026-10-23 16:00', 'Europe/Istanbul'));

        $result = $this->ingest($this->bulletin('10/23/2026', '49.6000'));

        $this->assertSame([], $result['anchored']);
        $this->assertSame(1, $result['resynced']);
    }

    /** Kur çevirisi açık birimin kuru bayatsa izleme alarm verir. */
    #[Test]
    public function ops_watch_reports_a_stale_rate(): void
    {
        [$tenant, , $connection] = $this->context();
        $this->rule($tenant, $connection, fx: ['USD', '0', 'none', null]);

        $keys = array_column(app(OpsWatch::class)->findings(), 'key');
        $this->assertContains('fx:USD', $keys);

        $this->ingest($this->fixture());
        $this->assertNotContains('fx:USD', array_column(app(OpsWatch::class)->findings(), 'key'));
    }

    /** Çekim gerçek URL'ye gider ve bülteni saklar (HTTP sahte). */
    #[Test]
    public function fetch_stores_the_bulletin(): void
    {
        Http::fake(['www.tcmb.gov.tr/*' => Http::response($this->fixture(), 200)]);

        $result = app(FetchExchangeRates::class)->run();

        $this->assertSame('2026-10-23', $result['date']);
        $this->assertSame(1, $this->asSystem(fn () => ExchangeRate::query()->where('currency', 'EUR')->count()));
        $this->assertSame('0.315228', (string) $this->asSystem(fn () => FxPricingRate::query()->findOrFail('JPY')->rate));
    }

    /** Ayar ekranı: çeviri açılırken birim zorunlu, kur payı negatif olamaz; kayıt resync ister. */
    #[Test]
    public function the_pricing_screen_saves_fx_settings(): void
    {
        [$tenant, $variant, $connection, $user] = $this->context();
        $this->asTenant($tenant, fn () => $this->listing($variant, $connection));
        $base = ['markup_percent' => '0', 'markup_amount' => '0', 'rounding' => 'none', 'min_margin_percent' => null];

        $this->actingAs($user)->put("/channels/{$connection->id}/pricing", $base + ['fx_enabled' => true, 'fx_currency' => null])
            ->assertSessionHasErrors('fx_currency');
        $this->actingAs($user)->put("/channels/{$connection->id}/pricing", $base + ['fx_enabled' => true, 'fx_currency' => 'USD', 'fx_markup_percent' => '-5'])
            ->assertSessionHasErrors('fx_markup_percent');
        $this->actingAs($user)->put("/channels/{$connection->id}/pricing", $base + ['fx_enabled' => true, 'fx_currency' => 'PLN'])
            ->assertSessionHasErrors('fx_currency');

        $this->actingAs($user)->put("/channels/{$connection->id}/pricing", $base + [
            'fx_enabled' => true, 'fx_currency' => 'USD', 'fx_markup_percent' => '8', 'fx_rounding' => 'x99', 'fx_min_price' => '3',
        ])->assertRedirect();

        $rule = $this->asTenant($tenant, fn () => ChannelPriceRule::query()->where('channel_connection_id', $connection->id)->sole());
        $this->assertTrue($rule->fx_enabled);
        $this->assertSame('USD', $rule->fx_currency);
        $this->assertSame('8.00', (string) $rule->fx_markup_percent);
        $this->assertSame('x99', $rule->fx_rounding);
        $this->assertSame('3.00', (string) $rule->fx_min_price);

        $this->actingAs($user)->get("/channels/{$connection->id}/pricing")
            ->assertInertia(fn ($page) => $page->component('Channels/Pricing')
                ->where('rule.fxCurrency', 'USD')
                ->where('channelCurrency', 'USD')
                ->has('fxQuotes.EUR'));
    }

    // ── yardımcılar ────────────────────────────────────────────────────

    private function fixture(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/fx/tcmb-2026-10-23.xml'));
    }

    private function bulletin(string $date, string $usdBuying): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <Tarih_Date Tarih="x" Date="{$date}" Bulten_No="test">
              <Currency CrossOrder="0" Kod="USD" CurrencyCode="USD">
                <Unit>1</Unit><ForexBuying>{$usdBuying}</ForexBuying><ForexSelling>{$usdBuying}</ForexSelling>
              </Currency>
            </Tarih_Date>
            XML;
    }

    /** @return array{date: string, stored: int, anchored: list<string>, resynced: int} */
    private function ingest(string $xml): array
    {
        $result = app(FetchExchangeRates::class)->ingest(TcmbBulletin::parse(trim($xml)));
        app(FxRates::class)->forget();

        return $result;
    }

    private function runPushPrices(SyncOperation $operation, Tenant $tenant): void
    {
        (new PushPrices($operation->id, $tenant->id))->handle(
            app(PriceBatchBuilder::class),
            app(SyncResultRecorder::class),
            app(AdapterRegistry::class),
        );
    }

    /** @return array{0: Tenant, 1: Variant, 2: ChannelConnection, 3: User} */
    private function context(?string $cost = null, ?string $compareAt = null): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Kur '.uniqid(), owner: $user);

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

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'etsy'], [
            'name' => 'Etsy',
            'kind' => 'marketplace',
            'adapter_class' => EtsyAdapter::class,
            'supports_webhooks' => false,
            'is_active' => true,
        ]));

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_type_code' => 'etsy',
            'external_account_id' => 'etsy-'.uniqid(),
            'status' => 'active',
            'health_status' => 'healthy',
            'settings' => [EtsyAdapter::SHOP_ID_KEY => '777', EtsyAdapter::SHOP_CURRENCY_KEY => 'USD'],
        ]));

        return [$tenant, $variant, $connection, $user];
    }

    /** @param  array{0: string, 1: string, 2: string, 3: string|null}|null  $fx  [birim, kur payı, yuvarlama, en düşük] */
    private function rule(
        Tenant $tenant,
        ChannelConnection $connection,
        string $percent = '0',
        string $rounding = 'none',
        ?string $margin = null,
        ?array $fx = null,
    ): void {
        $this->asTenant($tenant, fn () => ChannelPriceRule::query()->create([
            'tenant_id' => $tenant->id,
            'channel_connection_id' => $connection->id,
            'markup_percent' => $percent,
            'markup_amount' => '0',
            'rounding' => $rounding,
            'min_margin_percent' => $margin,
            'fx_enabled' => $fx !== null,
            'fx_currency' => $fx[0] ?? null,
            'fx_markup_percent' => $fx[1] ?? '0',
            'fx_rounding' => $fx[2] ?? 'none',
            'fx_min_price' => $fx[3] ?? null,
        ]));
    }

    private function listing(Variant $variant, ChannelConnection $connection, ?string $channelPrice = null, string $currency = 'TRY'): Listing
    {
        return Listing::factory()->create([
            'channel_connection_id' => $connection->id,
            'variant_id' => $variant->id,
            'external_id' => (string) random_int(1000, 999999),
            'external_parent_id' => '9001',
            'lifecycle_status' => 'live',
            'channel_price' => $channelPrice,
            'channel_price_currency' => $channelPrice === null ? null : $currency,
        ]);
    }
}
