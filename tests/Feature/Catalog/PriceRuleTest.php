<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\SetChannelPriceRule;
use App\Domain\Catalog\Actions\SetVariantCost;
use App\Domain\Catalog\Models\ChannelPriceRule;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Support\PriceRuleCalculator;
use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Identity\Models\AuditLog;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Actions\OpenSyncOperation;
use App\Domain\Sync\Actions\RequestResync;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fiyat kuralları — kalıcı kanal farkı + zarar koruması.
 *
 * "Trendyol'da her şey +%15, ,90'a yuvarla" kuralı TEK KAYNAKTA
 * (`Listing::effectivePrice`) uygulanır; gönderim, ilan açma ve mutabakat
 * aynı rakamı görür. Zarar koruması maliyet tabanının altındaki fiyatı
 * kanala GÖNDERMEZ ve nedenini listing'de gösterir.
 */
final class PriceRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();
    }

    // ─────────────────────────────────────────────────────────── hesap

    /**
     * Kuruş hesabı, yarım kuruş yukarı; yuvarlama DAİMA yukarı.
     *
     * @param  array{0: string, 1: string, 2: string}  $rule
     */
    #[Test]
    #[DataProvider('calculations')]
    public function the_rule_is_applied_in_minor_units(string $price, array $rule, string $expected): void
    {
        $model = new ChannelPriceRule(['markup_percent' => $rule[0], 'markup_amount' => $rule[1], 'rounding' => $rule[2]]);

        $this->assertSame($expected, PriceRuleCalculator::apply($price, $model));
    }

    /** @return array<string, array{0: string, 1: array{0: string, 1: string, 2: string}, 2: string}> */
    public static function calculations(): array
    {
        return [
            // 199,90 × 1,15 = 229,885 → float'ta 229,88 çıkabilirdi.
            'yüzde, yarım kuruş yukarı' => ['199.90', ['15', '0', 'none'], '229.89'],
            ',90 yukarı' => ['199.90', ['15', '0', 'x90'], '229.90'],
            ',99 yukarı' => ['199.90', ['15', '0', 'x99'], '229.99'],
            'tam sayıya yukarı' => ['199.90', ['15', '0', 'whole'], '230.00'],
            'zaten ,90 ise dokunmaz' => ['229.90', ['0', '0', 'x90'], '229.90'],
            ',95 → bir sonraki ,90' => ['229.95', ['0', '0', 'x90'], '230.90'],
            'yüzde sonra tutar' => ['100.00', ['10', '5', 'none'], '115.00'],
            'indirim' => ['100.00', ['-20', '0', 'none'], '80.00'],
            'negatif sıfıra kırpılır' => ['10.00', ['0', '-50', 'none'], '0.00'],
        ];
    }

    /** Taban yukarı yuvarlanır: bir kuruş aşağı kaysa sınırdaki zararlı fiyat geçerdi. */
    #[Test]
    public function the_floor_rounds_up(): void
    {
        $this->assertSame('110.00', PriceRuleCalculator::floor('100.00', '10'));
        // 100,01 × 1,10 = 110,011 → 110,02 (yukarı).
        $this->assertSame('110.02', PriceRuleCalculator::floor('100.01', '10'));
        $this->assertSame('100.00', PriceRuleCalculator::floor('100.00', '0'));
    }

    // ─────────────────────────────────────────────── tek kaynak

    /** Kural varyant fiyatına ve üstü çizili fiyata uygulanır. */
    #[Test]
    public function the_rule_applies_to_the_variant_price_and_compare_at(): void
    {
        [$tenant, $variant, $connection] = $this->context();
        $this->rule($tenant, $connection, percent: '15', rounding: 'x90');

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            $listing = $this->listing($variant, $connection);

            $this->assertSame('229.90', $listing->effectivePrice());
            // 249,90 × 1,15 = 287,385 → 287,39 → ,90 yukarı.
            $this->assertSame('287.90', $listing->effectiveCompareAtPrice());
        });
    }

    /**
     * ⚠️ ELLE GİRİLEN KANAL FİYATINA KURAL UYGULANMAZ.
     *
     * Satıcının o kanala bilinçli yazdığı rakamın üstüne %15 eklemek iki kez
     * zam yapmak olurdu.
     */
    #[Test]
    public function a_manual_channel_price_ignores_the_rule(): void
    {
        [$tenant, $variant, $connection] = $this->context();
        $this->rule($tenant, $connection, percent: '15');

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            $listing = $this->listing($variant, $connection, channelPrice: '180.00');

            $this->assertSame('180.00', $listing->effectivePrice());
        });
    }

    /** İndirimli kural üstü çizili fiyatı satış fiyatının altına iterse gönderilmez. */
    #[Test]
    public function a_compare_at_not_above_the_price_is_dropped(): void
    {
        [$tenant, $variant, $connection] = $this->context(compareAt: '200.00');
        $this->rule($tenant, $connection, rounding: 'whole');

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            // 199,90 → 200,00; üstü çizili 200,00 → 200,00: eşit, indirim yok.
            $this->assertNull($this->listing($variant, $connection)->effectiveCompareAtPrice());
        });
    }

    /** Kuralı olmayan bağlantıda her şey eskisi gibi. */
    #[Test]
    public function without_a_rule_nothing_changes(): void
    {
        [$tenant, $variant, $connection] = $this->context();

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            $listing = $this->listing($variant, $connection);

            $this->assertSame('199.90', $listing->effectivePrice());
            $this->assertNull($listing->priceFloorViolation());
        });
    }

    // ─────────────────────────────────────────────── zarar koruması

    /** Maliyet + kâr tabanının altı: neden döner; üstü: null. */
    #[Test]
    public function the_floor_flags_a_price_below_cost_plus_margin(): void
    {
        [$tenant, $variant, $connection] = $this->context(cost: '190.00');
        $this->rule($tenant, $connection, margin: '10');

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            $listing = $this->listing($variant, $connection);

            // Taban 209,00; fiyat 199,90.
            $violation = $listing->priceFloorViolation();

            $this->assertNotNull($violation);
            $this->assertStringContainsString('209.00', $violation);

            $listing->forceFill(['channel_price' => '209.00', 'channel_price_currency' => 'TRY']);
            $this->assertNull($listing->priceFloorViolation(), 'Tam tabandaki fiyat geçmeliydi.');
        });
    }

    /** Maliyet bilinmiyorsa ya da koruma kapalıysa durdurma yok. */
    #[Test]
    public function the_floor_needs_a_cost_and_a_margin(): void
    {
        [$tenant, $variant, $connection] = $this->context(cost: null);
        $this->rule($tenant, $connection, margin: '10');

        $this->asTenant($tenant, fn () => $this->assertNull($this->listing($variant, $connection)->priceFloorViolation()));

        [$tenant2, $variant2, $connection2] = $this->context(cost: '500.00');
        $this->rule($tenant2, $connection2, percent: '5');

        $this->asTenant($tenant2, fn () => $this->assertNull($this->listing($variant2, $connection2)->priceFloorViolation()));
    }

    /**
     * ⚠️ PARA BİRİMİ FARKLIYSA DENETLENMEZ.
     *
     * USD Etsy fiyatı TL maliyetle kıyaslansaydı $12.90 her TL maliyetin
     * altında kalır ve her ilan durardı.
     */
    #[Test]
    public function a_foreign_currency_channel_price_is_not_compared_with_cost(): void
    {
        [$tenant, $variant, $connection] = $this->context(cost: '190.00');
        $this->rule($tenant, $connection, margin: '0');

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            $this->assertNull($this->listing($variant, $connection, channelPrice: '12.90', currency: 'USD')->priceFloorViolation());
        });
    }

    /**
     * ⚠️ FİYAT TURU DURDURULAN FİYATI GÖNDERMEZ ve NEDENİ GÖSTERİR.
     *
     * Operasyon ölür, listing'in fiyat durumu kalıcı hata olur ve metin
     * Ürün → Kanallar'da görünür. Kanala HİÇBİR istek gitmez.
     */
    #[Test]
    public function the_price_push_blocks_a_price_below_the_floor(): void
    {
        [$tenant, $variant, $connection] = $this->context(cost: '190.00');
        $this->rule($tenant, $connection, margin: '10');

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
            $fresh = SyncOperation::query()->findOrFail($operation->id);
            $this->assertSame(SyncOperationStatus::DEAD, $fresh->status, 'Durdurulan operasyon "tamamlandı" sayıldı — neden görünmez olurdu.');

            $state = ListingSyncState::query()
                ->where('listing_id', $operation->entity_id)
                ->where('domain', SyncDomain::PRICE->value)
                ->sole();
            $this->assertSame('error_permanent', $state->status);
            $this->assertStringContainsString('209.00', (string) $state->last_error);
        });
    }

    /** Fiyat yükü kurallı fiyatı taşır. */
    #[Test]
    public function the_price_batch_carries_the_rule_price(): void
    {
        [$tenant, $variant, $connection] = $this->context();
        $this->rule($tenant, $connection, percent: '15', rounding: 'x90');

        $this->asTenant($tenant, function () use ($variant, $connection): void {
            $listing = $this->listing($variant, $connection);
            $operation = app(OpenSyncOperation::class)->run(listing: $listing, domain: SyncDomain::PRICE, eventVersion: 2);

            $batch = DB::transaction(fn () => app(PriceBatchBuilder::class)->build($operation));

            $this->assertSame('229.90', $batch->items[0]['price']);
            $this->assertSame([], $batch->blocked());
        });
    }

    /**
     * ⚠️ İLAN AÇMA / İÇERİK GÜNCELLEMESİ DE DURUR.
     *
     * Eşleyiciler `effectivePrice()` yazar; yalnız fiyat turu durdurulsaydı
     * maliyet altı fiyat ilk açılışta kanala yine giderdi.
     */
    #[Test]
    public function listing_push_is_blocked_below_the_floor(): void
    {
        [$tenant, $variant, $connection] = $this->context(cost: '190.00');
        $this->rule($tenant, $connection, margin: '10');

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
        $this->assertSame(
            SyncOperationStatus::DEAD,
            $this->asTenant($tenant, fn () => SyncOperation::query()->findOrFail($operation->id)->status),
        );
    }

    // ─────────────────────────────────────────────── eylemler

    /**
     * ⚠️ KURAL DEĞİŞİNCE CANLI İLANLAR YENİDEN GÖNDERİLİR.
     *
     * Hiçbir varyantın sürümü değişmez; resync olmasaydı satıcı "+%15" der,
     * kanalda hiçbir fiyat değişmezdi. Taslak ilan gönderilmez (açılırken
     * kurallı fiyatla açılır). Aynı değer ikinci kez yazılınca olay yok.
     */
    #[Test]
    public function saving_a_rule_resyncs_live_listings_and_records_audit(): void
    {
        [$tenant, $variant, $connection, $user] = $this->context();

        $this->asTenant($tenant, function () use ($tenant, $variant, $connection, $user): void {
            $live = $this->listing($variant, $connection);
            $draftVariant = Variant::factory()->create(['tenant_id' => $tenant->id, 'product_id' => $variant->product_id, 'price' => '10.00', 'currency' => 'TRY']);
            $this->listing($draftVariant, $connection, lifecycle: 'draft');

            // Önbellek "kural yok" ile dolsun: temizlenmeseydi aynı istekte
            // eski (kuralsız) fiyat okunurdu.
            $this->assertSame('199.90', $live->effectivePrice());

            $values = ['markup_percent' => '15', 'markup_amount' => '0', 'rounding' => 'x90', 'min_margin_percent' => '10'];

            $this->assertSame(1, app(SetChannelPriceRule::class)->run($connection, $values, $user->id));

            $event = OutboxEvent::query()->where('event_type', 'ListingResyncRequested')->sole();
            $this->assertSame($live->id, $event->payload['listing_id']);
            $this->assertSame(RequestResync::REASON_PRICE_RULE_CHANGED, $event->payload['reason']);
            $this->assertSame(1, AuditLog::query()->where('action', AuditAction::CHANNEL_PRICE_RULE_SET->value)->count());

            // Aynı istekte yeni kural okunur (önbellek temizlendi).
            $this->assertSame('229.90', Listing::query()->findOrFail($live->id)->effectivePrice());

            $this->assertSame(0, app(SetChannelPriceRule::class)->run($connection, $values, $user->id));
            $this->assertSame(1, OutboxEvent::query()->where('event_type', 'ListingResyncRequested')->count());
        });
    }

    /** Maliyet değişince yalnız koruması açık bağlantıdaki canlı ilan yeniden gönderilir. */
    #[Test]
    public function changing_the_cost_resyncs_only_guarded_listings(): void
    {
        [$tenant, $variant, $connection] = $this->context();
        $this->rule($tenant, $connection, margin: '10');
        $unguarded = $this->connection($tenant);

        $this->asTenant($tenant, function () use ($variant, $connection, $unguarded): void {
            $guardedListing = $this->listing($variant, $connection);
            $this->listing($variant, $unguarded);

            $this->assertTrue(app(SetVariantCost::class)->run($variant, '150'));
            $this->assertSame('150.00', (string) Variant::query()->findOrFail($variant->id)->cost_price);

            $event = OutboxEvent::query()->where('event_type', 'ListingResyncRequested')->sole();
            $this->assertSame($guardedListing->id, $event->payload['listing_id']);
            $this->assertSame(RequestResync::REASON_COST_CHANGED, $event->payload['reason']);

            $this->assertFalse(app(SetVariantCost::class)->run(Variant::query()->findOrFail($variant->id), '150.00'));
        });
    }

    // ─────────────────────────────────────────────── ekranlar

    /** Kural sayfası açılır, kaydedilir; kart özeti gösterir. */
    #[Test]
    public function the_pricing_screen_saves_the_rule_and_the_card_summarizes_it(): void
    {
        [$tenant, $variant, $connection, $user] = $this->context();

        $this->actingAs($user)->get("/channels/{$connection->id}/pricing")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Channels/Pricing')->where('rule.rounding', 'none'));

        $this->actingAs($user)->put("/channels/{$connection->id}/pricing", [
            'markup_percent' => '15',
            'markup_amount' => '0',
            'rounding' => 'x90',
            'min_margin_percent' => '10',
        ])->assertRedirect('/channels')->assertSessionHasNoErrors();

        $rule = $this->asTenant($tenant, fn () => ChannelPriceRule::query()->sole());
        $this->assertSame('15.00', (string) $rule->markup_percent);
        $this->assertSame('10.00', (string) $rule->min_margin_percent);

        $card = $this->actingAs($user)->get('/channels')->viewData('page')['props']['connections'][0];
        $this->assertSame('+%15 · ,90 ile bitir · zarar koruması %10', $card['priceRule']);
    }

    /** Geçersiz yuvarlama reddedilir; başka kiracının bağlantısı 404. */
    #[Test]
    public function the_pricing_screen_validates_and_is_tenant_scoped(): void
    {
        [, , $connection, $user] = $this->context();
        [, , , $stranger] = $this->context();

        $this->actingAs($user)->put("/channels/{$connection->id}/pricing", [
            'markup_percent' => '15', 'markup_amount' => '0', 'rounding' => 'x50', 'min_margin_percent' => null,
        ])->assertSessionHasErrors('rounding');

        $this->actingAs($stranger)->get("/channels/{$connection->id}/pricing")->assertNotFound();
        $this->actingAs($stranger)->put("/channels/{$connection->id}/pricing", [
            'markup_percent' => '15', 'markup_amount' => '0', 'rounding' => 'none', 'min_margin_percent' => null,
        ])->assertNotFound();
    }

    /** Maliyet ucu yazar; başka kiracının varyantı 404. */
    #[Test]
    public function the_cost_endpoint_writes_the_cost_and_is_tenant_scoped(): void
    {
        [$tenant, $variant, , $user] = $this->context();
        [, , , $stranger] = $this->context();

        $this->actingAs($user)
            ->put("/products/{$variant->product_id}/variants/{$variant->id}/cost", ['cost_price' => '120.5'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('120.50', (string) $this->asTenant($tenant, fn () => Variant::query()->findOrFail($variant->id)->cost_price));

        $this->actingAs($stranger)
            ->put("/products/{$variant->product_id}/variants/{$variant->id}/cost", ['cost_price' => '1'])
            ->assertNotFound();

        $this->actingAs($user)
            ->put("/products/{$variant->product_id}/variants/{$variant->id}/cost", ['cost_price' => '-5'])
            ->assertSessionHasErrors('cost_price');
    }

    // ─────────────────────────────────────────────── yardımcılar

    /** @return array{0: Tenant, 1: Variant, 2: ChannelConnection, 3: User} */
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
            'settings' => [EtsyAdapter::SHOP_ID_KEY => '777', EtsyAdapter::SHOP_CURRENCY_KEY => 'TRY'],
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
