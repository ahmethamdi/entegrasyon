<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
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
use App\Domain\Orders\Enums\StockStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderLine;
use App\Domain\Orders\Routing\OrderEventRouter;
use App\Domain\Orders\Support\PollChannelOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * FAZ 2 DEMOSU — "Trendyol siparişi stoğu düşürüyor".
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 2 ("Sipariş yoklaması"),
 * §6 · Inbox, §1 · Karar 24.
 *
 * NEDEN AYRI BİR TEST: `TrendyolOrderPollingTest` adapter'ı doğrudan
 * çağırır, `PollChannelOrdersTest` turu sınar ama işi `Queue::fake()` ile
 * yakalar. İkisi de yeşilken **sipariş hiç stoğa dokunmamış olabilir** —
 * bu projede tam bu biçimde iki ölümcül hata bulundu. Burada zincir
 * gerçek sınıflarla, kuyruk sahtesi OLMADAN yürütülür:
 *
 *   yoklama → `IngestInboxMessage` → `ProcessInboxMessage` →
 *   `OrderEventRouter` → `IngestChannelOrder` → `ApplyMovement` → ledger
 *
 * DEĞİŞMEZ KURAL — EŞLEŞMEMİŞ SKU SİPARİŞİ KAYBETTİRMEZ (Karar 24):
 *   `order_lines.variant_id` NULL kalabilir, satır PENDING olur ve stok
 *   düşülmez. Sipariş kaybetmek stok tutarsızlığından KÖTÜDÜR.
 */
