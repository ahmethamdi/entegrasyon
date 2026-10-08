<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\N11\N11Adapter;
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
 * N11 siparişi stoğu düşürür, iptal ve onaylanan iade geri ekler — zincir
 * gerçek sınıflarla (TicimaxOrderSliceTest'in kardeşi).
 *
 * Sahte N11 tek bir `Http::fake` kapanışıdır ve durumu test alanlarından
 * okur (ikinci `Http::fake` çağrısında İLK kayıt kazanırdı):
 *   - `$packages`: siparişin o anki bütün paketleri; statü sorgusu kendi
 *     statüsündekileri, `orderNumber` sorgusu hepsini döndürür;
 *   - `$claims`: SOAP `ClaimReturnList` (APPROVED) satırları.
 */
final class N11OrderSliceTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    private const ORDER = '203872347637';

    /** @var list<array<string, mixed>> */
    private array $packages = [];

    /** @var list<array<string, string>> */
    private array $claims = [];

    private int $lookups = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/ws/returnService/')) {
                return Http::response($this->claimResponse());
            }

            if (str_contains($request->url(), '/rest/order/v1/update')) {
                return Http::response(['content' => array_map(
                    static fn (array $line): array => ['lineId' => $line['lineId'], 'status' => 'SUCCESS', 'reasons' => 'Başarıyla tamamlandı.'],
                    $request->data()['lines'] ?? [],
                )]);
            }

            $query = $request->data();

            if (isset($query['orderNumber'])) {
                $this->lookups++;

                return Http::response(['content' => $this->packages, 'totalPages' => 1]);
            }

            $content = array_values(array_filter(
                $this->packages,
                static fn (array $p): bool => strcasecmp(trim((string) $p['shipmentPackageStatus']), (string) ($query['status'] ?? '')) === 0,
            ));

            return Http::response(['content' => $content, 'totalPages' => $content === [] ? 0 : 1]);
        });
    }

    #[Test]
    public function an_order_reduces_stock_and_its_cancellation_returns_it(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $tabak = $this->variant($tenant, 'TABAK-01');
        $this->seedStock($tenant, $kupa, 10);
        $this->seedStock($tenant, $tabak, 10);

        $this->packages = [$this->package('Created', [$this->line(415490391, 'KUPA-01', 111, 3), $this->line(415490392, 'TABAK-01', 222, 2)])];

        $this->poll($tenant);

        $this->assertSame(7, $this->availableFor($tenant, $kupa));
        $this->assertSame(8, $this->availableFor($tenant, $tabak));

        $order = $this->asTenant($tenant, fn () => Order::query()->where('external_id', self::ORDER)->firstOrFail());
        $this->assertSame(self::ORDER, $order->external_number);

        // Kişisel veri inbox'a girmez: adres, gsm, TCKN, e-posta, ad, özel metin.
        $stored = $this->asTenant($tenant, fn (): string => json_encode(InboxMessage::query()->pluck('payload')->all(), JSON_UNESCAPED_UNICODE));
        foreach (['billingAddress', 'shippingAddress', 'customerEmail', 'customerfullName', 'tcIdentityNumber', '5321234567', '11111111111', 'Ayşe Yılmaz', 'customTextOptionValues'] as $personal) {
            $this->assertStringNotContainsString($personal, $stored);
        }

        // Aynı veri yeniden: hiçbir şey ikinci kez düşmez.
        $this->poll($tenant);
        $this->assertSame(7, $this->availableFor($tenant, $kupa));
        $this->assertSame(8, $this->availableFor($tenant, $tabak));

        // Müşteri iptali — paket `Cancelled`.
        $this->packages[0]['shipmentPackageStatus'] = 'Cancelled';
        $this->poll($tenant);

        $this->assertSame(10, $this->availableFor($tenant, $kupa));
        $this->assertSame(10, $this->availableFor($tenant, $tabak));
        $this->assertSame('Cancelled', $this->asTenant($tenant, fn () => Order::query()->firstOrFail()->status));

        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $kupa->id);
        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $tabak->id);
    }

    /** İlk kez iptal hâlinde görülen sipariş net sıfırdır: ne düşülür ne eklenir, kayıt bile üretilmez. */
    #[Test]
    public function an_order_first_seen_cancelled_changes_nothing(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $this->seedStock($tenant, $kupa, 10);

        $this->packages = [$this->package('Cancelled', [$this->line(415490391, 'KUPA-01', 111, 3)])];
        $this->poll($tenant);

        $this->assertSame(10, $this->availableFor($tenant, $kupa));
        $this->assertSame(0, $this->asTenant($tenant, fn (): int => InboxMessage::query()->count()));
        $this->assertSame(0, $this->lookups);
    }

    /** Onaylanan iade (SOAP, APPROVED) yalnız iade edilen adedi geri ekler; ikinci kez eklemez. */
    #[Test]
    public function an_approved_return_restocks_the_returned_quantity(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $this->seedStock($tenant, $kupa, 10);

        // İade REST'te `Delivered` görünür (tuzak 5).
        $this->packages = [$this->package('Delivered', [$this->line(415490391, 'KUPA-01', 111, 3)])];
        $this->poll($tenant);
        $this->assertSame(7, $this->availableFor($tenant, $kupa));

        $this->claims = [
            $this->claim('25391658', self::ORDER, '111', 1),
            // Hiç alınmamış siparişin iadesi stoğa dokunmaz.
            $this->claim('25391659', '999999999999', '111', 2),
        ];

        $this->poll($tenant);
        $this->assertSame(8, $this->availableFor($tenant, $kupa));

        // Alınmamış siparişin iadesi kayıt bile üretmez (yönlendiriciye bırakılmaz).
        $this->assertFalse($this->asTenant($tenant, fn (): bool => InboxMessage::query()->where('external_event_id', 'like', '999999999999:%')->exists()));

        $this->poll($tenant);
        $this->assertSame(8, $this->availableFor($tenant, $kupa));

        // İade kaydında da alıcı bilgisi yok.
        $stored = $this->asTenant($tenant, fn (): string => json_encode(InboxMessage::query()->pluck('payload')->all(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('buyer', $stored);

        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $kupa->id);
    }

    /**
     * Bölünmüş sipariş ilk kez görülüyor: bütün paketler okunur, `Unpacked`
     * ana paket yok sayılır, tedarik edilemeyen (`UnSupplied`) kısım net
     * sıfırdır — kalan kalemin stoğu bir kez düşer.
     */
    #[Test]
    public function a_split_order_first_seen_counts_each_item_once(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $tabak = $this->variant($tenant, 'TABAK-01');
        $this->seedStock($tenant, $kupa, 10);
        $this->seedStock($tenant, $tabak, 10);

        $this->packages = [
            $this->package('Unpacked', [$this->line(1, 'KUPA-01', 111, 3), $this->line(2, 'TABAK-01', 222, 2)], id: '9001'),
            $this->package('Picking', [$this->line(11, 'KUPA-01', 111, 3)], id: '9002'),
            $this->package('UnSupplied', [$this->line(12, 'TABAK-01', 222, 2)], id: '9003'),
        ];

        $this->poll($tenant);

        $this->assertSame(7, $this->availableFor($tenant, $kupa));
        $this->assertSame(10, $this->availableFor($tenant, $tabak));
        $this->assertSame(1, $this->lookups);

        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $tabak->id);
    }

    /** n11depom kalemi (`sender = N11`) stoğa dokunmaz; satıcının kalemi düşer. */
    #[Test]
    public function an_n11_warehouse_line_does_not_touch_stock(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $tabak = $this->variant($tenant, 'TABAK-01');
        $this->seedStock($tenant, $kupa, 10);
        $this->seedStock($tenant, $tabak, 10);

        $this->packages = [$this->package('Created', [
            $this->line(415490391, 'KUPA-01', 111, 3),
            [...$this->line(415490392, 'TABAK-01', 222, 2), 'sender' => 'N11'],
        ])];

        $this->poll($tenant);

        $this->assertSame(7, $this->availableFor($tenant, $kupa));
        $this->assertSame(10, $this->availableFor($tenant, $tabak));
    }

    /** Onay: kalemler `Picking`'e — kalem kimlikleri `orderLineId`. */
    #[Test]
    public function acknowledging_moves_the_lines_to_picking(): void
    {
        [$tenant, $connection] = $this->setUpConnection();

        $this->variant($tenant, 'KUPA-01');
        $this->packages = [$this->package('Created', [$this->line(415490391, 'KUPA-01', 111, 3)])];
        $this->poll($tenant);

        $order = $this->asTenant($tenant, fn () => Order::query()->firstOrFail());
        $adapter = $this->asSystem(fn () => (new AdapterRegistry)->for($connection->fresh('channelType')));

        $result = $this->asTenant($tenant, fn () => $adapter->acknowledgeOrder($order));

        $this->assertTrue($result->successful);
        Http::assertSent(static fn (Request $r): bool => $r->method() === 'PUT'
            && $r->url() === 'https://api.n11.com/rest/order/v1/update'
            && $r->data() === ['lines' => [['lineId' => 415490391]], 'status' => 'Picking']);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function package(string $status, array $lines, ?string $id = '112999455244259'): array
    {
        $placed = Carbon::now()->subHour()->getTimestampMs();

        return [
            'billingAddress' => ['address' => 'Reşitpaşa Mah. No:8', 'city' => 'İstanbul', 'fullName' => 'Ayşe Yılmaz', 'gsm' => '5321234567', 'tcId' => '11111111111'],
            'shippingAddress' => ['address' => 'Reşitpaşa Mah. No:8', 'city' => 'İstanbul', 'fullName' => 'Ayşe Yılmaz', 'gsm' => '5321234567', 'tcId' => '11111111111'],
            'orderNumber' => self::ORDER,
            'id' => $id,
            'customerEmail' => 'ayse@ornek.com',
            'customerfullName' => 'Ayşe Yılmaz',
            'customerId' => 12345678,
            'taxId' => null,
            'taxOffice' => null,
            'tcIdentityNumber' => '11111111111',
            'cargoTrackingNumber' => '112999455244259',
            'cargoProviderName' => 'MNG Kargo',
            'lines' => $lines,
            'lastModifiedDate' => Carbon::now()->subMinutes(10)->getTimestampMs(),
            'totalAmount' => 1479.79,
            // Statülerin başında boşluk olabilir (resmi örnek).
            'packageHistories' => [['createdDate' => $placed, 'status' => 'Created'], ['createdDate' => $placed + 60_000, 'status' => ' Picking']],
            'shipmentPackageStatus' => $status,
            'sellerId' => 9876543,
        ];
    }

    /** @return array<string, mixed> */
    private function line(int $lineId, string $stockCode, int $productId, int $quantity): array
    {
        return [
            'quantity' => $quantity, 'productId' => $productId, 'productName' => $stockCode, 'stockCode' => $stockCode,
            'customTextOptionValues' => [['name' => 'Baskı', 'value' => 'Ayşe Yılmaz']],
            'price' => 250, 'sellerInvoiceAmount' => 250 * $quantity, 'totalSellerDiscountPrice' => 0,
            'orderLineId' => $lineId, 'orderItemLineItemStatusName' => 'Created', 'vatRate' => 20, 'barcode' => null,
            'sender' => 'SELLER',
        ];
    }

    /** @return array<string, string> */
    private function claim(string $id, string $orderNumber, string $productId, int $quantity): array
    {
        return ['claimReturnId' => $id, 'orderNumber' => $orderNumber, 'productId' => $productId, 'quantity' => (string) $quantity];
    }

    private function claimResponse(): string
    {
        $rows = '';

        foreach ($this->claims as $c) {
            $rows .= '<claimReturn><approvedDate>08/10/2026</approvedDate><buyerEmail>****@n11.com</buyerEmail><buyerName>Ayşe Yılmaz</buyerName>'
                .'<buyerPhone>5321234567</buyerPhone><claimReturnId>'.$c['claimReturnId'].'</claimReturnId><orderNumber>'.$c['orderNumber'].'</orderNumber>'
                .'<productId>'.$c['productId'].'</productId><quantity>'.$c['quantity'].'</quantity><sender>SELLER</sender>'
                .'<status>APPROVED</status><unitPrice>250</unitPrice></claimReturn>';
        }

        return '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"><SOAP-ENV:Header/><SOAP-ENV:Body>'
            .'<ns3:ClaimReturnListResponse xmlns:ns3="http://www.n11.com/ws/schemas"><result><status>success</status></result>'
            .'<claimReturnList>'.$rows.'</claimReturnList>'
            .'<pagingData><currentPage>0</currentPage><pageSize>20</pageSize><totalCount>'.count($this->claims).'</totalCount><pageCount>1</pageCount></pagingData>'
            .'</ns3:ClaimReturnListResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>';
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
        $tenant = (new CreateTenant)->run(name: 'N11 Dilim '.uniqid(), owner: User::factory()->create());

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'n11'],
            [
                'name' => 'N11',
                'kind' => 'marketplace',
                'adapter_class' => N11Adapter::class,
                'capabilities' => [
                    'catalog' => false, 'catalog_import' => true, 'inventory' => true, 'pricing' => true,
                    'orders' => true, 'taxonomy' => false, 'approval' => false, 'fulfillment' => false,
                ],
                'rate_limit_profile' => ['requests_per_second' => 5, 'burst_capacity' => 10],
                'supports_webhooks' => false,
                'is_active' => true,
            ],
        ));

        $connection = $this->asTenant($tenant, function (): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'n11',
                'external_account_id' => 'testMagaza',
                'status' => 'active',
                'connected_at' => now()->subDays(7),
                'settings' => [N11Adapter::SELLER_NAME_KEY => 'testMagaza'],
            ]);

            app(CredentialVault::class)->store($connection, ['app_key' => 'ANAHTAR-1', 'app_secret' => 'SIR-1']);

            return $connection;
        });

        return [$tenant, $connection];
    }
}
