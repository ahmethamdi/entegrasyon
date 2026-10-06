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
use App\Domain\Sync\Models\Listing;
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

    /**
     * ⚠️ İKİ TUR ARASINDA PAKETLENEN SİPARİŞ — `/orders`'ta hiç görünmez.
     *
     * Paket listesi okunmasaydı sipariş hiç alınmaz ve stoğu düşmezdi.
     * Detay 404 → paketteki kalemlerle kurulur.
     */
    #[Test]
    public function an_order_packed_between_polls_is_taken_from_the_package_list(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $this->seedStock($tenant, $kupa, 10);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/packages/merchantid/')) {
                return Http::response([[
                    'packageNumber' => 'P-1', 'status' => 'Open',
                    'items' => [[
                        'lineItemId' => 'L-9', 'orderNumber' => '4100000009', 'merchantSku' => 'kupa-01',
                        'hbSku' => 'HBV-KUPA', 'productName' => 'Kupa', 'quantity' => 4,
                        'price' => ['amount' => 25, 'currency' => 'TRY'], 'totalPrice' => ['amount' => 100, 'currency' => 'TRY'],
                        'orderDate' => '2026-10-06T10:00:00Z',
                    ]],
                ]], 200);
            }

            if (str_contains($request->url(), '/ordernumber/')) {
                return Http::response('', 404);
            }

            return Http::response(['totalCount' => 0, 'items' => []], 200);
        });

        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        $this->assertSame(6, $this->availableFor($tenant, $kupa));

        // İkinci tur: paket yine listede — stok ikinci kez düşmez, detay
        // yeniden sorulmaz.
        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        $this->assertSame(6, $this->availableFor($tenant, $kupa));
        $this->assertCount(1, Http::recorded(static fn (Request $r): bool => str_contains($r->url(), '/ordernumber/')));
        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $kupa->id);
    }

    /**
     * ⚠️ KISMİ PAKETLEME: A kalemi paketli, B açık. Sipariş hangi listeden
     * kurulursa kursun öteki tekilleştirmede yutulur — kalemler DETAYDAN
     * gelir, iptal edilmiş kalem alınmaz.
     */
    #[Test]
    public function a_partially_packed_order_is_created_with_all_lines_from_the_detail(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $tabak = $this->variant($tenant, 'TABAK-01');
        $catal = $this->variant($tenant, 'CATAL-01');

        foreach ([$kupa, $tabak, $catal] as $variant) {
            $this->seedStock($tenant, $variant, 10);
        }

        $line = fn (string $id, string $sku, int $qty, string $status): array => [
            'id' => $id, 'orderNumber' => '4100000005', 'orderDate' => '2026-10-06T10:00:00Z',
            'merchantSKU' => $sku, 'sku' => 'HBV-'.$sku, 'name' => $sku, 'quantity' => $qty, 'status' => $status,
            'unitPrice' => ['amount' => 10, 'currency' => 'TRY'], 'totalPrice' => ['amount' => 10 * $qty, 'currency' => 'TRY'],
        ];

        Http::fake(function (Request $request) use ($line) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/ordernumber/4100000005') => Http::response(['orderNumber' => '4100000005', 'items' => [
                    $line('A', 'KUPA-01', 1, 'Packaged'), $line('B', 'TABAK-01', 2, 'Open'), $line('C', 'CATAL-01', 3, 'CancelledByMerchant'),
                ]], 200),
                str_contains($url, '/packages/merchantid/') => Http::response([[
                    'packageNumber' => 'P-5', 'items' => [['lineItemId' => 'A', 'orderNumber' => '4100000005', 'merchantSku' => 'KUPA-01', 'quantity' => 1]],
                ]], 200),
                str_contains($url, '/cancelled') => Http::response(['totalCount' => 0, 'items' => []], 200),
                str_contains($url, '/orders/merchantid/') => Http::response(['totalCount' => 1, 'items' => [$line('B', 'TABAK-01', 2, 'Open')]], 200),
                default => Http::response([], 404),
            };
        });

        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        $this->assertSame(9, $this->availableFor($tenant, $kupa));
        $this->assertSame(8, $this->availableFor($tenant, $tabak));
        $this->assertSame(10, $this->availableFor($tenant, $catal), 'İptal edilmiş kalem stok düşürmez.');
        $this->assertSame(1, $this->asTenant($tenant, fn () => Order::query()->where('external_id', '4100000005')->count()));
    }

    /**
     * Paket sorgusu ≤24 saatlik dilimlerle yürür — uzun aralıkta `enddate`
     * SESSİZCE yok sayılır (belgeli). 30 saatlik pencere iki dilim olur.
     * Dolu sayfa (10 paket) sonraki ofseti ister.
     */
    #[Test]
    public function the_package_list_is_walked_in_windows_of_at_most_24_hours(): void
    {
        [$tenant, $connection] = $this->setUpConnection();

        Carbon::setTestNow('2026-10-06 12:00:00');

        $full = array_map(static fn (int $i): array => ['packageNumber' => "P-{$i}", 'items' => []], range(1, 10));

        Http::fake(function (Request $request) use ($full) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(($query['offset'] ?? '0') === '0' ? $full : [], 200);
        });

        $adapter = $this->asTenant($tenant, fn () => app(AdapterRegistry::class)->for($connection));
        $since = Carbon::parse('2026-10-05 09:00:00', 'UTC');

        $first = $adapter->fetchOrders($since, 'packages:0:0');
        $this->assertSame('packages:0:10', $first->nextCursor, 'Dolu sayfa → aynı dilimde sonraki ofset.');

        $this->assertSame('packages:1:0', $adapter->fetchOrders($since, $first->nextCursor)->nextCursor);
        $adapter->fetchOrders($since, 'packages:1:0');
        $this->assertSame('cancelled:0', $adapter->fetchOrders($since, 'packages:2:0')->nextCursor);

        $windows = collect(Http::recorded())->map(static function (array $pair): array {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return [$query['begindate'], $query['enddate'], $query['limit']];
        })->unique()->values()->all();

        // 09:00 UTC − 3 sa = 06:00 UTC = 09:00 TR; bitiş 12:00 UTC + 3 sa = 18:00 TR (ertesi gün).
        $this->assertSame([
            ['2026-10-05 09:00', '2026-10-06 09:00', '10'],
            ['2026-10-06 09:00', '2026-10-06 18:00', '10'],
        ], $windows);

        Carbon::setTestNow();
    }

    /**
     * Onaylanmış iade stoğu geri ekler; yeni (yoldaki) talep eklemez.
     *
     * ⚠️ Talepteki `sku` HB kodudur (HBV…); sipariş satırı satıcı SKU'sunu
     * taşır. İlanın `merchant_sku`'suna çevrilmeseydi iade hiçbir satırla
     * tutmaz ve stok dönmezdi. Aynı talep ikinci turda yine gelir, stok
     * ikinci kez eklenmez.
     */
    #[Test]
    public function an_accepted_claim_returns_stock_and_a_new_request_does_not(): void
    {
        [$tenant, $connection] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $tabak = $this->variant($tenant, 'TABAK-01');
        $this->seedStock($tenant, $kupa, 10);
        $this->seedStock($tenant, $tabak, 10);

        $this->asTenant($tenant, fn () => Listing::factory()->create([
            'channel_connection_id' => $connection->id,
            'variant_id' => $kupa->id,
            'external_id' => 'HBV-KUPA',
            'channel_metadata' => ['merchant_sku' => 'kupa-01'],
        ]));

        $line = fn (string $id, string $sku, int $qty): array => [
            'id' => $id, 'orderNumber' => '4100000007', 'orderDate' => '2026-10-06T10:00:00Z', 'merchantSKU' => $sku,
            'quantity' => $qty, 'unitPrice' => ['amount' => 10, 'currency' => 'TRY'], 'totalPrice' => ['amount' => 10 * $qty, 'currency' => 'TRY'],
        ];

        Http::fake(function (Request $request) use ($line) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/claims/merchantId/') => Http::response([
                    ['number' => 'T-1', 'orderNumber' => '4100000007', 'sku' => 'HBV-KUPA', 'quantity' => 2, 'status' => 'Accepted', 'claimType' => 'Return', 'claimDate' => '2026-10-07T09:00:00Z'],
                    ['number' => 'T-2', 'orderNumber' => '4100000007', 'sku' => 'TABAK-01', 'quantity' => 1, 'status' => 'NewRequest'],
                ], 200),
                str_contains($url, '/cancelled') || str_contains($url, '/packages/') => Http::response(['totalCount' => 0, 'items' => []], 200),
                str_contains($url, '/ordernumber/') => Http::response('', 404),
                str_contains($url, '/orders/merchantid/') => Http::response(['totalCount' => 2, 'items' => [$line('L-1', 'KUPA-01', 3), $line('L-2', 'TABAK-01', 1)]], 200),
                default => Http::response([], 404),
            };
        });

        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        $this->assertSame(9, $this->availableFor($tenant, $kupa), 'Satış 3, onaylı iade 2.');
        $this->assertSame(9, $this->availableFor($tenant, $tabak), 'Yoldaki talep stok eklemez.');

        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        $this->assertSame(9, $this->availableFor($tenant, $kupa), 'Aynı talep ikinci kez stok eklemez.');
        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $kupa->id);
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
