<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Messaging\Actions\IngestInboxMessage;
use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Support\ReconcileChannelOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * Shopify kaçan sipariş kurtarma — `reconcile:orders` (mimari denetim P0-3).
 *
 * Webhook kanalı yoklanmaz; kaybolan webhook önceden kalıcı sipariş
 * kaybıydı. Tur siparişleri GraphQL'den okur, webhook gövdesi biçimine
 * çevirir ve AYNI inbox hattından geçirir (kuyruk senkron).
 */
final class ShopifyOrderRecoveryTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    #[Test]
    public function a_missed_shopify_webhook_is_recovered_by_order_reconciliation(): void
    {
        [$tenant, $connection, $variant, $warehouse] = $this->shop('kurtarma.myshopify.com', stock: 10);

        $this->fakeGraphql(['kurtarma.myshopify.com' => [[$this->order('5001', $variant->sku, 2)]]]);

        $this->artisan('reconcile:orders')->assertSuccessful();

        $message = $this->asTenant($tenant, fn () => InboxMessage::query()->sole());
        $this->assertSame('reconcile', $message->source);
        $this->assertSame('processed', $message->status);
        $this->assertSame(1, $this->asTenant($tenant, fn (): int => Order::query()->where('external_id', '5001')->count()));
        $this->assertSame(8, $this->onHand($tenant, $warehouse, $variant));
        $this->assertArrayHasKey(ReconcileChannelOrders::CURSOR_KEY, $this->settings($connection));
        $this->assertLedgerMatchesProjection($tenant->id, $warehouse, $variant->id);
    }

    /** Tekrar eden tur ve GEÇ GELEN asıl webhook stoğu ikinci kez düşmez. */
    #[Test]
    public function recovery_is_idempotent_and_a_late_webhook_does_not_double_deduct(): void
    {
        [$tenant, $connection, $variant, $warehouse] = $this->shop('tekrar.myshopify.com', stock: 10);

        $order = $this->order('5002', $variant->sku, 3);
        $this->fakeGraphql(['tekrar.myshopify.com' => [[$order]]]);

        $this->artisan('reconcile:orders')->assertSuccessful();
        $this->travel(31)->minutes();
        $this->artisan('reconcile:orders')->assertSuccessful();

        // Shopify'ın yeniden denediği asıl webhook sonradan gelir.
        $webhook = $this->asTenant($tenant, fn () => app(IngestInboxMessage::class)->run(
            connection: $connection,
            source: 'webhook',
            externalEventId: 'evt-gec-gelen',
            eventType: 'orders/create',
            payload: (string) json_encode(ShopifyAdapter::restOrderFromGraphql($order)),
        ));
        (new ProcessInboxMessage($tenant->id, $webhook->id))->handle();

        $this->assertSame(1, $this->asTenant($tenant, fn (): int => Order::query()->count()));
        $this->assertSame(7, $this->onHand($tenant, $warehouse, $variant));
        $this->assertSame(2, $this->asTenant($tenant, fn (): int => InboxMessage::query()->count()), 'Turlar aynı olayı ikinci kez yazmamalı.');
        $this->assertLedgerMatchesProjection($tenant->id, $warehouse, $variant->id);
    }

    #[Test]
    public function all_pages_are_read_with_the_cursor(): void
    {
        [$tenant, , $variant] = $this->shop('sayfa.myshopify.com', stock: 10);

        $afters = [];
        $this->fakeGraphql(['sayfa.myshopify.com' => [
            [$this->order('6001', $variant->sku, 1)],
            [$this->order('6002', $variant->sku, 1)],
        ]], $afters);

        $this->artisan('reconcile:orders')->assertSuccessful();

        $this->assertSame([null, 'SAYFA-1'], $afters);
        $this->assertSame(2, $this->asTenant($tenant, fn (): int => Order::query()->count()));
    }

    /** A'nın mağazası 500 döner — B ve C yine kurtarılır, A'nın imleci ilerlemez. */
    #[Test]
    public function a_broken_shop_does_not_stop_recovery_for_other_merchants(): void
    {
        [, $broken] = $this->shop('bozuk.myshopify.com', stock: 10);
        [$tenantB, , $variantB] = $this->shop('saglam-b.myshopify.com', stock: 10);
        [$tenantC, , $variantC] = $this->shop('saglam-c.myshopify.com', stock: 10);

        $this->fakeGraphql([
            'bozuk.myshopify.com' => 500,
            'saglam-b.myshopify.com' => [[$this->order('7001', $variantB->sku, 1)]],
            'saglam-c.myshopify.com' => [[$this->order('7002', $variantC->sku, 1)]],
        ]);

        $this->artisan('reconcile:orders')->assertSuccessful();

        $this->assertSame(1, $this->asTenant($tenantB, fn (): int => Order::query()->count()));
        $this->assertSame(1, $this->asTenant($tenantC, fn (): int => Order::query()->count()));
        $this->assertArrayNotHasKey(ReconcileChannelOrders::CURSOR_KEY, $this->settings($broken));
    }

    /** İptal edilmiş kaçan sipariş: düşülür ve geri eklenir — net sıfır, defterde iz. */
    #[Test]
    public function a_missed_cancelled_order_nets_to_zero(): void
    {
        [$tenant, , $variant, $warehouse] = $this->shop('iptal.myshopify.com', stock: 10);

        $this->fakeGraphql(['iptal.myshopify.com' => [[$this->order('8001', $variant->sku, 4, cancelled: true)]]]);

        $this->artisan('reconcile:orders')->assertSuccessful();

        $this->assertSame(10, $this->onHand($tenant, $warehouse, $variant));
        $this->assertSame(1, $this->asTenant($tenant, fn (): int => Order::query()->count()));
        $this->assertLedgerMatchesProjection($tenant->id, $warehouse, $variant->id);
    }

    /** Bağlantıdan önce verilmiş sipariş açılış stoğundadır — yaratılmaz. */
    #[Test]
    public function an_order_placed_before_the_connection_is_not_recovered(): void
    {
        [$tenant, , $variant, $warehouse] = $this->shop('eski.myshopify.com', stock: 10);

        $this->fakeGraphql(['eski.myshopify.com' => [[$this->order('9001', $variant->sku, 2, createdAt: now()->subDays(5)->toIso8601String())]]]);

        $this->artisan('reconcile:orders')->assertSuccessful();

        $this->assertSame(0, $this->asTenant($tenant, fn (): int => Order::query()->count()));
        $this->assertSame(10, $this->onHand($tenant, $warehouse, $variant));
    }

    /** İmleçten geriye örtüşen pencere sorulur. */
    #[Test]
    public function the_window_starts_before_the_cursor(): void
    {
        [, $connection] = $this->shop('pencere.myshopify.com', stock: 1);

        $this->asSystem(fn () => DB::table('channel_connections')->where('id', $connection->id)->update([
            'settings' => json_encode([ReconcileChannelOrders::CURSOR_KEY => '2026-10-11T10:00:00+00:00']),
        ]));
        $this->travelTo('2026-10-11 10:30:00');

        $queries = [];
        $this->fakeGraphql(['pencere.myshopify.com' => [[]]], queries: $queries);

        $this->artisan('reconcile:orders')->assertSuccessful();

        $this->assertSame(["updated_at:>='2026-10-11T09:50:00Z'"], $queries);
    }

    // ---------------------------------------------------------------- yardımcılar

    /**
     * @param  array<string, int|list<list<array<string, mixed>>>>  $shops  alan adı → sayfalar (ya da HTTP durum)
     * @param  list<string|null>  $afters
     * @param  list<string>  $queries
     */
    private function fakeGraphql(array $shops, array &$afters = [], array &$queries = []): void
    {
        Http::fake(function (Request $request) use ($shops, &$afters, &$queries) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            $pages = $shops[$host] ?? null;

            if (is_int($pages)) {
                return Http::response(['errors' => 'down'], $pages);
            }

            $after = $request['variables']['after'] ?? null;
            $afters[] = $after;
            $queries[] = (string) ($request['variables']['query'] ?? '');
            $index = $after === null ? 0 : (int) substr((string) $after, strlen('SAYFA-'));
            $nodes = $pages[$index] ?? [];
            $hasNext = isset($pages[$index + 1]);

            return Http::response(['data' => ['orders' => [
                'pageInfo' => ['hasNextPage' => $hasNext, 'endCursor' => $hasNext ? 'SAYFA-'.($index + 1) : null],
                'nodes' => $nodes,
            ]]]);
        });
    }

    /** @return array<string, mixed> */
    private function order(string $id, string $sku, int $quantity, bool $cancelled = false, ?string $createdAt = null): array
    {
        return [
            'legacyResourceId' => $id,
            'name' => '#'.$id,
            'createdAt' => $createdAt ?? now()->subMinutes(20)->toIso8601String(),
            'updatedAt' => now()->subMinutes(5)->toIso8601String(),
            'cancelledAt' => $cancelled ? now()->subMinutes(5)->toIso8601String() : null,
            'displayFinancialStatus' => $cancelled ? 'VOIDED' : 'PAID',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'currencyCode' => 'TRY',
            'currentSubtotalPriceSet' => ['shopMoney' => ['amount' => '100.00']],
            'currentTotalTaxSet' => ['shopMoney' => ['amount' => '16.67']],
            'currentTotalPriceSet' => ['shopMoney' => ['amount' => '100.00']],
            'totalShippingPriceSet' => ['shopMoney' => ['amount' => '0.00']],
            'lineItems' => [
                'pageInfo' => ['hasNextPage' => false],
                'nodes' => [[
                    'id' => 'gid://shopify/LineItem/'.$id.'1',
                    'sku' => $sku,
                    'title' => 'Ürün',
                    'name' => 'Ürün',
                    'quantity' => $quantity,
                    'variant' => ['legacyResourceId' => '44'.$id],
                    'originalUnitPriceSet' => ['shopMoney' => ['amount' => '50.00']],
                ]],
            ],
        ];
    }

    /** @return array{0: Tenant, 1: ChannelConnection, 2: Variant, 3: string} */
    private function shop(string $domain, int $stock): array
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'shopify'], [
            'name' => 'Shopify', 'kind' => 'storefront', 'adapter_class' => ShopifyAdapter::class,
            'supports_webhooks' => true, 'is_active' => true,
        ]));

        $tenant = (new CreateTenant)->run(name: 'Kurtarma '.uniqid(), owner: User::factory()->create());

        $connection = $this->asTenant($tenant, function () use ($domain): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'shopify',
                'external_account_id' => $domain,
                'status' => 'active',
                'connected_at' => now()->subDays(2),
                'settings' => ['location_gid' => 'gid://shopify/Location/1'],
            ]);

            app(CredentialVault::class)->store($connection, ['access_token' => 'erisim']);

            return $connection;
        });

        $warehouse = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse()->id);
        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

        $this->asTenant($tenant, fn () => DB::transaction(function () use ($warehouse, $variant, $stock): void {
            (new LockInventoryRows)->run($warehouse, [$variant->id]);
            (new ApplyMovement)->run(
                warehouseId: $warehouse,
                variantId: $variant->id,
                type: MovementType::IMPORT,
                quantity: $stock,
                idempotencyKey: MovementKey::import((string) new UuidV7),
                sourceType: 'import_row',
            );
        }));

        return [$tenant, $connection, $variant, $warehouse];
    }

    /** @return array<string, mixed> */
    private function settings(ChannelConnection $connection): array
    {
        return (array) $this->asSystem(fn () => json_decode(
            (string) DB::table('channel_connections')->where('id', $connection->id)->value('settings'),
            true,
        ));
    }

    private function onHand(Tenant $tenant, string $warehouse, Variant $variant): int
    {
        return (int) $this->asSystem(fn () => DB::table('inventory_levels')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse)
            ->where('variant_id', $variant->id)
            ->value('on_hand'));
    }
}
