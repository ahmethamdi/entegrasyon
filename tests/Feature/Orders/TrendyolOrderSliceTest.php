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
                'orderNumber' => 'TY-1',
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
            ->where('external_id', 'TY-1')
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
                'orderNumber' => 'TY-2',
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
            ->where('external_id', 'TY-2')
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
                'orderNumber' => 'TY-1', 'status' => 'Created', 'lines' => [$line],
            ]], 'totalPages' => 1], 200)
            ->push(['content' => [[
                'orderNumber' => 'TY-1', 'status' => 'Cancelled', 'lines' => [$line],
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
                'orderNumber' => 'TY-1', 'status' => 'Created', 'lines' => [$line],
            ]], 'totalPages' => 1], 200)
            ->push(['content' => [[
                'orderNumber' => 'TY-1', 'status' => 'Shipped', 'lines' => [$line],
            ]], 'totalPages' => 1], 200),
        ]);

        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        app(PollChannelOrders::class)->run();
        $this->processInbox($tenant);

        // HAM SATIR okunur.
        $status = $this->asTenant($tenant, fn () => DB::table('orders')
            ->where('tenant_id', $tenant->id)
            ->where('external_id', 'TY-1')
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
                'orderNumber' => 'TY-1',
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
