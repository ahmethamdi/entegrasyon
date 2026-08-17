<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Domain\Catalog\Models\Product;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Jobs\PushListing;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\ListingPayloadBuilder;
use App\Domain\Sync\Support\SyncResultRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * DİKEY DİLİMİN SON HALKASI — panelden kanala, gerçek Woo yükleriyle.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · faz 1.5, §19 · dikey dilim.
 *
 * `WooCommerceVerticalSliceTest` zinciri listing satırı ELDE VARKEN başlatır;
 * bu test o satırın nereden geldiğini kapatır:
 *
 *   Panelden ürün yaratılır (açılış stoğu LEDGER üzerinden)
 *      ↓  POST /products
 *   Panelden kanala gönderilir
 *      ↓  POST /products/{id}/channels → PublishListing
 *         listing (draft) + CONTENT operasyonu + PushListing işi
 *   PushListing → WooCommerceAdapter::createListing
 *      ↓  GERÇEK Woo gövdesi: manage_stock ZORUNLU, sku taşınır
 *   Woo kimliği yazılır, listing CANLI olur, sync state ilerler
 *
 * Bu bittiğinde §19'daki zincir baştan sona PANELDEN sürülebilir:
 * ürün panelde doğar, kanala panelden gider, sipariş webhook'la geri gelir.
 *
 * SINIF VAR OLMASI ÇAĞRILDIĞI ANLAMINA GELMEZ: bu test rotayı, action'ı,
 * işi ve GERÇEK adapter'ı tek zincirde yürütür — sahte adapter kullanmaz.
 */
