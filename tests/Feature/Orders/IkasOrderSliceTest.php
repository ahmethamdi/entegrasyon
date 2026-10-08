<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Ikas\IkasAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * ikas siparişi stoğu düşürür, kalem iptali geri ekler — zincir gerçek
 * sınıflarla (HepsiburadaOrderSliceTest'in kardeşi):
 *
 *   yoklama → inbox → `ProcessInboxMessage` → `OrderEventRouter` → ledger
 *
 * ⚠️ ikas KISMİ İPTALDE KALEMİ BÖLER: iptal edilen adet YENİ bir kalemde
 * (`originalOrderLineItemId`) durur ve o kimlik bizde yoktur. İptal SKU ile
 * asıl kaleme düşmeli; düşmeseydi stok hiç geri eklenmezdi.
 */
final class IkasOrderSliceTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    #[Test]
    public function an_order_reduces_stock_and_line_cancellations_return_it(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $tabak = $this->variant($tenant, 'TABAK-01');
        $this->seedStock($tenant, $kupa, 10);
        $this->seedStock($tenant, $tabak, 10);

        $line = static fn (string $id, string $sku, int $qty, string $status = 'UNFULFILLED', ?string $original = null): array => [
            'id' => $id, 'quantity' => $qty, 'price' => 50, 'finalPrice' => 50 * $qty, 'finalUnitPrice' => 50,
            'status' => $status, 'statusUpdatedAt' => 1_760_003_600_000, 'originalOrderLineItemId' => $original, 'deleted' => false,
            'variant' => ['id' => 'v-'.$sku, 'productId' => 'p-1', 'sku' => $sku, 'name' => $sku],
        ];

        $order = static fn (string $status, array $lines): array => ['data' => ['listOrder' => ['hasNext' => false, 'data' => [[
            'id' => 'o-1', 'orderNumber' => '1001', 'status' => $status, 'orderPaymentStatus' => 'PAID',
            'orderedAt' => 1_760_000_000_000, 'updatedAt' => 1_760_003_600_000, 'currencyCode' => 'TRY',
            'totalPrice' => 250, 'totalFinalPrice' => 250, 'customerId' => 'c-1', 'orderLineItems' => $lines,
        ]]]]];

        // 1. tur: açık sipariş — kupa 3, tabak 2.
        $this->respond($order('CREATED', [$line('l-1', 'KUPA-01', 3), $line('l-2', 'TABAK-01', 2)]));
        $this->poll($tenant);

        $this->assertSame(7, $this->availableFor($tenant, $kupa));
        $this->assertSame(8, $this->availableFor($tenant, $tabak));

        $placed = $this->asTenant($tenant, fn () => Order::query()->where('external_id', 'o-1')->firstOrFail());
        $this->assertSame('1001', $placed->external_number);
        $this->assertSame('2025-10-09T08:53:20+00:00', $placed->placed_at->toIso8601String());

        // 2. tur: tabağın 1 adedi iptal — ikas kalemi BÖLER (l-2 → 1, l-2b iptal 1).
        $this->respond($order('PARTIALLY_CANCELLED', [
            $line('l-1', 'KUPA-01', 3), $line('l-2', 'TABAK-01', 1), $line('l-2b', 'TABAK-01', 1, 'CANCELLED', 'l-2'),
        ]));
        $this->poll($tenant);

        $this->assertSame(7, $this->availableFor($tenant, $kupa));
        $this->assertSame(9, $this->availableFor($tenant, $tabak));
        // Tek kalemin iptali siparişi "iptal" göstermez.
        $this->assertSame('CREATED', $this->asTenant($tenant, fn () => $placed->fresh()->status));

        // 3. tur: aynı veri yeniden gelir — hiçbir şey İKİNCİ kez işlenmez.
        $this->poll($tenant);
        $this->assertSame(7, $this->availableFor($tenant, $kupa));
        $this->assertSame(9, $this->availableFor($tenant, $tabak));

        // 4. tur: siparişin kalanı iptal.
        $this->respond($order('CANCELLED', [
            $line('l-1', 'KUPA-01', 3, 'CANCELLED'), $line('l-2', 'TABAK-01', 1, 'CANCELLED'), $line('l-2b', 'TABAK-01', 1, 'CANCELLED', 'l-2'),
        ]));
        $this->poll($tenant);

        $this->assertSame(10, $this->availableFor($tenant, $kupa));
        $this->assertSame(10, $this->availableFor($tenant, $tabak));
        $this->assertSame('CANCELLED', $this->asTenant($tenant, fn () => $placed->fresh()->status));

        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $kupa->id);
        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $tabak->id);
    }

    /**
     * Sipariş İLK KEZ zaten kısmen iptal hâlinde görülür: bütün kalemler
     * düşülür, iptal kalemi kendi kimliğiyle geri eklenir — net doğru.
     */
    #[Test]
    public function an_order_first_seen_partially_cancelled_nets_out(): void
    {
        [$tenant] = $this->setUpConnection();

        $tabak = $this->variant($tenant, 'TABAK-01');
        $this->seedStock($tenant, $tabak, 10);

        $this->respond(['data' => ['listOrder' => ['hasNext' => false, 'data' => [[
            'id' => 'o-2', 'orderNumber' => '1002', 'status' => 'PARTIALLY_CANCELLED', 'orderedAt' => 1_760_000_000_000,
            'currencyCode' => 'TRY', 'totalPrice' => 100, 'totalFinalPrice' => 100,
            'orderLineItems' => [
                ['id' => 'l-1', 'quantity' => 2, 'price' => 50, 'status' => 'UNFULFILLED', 'variant' => ['id' => 'v-t', 'sku' => 'TABAK-01']],
                ['id' => 'l-1b', 'quantity' => 1, 'price' => 50, 'status' => 'CANCELLED', 'originalOrderLineItemId' => 'l-1', 'variant' => ['id' => 'v-t', 'sku' => 'TABAK-01']],
            ],
        ]]]]]);

        $this->poll($tenant);

        $this->assertSame(8, $this->availableFor($tenant, $tabak));
        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $tabak->id);
    }

    /** İadesi teslim alınan kalem stoğa döner; yalnız para iadesi (REFUNDED) dönmez. */
    #[Test]
    public function only_a_delivered_return_restocks(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $tabak = $this->variant($tenant, 'TABAK-01');
        $this->seedStock($tenant, $kupa, 10);
        $this->seedStock($tenant, $tabak, 10);

        $order = static fn (string $kupaStatus, string $tabakStatus): array => ['data' => ['listOrder' => ['hasNext' => false, 'data' => [[
            'id' => 'o-3', 'orderNumber' => '1003', 'status' => 'CREATED', 'orderedAt' => 1_760_000_000_000,
            'currencyCode' => 'TRY', 'totalPrice' => 100, 'totalFinalPrice' => 100,
            'orderLineItems' => [
                ['id' => 'l-k', 'quantity' => 1, 'price' => 50, 'status' => $kupaStatus, 'variant' => ['id' => 'v-k', 'sku' => 'KUPA-01']],
                ['id' => 'l-t', 'quantity' => 1, 'price' => 50, 'status' => $tabakStatus, 'variant' => ['id' => 'v-t', 'sku' => 'TABAK-01']],
            ],
        ]]]]];

        $this->respond($order('DELIVERED', 'DELIVERED'));
        $this->poll($tenant);

        $this->respond($order('REFUND_DELIVERED', 'REFUNDED'));
        $this->poll($tenant);

        $this->assertSame(10, $this->availableFor($tenant, $kupa));
        $this->assertSame(9, $this->availableFor($tenant, $tabak));
    }

    /** @var array<string, mixed> */
    private array $response = [];

    /**
     * Sıradaki yoklamanın yanıtı. `Http::fake()` iki kez çağrılırsa İLK
     * kayıt kazanır; yanıt bu yüzden değişkenden okunur.
     *
     * @param  array<string, mixed>  $body
     */
    private function respond(array $body): void
    {
        $this->response = $body;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn () => Http::response($this->response));
    }

    private function poll(Tenant $tenant): void
    {
        app(PollChannelOrders::class)->run();

        $ids = $this->asTenant($tenant, fn (): array => InboxMessage::query()
            ->where('status', 'pending')
            ->orderBy('received_at')
            ->orderBy('id')
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
        $tenant = (new CreateTenant)->run(name: 'ikas Dilim '.uniqid(), owner: User::factory()->create());

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'ikas'],
            [
                'name' => 'ikas',
                'kind' => 'storefront',
                'adapter_class' => IkasAdapter::class,
                'capabilities' => [
                    'catalog' => false, 'catalog_import' => true, 'inventory' => true, 'pricing' => true,
                    'orders' => true, 'taxonomy' => false, 'approval' => false, 'fulfillment' => false,
                ],
                'rate_limit_profile' => ['requests_per_second' => 4, 'burst_capacity' => 8],
                'supports_webhooks' => false,
                'is_active' => true,
            ],
        ));

        $connection = $this->asTenant($tenant, function (): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'ikas',
                'external_account_id' => 'magazam',
                'status' => 'active',
                // Sipariş tarihinden (9 Eki 2025) önce: sonradan gelen
                // iptal/güncelleme kaçırılmış siparişi yaratabilsin (A14).
                'connected_at' => now()->setDate(2025, 10, 1),
                'settings' => [],
            ]);

            app(CredentialVault::class)->store($connection, ['client_id' => 'CID', 'client_secret' => 'CSECRET-123', 'access_token' => 'TOKEN-1']);

            return $connection;
        });

        return [$tenant, $connection];
    }
}
