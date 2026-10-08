<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Pazarama\PazaramaAdapter;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * Pazarama siparişi stoğu düşürür; iptal, tedarik edilemeyen kalem ve
 * onaylanan iade geri ekler — zincir gerçek sınıflarla (N11OrderSliceTest'in
 * kardeşi).
 *
 * Sahte Pazarama tek bir `Http::fake` kapanışıdır ve durumu test alanından
 * okur (ikinci `Http::fake` çağrısında İLK kayıt kazanırdı): `$orders`
 * siparişlerin o anki hâli; istek penceresi (`startDate` dahil, `endDate`
 * HARİÇ, Türkiye günü) sipariş tarihine uygulanır — gerçek servis gibi.
 */
final class PazaramaOrderSliceTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    private const ORDER = '283534307';

    /** @var list<array<string, mixed>> */
    private array $orders = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/order/updateOrderStatusList')) {
                return Http::response(['success' => true, 'messageCode' => null, 'message' => null]);
            }

            $body = $request->data();

            if (($body['pageNumber'] ?? 1) > 1) {
                return Http::response(['data' => [], 'success' => true]);
            }

            $inWindow = array_values(array_filter($this->orders, static function (array $order) use ($body): bool {
                $day = substr((string) $order['orderDate'], 0, 10);

                return $day >= $body['startDate'] && $day < $body['endDate'];
            }));

            return Http::response(['data' => $inWindow, 'success' => true]);
        });
    }

    /**
     * V2: aynı barkodun her adedi ayrı kalem — satırda TOPLANIR, stok bir
     * kez ve doğru adetle düşer; tek adedin iptali tek adet geri ekler.
     */
    #[Test]
    public function split_items_of_the_same_barcode_are_summed_and_a_single_unit_cancellation_returns_one(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $tabak = $this->variant($tenant, 'TABAK-01');
        $this->seedStock($tenant, $kupa, 10);
        $this->seedStock($tenant, $tabak, 10);

        $this->orders = [$this->order([
            $this->item('i-1', 'BRK-KUPA', 'KUPA-01', 3),
            $this->item('i-2', 'BRK-KUPA', 'KUPA-01', 3),
            $this->item('i-3', 'BRK-KUPA', 'KUPA-01', 3),
            $this->item('i-4', 'BRK-TABAK', 'TABAK-01', 3),
        ])];

        $this->poll($tenant);

        $this->assertSame(7, $this->availableFor($tenant, $kupa));
        $this->assertSame(9, $this->availableFor($tenant, $tabak));

        $order = $this->asTenant($tenant, fn () => Order::query()->with('lines')->where('external_id', self::ORDER)->firstOrFail());
        $this->assertSame(self::ORDER, $order->external_number);
        $this->assertCount(2, $order->lines);
        $kupaLine = $order->lines->firstWhere('external_line_id', 'BRK-KUPA');
        $this->assertSame(3, (int) $kupaLine->quantity);
        $this->assertSame('250.00', (string) $kupaLine->unit_price);
        $this->assertSame('750.00', (string) $kupaLine->line_total);

        // Kişisel veri inbox'a girmez: ad, e-posta, adres, telefon, TCKN, müşteri kimliği.
        $stored = $this->asTenant($tenant, fn (): string => json_encode(InboxMessage::query()->pluck('payload')->all(), JSON_UNESCAPED_UNICODE));
        foreach (['customerName', 'customerEmail', 'shipmentAddress', 'billingAddress', 'Ayşe Yılmaz', 'ayse@ornek.com', '05321234567', '11111111111', 'Reşitpaşa', 'cust-guid-1'] as $personal) {
            $this->assertStringNotContainsString($personal, $stored);
        }

        // Aynı veri yeniden: hiçbir şey ikinci kez düşmez.
        $this->poll($tenant);
        $this->assertSame(7, $this->availableFor($tenant, $kupa));

        // Müşteri tek adedi iptal etti (6).
        $this->orders[0]['items'][1]['orderItemStatus'] = 6;
        $this->poll($tenant);
        $this->assertSame(8, $this->availableFor($tenant, $kupa));

        // Aynı iptal yeniden görülür: ikinci kez eklenmez.
        $this->poll($tenant);
        $this->assertSame(8, $this->availableFor($tenant, $kupa));

        // Satıcı kalan kupaların birini tedarik edemedi (13).
        $this->orders[0]['items'][2]['orderItemStatus'] = 13;
        $this->poll($tenant);
        $this->assertSame(9, $this->availableFor($tenant, $kupa));
        $this->assertSame(9, $this->availableFor($tenant, $tabak));

        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $kupa->id);
        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $tabak->id);
    }

    /** Bütün kalemler iptal: sipariş başlığı "Cancelled", stok tamamen geri. */
    #[Test]
    public function a_full_cancellation_restores_everything(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $this->seedStock($tenant, $kupa, 10);

        $this->orders = [$this->order([$this->item('i-1', 'BRK-KUPA', 'KUPA-01', 3), $this->item('i-2', 'BRK-KUPA', 'KUPA-01', 3)])];
        $this->poll($tenant);
        $this->assertSame(8, $this->availableFor($tenant, $kupa));

        $this->orders[0]['items'][0]['orderItemStatus'] = 6;
        $this->orders[0]['items'][1]['orderItemStatus'] = 6;
        $this->poll($tenant);

        $this->assertSame(10, $this->availableFor($tenant, $kupa));
        $this->assertSame('Cancelled', $this->asTenant($tenant, fn () => Order::query()->firstOrFail()->status));
    }

    /** İptal SÜRECİ (18) yalnız taleptir: stok değişmez. */
    #[Test]
    public function a_cancellation_request_does_not_touch_stock(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $this->seedStock($tenant, $kupa, 10);

        $this->orders = [$this->order([$this->item('i-1', 'BRK-KUPA', 'KUPA-01', 12)])];
        $this->poll($tenant);

        $this->orders[0]['items'][0]['orderItemStatus'] = 18;
        $this->poll($tenant);

        $this->assertSame(9, $this->availableFor($tenant, $kupa));
    }

    /** İlk kez iptal hâlinde görülen sipariş net sıfırdır: kayıt bile üretilmez. */
    #[Test]
    public function an_order_first_seen_cancelled_changes_nothing(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $this->seedStock($tenant, $kupa, 10);

        $this->orders = [$this->order([$this->item('i-1', 'BRK-KUPA', 'KUPA-01', 6), $this->item('i-2', 'BRK-KUPA', 'KUPA-01', 13)])];
        $this->poll($tenant);

        $this->assertSame(10, $this->availableFor($tenant, $kupa));
        $this->assertSame(0, $this->asTenant($tenant, fn (): int => InboxMessage::query()->count()));
    }

    /**
     * İlk kez görülen siparişin bir kalemi zaten iptal: o kalem "created"e
     * girer ve hemen geri eklenir; kalan adet bir kez düşer.
     */
    #[Test]
    public function a_partly_cancelled_order_first_seen_counts_only_the_live_units(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $this->seedStock($tenant, $kupa, 10);

        $this->orders = [$this->order([$this->item('i-1', 'BRK-KUPA', 'KUPA-01', 6), $this->item('i-2', 'BRK-KUPA', 'KUPA-01', 3)])];
        $this->poll($tenant);

        $this->assertSame(9, $this->availableFor($tenant, $kupa));
        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $kupa->id);
    }

    /**
     * İade: `8` (İade Onaylandı) tek adedi geri ekler, ikinci kez eklemez;
     * geçmişinde `8` olmayan `10` (İade Edildi) stoğa DOKUNMAZ; `7` de.
     */
    #[Test]
    public function an_approved_return_restocks_and_a_bare_refund_does_not(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $this->seedStock($tenant, $kupa, 10);

        $this->orders = [$this->order([
            $this->item('i-1', 'BRK-KUPA', 'KUPA-01', 11),
            $this->item('i-2', 'BRK-KUPA', 'KUPA-01', 11),
            $this->item('i-3', 'BRK-KUPA', 'KUPA-01', 11),
        ])];
        $this->poll($tenant);
        $this->assertSame(7, $this->availableFor($tenant, $kupa));

        $this->orders[0]['items'][0]['orderItemStatus'] = 7;
        $this->poll($tenant);
        $this->assertSame(7, $this->availableFor($tenant, $kupa));

        $this->orders[0]['items'][0]['orderItemStatus'] = 8;
        $this->orders[0]['items'][0]['orderItemStatusHistory'][] = ['historyStatus' => 8, 'historyStatusName' => 'İade Onaylandı', 'historyCreatedDate' => Carbon::now('Europe/Istanbul')->toIso8601String()];
        // Para iadesi: geçmişte 8 YOK.
        $this->orders[0]['items'][1]['orderItemStatus'] = 10;
        $this->poll($tenant);
        $this->assertSame(8, $this->availableFor($tenant, $kupa));

        // 8 → 10: aynı kalem, ikinci kez eklenmez.
        $this->orders[0]['items'][0]['orderItemStatus'] = 10;
        $this->poll($tenant);
        $this->assertSame(8, $this->availableFor($tenant, $kupa));

        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $kupa->id);
    }

    /** Hiç alınmamış siparişin iadesi stoğa dokunmaz, kayıt bile üretmez. */
    #[Test]
    public function a_return_of_an_order_never_taken_changes_nothing(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $this->seedStock($tenant, $kupa, 10);

        // İlk kez görülüyor ve iade onaylı: alınır + hemen iade = net sıfır.
        $this->orders = [$this->order([$this->item('i-1', 'BRK-KUPA', 'KUPA-01', 8)])];
        $this->poll($tenant);

        $this->assertSame(10, $this->availableFor($tenant, $kupa));
        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $kupa->id);
    }

    /**
     * Güncellenme filtresi yok: 20 gün önce verilmiş siparişin iptali,
     * imleç yeni olsa da geriye dönük pencereyle görülür.
     */
    #[Test]
    public function an_old_order_cancellation_is_seen_through_the_lookback_window(): void
    {
        [$tenant] = $this->setUpConnection(cursor: Carbon::now()->subMinutes(5)->toIso8601String());

        $kupa = $this->variant($tenant, 'KUPA-01');
        $this->seedStock($tenant, $kupa, 10);

        $this->orders = [$this->order([$this->item('i-1', 'BRK-KUPA', 'KUPA-01', 5)], placed: Carbon::now('Europe/Istanbul')->subDays(20))];
        $this->poll($tenant);
        $this->assertSame(9, $this->availableFor($tenant, $kupa));

        $this->orders[0]['items'][0]['orderItemStatus'] = 6;
        $this->poll($tenant);
        $this->assertSame(10, $this->availableFor($tenant, $kupa));
    }

    /** Bugün verilen sipariş görülür (`endDate` o günü kapsamadığı için yarın gönderilir). */
    #[Test]
    public function todays_order_is_not_lost_to_the_exclusive_end_date(): void
    {
        [$tenant] = $this->setUpConnection(cursor: Carbon::now()->subMinutes(5)->toIso8601String());

        $kupa = $this->variant($tenant, 'KUPA-01');
        $this->seedStock($tenant, $kupa, 10);

        $this->orders = [$this->order([$this->item('i-1', 'BRK-KUPA', 'KUPA-01', 3)], placed: Carbon::now('Europe/Istanbul'))];
        $this->poll($tenant);

        $this->assertSame(9, $this->availableFor($tenant, $kupa));
    }

    /** Onay: siparişin bütün kalemleri 12'ye — `orderNumber` SAYI. */
    #[Test]
    public function acknowledging_moves_the_order_to_preparing(): void
    {
        [$tenant, $connection] = $this->setUpConnection();

        $this->variant($tenant, 'KUPA-01');
        $this->orders = [$this->order([$this->item('i-1', 'BRK-KUPA', 'KUPA-01', 3)])];
        $this->poll($tenant);

        $order = $this->asTenant($tenant, fn () => Order::query()->firstOrFail());
        $adapter = $this->asSystem(fn () => (new AdapterRegistry)->for($connection->fresh('channelType')));

        $result = $this->asTenant($tenant, fn () => $adapter->acknowledgeOrder($order));

        $this->assertTrue($result->successful);
        Http::assertSent(static fn (Request $r): bool => $r->method() === 'PUT'
            && $r->url() === 'https://isortagimapi.pazarama.com/order/updateOrderStatusList'
            && $r->data() === ['orderNumber' => 283534307, 'status' => 12]);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function order(array $items, ?Carbon $placed = null): array
    {
        $placed ??= Carbon::now('Europe/Istanbul')->subHour();
        $address = [
            'addressId' => 'a-1', 'title' => 'evim', 'nameSurname' => 'Ayşe Yılmaz', 'customerEmail' => 'ayse@ornek.com',
            'cityName' => 'İstanbul', 'districtName' => 'Beşiktaş', 'neighborhoodName' => 'Reşitpaşa Mah',
            'addressDetail' => 'Reşitpaşa Mah. No:8', 'displayAddressText' => 'Reşitpaşa Mah. No:8 Beşiktaş/İstanbul',
            'phoneNumber' => '05321234567', 'postalCode' => '34000',
        ];

        return [
            'orderId' => '3099b7db-6a35-498d-a45a-4f6dd57a2ecb',
            'orderNumber' => (int) self::ORDER,
            'orderDate' => $placed->format('Y-m-d H:i'),
            'orderAmount' => 250.0 * count($items),
            'shipmentAmount' => 0,
            'discountAmount' => 0,
            'currency' => 'TL',
            'paymentType' => 1,
            'orderStatus' => 3,
            'customerId' => 'cust-guid-1',
            'customerName' => 'Ayşe Yılmaz',
            'customerEmail' => 'ayse@ornek.com',
            'shipmentAddress' => $address,
            'billingAddress' => [...$address, 'identityNumber' => '11111111111', 'invoiceType' => 1, 'taxNumber' => null],
            'items' => $items,
            'plusOrder' => false,
            'channelCode' => 2,
        ];
    }

    /** @return array<string, mixed> */
    private function item(string $id, string $barcode, string $stockCode, int $status): array
    {
        $money = static fn (float $v): array => ['currency' => 'TL', 'value' => $v, 'valueInt' => (int) ($v * 100), 'valueString' => number_format($v, 2, ',', '').' TL'];
        $placed = Carbon::now('Europe/Istanbul')->subHour()->toIso8601String();

        return [
            'orderItemId' => $id,
            'orderItemStatus' => $status,
            'orderItemStatusName' => 'x',
            'orderItemStatusHistory' => [['historyStatus' => 3, 'historyStatusName' => 'Siparişiniz Alındı', 'historyCreatedDate' => $placed]],
            'shipmentCode' => 'PZ00250056371',
            'quantity' => 1,
            'listPrice' => $money(300),
            'salePrice' => $money(250),
            'taxAmount' => $money(41.67),
            'totalPrice' => $money(250),
            'discountAmount' => $money(0),
            'taxIncluded' => true,
            'deliveryType' => 1,
            'deliveryDetail' => ['phoneNumber' => '05321234567'],
            'cargo' => ['companyId' => 'c-guid', 'companyName' => 'Aras Kargo', 'trackingNumber' => '655675687', 'trackingUrl' => 'www.araskargo.com'],
            'product' => [
                'productId' => 'p-'.$barcode, 'name' => 'Ürün '.$stockCode, 'title' => null, 'url' => 'x', 'imageURL' => 'https://img.pzrmcdn.com/x.png',
                'variantOptionDisplay' => 'Siyah', 'stockCode' => $stockCode, 'code' => $barcode, 'vatRate' => 20,
            ],
            'sellerAddressId' => 's-guid',
            'packageNumber' => 0,
        ];
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
    private function setUpConnection(?string $cursor = null): array
    {
        $tenant = (new CreateTenant)->run(name: 'Pazarama Dilim '.uniqid(), owner: User::factory()->create());

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'pazarama'],
            [
                'name' => 'Pazarama',
                'kind' => 'marketplace',
                'adapter_class' => PazaramaAdapter::class,
                'capabilities' => [
                    'catalog' => false, 'catalog_import' => true, 'inventory' => true, 'pricing' => true,
                    'orders' => true, 'taxonomy' => false, 'approval' => false, 'fulfillment' => false,
                ],
                'rate_limit_profile' => ['requests_per_second' => 1, 'burst_capacity' => 1, 'max_concurrent' => 1],
                'supports_webhooks' => false,
                'is_active' => true,
            ],
        ));

        $connection = $this->asTenant($tenant, function () use ($cursor): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'pazarama',
                'external_account_id' => 'magazam',
                'status' => 'active',
                'connected_at' => now()->subDays(30),
                'settings' => array_filter([
                    PazaramaAdapter::SELLER_NAME_KEY => 'magazam',
                    PollChannelOrders::CURSOR_KEY => $cursor,
                ]),
            ]);

            app(CredentialVault::class)->store($connection, ['client_id' => 'CID-1', 'client_secret' => 'SIR-1', 'access_token' => 'TOKEN-1']);

            return $connection;
        });

        return [$tenant, $connection];
    }
}
