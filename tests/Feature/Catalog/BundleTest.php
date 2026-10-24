<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\SaveBundle;
use App\Domain\Catalog\Exceptions\InvalidBundle;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\AdjustStock;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Support\SellableStock;
use App\Domain\Messaging\Consumers\InventoryLevelChangedConsumer;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Orders\Actions\ApplyOrderCancellation;
use App\Domain\Orders\Actions\ApplyOrderReturn;
use App\Domain\Orders\Actions\IngestChannelOrder;
use App\Domain\Orders\Enums\StockStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderLine;
use App\Domain\Orders\Support\CancellationEvent;
use App\Domain\Orders\Support\CancelledLine;
use App\Domain\Orders\Support\IncomingOrder;
use App\Domain\Orders\Support\IncomingOrderLine;
use App\Domain\Orders\Support\ReturnedLine;
use App\Domain\Orders\Support\ReturnEvent;
use App\Domain\Sync\Actions\OpenSyncOperation;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\InventoryBatchBuilder;
use App\Domain\Sync\Support\ListingProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Set / paket ürün — türetilmiş stok, bileşenden düşüş, geri ekleme, fan-out.
 *
 * Denetim · 03-DOMAIN-MODEL-AUDIT § E'nin dört noktası: satış bileşen başına
 * (`sale:{satır}:{bileşen}`), iptal/iade/eşleşmemiş yolları, fan-out (bileşen
 * değişince setin ilanı), paket kurucu (set SESSİZCE atlanmaz).
 */
