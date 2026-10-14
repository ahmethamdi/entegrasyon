<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Actions\ImportProductsFromChannel;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderLine;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\InventoryPushItem;
use App\Domain\Sync\Support\PricePushBatch;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * S5 · WooCommerce üretime hazırlık: varyasyonlar (ADR-003), ilan kimliği
 * bağı, varyasyon stok/fiyat uçları, SKU'suz satırın varyasyon kimliğiyle
 * eşleşmesi, kaybolan webhook için sipariş mutabakatı.
 *
 * GERÇEK MAĞAZADA DOĞRULANMADI — sahte HTTP; uç biçimleri WooCommerce REST
 * v3 belgesinden. Durum: IMPLEMENTED — LIVE VERIFICATION PENDING.
 */
final class WooVariationsAndRecoveryTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    // ─────────────────────────────────────────── içe aktarma

    #[Test]
    public function a_variable_product_imports_as_one_product_with_bound_variants(): void
    {
        [$tenant, $connection, $warehouse] = $this->store();

        Http::fake([
            '*/products/50/variations*' => Http::response([
                $this->variation(501, 'TS-S', 'S', 3, '199.90'),
                $this->variation(502, '', 'M', 4, '199.90'),
            ], 200, ['X-WP-TotalPages' => '1']),
            '*/products*' => Http::response([
                ['id' => 50, 'type' => 'variable', 'name' => 'Tişört', 'variations' => [501, 502], 'images' => [['src' => 'https://shop.example.com/t.jpg', 'position' => 0]]],
                ['id' => 60, 'type' => 'simple', 'name' => 'Kupa', 'sku' => 'KUPA', 'regular_price' => '50', 'stock_quantity' => 7],
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        $this->asTenant($tenant, fn () => app(ImportProductsFromChannel::class)->run($connection, $warehouse));

        $this->asTenant($tenant, function () use ($connection): void {
            $this->assertSame(2, Product::query()->count(), 'Değişken ürün TEK ürün, basit ürün ayrı.');
            $tshirt = Product::query()->where('title', 'Tişört')->sole();
            $this->assertSame(2, $tshirt->variants()->count());

            $listings = Listing::query()->where('channel_connection_id', $connection->id)->get()->keyBy('external_id');
            $this->assertSame('50', $listings['501']->external_parent_id);
            $this->assertSame('50', $listings['502']->external_parent_id);
            $this->assertNull($listings['60']->external_parent_id);
            $this->assertSame('live', $listings['501']->lifecycle_status);
        });

        $this->assertSame(3, $this->onHand($tenant, $warehouse, 'TS-S'));
    }

    #[Test]
    public function variations_are_read_across_pages_and_a_failure_does_not_import_half(): void
    {
        [$tenant, $connection] = $this->store();

        Http::fake([
            '*/products/50/variations*' => fn (Request $r) => str_contains($r->url(), 'page=2')
                ? Http::response(['message' => 'boom'], 500)
                : Http::response([$this->variation(501, 'A', 'S', 1, '10')], 200, ['X-WP-TotalPages' => '2']),
            '*/products*' => Http::response([['id' => 50, 'type' => 'variable', 'name' => 'X']], 200, ['X-WP-TotalPages' => '1']),
        ]);

        $this->expectException(\Throwable::class);

        try {
            $this->asTenant($tenant, fn () => $this->adapter($connection)->fetchProductPage());
        } finally {
            $this->assertSame(0, $this->asTenant($tenant, fn () => Product::query()->count()));
        }
    }

    // ─────────────────────────────────────────── stok / fiyat uçları

    #[Test]
    public function stock_for_variations_goes_to_the_parent_variations_batch(): void
    {
        [$tenant, $connection] = $this->store();
        Http::fake([
            '*/products/50/variations/batch' => Http::response(['update' => [['id' => 501], ['id' => 502, 'error' => ['code' => 'invalid', 'message' => 'x']]]]),
            '*/products/batch' => Http::response(['update' => [['id' => 60]]]),
        ]);

        $batch = new InventoryPushBatch($connection->id, [
            new InventoryPushItem('L1', '501', 'A', 2, 1, '50'),
            new InventoryPushItem('L2', '502', 'B', 0, 1, '50'),
            new InventoryPushItem('L3', '60', 'C', 5, 1),
        ]);

        $result = $this->asTenant($tenant, fn () => $this->adapter($connection)->pushInventory($batch));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/products/50/variations/batch')
            && $r['update'][0] === ['id' => 501, 'manage_stock' => true, 'stock_quantity' => 2, 'stock_status' => 'instock']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/products/batch') && $r['update'][0]['id'] === 60);
        Http::assertSentCount(2);
        $this->assertFalse($result->failed());
    }

    #[Test]
    public function price_for_variations_goes_to_the_parent_variations_batch(): void
    {
        [$tenant, $connection] = $this->store();
        Http::fake(['*/products/50/variations/batch' => Http::response(['update' => [['id' => 501]]])]);

        $batch = new PricePushBatch($connection->id, [
            ['listing_id' => 'L1', 'external_id' => '501', 'price' => '149.90', 'compare_at_price' => null, 'version' => 1, 'external_parent_id' => '50'],
        ]);

        $this->asTenant($tenant, fn () => $this->adapter($connection)->pushPrices($batch));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/products/50/variations/batch')
            && $r['update'][0] === ['id' => 501, 'regular_price' => '149.90']);
    }

    #[Test]
    public function a_non_numeric_parent_never_reaches_the_url(): void
    {
        [$tenant, $connection] = $this->store();
        Http::fake();

        $batch = new InventoryPushBatch($connection->id, [new InventoryPushItem('L1', '501', 'A', 2, 1, '../orders')]);
        $this->asTenant($tenant, fn () => $this->adapter($connection)->pushInventory($batch));

        Http::assertNothingSent();
    }

    #[Test]
    public function stock_and_price_are_read_back_from_the_variation_endpoint(): void
    {
        [$tenant, $connection] = $this->store();
        Http::fake([
            '*/products/50/variations*' => Http::response([['id' => 501, 'stock_quantity' => 4, 'regular_price' => '10.00']]),
            '*/products*' => Http::response([['id' => 60, 'stock_quantity' => 9, 'regular_price' => '20.00']]),
        ]);

        [$inventory, $prices] = $this->asTenant($tenant, function () use ($connection): array {
            $listings = [
                Listing::factory()->create(['channel_connection_id' => $connection->id, 'external_id' => '501', 'external_parent_id' => '50']),
                Listing::factory()->create(['channel_connection_id' => $connection->id, 'external_id' => '60']),
            ];

            return [$this->adapter($connection)->fetchInventory($listings), $this->adapter($connection)->fetchPrices($listings)];
        });

        $this->assertSame(4, $inventory->quantitiesByExternalId['501']);
        $this->assertSame(9, $inventory->quantitiesByExternalId['60']);
        $this->assertSame('10.00', $prices->pricesByExternalId['501']);
    }

    // ─────────────────────────────────────────── sipariş mutabakatı

    #[Test]
    public function a_missed_woo_order_is_recovered_once_and_matched_by_variation_id(): void
    {
        [$tenant, $connection, $warehouse] = $this->store();
        $variant = $this->boundVariation($tenant, $connection, $warehouse, sku: 'OWN-SKU', variationId: '501', parentId: '50', stock: 10);

        // Satırda SKU YOK — eşleşme variation_id ile.
        $order = $this->wooOrder(9001, [['id' => 77, 'product_id' => 50, 'variation_id' => 501, 'sku' => '', 'quantity' => 2]]);
        Http::fake(['*/orders*' => Http::response([$order], 200, ['X-WP-TotalPages' => '1'])]);

        $this->artisan('reconcile:orders')->assertSuccessful();
        $this->travel(31)->minutes();
        $this->artisan('reconcile:orders')->assertSuccessful();

        $this->assertSame(1, $this->asTenant($tenant, fn () => Order::query()->where('external_id', '9001')->count()));
        $this->assertSame(1, $this->asTenant($tenant, fn () => InboxMessage::query()->count()), 'Aynı olay ikinci turda yazılmamalı.');
        $line = $this->asTenant($tenant, fn () => OrderLine::query()->sole());
        $this->assertSame($variant->id, $line->variant_id);
        $this->assertSame(8, $this->onHand($tenant, $warehouse, 'OWN-SKU'));
        $this->assertLedgerMatchesProjection($tenant->id, $warehouse, $variant->id);
    }

    #[Test]
    public function a_recovered_cancellation_restores_stock_once(): void
    {
        [$tenant, $connection, $warehouse] = $this->store();
        $this->boundVariation($tenant, $connection, $warehouse, sku: 'C-SKU', variationId: '601', parentId: '60', stock: 10);
        $line = [['id' => 88, 'product_id' => 60, 'variation_id' => 601, 'sku' => 'C-SKU', 'quantity' => 3]];

        Http::fake(['*/orders*' => Http::sequence()
            ->push([$this->wooOrder(9002, $line)], 200, ['X-WP-TotalPages' => '1'])
            ->push([$this->wooOrder(9002, $line, 'cancelled')], 200, ['X-WP-TotalPages' => '1'])
            ->push([$this->wooOrder(9002, $line, 'cancelled')], 200, ['X-WP-TotalPages' => '1'])]);

        $this->artisan('reconcile:orders')->assertSuccessful();
        $this->assertSame(7, $this->onHand($tenant, $warehouse, 'C-SKU'));

        $this->travel(31)->minutes();
        $this->artisan('reconcile:orders')->assertSuccessful();
        $this->travel(31)->minutes();
        $this->artisan('reconcile:orders')->assertSuccessful();

        $this->assertSame(10, $this->onHand($tenant, $warehouse, 'C-SKU'));
        $this->assertSame('cancelled', $this->asTenant($tenant, fn () => Order::query()->sole()->state?->value ?? Order::query()->sole()->state));
    }

    #[Test]
    public function orders_placed_before_connecting_and_drafts_are_not_recovered(): void
    {
        [$tenant, $connection, $warehouse] = $this->store();
        $this->boundVariation($tenant, $connection, $warehouse, sku: 'O-SKU', variationId: '701', parentId: '70', stock: 5);
        $line = [['id' => 1, 'product_id' => 70, 'variation_id' => 701, 'sku' => 'O-SKU', 'quantity' => 1]];

        $old = $this->wooOrder(9100, $line);
        $old['date_created_gmt'] = now()->subDays(5)->utc()->format('Y-m-d\TH:i:s');
        $draft = $this->wooOrder(9101, $line, 'checkout-draft');

        Http::fake(['*/orders*' => Http::response([$old, $draft], 200, ['X-WP-TotalPages' => '1'])]);

        $this->artisan('reconcile:orders')->assertSuccessful();

        $this->assertSame(0, $this->asTenant($tenant, fn () => Order::query()->count()));
        $this->assertSame(5, $this->onHand($tenant, $warehouse, 'O-SKU'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'modified_after=') && str_contains($r->url(), 'dates_are_gmt=true'));
    }

    // ─────────────────────────────────────────── yardımcılar

    /**
     * @return array{0: Tenant, 1: ChannelConnection, 2: string}
     */
    private function store(): array
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'woocommerce'], [
            'name' => 'WooCommerce', 'kind' => 'storefront', 'adapter_class' => WooCommerceAdapter::class,
            'supports_webhooks' => true, 'is_active' => true,
            'rate_limit_profile' => ['requests_per_second' => 50, 'burst_capacity' => 100],
        ]));

        $tenant = (new CreateTenant)->run(name: 'Woo '.uniqid(), owner: User::factory()->create());

        $connection = $this->asTenant($tenant, function (): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'woocommerce',
                'external_account_id' => 'shop'.uniqid().'.example.com',
                'status' => 'active',
                'connected_at' => now()->subDays(2),
                'settings' => ['base_url' => 'https://shop.example.com/wp-json/wc/v3/'],
            ]);
            app(CredentialVault::class)->store($connection, ['consumer_key' => 'ck_x', 'consumer_secret' => 'cs_x', 'webhook_secret' => 's']);

            return $connection;
        });

        $warehouse = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse()->id);

        return [$tenant, $connection, $warehouse];
    }

    private function adapter(ChannelConnection $connection): WooCommerceAdapter
    {
        return new WooCommerceAdapter(
            connection: $connection,
            client: new ChannelHttpClient(connection: $connection, vault: app(CredentialVault::class), redactor: app(PayloadRedactor::class)),
        );
    }

    private function boundVariation(Tenant $tenant, ChannelConnection $connection, string $warehouse, string $sku, string $variationId, string $parentId, int $stock): Variant
    {
        return $this->asTenant($tenant, function () use ($connection, $warehouse, $sku, $variationId, $parentId, $stock): Variant {
            $variant = Variant::factory()->create(['sku' => $sku]);
            Listing::factory()->create([
                'channel_connection_id' => $connection->id,
                'variant_id' => $variant->id,
                'external_id' => $variationId,
                'external_parent_id' => $parentId,
                'lifecycle_status' => 'live',
            ]);

            DB::transaction(function () use ($warehouse, $variant, $stock): void {
                (new LockInventoryRows)->run($warehouse, [$variant->id]);
                (new ApplyMovement)->run(
                    warehouseId: $warehouse,
                    variantId: $variant->id,
                    type: MovementType::IMPORT,
                    quantity: $stock,
                    idempotencyKey: MovementKey::import((string) new UuidV7),
                    sourceType: 'import_row',
                );
            });

            return $variant;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function variation(int $id, string $sku, string $size, int $stock, string $price): array
    {
        return [
            'id' => $id,
            'sku' => $sku,
            'regular_price' => $price,
            'manage_stock' => true,
            'stock_quantity' => $stock,
            'attributes' => [['name' => 'Beden', 'option' => $size]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function wooOrder(int $id, array $lines, string $status = 'processing'): array
    {
        return [
            'id' => $id,
            'number' => (string) $id,
            'status' => $status,
            'currency' => 'TRY',
            'date_created_gmt' => now()->subHour()->utc()->format('Y-m-d\TH:i:s'),
            'date_modified_gmt' => now()->utc()->format('Y-m-d\TH:i:s'),
            'total' => '100.00',
            'total_tax' => '0.00',
            'shipping_total' => '0.00',
            'line_items' => array_map(fn (array $l) => $l + [
                'name' => 'Ürün',
                'price' => '50.00',
                'subtotal' => '100.00',
                'total' => '100.00',
                'total_tax' => '0.00',
            ], $lines),
        ];
    }

    private function onHand(Tenant $tenant, string $warehouse, string $sku): int
    {
        return (int) $this->asTenant($tenant, fn () => DB::table('inventory_levels')
            ->join('variants', 'variants.id', '=', 'inventory_levels.variant_id')
            ->where('inventory_levels.tenant_id', $tenant->id)
            ->where('inventory_levels.warehouse_id', $warehouse)
            ->where('variants.sku', $sku)
            ->value('inventory_levels.on_hand'));
    }
}
