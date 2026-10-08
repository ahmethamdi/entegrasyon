<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Ciceksepeti\CiceksepetiAdapter;
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
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * Çiçeksepeti siparişi stoğu düşürür; pasife düşen alt sipariş (iptal) ve
 * ürünü dönen iade geri ekler — zincir gerçek sınıflarla (Pazarama/N11
 * dilim testlerinin kardeşi).
 *
 * Sahte Çiçeksepeti tek bir `Http::fake` kapanışıdır ve durumu test
 * alanından okur (ikinci `Http::fake` çağrısında İLK kayıt kazanırdı):
 * `$rows` alt sipariş satırlarının o anki hâli, `$returns` iade listesi.
 * `GetOrders` `isOrderStatusActive`'e göre süzer (gerçek servis gibi: pasif
 * satır varsayılan listede görünmez), `orderNo` verilirse o siparişin aktif
 * satırlarını döner; sayfa 0'dan, 100'lük.
 */
final class CiceksepetiOrderSliceTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    private const ORDER = '123456789';

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /** @var list<array<string, mixed>> */
    private array $returns = [];

    /** Sahte servis `isOrderStatusActive` süzgecini yok sayar (savunma testi). */
    private bool $ignoreActiveFilter = false;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake(syncWithCarbon: true);

        Http::fake(function (Request $request) {
            $body = $request->data();
            $page = (int) ($body['page'] ?? 0);

            if (str_contains($request->url(), '/Order/getcanceledorders')) {
                return Http::response(['orderItemList' => array_slice($this->returns, $page * 100, 100)]);
            }

            $rows = array_values(array_filter($this->rows, function (array $row) use ($body): bool {
                if (isset($body['orderNo'])) {
                    return (string) $row['orderId'] === (string) $body['orderNo'] && $row['isOrderStatusActive'] === true;
                }

                return $this->ignoreActiveFilter || ($row['isOrderStatusActive'] ?? null) === ($body['isOrderStatusActive'] ?? true);
            }));

            return Http::response([
                'orderListCount' => count($rows),
                'supplierOrderListWithBranch' => array_slice($rows, $page * 100, 100),
            ]);
        });
    }

    /** İki alt sipariş: ikisinin de stoğu bir kez düşer; kişisel veri inbox'a girmez. */
    #[Test]
    public function a_new_order_takes_stock_once_and_stores_no_personal_data(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $tabak = $this->variant($tenant, 'TABAK-01');
        $this->seedStock($tenant, $kupa, 10);
        $this->seedStock($tenant, $tabak, 10);

        $this->rows = [$this->row('901', 'KUPA-01', 2), $this->row('902', 'TABAK-01', 1)];
        $this->poll($tenant);

        $this->assertSame(8, $this->availableFor($tenant, $kupa));
        $this->assertSame(9, $this->availableFor($tenant, $tabak));

        $order = $this->asTenant($tenant, fn () => Order::query()->with('lines')->where('external_id', self::ORDER)->firstOrFail());
        $this->assertCount(2, $order->lines);
        $line = $order->lines->firstWhere('external_line_id', '901');
        $this->assertSame(2, (int) $line->quantity);
        $this->assertSame('100.00', (string) $line->line_total);
        $this->assertSame('50.00', (string) $line->unit_price);

        $stored = $this->asTenant($tenant, fn (): string => json_encode(InboxMessage::query()->pluck('payload')->all(), JSON_UNESCAPED_UNICODE));
        foreach (['Ayşe Alıcı', '05551234567', 'Reşitpaşa', 'Mehmet Gönderen', '1234567890', 'fatura+abc@ciceksepeti.com', 'İyi ki doğdun', 'qr-video', 'Kolyeye Yazılacak', 'cust-7'] as $personal) {
            $this->assertStringNotContainsString($personal, $stored);
        }

        // Aynı veri yeniden: hiçbir şey ikinci kez düşmez.
        $this->poll($tenant);
        $this->assertSame(8, $this->availableFor($tenant, $kupa));

        // Değişim onayı satırı "Yeni"ye döndürür (tuzak 8): stok yine düşmez.
        $this->rows[0]['orderItemStatusId'] = 1;
        $this->rows[0]['orderModifyTime'] = '23:59';
        $this->poll($tenant);
        $this->assertSame(8, $this->availableFor($tenant, $kupa));

        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $kupa->id);
    }

    /**
     * İptal YALNIZ pasif listede görünür (tuzak 7): alt sipariş pasife düşünce
     * kendi adedi geri eklenir, ikinci kez eklenmez; hepsi pasifse başlık
     * "Cancelled".
     */
    #[Test]
    public function a_passive_sub_order_restores_its_quantity_once(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $tabak = $this->variant($tenant, 'TABAK-01');
        $this->seedStock($tenant, $kupa, 10);
        $this->seedStock($tenant, $tabak, 10);

        $this->rows = [$this->row('901', 'KUPA-01', 3), $this->row('902', 'TABAK-01', 1)];
        $this->poll($tenant);
        $this->assertSame(7, $this->availableFor($tenant, $kupa));

        $this->rows[0]['isOrderStatusActive'] = false;
        $this->poll($tenant);
        $this->assertSame(10, $this->availableFor($tenant, $kupa));
        $this->assertSame(9, $this->availableFor($tenant, $tabak));
        $this->assertNotSame('Cancelled', $this->asTenant($tenant, fn () => Order::query()->firstOrFail()->status));

        $this->poll($tenant);
        $this->assertSame(10, $this->availableFor($tenant, $kupa));

        $this->rows[1]['isOrderStatusActive'] = false;
        $this->poll($tenant);
        $this->assertSame(10, $this->availableFor($tenant, $tabak));
        $this->assertSame('Cancelled', $this->asTenant($tenant, fn () => Order::query()->firstOrFail()->status));

        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $kupa->id);
        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $tabak->id);
    }

    /** İlk kez görülen sipariş tamamen pasif: hiçbir şey olmaz, kayıt bile üretilmez. */
    #[Test]
    public function an_order_first_seen_passive_changes_nothing(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $this->seedStock($tenant, $kupa, 10);

        $this->rows = [[...$this->row('901', 'KUPA-01', 2), 'isOrderStatusActive' => false]];
        $this->poll($tenant);

        $this->assertSame(10, $this->availableFor($tenant, $kupa));
        $this->assertSame(0, $this->asTenant($tenant, fn (): int => InboxMessage::query()->count()));
    }

    /** İlk görüldüğünde bir alt siparişi zaten pasif: yalnız canlı olan düşer, pasif olan geri EKLENMEZ. */
    #[Test]
    public function a_partly_passive_order_first_seen_counts_only_the_live_sub_orders(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $tabak = $this->variant($tenant, 'TABAK-01');
        $this->seedStock($tenant, $kupa, 10);
        $this->seedStock($tenant, $tabak, 10);

        $this->rows = [[...$this->row('901', 'KUPA-01', 2), 'isOrderStatusActive' => false], $this->row('902', 'TABAK-01', 1)];
        $this->poll($tenant);
        $this->poll($tenant);

        $this->assertSame(10, $this->availableFor($tenant, $kupa));
        $this->assertSame(9, $this->availableFor($tenant, $tabak));
        // Çekirdek yutsa da adapter "created"e girmemiş satıra iptal kaydı ÜRETMEZ.
        $this->assertSame(0, $this->asTenant($tenant, fn (): int => InboxMessage::query()->where('external_event_id', 'like', '%:cancel:%')->count()));
        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $kupa->id);
    }

    /**
     * Servis pasif süzgecini yok sayıp AKTİF satır döndürse bile iptal
     * sayılmaz; alanı hiç taşımayan satır da. İptal yalnız açıkça
     * `isOrderStatusActive: false` taşıyan satır.
     */
    #[Test]
    public function an_active_row_in_the_passive_list_is_not_a_cancellation(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $this->seedStock($tenant, $kupa, 10);

        $this->rows = [$this->row('901', 'KUPA-01', 2)];
        $this->poll($tenant);

        // Süzgeç yok sayılıyor: aktif satır pasif listede de gelir…
        $this->ignoreActiveFilter = true;
        $this->poll($tenant);
        $this->assertSame(8, $this->availableFor($tenant, $kupa));

        // …ve alan hiç gelmezse de iptal sayılmaz (yalnız AÇIK false).
        unset($this->rows[0]['isOrderStatusActive']);
        $this->poll($tenant);

        $this->assertSame(8, $this->availableFor($tenant, $kupa));
        $this->assertSame(0, $this->asTenant($tenant, fn (): int => InboxMessage::query()->where('external_event_id', 'like', '%:cancel:%')->count()));
    }

    /**
     * İade: satıcı onayı ("Bayi Onay") ürünü geri ekler, bir kez. Bayi
     * reddi, değişim ve "müşteriye geri gönderilecek" stoğa dokunmaz.
     */
    #[Test]
    public function only_a_return_whose_product_came_back_restocks(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $tabak = $this->variant($tenant, 'TABAK-01');
        $vazo = $this->variant($tenant, 'VAZO-01');
        $this->seedStock($tenant, $kupa, 10);
        $this->seedStock($tenant, $tabak, 10);
        $this->seedStock($tenant, $vazo, 10);

        $this->rows = [$this->row('901', 'KUPA-01', 2, status: 7), $this->row('902', 'TABAK-01', 1, status: 7), $this->row('903', 'VAZO-01', 1, status: 7)];
        $this->poll($tenant);
        $this->assertSame(8, $this->availableFor($tenant, $kupa));

        $this->returns = [
            $this->returnRow('901', 'İade Tedarikçide', 'Bayi Red'),
            $this->returnRow('902', 'İade Tedarikçide', 'Bayi Onay', reasonId: 2),
            [...$this->returnRow('903', 'İade Tedarikçide', 'Bayi Onay'), 'cancelType' => 3],
        ];
        $this->poll($tenant);
        $this->assertSame([8, 9, 9], [$this->availableFor($tenant, $kupa), $this->availableFor($tenant, $tabak), $this->availableFor($tenant, $vazo)]);

        $this->returns = [$this->returnRow('901', 'İade Tedarikçide', 'Bayi Onay')];
        $this->poll($tenant);
        $this->assertSame(10, $this->availableFor($tenant, $kupa));

        $this->poll($tenant);
        $this->assertSame(10, $this->availableFor($tenant, $kupa));

        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $kupa->id);
    }

    /** "Müşteri Haklı" yalnız ürün tedarikçideyse stoğa ekler; yoldayken eklemez. */
    #[Test]
    public function a_customer_right_decision_restocks_only_when_the_product_is_back(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $this->seedStock($tenant, $kupa, 10);

        $this->rows = [$this->row('901', 'KUPA-01', 1, status: 7)];
        $this->poll($tenant);

        $this->returns = [$this->returnRow('901', 'İade Kargoda', 'Müşteri Haklı')];
        $this->poll($tenant);
        $this->assertSame(9, $this->availableFor($tenant, $kupa));

        $this->returns = [$this->returnRow('901', 'İade Tedarikçide', 'Müşteri Haklı')];
        $this->poll($tenant);
        $this->assertSame(10, $this->availableFor($tenant, $kupa));
    }

    /** Hiç alınmamış siparişin iadesi stoğa dokunmaz, kayıt bile üretmez. */
    #[Test]
    public function a_return_of_an_order_never_taken_changes_nothing(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $this->seedStock($tenant, $kupa, 10);

        $this->returns = [$this->returnRow('901', 'İade Tedarikçide', 'Bayi Onay')];
        $this->poll($tenant);

        $this->assertSame(10, $this->availableFor($tenant, $kupa));
        $this->assertSame(0, $this->asTenant($tenant, fn (): int => InboxMessage::query()->count()));
    }

    /**
     * Sayfa sınırı: siparişin ilk satırı dolu sayfanın SONUNDA, ikincisi
     * sonraki sayfada. Sipariş `orderNo` ile yeniden okunur — ikinci satırın
     * stoğu da düşer (aksi hâlde sipariş "alınmış" sayılır ve satır kaybolur).
     */
    #[Test]
    public function an_order_split_across_pages_is_read_whole(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $tabak = $this->variant($tenant, 'TABAK-01');
        $dolgu = $this->variant($tenant, 'DOLGU-01');
        $this->seedStock($tenant, $kupa, 10);
        $this->seedStock($tenant, $tabak, 10);
        $this->seedStock($tenant, $dolgu, 200);

        $filler = [];

        foreach (range(1, 99) as $i) {
            $filler[] = [...$this->row((string) (5000 + $i), 'DOLGU-01', 1), 'orderId' => 700000 + $i];
        }

        $this->rows = [...$filler, $this->row('901', 'KUPA-01', 2), $this->row('902', 'TABAK-01', 3)];
        $this->poll($tenant);

        $this->assertSame(8, $this->availableFor($tenant, $kupa));
        $this->assertSame(7, $this->availableFor($tenant, $tabak));
        $this->assertSame(101, $this->availableFor($tenant, $dolgu));
        Http::assertSent(static fn (Request $r): bool => ($r->data()['orderNo'] ?? null) === (int) self::ORDER);
    }

    /** Onay ucu yok: `acknowledgeOrder` istek ATMAZ. */
    #[Test]
    public function acknowledging_sends_nothing(): void
    {
        [$tenant, $connection] = $this->setUpConnection();

        $this->variant($tenant, 'KUPA-01');
        $this->rows = [$this->row('901', 'KUPA-01', 1)];
        $this->poll($tenant);

        $sent = count(Http::recorded());
        $order = $this->asTenant($tenant, fn () => Order::query()->firstOrFail());
        $adapter = $this->asSystem(fn () => (new AdapterRegistry)->for($connection->fresh('channelType')));

        $result = $this->asTenant($tenant, fn () => $adapter->acknowledgeOrder($order));

        $this->assertTrue($result->successful);
        $this->assertFalse($result->data['acknowledged']);
        $this->assertCount($sent, Http::recorded());
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @return array<string, mixed> */
    private function row(string $itemId, string $code, int $quantity, int $status = 1): array
    {
        $placed = Carbon::now('Europe/Istanbul')->subHour();

        return [
            'branchId' => 150000123456, 'customerId' => 'cust-7', 'accountCode' => 'TCD1111', 'accountCodePrefix' => 'TCD',
            'orderId' => (int) self::ORDER, 'orderItemId' => (int) $itemId,
            'orderCreateDate' => $placed->format('d/m/Y'), 'orderCreateTime' => $placed->format('H:i'),
            'orderModifyDate' => $placed->format('d/m/Y'), 'orderModifyTime' => $placed->format('H:i'),
            'barcode' => 'BRK-'.$code, 'cardMessage' => 'İyi ki doğdun', 'qrCodeMessage' => 'https://www.ciceksepeti.com/qr-video?k=1',
            'deliveryCharge' => 0, 'orderPaymentType' => 'Kredi Kartı 3D İle Ödeme',
            'orderItemStatusId' => $status, 'orderProductStatus' => 'Yeni',
            'orderItemTextListModel' => [['text' => 'Ali', 'value' => 'Kolyeye Yazılacak İsim 1']],
            'discount' => 0, 'totalPrice' => 50.0 * $quantity, 'tax' => 20,
            'receiverName' => 'Ayşe Alıcı', 'receiverPhone' => '05551234567', 'receiverAddress' => 'Reşitpaşa Mah. No:8',
            'deliveryType' => '2', 'deliveryDate' => null, 'requestedDeliveryDate' => null, 'cargoCompany' => 'Yurtiçi Kargo',
            'receiverCity' => 'İSTANBUL', 'receiverRegion' => 'Reşitpaşa', 'receiverDistrict' => 'Sarıyer',
            'senderName' => 'Mehmet Gönderen', 'senderAddress' => 'Gönderen Sok. 1', 'senderTaxNumber' => '1234567890', 'senderTaxOfficeName' => 'Kadıköy',
            'senderCity' => 'İstanbul', 'senderRegion' => 'Kadıköy', 'cargoNumber' => null, 'shipmentTrackingUrl' => null,
            'productId' => 34343434, 'productCode' => 'kc565656', 'code' => $code, 'name' => 'Ürün '.$code,
            'quantity' => $quantity, 'quantityUnit' => 'Ad', 'invoiceEmail' => 'fatura+abc@ciceksepeti.com',
            'isOrderStatusActive' => true, 'partialNumber' => null, 'senderCompanyName' => null, 'allowanceRate' => 0.85,
            'credit' => 0, 'branchDiscountPart' => 0, 'csDiscountPart' => 0, 'invoicePrice' => 50.0 * $quantity,
            'itemPrice' => 50.0, 'isInvoiceSent' => false, 'cargoModelType' => 1, 'cancellationResult' => null,
            'orderItemStatusHistoryList' => [['orderItemId' => (int) $itemId, 'partialNumber' => null, 'orderItemStatusId' => 1, 'transactionTime' => $placed->format('Y-m-d\TH:i:s'), 'createdOn' => $placed->format('Y-m-d\TH:i:s')]],
        ];
    }

    /** @return array<string, mixed> */
    private function returnRow(string $itemId, string $status, string $decision, int $reasonId = 1): array
    {
        return [
            'orderId' => (int) self::ORDER, 'orderItemId' => (int) $itemId, 'customerName' => 'Ayşe Alıcı', 'price' => 50,
            'orderItemStatus' => $status, 'cancelReason' => $reasonId === 2 ? 'Değişim Sebepleri' : 'İade Sebepleri',
            'subCancelReason' => 'Diğer', 'cancelReasonId' => $reasonId, 'subCancelReasonId' => 6,
            'orderItemCancelStatus' => $decision, 'cargoCompany' => 'Yurtiçi Kargo', 'shipmentTrackingUrl' => null,
            'shipmentNumber' => null, 'partialNumber' => '123456789-40', 'variantName' => 'Standart Boy',
            'supplierProductVariantCode' => null, 'productName' => 'test ürün', 'productCode' => 'kc1223',
            'supplierProductCode' => null, 'texts' => [['name' => 'Kolyeye Yazılacak İsim', 'textValue' => 'Ali']],
            'cancelType' => 2,
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
    private function setUpConnection(): array
    {
        $tenant = (new CreateTenant)->run(name: 'Çiçeksepeti Dilim '.uniqid(), owner: User::factory()->create());

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'ciceksepeti'],
            [
                'name' => 'Çiçeksepeti',
                'kind' => 'marketplace',
                'adapter_class' => CiceksepetiAdapter::class,
                'capabilities' => [
                    'catalog' => false, 'catalog_import' => true, 'inventory' => true, 'pricing' => true,
                    'orders' => true, 'taxonomy' => false, 'approval' => false, 'fulfillment' => false,
                ],
                'rate_limit_profile' => ['requests_per_second' => 1, 'burst_capacity' => 1, 'max_concurrent' => 1],
                'supports_webhooks' => false,
                'is_active' => true,
            ],
        ));

        $connection = $this->asTenant($tenant, function (): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'ciceksepeti',
                'external_account_id' => '998877',
                'status' => 'active',
                'connected_at' => now()->subDays(30),
                'settings' => [CiceksepetiAdapter::SELLER_ID_KEY => '998877'],
            ]);

            app(CredentialVault::class)->store($connection, [CiceksepetiAdapter::API_KEY_SECRET => 'CS-ANAHTAR-123']);

            return $connection;
        });

        return [$tenant, $connection];
    }
}
