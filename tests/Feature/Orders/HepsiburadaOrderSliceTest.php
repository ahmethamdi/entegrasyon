<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Hepsiburada\HepsiburadaAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Routing\OrderEventRouter;
use App\Domain\Orders\Support\PollChannelOrders;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * Hepsiburada siparişi stoğu düşürür, iptali geri ekler — zincir gerçek
 * sınıflarla, kuyruk sahtesi OLMADAN (TrendyolOrderSliceTest'in kardeşi):
 *
 *   yoklama → inbox → `ProcessInboxMessage` → `OrderEventRouter` →
 *   `IngestChannelOrder` / iptal → `ApplyMovement` → ledger
 *
 * Kanal yanıtları resmî OpenAPI şemasındaki alan adlarıyla kurulur
 * (`docs/hepsiburada-openapi/siparis.json`).
 */
final class HepsiburadaOrderSliceTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    /**
     * Açık sipariş stoğu düşürür, kalem iptali geri ekler; iki kalemli
     * siparişte yalnız iptal edilen kalemin stoğu döner.
     */
    #[Test]
    public function an_order_reduces_stock_and_a_line_cancellation_returns_it(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $tabak = $this->variant($tenant, 'TABAK-01');
        $this->seedStock($tenant, $kupa, 10);
        $this->seedStock($tenant, $tabak, 10);

        $line = fn (string $id, string $merchantSku, int $qty): array => [
            'id' => $id, 'orderNumber' => '4100000001', 'orderDate' => '2026-10-06T10:00:00Z',
            'merchantSKU' => strtolower($merchantSku), 'sku' => 'HBV-'.$merchantSku, 'name' => $merchantSku,
            'quantity' => $qty, 'unitPrice' => ['amount' => 50, 'currency' => 'TRY'],
            'totalPrice' => ['amount' => 50 * $qty, 'currency' => 'TRY'], 'status' => 'Open',
        ];

        $cancelled = false;

        Http::fake(function (Request $request) use ($line, &$cancelled) {
            if (str_contains($request->url(), '/orders/merchantid/') && str_contains($request->url(), '/cancelled')) {
                return Http::response($cancelled ? ['totalCount' => 1, 'items' => [[
                    'lineItemId' => 'L-2', 'orderNumber' => '4100000001', 'merchantSku' => 'TABAK-01',
                    'quantity' => 2, 'cancelDate' => '2026-10-06T11:00:00Z', 'cancelReasonCode' => 'X',
                ]]] : ['totalCount' => 0, 'items' => []], 200);
            }

            if (str_contains($request->url(), '/orders/merchantid/')) {
                return Http::response(['totalCount' => 2, 'items' => [$line('L-1', 'KUPA-01', 3), $line('L-2', 'TABAK-01', 2)]], 200);
            }

            return Http::response([], 404);
        });

        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        $this->assertSame(7, $this->availableFor($tenant, $kupa));
        $this->assertSame(8, $this->availableFor($tenant, $tabak));

        $order = $this->asTenant($tenant, fn () => Order::query()->where('external_id', '4100000001')->firstOrFail());
        $this->assertSame('250', rtrim(rtrim((string) $order->grand_total, '0'), '.'));
        $this->assertSame('2026-10-06T10:00:00+00:00', $order->placed_at->toIso8601String());

        // İkinci tur: aynı açık sipariş tekrar gelir (stok İKİNCİ kez düşmez),
        // tabağın iptali gelir (yalnız tabak geri döner).
        $cancelled = true;
        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        $this->assertSame(7, $this->availableFor($tenant, $kupa));
        $this->assertSame(10, $this->availableFor($tenant, $tabak));

        // Tek kalemin iptali bütün siparişi "iptal" göstermez.
        $this->assertSame('Open', $this->asTenant($tenant, fn () => $order->fresh()->status));

        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $kupa->id);
        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $tabak->id);
    }

    /**
     * ⚠️ SAYFA SINIRINDA YARIM SİPARİŞ ERTELENİR.
     *
     * Siparişin ikinci kalemi bir sonraki sayfadaydı; ilk sayfada sipariş
     * tek kalemle yaratılsaydı ikinci kalem "sipariş zaten var" diye atlanır
     * ve stoğu hiç düşmezdi.
     */
    #[Test]
    public function an_order_split_across_pages_is_created_with_all_lines(): void
    {
        [$tenant, $connection] = $this->setUpConnection();

        $line = fn (string $id, string $order): array => [
            'id' => $id, 'orderNumber' => $order, 'merchantSKU' => 'X', 'quantity' => 1,
            'unitPrice' => ['amount' => 1, 'currency' => 'TRY'], 'totalPrice' => ['amount' => 1, 'currency' => 'TRY'],
        ];

        Http::fake(['*' => Http::response(['totalCount' => 3, 'items' => [$line('a', 'S-1'), $line('b', 'S-2')]], 200)]);

        $adapter = $this->asTenant($tenant, fn () => app(AdapterRegistry::class)->for($connection));
        $page = $adapter->fetchOrders(now()->subHour());

        $this->assertSame(['S-1'], array_column($page->orders, 'orderNumber'));
        $this->assertSame('open:1', $page->nextCursor, 'S-2 bir sonraki sayfada bütün olarak gelmeli.');
    }

    /** Sorgu penceresi 3 saat geriye genişletilir (saat dilimi belgesiz). */
    #[Test]
    public function the_query_window_is_widened_for_the_undocumented_timezone(): void
    {
        [$tenant, $connection] = $this->setUpConnection();

        Http::fake(['*' => Http::response(['totalCount' => 0, 'items' => []], 200)]);

        $adapter = $this->asTenant($tenant, fn () => app(AdapterRegistry::class)->for($connection));
        $adapter->fetchOrders(Carbon::parse('2026-10-06 12:00:00', 'UTC'));

        // 12:00 UTC − 3 saat = 09:00 UTC = 12:00 Türkiye.
        Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), 'oms-external.hepsiburada.com/orders/merchantid/')
            && str_contains(urldecode($r->url()), 'begindate=2026-10-06 12:00'));
    }

    private function processInbox(Tenant $tenant): void
    {
        $ids = $this->asTenant($tenant, fn (): array => InboxMessage::query()
            ->where('status', 'pending')
            ->orderBy('received_at')
            ->pluck('id')
            ->all());

        foreach ($ids as $id) {
            (new ProcessInboxMessage($tenant->id, $id))->handle(app(OrderEventRouter::class));
        }
    }

    private function availableFor(Tenant $tenant, Variant $variant): int
    {
        return (int) $this->asTenant($tenant, fn () => DB::table('inventory_levels')
            ->where('tenant_id', $tenant->id)
            ->where('variant_id', $variant->id)
            ->value('available'));
    }

    private function variant(Tenant $tenant, string $sku): Variant
    {
        return $this->asTenant($tenant, function () use ($sku): Variant {
            $product = Product::factory()->create();

            return Variant::factory()->create(['product_id' => $product->id, 'sku' => $sku]);
        });
    }

    private function seedStock(Tenant $tenant, Variant $variant, int $quantity): void
    {
        $this->asTenant($tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: $this->warehouse($tenant)->id,
            variantId: $variant->id,
            type: MovementType::IMPORT,
            quantity: $quantity,
            idempotencyKey: 'import:'.$variant->id,
            sourceType: 'test',
        ));
    }

    private function warehouse(Tenant $tenant): Warehouse
    {
        return $this->asTenant($tenant, fn (): Warehouse => Warehouse::query()
            ->where('is_default', true)
            ->firstOrFail());
    }

    /** @return array{0: Tenant, 1: ChannelConnection} */
    private function setUpConnection(): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'HB Dilim '.uniqid(), owner: $user);

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'hepsiburada'],
            [
                'name' => 'Hepsiburada',
                'kind' => 'marketplace',
                'adapter_class' => HepsiburadaAdapter::class,
                'capabilities' => [
                    'catalog' => false, 'catalog_import' => true, 'inventory' => true, 'pricing' => true,
                    'orders' => true, 'taxonomy' => false, 'approval' => false, 'fulfillment' => false,
                ],
                'rate_limit_profile' => ['requests_per_second' => 10, 'burst_capacity' => 10],
                'supports_webhooks' => false,
                'is_active' => true,
            ],
        ));

        $connection = $this->asTenant($tenant, function (): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'hepsiburada',
                'external_account_id' => '11111111-2222-3333-4444-555555555555',
                'status' => 'active',
                'connected_at' => now()->subDay(),
                'settings' => [HepsiburadaAdapter::INTEGRATOR_KEY => 'firma_dev'],
            ]);

            app(CredentialVault::class)->store($connection, ['service_key' => 'ANAHTAR12345']);

            return $connection;
        });

        return [$tenant, $connection];
    }
}