final class PanelToChannelSliceTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    /**
     * Panelden yaratılan ürün, panelden kanala gönderilir ve Woo'da doğar.
     */
    #[Test]
    public function product_created_in_panel_is_published_to_woocommerce(): void
    {
        Queue::fake();

        [$tenant, $user] = $this->makeContext();

        $connection = $this->wooConnection($tenant);

        // ── 1 · Ürün PANELDEN yaratılır; açılış stoğu ledger üzerinden girer.
        $this->actingAs($user)->post('/products', [
            'sku' => 'KAZAK-001',
            'title' => 'Yün Kazak',
            'price' => 249.90,
            'opening_stock' => 10,
            'description' => 'Saf yün.',
        ])->assertRedirect('/products');

        $product = $this->asTenant($tenant, fn () => Product::query()
            ->where('sku', 'KAZAK-001')->with('variants')->firstOrFail());

        // Açılış stoğu LEDGER üzerinden geldi — projeksiyon ondan türedi.
        $this->assertLedgerMatchesProjectionForTenant($tenant->id);

        // ── 2 · Panelden kanala GÖNDERİLİR.
        $this->actingAs($user)
            ->post("/products/{$product->id}/channels", ['connection_id' => $connection->id])
            ->assertRedirect("/products/{$product->id}/channels");

        $listing = $this->asTenant($tenant, fn () => Listing::query()->firstOrFail());

        $this->assertSame('draft', $listing->lifecycle_status,
            'Kanal onaylamadan CANLI işaretlenmez.');

        $operation = $this->asTenant($tenant, fn () => SyncOperation::query()->firstOrFail());

        $this->assertSame('CONTENT_PUSH', $operation->operation_type);

        Queue::assertPushed(PushListing::class);

        // ── 3 · İş çalışır: GERÇEK Woo adapter'ı ürünü yaratır.
        Http::fake([
            // SKU araması: kanalda böyle bir ürün yok → create yoluna gir.
            '*/products?sku=*' => Http::response([], 200),
            '*/products' => Http::response([
                'id' => 4242,
                'permalink' => 'https://store.example.com/urun/yun-kazak',
                'sku' => 'KAZAK-001',
                'status' => 'publish',
            ], 201),
        ]);

        $this->runPushListing($tenant, $operation->id);

        // ── 4 · Woo'ya GİDEN gövde doğrulanır.
        $created = null;

        Http::assertSent(function (Request $request) use (&$created): bool {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/products')) {
                return false;
            }

            $created = $request->data();

            return true;
        });

        $this->assertNotNull($created, 'Woo ürün uç noktasına POST gitmeli.');
        $this->assertSame('Yün Kazak', $created['name']);
        $this->assertSame('KAZAK-001', $created['sku']);

        // manage_stock ZORUNLU: kapalıyken Woo stock_quantity alanını
        // SESSİZCE yok sayar; senkron başarılı görünürken hiçbir şey değişmez.
        $this->assertTrue($created['manage_stock'],
            'manage_stock gönderilmezse sonraki stok senkronu sessizce boşa gider.');

        // ── 5 · Kanaldan dönen kimlik yazıldı ve satır CANLI oldu.
        $fresh = $this->asTenant($tenant, fn () => $listing->fresh());

        $this->assertSame('4242', $fresh->external_id);
        $this->assertTrue($fresh->isLive(), 'Kanal kabul ettikten sonra satır CANLI olmalı.');
        $this->assertSame('https://store.example.com/urun/yun-kazak', $fresh->external_url);

        $this->assertSame(
            SyncOperationStatus::COMPLETED,
            $this->asTenant($tenant, fn () => $operation->fresh())->status,
        );

        $state = $this->asTenant($tenant, fn () => ListingSyncState::query()
            ->where('listing_id', $listing->id)
            ->where('domain', SyncDomain::CONTENT->value)
            ->firstOrFail());

        $this->assertSame('synced', $state->status);
        $this->assertFalse($state->is_dirty, 'Gönderim bitince bekleyen iş kalmamalı.');
        $this->assertNotNull($state->synced_hash);
        $this->assertSame($state->desired_hash, $state->synced_hash);

        // ── 6 · Stok DOKUNULMADI: içerik gönderimi ledger'a yazmaz.
        $this->assertSame(
            10,
            $this->asTenant($tenant, fn () => InventoryLevel::query()
                ->where('variant_id', $listing->variant_id)->firstOrFail())->on_hand,
        );

        $this->assertLedgerMatchesProjectionForTenant($tenant->id);
    }

    /**
     * CANLI OLAN LISTING ARTIK STOK FAN-OUT'UNUN HEDEFİDİR.
     *
     * Zincirin kapandığı yer burası: PushListing satırı canlı yaptığı için
     * sonraki stok değişimi bu kanala da gider. Taslak kalsaydı fan-out onu
     * atlar ve ürün kanalda dururken stoğu hiç güncellenmezdi.
     */
    #[Test]
    public function published_listing_becomes_a_target_for_inventory_fanout(): void
    {
        Queue::fake();

        [$tenant, $user] = $this->makeContext();

        $connection = $this->wooConnection($tenant);

        $this->actingAs($user)->post('/products', [
            'sku' => 'BERE-002',
            'title' => 'Bere',
            'price' => 79.90,
            'opening_stock' => 5,
        ])->assertRedirect('/products');

        $product = $this->asTenant($tenant, fn () => Product::query()
            ->where('sku', 'BERE-002')->firstOrFail());

        $this->actingAs($user)
            ->post("/products/{$product->id}/channels", ['connection_id' => $connection->id])
            ->assertRedirect();

        $operation = $this->asTenant($tenant, fn () => SyncOperation::query()
            ->where('operation_type', 'CONTENT_PUSH')->firstOrFail());

        // Gönderimden ÖNCE fan-out hedefi YOK — satır taslak.
        $this->assertSame(
            0,
            $this->asTenant($tenant, fn (): int => Listing::query()->live()->count()),
            'Gönderilmemiş satır fan-out hedefi olmamalı.',
        );

        Http::fake([
            '*/products?sku=*' => Http::response([], 200),
            '*/products' => Http::response(['id' => 77, 'permalink' => 'https://x.test/p/77'], 201),
        ]);

        $this->runPushListing($tenant, $operation->id);

        // Gönderimden SONRA hedef VAR.
        $this->assertSame(
            1,
            $this->asTenant($tenant, fn (): int => Listing::query()->live()->count()),
            'Kanala giren satır stok fan-out’unun hedefi olmalı.',
        );
    }

    /**
     * KOPYA LİSTELEME KORUMASI — GERÇEK adapter üzerinden.
     *
     * Satıcı ürünü daha önce Woo panelinden açmış olabilir. `PushListing`
     * create'ten önce SKU araması yapar; kanal eşleşme dönerse bulunan
     * kimlik benimsenir ve PUT yoluna girilir. Bu adım olmadan kanalda ikinci
     * bir ürün açılırdı — geri alınamaz ve yorumlar, sıralama, SEO geçmişi
     * ilk üründe kalırdı.
     *
     * Sahte adapter testi (PushListingTest) bu kuralı sınıyor ama GERÇEK
     * adapter'ın arama uç noktasını gerçekten çağırdığını göstermiyor.
     */
    #[Test]
    public function existing_woo_product_is_adopted_instead_of_duplicated(): void
    {
        Queue::fake();

        [$tenant, $user] = $this->makeContext();

        $connection = $this->wooConnection($tenant);

        $this->actingAs($user)->post('/products', [
            'sku' => 'ATKI-003',
            'title' => 'Atkı',
            'price' => 99.90,
            'opening_stock' => 3,
        ])->assertRedirect('/products');

        $product = $this->asTenant($tenant, fn () => Product::query()
            ->where('sku', 'ATKI-003')->firstOrFail());

        $this->actingAs($user)
            ->post("/products/{$product->id}/channels", ['connection_id' => $connection->id])
            ->assertRedirect();

        $operation = $this->asTenant($tenant, fn () => SyncOperation::query()->firstOrFail());

        // Kanalda AYNI SKU ile ürün ZATEN var.
        Http::fake([
            '*/products?sku=*' => Http::response([
                ['id' => 555, 'sku' => 'ATKI-003', 'name' => 'Atkı (kanalda açılmış)'],
            ], 200),
            '*/products/555' => Http::response(['id' => 555], 200),
            '*/products' => Http::response(['id' => 999], 201),
        ]);

        $this->runPushListing($tenant, $operation->id);

        // POST HİÇ GİTMEDİ: ürün yeniden yaratılmadı.
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');

        // Var olan ürün GÜNCELLENDİ.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/products/555'));

        $listing = $this->asTenant($tenant, fn () => Listing::query()->firstOrFail());

        $this->assertSame('555', $listing->external_id, 'Kanalda bulunan kimlik benimsenmeli.');
        $this->assertTrue($listing->isLive());
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: User} */
    private function makeContext(): array
    {
        $user = User::factory()->create();

        $tenant = (new CreateTenant)->run(name: 'Panel '.uniqid(), owner: $user);

        return [$tenant, $user];
    }

    /** GERÇEK WooCommerceAdapter'a bağlı, aktif bağlantı. */
    private function wooConnection(Tenant $tenant): ChannelConnection
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'woocommerce'],
            [
                'name' => 'WooCommerce',
                'kind' => 'store',
                'adapter_class' => WooCommerceAdapter::class,
                'is_active' => true,
                'rate_limit_profile' => ['requests_per_second' => 5, 'burst_capacity' => 10],
            ],
        ));

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'channel_type_code' => 'woocommerce',
            'label' => 'Ana Mağaza',
            'status' => 'active',
            'health_status' => 'healthy',
            'settings' => ['base_url' => 'https://store.example.com/wp-json/wc/v3/'],
        ]));

        $this->asTenant($tenant, fn () => app(CredentialVault::class)->store($connection, [
            'consumer_key' => 'ck_panel_slice_1234567890',
            'consumer_secret' => 'cs_panel_slice_1234567890',
        ]));

        return $connection;
    }

    private function runPushListing(Tenant $tenant, string $operationId): void
    {
        // Worker'daki gibi: bağlam sarmalayıcısı YOK, işin kendisi kurar.
        (new PushListing($operationId, $tenant->id))->handle(
            app(ListingPayloadBuilder::class),
            app(SyncResultRecorder::class),
            app(AdapterRegistry::class),
        );
    }
}