final class BundleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Planlama sınanır; gönderim işleri kuyruğa düşer, koşmaz.
        Queue::fake();
    }

    /** Set stoğu = min ⌊bileşen / adet⌋; sınırlayan bileşen bilinir. */
    #[Test]
    public function bundle_stock_is_derived_from_components(): void
    {
        [$tenant, $a, $b, $bundle] = $this->giftSet(stockA: 10, stockB: 7);

        $view = $this->asTenant($tenant, fn () => SellableStock::forVariant($tenant->id, $bundle->id));

        // A: ⌊10/2⌋ = 5, B: ⌊7/1⌋ = 7 → 5, sınırlayan A.
        $this->assertSame(5, $view->available);
        $this->assertSame($a->id, $view->limitingVariantId);
        $this->assertSame(0, $this->asTenant($tenant, fn () => InventoryLevel::query()->where('variant_id', $bundle->id)->count()), 'Setin seviye satırı açılmamalı.');
    }

    /**
     * Set satışı bileşenlerden (adet × bileşen adedi) düşer; anahtar bileşen
     * başına; satış anı görüntüsü yazılır; aynı sipariş ikinci kez gelirse
     * ÇİFT DÜŞÜŞ YOK.
     */
    #[Test]
    public function selling_a_bundle_deducts_each_component_once(): void
    {
        [$tenant, $a, $b, $bundle] = $this->giftSet(stockA: 10, stockB: 7);
        $connection = $this->connection($tenant);

        $order = $this->sell($tenant, $connection, $bundle, quantity: 2, externalId: 'S-1');
        $this->sell($tenant, $connection, $bundle, quantity: 2, externalId: 'S-1');   // tekrar teslim

        $this->assertSame(6, $this->onHand($tenant, $a), 'A: 10 − 2×2');
        $this->assertSame(5, $this->onHand($tenant, $b), 'B: 7 − 2×1');

        $line = $this->asTenant($tenant, fn () => OrderLine::query()->where('order_id', $order->id)->sole());
        $this->assertSame(StockStatus::APPLIED, $line->stock_status);
        $this->assertEquals([['variant_id' => $a->id, 'quantity' => 2], ['variant_id' => $b->id, 'quantity' => 1]], $this->sorted($line->bundle_components));

        $keys = $this->asTenant($tenant, fn () => InventoryMovement::query()->where('type', 'SALE')->orderBy('idempotency_key')->pluck('idempotency_key')->all());
        $this->assertEqualsCanonicalizing(['sale:'.$line->id.':'.$a->id, 'sale:'.$line->id.':'.$b->id], $keys, 'Bileşen başına tam iki SALE.');
    }

    /** Türetilmiş stok yetmezse satış YİNE yazılır (pazaryeri otoriter) ve fazla satış işaretlenir. */
    #[Test]
    public function an_oversold_bundle_is_flagged_and_the_ledger_stays_exact(): void
    {
        [$tenant, $a, $b, $bundle] = $this->giftSet(stockA: 3, stockB: 7);
        $connection = $this->connection($tenant);

        $order = $this->sell($tenant, $connection, $bundle, quantity: 2, externalId: 'S-2');

        $line = $this->asTenant($tenant, fn () => OrderLine::query()->where('order_id', $order->id)->sole());
        $this->assertSame(StockStatus::OVERSOLD, $line->stock_status, 'Set stoğu ⌊3/2⌋ = 1 < 2.');
        $this->assertSame(-1, $this->onHand($tenant, $a));
        $this->assertSame(-1, $this->asTenant($tenant, fn () => SellableStock::forVariant($tenant->id, $bundle->id))->available);
    }

    /**
     * Aynı siparişte set + aynı bileşenin kendisi: kilit tek satıra bir kez
     * alınır, iki hareket de düşer ("önce" stoğu ikinci satır için güncel).
     */
    #[Test]
    public function a_bundle_and_its_component_in_one_order_share_the_lock(): void
    {
        [$tenant, $a, , $bundle] = $this->giftSet(stockA: 10, stockB: 7);
        $connection = $this->connection($tenant);

        $this->asTenant($tenant, fn () => app(IngestChannelOrder::class)->run(new IncomingOrder(
            channelConnectionId: $connection->id,
            externalId: 'S-3',
            lines: [
                new IncomingOrderLine(externalLineId: '1', sku: $bundle->sku, title: 'Set', quantity: 1, variantId: $bundle->id, unitPrice: '100.00', lineTotal: '100.00'),
                new IncomingOrderLine(externalLineId: '2', sku: $a->sku, title: 'A', quantity: 3, variantId: $a->id, unitPrice: '10.00', lineTotal: '30.00'),
            ],
            externalNumber: 'S-3',
            grandTotal: '130.00',
        ), $this->warehouse($tenant)));

        $this->assertSame(5, $this->onHand($tenant, $a), 'A: 10 − 2 (set) − 3');
    }

    /** İptal ve iade SATIŞ ANI bileşenlerini geri ekler — tanım sonradan değişse de. */
    #[Test]
    public function cancel_and_return_restore_the_components_sold_even_after_the_set_changed(): void
    {
        [$tenant, $a, $b, $bundle] = $this->giftSet(stockA: 10, stockB: 7);
        $connection = $this->connection($tenant);
        $order = $this->sell($tenant, $connection, $bundle, quantity: 3, externalId: 'S-4');
        $line = $this->asTenant($tenant, fn () => OrderLine::query()->where('order_id', $order->id)->sole());

        // Satıştan SONRA set değişti: artık yalnız B × 3.
        $this->asTenant($tenant, fn () => app(SaveBundle::class)->update($bundle->fresh(), [['variant_id' => $b->id, 'quantity' => 3]]));

        $this->asTenant($tenant, fn () => app(ApplyOrderCancellation::class)->run(new CancellationEvent($order->id, 'c-1', [new CancelledLine($line->id, 1)])));
        $this->assertSame(10 - 6 + 2, $this->onHand($tenant, $a), 'A satış anındaki 2 adet/set ile geri gelmeli.');
        $this->assertSame(7 - 3 + 1, $this->onHand($tenant, $b), 'B satış anındaki 1 adet/set ile geri gelmeli.');

        $this->asTenant($tenant, fn () => app(ApplyOrderReturn::class)->run(new ReturnEvent($order->id, 'r-1', [new ReturnedLine($line->id, 1)])));
        $this->assertSame(10 - 6 + 4, $this->onHand($tenant, $a));
        $this->assertSame(7 - 3 + 2, $this->onHand($tenant, $b));
    }

    /**
     * Bileşen değişince setin CANLI ilanına da operasyon açılır; sürüm SETİN
     * sürümüdür (bileşen toplamı). Bileşenin kendi sürümü verilseydi küçük
     * sürümlü bileşenin değişimi kapıda elenirdi.
     */
    #[Test]
    public function a_component_change_fans_out_to_the_bundle_listing_with_the_bundle_version(): void
    {
        [$tenant, $a, $b, $bundle] = $this->giftSet(stockA: 10, stockB: 7);
        $listing = $this->listOn($tenant, $bundle, $this->connection($tenant));

        // B'yi değiştir: B'nin seviye sürümü A'nınkinden KÜÇÜK olsa da set ilanı güncellenmeli.
        $this->asTenant($tenant, fn () => app(AdjustStock::class)->setTo($this->warehouse($tenant), $a->id, 12));
        $this->asTenant($tenant, fn () => app(AdjustStock::class)->setTo($this->warehouse($tenant), $a->id, 14));
        $this->consumeAll($tenant);

        $this->asTenant($tenant, fn () => app(AdjustStock::class)->setTo($this->warehouse($tenant), $b->id, 2));
        $events = $this->consumeAll($tenant);

        $expected = $this->asTenant($tenant, fn () => SellableStock::forVariant($tenant->id, $bundle->id))->version;
        $operation = $this->asTenant($tenant, fn () => SyncOperation::query()->where('entity_id', $listing->id)->orderByDesc('entity_version')->first());

        $this->assertNotNull($operation, 'B değişince set ilanına operasyon açılmalı.');
        $this->assertSame($expected, $operation->entity_version);
        $this->assertGreaterThan(0, $events);
        $this->assertSame(2, $this->asTenant($tenant, fn () => ListingProjection::quantity($listing)), 'Set: min(⌊14/2⌋, ⌊2/1⌋) = 2.');
    }

    /**
     * Set X kanalında satıldı: bileşenin X'teki KENDİ ilanı da güncellenir
     * (kanal kendi tarafında yalnız set ilanını düşürdü — yankı yok).
     */
    #[Test]
    public function the_components_own_listing_on_the_selling_channel_is_not_echo_suppressed(): void
    {
        // Pencere 0 = yankı HER ZAMAN bastırılır: kurgudaki yakın tarihli
        // stok girişleri bastırmayı kendiliğinden kapatmasın, kural sınansın.
        config(['entegrasyon.sync.origin_echo_window_minutes' => 0]);

        [$tenant, $a, , $bundle] = $this->giftSet(stockA: 10, stockB: 7);
        $connection = $this->connection($tenant);
        $componentListing = $this->listOn($tenant, $a, $connection);
        $this->consumeAll($tenant);

        $count = fn (): int => $this->asTenant($tenant, fn () => SyncOperation::query()->where('entity_id', $componentListing->id)->count());
        $before = $count();

        $this->sell($tenant, $connection, $bundle, quantity: 1, externalId: 'S-5');
        $this->consumeAll($tenant);

        $this->assertSame($before + 1, $count(), 'A\'nın satan kanaldaki ilanına SATIŞTAN SONRA yeni operasyon açılmalı.');
    }

    /**
     * Paket kurucu set ilanını ATLAMAZ ve türetilmiş değeri gönderir; bileşen
     * sıfırlanınca set de 0 gider.
     */
    #[Test]
    public function the_batch_builder_sends_the_derived_bundle_quantity(): void
    {
        [$tenant, $a, , $bundle] = $this->giftSet(stockA: 10, stockB: 7);
        $connection = $this->connection($tenant);
        $listing = $this->listOn($tenant, $bundle, $connection);

        $quantity = function () use ($tenant, $listing): ?int {
            $operation = $this->asTenant($tenant, fn () => app(OpenSyncOperation::class)->run($listing, SyncDomain::INVENTORY, SellableStock::forVariant($tenant->id, $listing->variant_id)->version));
            $operation ??= $this->asTenant($tenant, fn () => SyncOperation::query()->where('entity_id', $listing->id)->latest('created_at')->first());

            $batch = $this->asTenant($tenant, fn () => DB::transaction(fn () => app(InventoryBatchBuilder::class)->build($operation->fresh(['connection']))));
            $item = collect($batch->items)->firstWhere('listingId', $listing->id);

            return $item?->quantity;
        };

        $this->assertSame(5, $quantity());

        $this->asTenant($tenant, fn () => app(AdjustStock::class)->setTo($this->warehouse($tenant), $a->id, 0));
        $this->assertSame(0, $quantity(), 'Bileşen 0 → set her kanalda 0.');
    }

    // ─────────────────────────────────────────────────── tanım kuralları

    #[Test]
    public function the_definition_rules_are_enforced(): void
    {
        [$tenant, $a, $b, $bundle] = $this->giftSet(stockA: 1, stockB: 1);

        $refused = function (array $components, ?Variant $existing = null) use ($tenant): string {
            try {
                $this->asTenant($tenant, fn () => $existing === null
                    ? app(SaveBundle::class)->create('SET-'.uniqid(), 'Set', 10.0, $components)
                    : app(SaveBundle::class)->update($existing, $components));
            } catch (InvalidBundle $e) {
                return $e->getMessage();
            }

            $this->fail('Kural ihlali reddedilmeliydi: '.json_encode($components));
        };

        $this->assertStringContainsString('en az bir', $refused([]));
        $this->assertStringContainsString('başka bir sete', $refused([['variant_id' => $bundle->id, 'quantity' => 1]]));
        $this->assertStringContainsString('kendisini', $refused([['variant_id' => $bundle->id, 'quantity' => 1]], $bundle));
        $this->assertStringContainsString('1 ile', $refused([['variant_id' => $a->id, 'quantity' => 0]]));

        $other = (new CreateTenant)->run(name: 'Başka '.uniqid(), owner: User::factory()->create());
        $foreign = $this->asTenant($other, fn () => Variant::factory()->create());
        $this->assertStringContainsString('bulunamadı', $refused([['variant_id' => $foreign->id, 'quantity' => 1]]));

        // Aynı bileşen iki kez → adet toplanır.
        $merged = $this->asTenant($tenant, fn () => app(SaveBundle::class)->create('SET-M', 'Set', 10.0, [['variant_id' => $a->id, 'quantity' => 1], ['variant_id' => $a->id, 'quantity' => 2]]));
        $components = $this->asTenant($tenant, fn () => SellableStock::componentsOf($tenant->id, [$merged->variants()->sole()->id]));
        $this->assertSame([['variant_id' => $a->id, 'quantity' => 3]], array_values($components)[0]);
    }

    /** Bileşen çıkarılınca bile setin sürümü İLERİ gider (sürüm kapısı yeni değeri elemesin). */
    #[Test]
    public function changing_the_definition_always_moves_the_version_forward(): void
    {
        [$tenant, $a, $b, $bundle] = $this->giftSet(stockA: 50, stockB: 50);
        // A'nın sürümünü büyüt.
        foreach ([40, 30, 20] as $count) {
            $this->asTenant($tenant, fn () => app(AdjustStock::class)->setTo($this->warehouse($tenant), $a->id, $count));
        }

        $before = $this->asTenant($tenant, fn () => SellableStock::forVariant($tenant->id, $bundle->id))->version;
        $this->asTenant($tenant, fn () => app(SaveBundle::class)->update($bundle->fresh(), [['variant_id' => $b->id, 'quantity' => 1]]));
        $after = $this->asTenant($tenant, fn () => SellableStock::forVariant($tenant->id, $bundle->id))->version;

        $this->assertGreaterThan($before, $after, 'A çıkarıldı (büyük sürüm) — toplam düşmemeli.');
    }

    /** Setin kendi stoğuna hiçbir yoldan yazılmaz. */
    #[Test]
    public function stock_cannot_be_written_to_a_bundle(): void
    {
        [$tenant, , , $bundle] = $this->giftSet(stockA: 1, stockB: 1);

        try {
            $this->asTenant($tenant, fn () => DB::transaction(fn () => app(LockInventoryRows::class)->run($this->warehouse($tenant), [$bundle->id])));
            $this->fail('Set için kilit/seviye satırı açılmamalı.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $this->asTenant($tenant, fn () => app(AdjustStock::class)->setTo($this->warehouse($tenant), $bundle->id, 5));
    }

    #[Test]
    public function the_panel_refuses_a_manual_count_on_a_bundle(): void
    {
        [$tenant, , , $bundle, $user] = $this->giftSet(stockA: 1, stockB: 1);

        $this->actingAs($user)->post('/inventory/adjust', ['variant_id' => $bundle->id, 'target' => 5])
            ->assertSessionHasErrors('target');

        $this->assertSame(0, $this->asTenant($tenant, fn () => InventoryLevel::query()->where('variant_id', $bundle->id)->count()));
    }

    // ─────────────────────────────────────────────────── panel

    #[Test]
    public function the_panel_creates_a_bundle_and_refuses_a_nested_one(): void
    {
        [$tenant, $a, $b, $bundle, $user] = $this->giftSet(stockA: 10, stockB: 7);

        $this->actingAs($user)->post('/products/bundles', [
            'sku' => 'SET-PANEL', 'title' => 'Panel seti', 'price' => '149.90',
            'components' => [['variant_id' => $a->id, 'quantity' => 3], ['variant_id' => $b->id, 'quantity' => 1]],
        ])->assertRedirect();

        $created = $this->asTenant($tenant, fn () => Variant::query()->where('sku', 'SET-PANEL')->sole());
        $this->assertTrue($created->isBundle());
        $this->assertSame(3, $this->asTenant($tenant, fn () => SellableStock::forVariant($tenant->id, $created->id))->available, 'min(⌊10/3⌋, ⌊7/1⌋)');

        $this->actingAs($user)->post('/products/bundles', [
            'sku' => 'SET-IC-ICE', 'title' => 'İç içe', 'price' => '10',
            'components' => [['variant_id' => $bundle->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('components');

        $this->assertFalse($this->asTenant($tenant, fn () => Variant::query()->where('sku', 'SET-IC-ICE')->exists()));
    }

    /** Bileşen aramasında setler çıkmaz (set içinde set yok). */
    #[Test]
    public function the_component_search_lists_only_standard_variants(): void
    {
        [, $a, $b, $bundle, $user] = $this->giftSet(stockA: 10, stockB: 7);

        $ids = collect($this->actingAs($user)->getJson('/products/bundles/variants')->assertOk()->json())->pluck('id')->all();

        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
        $this->assertNotContains($bundle->id, $ids);
    }

    /** Listede set türetilmiş stokla görünür; stoğu olan set "Tükenen" sekmesine düşmez. */
    #[Test]
    public function the_product_list_shows_the_derived_stock_and_filters_by_it(): void
    {
        [, $a, , $bundle, $user] = $this->giftSet(stockA: 10, stockB: 7);

        $rows = collect($this->actingAs($user)->get('/products')->assertOk()->viewData('page')['props']['rows']);
        $row = $rows->firstWhere('sku', $bundle->sku);

        $this->assertTrue($row['isBundle']);
        $this->assertSame(5, $row['available']);
        $this->assertSame($a->sku, $row['limitingSku']);
        $this->assertNull($row['countVariantId'], 'Sette yerinde sayım olmaz.');

        $out = collect($this->actingAs($user)->get('/products?filter=out')->viewData('page')['props']['rows'])->pluck('sku')->all();
        $this->assertNotContains($bundle->sku, $out);
    }

    #[Test]
    public function the_edit_screen_shows_and_saves_the_contents(): void
    {
        [$tenant, $a, $b, $bundle, $user] = $this->giftSet(stockA: 10, stockB: 7);
        $productId = $bundle->product_id;

        $props = $this->actingAs($user)->get("/products/{$productId}/edit")->assertOk()->viewData('page')['props'];
        $this->assertSame(5, $props['product']['bundle']['available']);
        $this->assertCount(2, $props['product']['bundle']['components']);

        $this->actingAs($user)->put("/products/{$productId}/bundle", ['components' => [['variant_id' => $b->id, 'quantity' => 2]]])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, $this->asTenant($tenant, fn () => SellableStock::forVariant($tenant->id, $bundle->id))->available, '⌊7/2⌋');
    }

    /** Depo üyesi set oluşturamaz (katalog yazma izni yok). */
    #[Test]
    public function a_warehouse_member_cannot_create_a_bundle(): void
    {
        [$tenant, $a] = $this->giftSet(stockA: 10, stockB: 7);
        $depo = User::factory()->create();
        TenantUser::query()->create(['tenant_id' => $tenant->id, 'user_id' => $depo->id, 'role' => 'warehouse', 'accepted_at' => now()]);

        $this->actingAs($depo)->get('/products/bundles/create')->assertForbidden();
        $this->actingAs($depo)->post('/products/bundles', [
            'sku' => 'SET-DEPO', 'title' => 'x', 'price' => '1', 'components' => [['variant_id' => $a->id, 'quantity' => 1]],
        ])->assertForbidden();
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /**
     * "Hediye seti" = 2 × A + 1 × B.
     *
     * @return array{0: Tenant, 1: Variant, 2: Variant, 3: Variant, 4: User}
     */
    private function giftSet(int $stockA, int $stockB): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Set '.uniqid(), owner: $user);
        $warehouse = $this->warehouse($tenant);

        [$a, $b] = $this->asTenant($tenant, fn () => [Variant::factory()->create(['sku' => 'A-'.uniqid()]), Variant::factory()->create(['sku' => 'B-'.uniqid()])]);

        $this->asTenant($tenant, function () use ($warehouse, $a, $b, $stockA, $stockB): void {
            app(AdjustStock::class)->setTo($warehouse, $a->id, $stockA);
            app(AdjustStock::class)->setTo($warehouse, $b->id, $stockB);
        });

        $product = $this->asTenant($tenant, fn (): Product => app(SaveBundle::class)->create('SET-'.uniqid(), 'Hediye seti', 199.90, [
            ['variant_id' => $a->id, 'quantity' => 2],
            ['variant_id' => $b->id, 'quantity' => 1],
        ]));

        $bundle = $this->asTenant($tenant, fn (): Variant => $product->variants()->sole());

        return [$tenant, $a, $b, $bundle, $user];
    }

    private function warehouse(Tenant $tenant): string
    {
        return $this->asTenant($tenant, fn (): string => $tenant->defaultWarehouse()->id);
    }

    private function connection(Tenant $tenant): ChannelConnection
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'woocommerce'], [
            'name' => 'WooCommerce',
            'kind' => 'storefront',
            'adapter_class' => WooCommerceAdapter::class,
            'is_active' => true,
            'rate_limit_profile' => ['requests_per_second' => 50, 'burst_capacity' => 50],
        ]));

        return $this->asTenant($tenant, function (): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'woocommerce',
                'settings' => ['base_url' => 'https://set.example.com/wp-json/wc/v3/'],
                'stock_sync_enabled' => true,
            ]);

            app(CredentialVault::class)->store($connection, ['consumer_key' => 'ck_set_test_1234567890', 'consumer_secret' => 'cs_set_test_1234567890']);

            return $connection;
        });
    }

    private function listOn(Tenant $tenant, Variant $variant, ChannelConnection $connection): Listing
    {
        return $this->asTenant($tenant, fn (): Listing => Listing::factory()->create([
            'channel_connection_id' => $connection->id,
            'variant_id' => $variant->id,
        ]));
    }

    private function sell(Tenant $tenant, ChannelConnection $connection, Variant $bundle, int $quantity, string $externalId): Order
    {
        return $this->asTenant($tenant, fn (): Order => app(IngestChannelOrder::class)->run(new IncomingOrder(
            channelConnectionId: $connection->id,
            externalId: $externalId,
            lines: [new IncomingOrderLine(externalLineId: '1', sku: $bundle->sku, title: 'Hediye seti', quantity: $quantity, variantId: $bundle->id, unitPrice: '199.90', lineTotal: number_format(199.90 * $quantity, 2, '.', ''))],
            externalNumber: $externalId,
            grandTotal: number_format(199.90 * $quantity, 2, '.', ''),
        ), $this->warehouse($tenant)));
    }

    /** Bekleyen stok olaylarını gerçek tüketiciyle işler. */
    private function consumeAll(Tenant $tenant): int
    {
        $events = $this->asTenant($tenant, fn () => OutboxEvent::query()
            ->where('event_type', 'InventoryLevelChanged')
            ->whereNull('consumed_at')
            ->orderBy('created_at')
            ->get());

        foreach ($events as $event) {
            $this->asTenant($tenant, fn () => app(InventoryLevelChangedConsumer::class)->handle($event));
        }

        return $events->count();
    }

    private function onHand(Tenant $tenant, Variant $variant): int
    {
        return (int) $this->asTenant($tenant, fn () => InventoryLevel::query()->where('variant_id', $variant->id)->value('on_hand'));
    }

    /** @param list<array{variant_id: string, quantity: int}> $components */
    private function sorted(array $components): array
    {
        usort($components, static fn (array $x, array $y): int => $y['quantity'] <=> $x['quantity']);

        return $components;
    }
}