final class TrendyolOrderSliceTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    /**
     * YOKLANAN SİPARİŞ STOĞU DÜŞÜRÜR — Faz 2 demosunun tam cümlesi.
     */
    #[Test]
    public function a_polled_trendyol_order_reduces_stock(): void
    {
        [$tenant, $connection] = $this->setUpConnection();

        $variant = $this->variant($tenant, sku: 'BARKOD-A');
        $this->seedStock($tenant, $variant, 10);

        Http::fake(['*' => Http::response([
            'content' => [[
                'shipmentPackageId' => 'PKG-1', 'orderNumber' => 'TY-1',
                'status' => 'Created',
                'grossAmount' => 240.0,
                'totalPrice' => 240.0,
                'currencyCode' => 'TRY',
                'lines' => [[
                    'id' => 9001,
                    'barcode' => 'BARKOD-A',
                    'productName' => 'Tişört',
                    'quantity' => 3,
                    'amount' => 240.0,
                ]],
            ]],
            'totalPages' => 1,
        ], 200)]);

        // Kuyruk SAHTE DEĞİL: iş gerçekten çalışsın.
        app(PollChannelOrders::class)->run();

        $this->processInbox($tenant);

        // SİPARİŞ YAZILDI.
        $order = $this->asTenant($tenant, fn (): ?Order => Order::query()
            ->where('external_id', 'PKG-1')
            ->first());

        $this->assertNotNull($order, 'Yoklanan sipariş kaydedilmeliydi.');

        // STOK DÜŞTÜ: 10 − 3 = 7.
        $available = $this->availableFor($tenant, $variant);

        $this->assertSame(7, $available, 'Trendyol siparişi stoğu düşürmeliydi.');

        $this->assertLedgerMatchesProjection(
            $tenant->id,
            $this->warehouse($tenant)->id,
            $variant->id,
        );
    }

    /**
     * EŞLEŞMEMİŞ BARKOD SİPARİŞİ KAYBETTİRMEZ — stoğa da DOKUNMAZ.
     *
     * Satır `PENDING` kalır ve satıcı eşleştirmeyi yapana kadar bakiye
     * olduğundan fazla görünür. Bu sessiz hâl panelde AYRI bir uyarıdır
     * (fazla satışla birleştirilmez).
     */
    #[Test]
    public function an_unmatched_barcode_still_records_the_order(): void
    {
        [$tenant] = $this->setUpConnection();

        $variant = $this->variant($tenant, sku: 'BARKOD-A');
        $this->seedStock($tenant, $variant, 10);

        Http::fake(['*' => Http::response([
            'content' => [[
                'shipmentPackageId' => 'PKG-2', 'orderNumber' => 'TY-2',
                'status' => 'Created',
                'lines' => [[
                    'id' => 9002,
                    // Katalogda OLMAYAN barkod.
                    'barcode' => 'HIC-YOK',
                    'quantity' => 2,
                    'amount' => 50.0,
                ]],
            ]],
            'totalPages' => 1,
        ], 200)]);

        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        $order = $this->asTenant($tenant, fn (): ?Order => Order::query()
            ->where('external_id', 'PKG-2')
            ->first());

        // SİPARİŞ KAYBEDİLMEDİ.
        $this->assertNotNull($order);

        $line = $this->asTenant($tenant, fn (): OrderLine => OrderLine::query()
            ->where('order_id', $order->id)
            ->firstOrFail());

        $this->assertNull($line->variant_id, 'Eşleşmeyen satır varyantsız kalmalı.');
        $this->assertSame(StockStatus::PENDING, $line->stock_status);

        // STOĞA HİÇ DOKUNULMADI.
        $this->assertSame(10, $this->availableFor($tenant, $variant));
    }

    /**
     * ⚠️ AYNI SİPARİŞİN İKİ PAKETİ İKİ AYRI SATIŞTIR (A11).
     *
     * Sipariş kimliği sipariş numarası olsaydı ikinci paketin `created`'ı
     * "bu sipariş zaten alınmış" diye atlanır ve stoğu HİÇ düşmezdi.
     * v2 gövdesi kalemleri paket başına taşır.
     */
    #[Test]
    public function two_packages_of_one_order_both_reduce_stock(): void
    {
        [$tenant] = $this->setUpConnection();

        $variant = $this->variant($tenant, sku: 'BARKOD-A');
        $this->seedStock($tenant, $variant, 10);

        Http::fake(['*' => Http::response([
            'content' => [
                [
                    'shipmentPackageId' => 3001, 'orderNumber' => 'TY-7',
                    'shipmentPackageStatus' => 'Created',
                    'lines' => [['lineId' => 1, 'barcode' => 'BARKOD-A', 'quantity' => 2, 'lineUnitPrice' => 10, 'lineGrossAmount' => 20]],
                ],
                [
                    'shipmentPackageId' => 3002, 'orderNumber' => 'TY-7',
                    'shipmentPackageStatus' => 'Created',
                    'lines' => [['lineId' => 2, 'barcode' => 'BARKOD-A', 'quantity' => 1, 'lineUnitPrice' => 10, 'lineGrossAmount' => 10]],
                ],
            ],
            'totalPages' => 1,
        ], 200)]);

        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        $this->assertSame(7, $this->availableFor($tenant, $variant), 'İkinci paketin stoğu da düşmeliydi.');

        $numbers = $this->asTenant($tenant, fn (): array => Order::query()
            ->orderBy('external_id')->pluck('external_number', 'external_id')->all());

        $this->assertSame(['3001' => 'TY-7', '3002' => 'TY-7'], $numbers);
    }

    /**
     * ⚠️ BÖLÜNEN PAKET STOĞU İKİ KEZ DÜŞÜRMEZ (A11).
     *
     * Bölmede eski paket `UnPacked` olur, kalemler yeni kimlikli
     * paketlerde `Created` olarak yeniden gelir. `UnPacked` güncelleme
     * sayılsaydı aynı 3 adet iki kez düşerdi (10 → 4).
     */
    #[Test]
    public function a_split_package_does_not_double_count(): void
    {
        [$tenant] = $this->setUpConnection();

        $variant = $this->variant($tenant, sku: 'BARKOD-A');
        $this->seedStock($tenant, $variant, 10);

        $line = fn (int $id, int $qty): array => ['lineId' => $id, 'barcode' => 'BARKOD-A', 'quantity' => $qty];

        Http::fake(['*' => Http::sequence()
            ->push(['content' => [
                ['shipmentPackageId' => 4001, 'orderNumber' => 'TY-8', 'shipmentPackageStatus' => 'Created', 'lines' => [$line(1, 3)]],
            ], 'totalPages' => 1], 200)
            ->push(['content' => [
                ['shipmentPackageId' => 4001, 'orderNumber' => 'TY-8', 'shipmentPackageStatus' => 'UnPacked', 'lines' => [$line(1, 3)]],
                ['shipmentPackageId' => 4002, 'orderNumber' => 'TY-8', 'shipmentPackageStatus' => 'Created', 'lines' => [$line(2, 2)]],
                ['shipmentPackageId' => 4003, 'orderNumber' => 'TY-8', 'shipmentPackageStatus' => 'Created', 'lines' => [$line(3, 1)]],
            ], 'totalPages' => 1], 200),
        ]);

        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);
        $this->assertSame(7, $this->availableFor($tenant, $variant));

        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        $this->assertSame(7, $this->availableFor($tenant, $variant), 'Bölme satışı ikinci kez düşürmemeli.');

        $this->assertLedgerMatchesProjection(
            $tenant->id,
            $this->warehouse($tenant)->id,
            $variant->id,
        );
    }

    /**
     * ⚠️ İLK KEZ "PICKING" GÖRÜLEN SİPARİŞ KAYBOLMAZ (A14).
     *
     * Yoklama arası kısa sürede satıcı paketi işleme alırsa sipariş bize
     * hiç "Created" olarak gelmez. Önceden olay log'a düşer, sipariş hiç
     * yaratılmaz ve stok DÜŞMEZDİ — fazla satış. Sonradan "Created" gelse
     * bile stok ikinci kez düşmez.
     */
    #[Test]
    public function an_order_first_seen_while_picking_still_reduces_stock(): void
    {
        [$tenant, $connection] = $this->setUpConnection();
        $this->connectedAt($tenant, $connection, '-1 day');

        $variant = $this->variant($tenant, sku: 'BARKOD-A');
        $this->seedStock($tenant, $variant, 10);

        $package = fn (string $status): array => [
            'shipmentPackageId' => 5001, 'orderNumber' => 'TY-50',
            'shipmentPackageStatus' => $status,
            'orderDate' => now()->subHour()->getTimestampMs(),
            'lines' => [['lineId' => 1, 'barcode' => 'BARKOD-A', 'quantity' => 3]],
        ];

        Http::fake(['*' => Http::sequence()
            ->push(['content' => [$package('Picking')], 'totalPages' => 1], 200)
            ->push(['content' => [$package('Created')], 'totalPages' => 1], 200),
        ]);

        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        $this->assertSame(7, $this->availableFor($tenant, $variant), 'Kaçırılan yaratma tamamlanmalı.');

        $status = $this->asTenant($tenant, fn () => Order::query()->where('external_id', '5001')->value('status'));
        $this->assertSame('Picking', $status);

        // Geç gelen "Created" stoğu İKİNCİ kez düşürmez.
        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        $this->assertSame(7, $this->availableFor($tenant, $variant));
        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $variant->id);
    }

    /**
     * ⚠️ BAĞLANTIDAN ÖNCE VERİLMİŞ SİPARİŞ YARATILMAZ (A14).
     *
     * O satış kanaldan içe aktarılan açılış stoğuna zaten yansımıştır;
     * yaratılsaydı aynı satış İKİNCİ kez düşerdi.
     */
    #[Test]
    public function an_order_placed_before_the_connection_is_not_adopted(): void
    {
        [$tenant, $connection] = $this->setUpConnection();
        $this->connectedAt($tenant, $connection, '-1 hour');

        $variant = $this->variant($tenant, sku: 'BARKOD-A');
        $this->seedStock($tenant, $variant, 10);

        Http::fake(['*' => Http::response(['content' => [[
            'shipmentPackageId' => 5002, 'orderNumber' => 'TY-51',
            'shipmentPackageStatus' => 'Shipped',
            'orderDate' => now()->subDays(3)->getTimestampMs(),
            'lines' => [['lineId' => 1, 'barcode' => 'BARKOD-A', 'quantity' => 3]],
        ]], 'totalPages' => 1], 200)]);

        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        $this->assertSame(10, $this->availableFor($tenant, $variant));
        $this->assertNull($this->asTenant($tenant, fn () => Order::query()->where('external_id', '5002')->first()));
    }

    private function connectedAt(Tenant $tenant, ChannelConnection $connection, string $when): void
    {
        $this->asTenant($tenant, fn () => $connection->forceFill(['connected_at' => now()->modify($when)])->save());
    }

    /**
     * İPTAL STOĞU GERİ EKLER — ve `created` sanılmaz.
     *
     * Bu, Karar 24'ün en pahalı hata biçiminin testidir: kimlik yalnızca
     * sipariş numarasına bağlansaydı iptal tekillik kısıtına takılıp
     * SESSİZCE YUTULUR, stok geri eklenmez ve bakiye kalıcı eksik kalırdı.
     */
    #[Test]
    public function a_polled_cancellation_returns_the_stock(): void
    {
        [$tenant] = $this->setUpConnection();

        $variant = $this->variant($tenant, sku: 'BARKOD-A');
        $this->seedStock($tenant, $variant, 10);

        $line = [
            'id' => 9001,
            'barcode' => 'BARKOD-A',
            'productName' => 'Tişört',
            'quantity' => 3,
            'amount' => 240.0,
        ];

        Http::fake(['*' => Http::sequence()
            ->push(['content' => [[
                'shipmentPackageId' => 'PKG-1', 'orderNumber' => 'TY-1', 'status' => 'Created', 'lines' => [$line],
            ]], 'totalPages' => 1], 200)
            ->push(['content' => [[
                'shipmentPackageId' => 'PKG-1', 'orderNumber' => 'TY-1', 'status' => 'Cancelled', 'lines' => [$line],
            ]], 'totalPages' => 1], 200),
        ]);

        // 1. tur: sipariş → stok 7.
        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        $this->assertSame(7, $this->availableFor($tenant, $variant));

        // 2. tur: iptal → stok GERİ.
        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        $this->assertSame(
            10,
            $this->availableFor($tenant, $variant),
            'İptal stoğu geri eklemeliydi — yutulmuş olabilir.',
        );

        $this->assertLedgerMatchesProjection(
            $tenant->id,
            $this->warehouse($tenant)->id,
            $variant->id,
        );
    }

    /**
     * DURUM DEĞİŞİMİ SİPARİŞİ GERÇEKTEN TAZELİYOR — §13 · Faz 3.
     *
     * KAPATILAN BOŞLUK: `OrderEventRouter` bugüne kadar `UPDATED`
     * olayını YALNIZCA LOG'LUYORDU. Faz 2'de yoklama yazıldıktan sonra
     * boşluk CANLI hale geldi: sipariş `Shipped`'a geçtiğinde olay
     * inbox'a yazılıyor, işleniyor ve sessizce düşüyordu — panel
     * siparişi sonsuza kadar "Created" gösterirdi.
     *
     * Bu test yönlendirmenin GERÇEKTEN bağlandığını doğrular: eylem
     * sınıflarının kendi testleri yeşilken router onları hiç
     * çağırmıyor olabilirdi.
     */
    #[Test]
    public function a_polled_status_change_refreshes_the_order(): void
    {
        [$tenant] = $this->setUpConnection();

        $variant = $this->variant($tenant, sku: 'BARKOD-A');
        $this->seedStock($tenant, $variant, 10);

        $line = [
            'id' => 9001, 'barcode' => 'BARKOD-A', 'quantity' => 3, 'amount' => 240.0,
        ];

        Http::fake(['*' => Http::sequence()
            ->push(['content' => [[
                'shipmentPackageId' => 'PKG-1', 'orderNumber' => 'TY-1', 'status' => 'Created', 'lines' => [$line],
            ]], 'totalPages' => 1], 200)
            ->push(['content' => [[
                'shipmentPackageId' => 'PKG-1', 'orderNumber' => 'TY-1', 'status' => 'Shipped', 'lines' => [$line],
            ]], 'totalPages' => 1], 200),
        ]);

        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        // HAM SATIR okunur.
        $status = $this->asTenant($tenant, fn () => DB::table('orders')
            ->where('tenant_id', $tenant->id)
            ->where('external_id', 'PKG-1')
            ->value('status'));

        $this->assertSame('Shipped', $status, 'Durum değişimi siparişe YANSIMALI.');

        // KARGO AŞAMASI STOĞA DOKUNMAZ: mal satışta zaten düşüldü.
        $this->assertSame(7, $this->availableFor($tenant, $variant));

        $this->assertLedgerMatchesProjection(
            $tenant->id,
            $this->warehouse($tenant)->id,
            $variant->id,
        );
    }

    /**
     * AYNI SİPARİŞ İKİ TURDA STOĞU İKİ KEZ DÜŞÜRMEZ.
     *
     * Yoklama pencere örtüşmesi nedeniyle aynı siparişi tekrar görür.
     * Tekilleştirme çalışmasaydı her tur stoğu yeniden düşürür ve bakiye
     * hızla eksiye giderdi.
     */
    #[Test]
    public function polling_the_same_order_twice_does_not_double_count(): void
    {
        [$tenant] = $this->setUpConnection();

        $variant = $this->variant($tenant, sku: 'BARKOD-A');
        $this->seedStock($tenant, $variant, 10);

        Http::fake(['*' => Http::response([
            'content' => [[
                'shipmentPackageId' => 'PKG-1', 'orderNumber' => 'TY-1',
                'status' => 'Created',
                'lines' => [[
                    'id' => 9001, 'barcode' => 'BARKOD-A', 'quantity' => 3, 'amount' => 240.0,
                ]],
            ]],
            'totalPages' => 1,
        ], 200)]);

        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        $this->assertSame(7, $this->availableFor($tenant, $variant), 'Stok iki kez düşmemeli.');

        $orders = $this->asTenant($tenant, fn (): int => Order::query()->count());
        $this->assertSame(1, $orders, 'İkinci sipariş satırı açılmamalı.');
    }

    // ──────────────────────────────────────────────────────── yardımcı

    /**
     * Bekleyen inbox mesajlarını GERÇEKTEN işler.
     *
     * İş worker'daki gibi doğrudan çağrılır: `ProcessInboxMessage` kiracı
     * bağlamını KENDİ kurar, bu yüzden `asTenant()` ile sarmalanmaz.
     */
    private function processInbox(Tenant $tenant): void
    {
        $ids = $this->asTenant($tenant, fn (): array => InboxMessage::query()
            ->where('status', 'pending')
            ->orderBy('received_at')
            ->pluck('id')
            ->all());

        foreach ($ids as $id) {
            (new ProcessInboxMessage($tenant->id, $id))->handle(
                app(OrderEventRouter::class),
            );
        }
    }

    private function availableFor(Tenant $tenant, Variant $variant): int
    {
        // HAM SATIR okunur: Eloquent kimlik haritası bayat nesne verebilir.
        return (int) $this->asTenant($tenant, fn () => DB::table('inventory_levels')
            ->where('tenant_id', $tenant->id)
            ->where('variant_id', $variant->id)
            ->value('available'));
    }

    private function variant(Tenant $tenant, string $sku): Variant
    {
        return $this->asTenant($tenant, function () use ($sku): Variant {
            $product = Product::factory()->create();

            return Variant::factory()->create([
                'product_id' => $product->id,
                'sku' => $sku,
                'barcode' => $sku,
            ]);
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
    private function setUpConnection(string $supplierId = '123456'): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Dilim '.uniqid(), owner: $user);

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'trendyol'],
            [
                'name' => 'Trendyol',
                'kind' => 'marketplace',
                'adapter_class' => TrendyolAdapter::class,
                'capabilities' => [
                    'catalog' => true, 'inventory' => true, 'pricing' => true,
                    'orders' => true, 'taxonomy' => true, 'approval' => true,
                    'fulfillment' => false,
                ],
                'rate_limit_profile' => ['requests_per_second' => 5, 'burst_capacity' => 10],
                'supports_webhooks' => false,
                'is_active' => true,
            ],
        ));

        $connection = $this->asTenant($tenant, function () use ($supplierId): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'trendyol',
                'external_account_id' => $supplierId,
                'status' => 'active',
                'settings' => [
                    'base_url' => 'https://api.trendyol.com/sapigw',
                    'supplier_id' => $supplierId,
                ],
            ]);

            app(CredentialVault::class)->store($connection, [
                'api_key' => 'anahtar',
                'api_secret' => 'sifre',
            ]);

            return $connection;
        });

        return [$tenant, $connection];
    }
}
