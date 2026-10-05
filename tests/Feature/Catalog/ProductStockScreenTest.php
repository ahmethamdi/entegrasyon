<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * Ürünler + stok tek ekran (5 Ekim panel yenilemesi).
 *
 * Eski `/inventory` ekranının kuralları buraya taşındı:
 *   · FAZLA SATIŞ GİZLENMEZ — negatif satılabilir kırpılmaz, eksik söylenir
 *     ve "stoksuz" filtresine girer (§17 · P0).
 *   · KANAL DURUMU yalnız canlı/bekleyen listeleri sayar; listeden çıkarılmış
 *     satır sayılmaz. Sorun bekleyenden ÖNCE gelir.
 *   · `DB::table()` sorguları kiracı filtresini AÇIKÇA yazar.
 */
final class ProductStockScreenTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stok hareketi outbox olayı yazar; relay bu ekranın konusu değil.
        Queue::fake();
    }

    // ─────────────────────────────────────────────────── eski adres

    /** Eski stok ekranı Ürünler'e yönlenir; fazla satış filtresi "stoksuz" olur. */
    #[Test]
    public function old_inventory_address_redirects_to_products(): void
    {
        [, $user] = $this->makeTenant();

        $this->get('/inventory')->assertRedirect('/login');
        $this->actingAs($user)->get('/inventory')->assertRedirect('/products');
        $this->actingAs($user)->get('/inventory?filter=oversold&search=kupa')
            ->assertRedirect('/products?filter=out&search=kupa');
    }

    // ─────────────────────────────────────────────────── stok ve filtreler

    /** Satılabilir adet, eksik miktar ve fiyat para birimiyle gelir. */
    #[Test]
    public function rows_carry_available_shortfall_and_currency(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $variant = $this->stockedVariant($tenant, $warehouseId, sku: 'FAZLA-1', onHand: 0, currency: 'EUR', price: '12.50');
        $this->sell($tenant, $warehouseId, $variant, quantity: 2);

        $row = $this->rows($this->actingAs($user)->get('/products'))[0];

        $this->assertSame(-2, $row['available'], 'Negatif bakiye kırpılmamalı.');
        $this->assertSame(2, $row['shortfall']);
        $this->assertSame('EUR', $row['currency']);
        $this->assertSame('12.50', $row['price']);
        $this->assertTrue($row['hasOversold']);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * Filtreler ve sekme sayıları. Fazla satılan "stoksuz"a girer — eksik
     * ürün hiçbir filtrede saklanmaz.
     */
    #[Test]
    public function stock_filters_split_low_and_out_of_stock(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $this->stockedVariant($tenant, $warehouseId, sku: 'BOL-1', onHand: 40);
        $this->stockedVariant($tenant, $warehouseId, sku: 'AZ-1', onHand: 3);
        $this->stockedVariant($tenant, $warehouseId, sku: 'SIFIR-1', onHand: 0);
        $oversold = $this->stockedVariant($tenant, $warehouseId, sku: 'EKSI-1', onHand: 0);
        $this->sell($tenant, $warehouseId, $oversold, quantity: 1);

        $response = $this->actingAs($user)->get('/products');
        $counts = $response->viewData('page')['props']['tabCounts'];

        $this->assertSame(['all' => 4, 'low' => 1, 'out' => 2, 'problem' => 0], $counts);

        $this->assertSame(['AZ-1'], $this->skus('/products?filter=low', $user));
        $this->assertEqualsCanonicalizing(['SIFIR-1', 'EKSI-1'], $this->skus('/products?filter=out', $user));
    }

    /** Arama varyant SKU'sunu da bulur. */
    #[Test]
    public function search_matches_variant_sku(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $this->stockedVariant($tenant, $warehouseId, sku: 'ARANAN-42', onHand: 3);
        $this->stockedVariant($tenant, $warehouseId, sku: 'DIGER-7', onHand: 3);

        $this->assertSame(['ARANAN-42'], $this->skus('/products?search=aranan', $user));
    }

    // ─────────────────────────────────────────────────── kanal çipleri

    /** Canlı ve güncel liste "Satışta"; gönderilecek değişiklik "Bekliyor". */
    #[Test]
    public function channel_chip_reflects_live_and_pending(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $synced = $this->stockedVariant($tenant, $warehouseId, sku: 'CANLI-1', onHand: 5);
        $dirty = $this->stockedVariant($tenant, $warehouseId, sku: 'BEKLE-1', onHand: 5);
        $approval = $this->stockedVariant($tenant, $warehouseId, sku: 'ONAY-1', onHand: 5);

        $this->listOn($tenant, $synced, desiredVersion: 3, syncedVersion: 3, status: 'synced');
        $this->listOn($tenant, $dirty, desiredVersion: 4, syncedVersion: 2, status: 'pending');
        $this->listOn($tenant, $approval, desiredVersion: 1, syncedVersion: 1, status: 'synced', lifecycle: 'pending_approval');

        $states = $this->chipStates($user);

        $this->assertSame('live', $states['CANLI-1']);
        $this->assertSame('pending', $states['BEKLE-1']);
        $this->assertSame('pending', $states['ONAY-1']);
    }

    /**
     * SORUN BEKLEYENDEN ÖNCE GELİR — kalıcı hata, red ve çözülmemiş ölü
     * işlem ayrı ayrı "Sorun" yapar ve "Sorunlu" filtresine girer.
     */
    #[Test]
    public function problems_outrank_pending_and_feed_the_problem_filter(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $permanent = $this->stockedVariant($tenant, $warehouseId, sku: 'KALICI-1', onHand: 5);
        $rejected = $this->stockedVariant($tenant, $warehouseId, sku: 'RED-1', onHand: 5);
        $dead = $this->stockedVariant($tenant, $warehouseId, sku: 'OLU-1', onHand: 5);
        $recovered = $this->stockedVariant($tenant, $warehouseId, sku: 'KURTULDU-1', onHand: 5);

        $this->listOn($tenant, $permanent, desiredVersion: 5, syncedVersion: 1, status: 'error_permanent');
        $this->listOn($tenant, $rejected, desiredVersion: 1, syncedVersion: 1, status: 'synced', lifecycle: 'rejected');

        $deadListing = $this->listOn($tenant, $dead, desiredVersion: 2, syncedVersion: 2, status: 'synced');
        $this->operation($tenant, $deadListing, SyncOperationStatus::DEAD);

        // Ölü işlemden SONRA başaran işlem: sorun çözülmüş sayılır.
        $recoveredListing = $this->listOn($tenant, $recovered, desiredVersion: 2, syncedVersion: 2, status: 'synced');
        $this->operation($tenant, $recoveredListing, SyncOperationStatus::DEAD);
        $this->operation($tenant, $recoveredListing, SyncOperationStatus::COMPLETED);

        $states = $this->chipStates($user);

        $this->assertSame('problem', $states['KALICI-1'], 'Kalıcı hata "bekliyor" değil "sorun".');
        $this->assertSame('problem', $states['RED-1']);
        $this->assertSame('problem', $states['OLU-1']);
        $this->assertSame('live', $states['KURTULDU-1'], 'Sonradan başaran ölü işlem sorun sayılmaz.');

        $this->assertEqualsCanonicalizing(
            ['KALICI-1', 'RED-1', 'OLU-1'],
            $this->skus('/products?filter=problem', $user),
        );
    }

    /** Listeden çıkarılmış satır çip üretmez — o kanala stok gitmiyor. */
    #[Test]
    public function delisted_listings_produce_no_chip(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $variant = $this->stockedVariant($tenant, $warehouseId, sku: 'DELIST-1', onHand: 5);
        $this->listOn($tenant, $variant, desiredVersion: 7, syncedVersion: 1, status: 'error_permanent', lifecycle: 'delisted');

        $this->assertSame([], $this->rows($this->actingAs($user)->get('/products'))[0]['channels']);
        $this->assertSame([], $this->skus('/products?filter=problem', $user));
    }

    /**
     * ÇİPLER BAŞKA KİRACININ LİSTELERİNİ SAYMAZ. B kiracısının listing'i
     * A'nın varyant kimliğine bağlanır (FK kiracı sınırını zorlamıyor).
     */
    #[Test]
    public function chips_never_include_another_tenants_listings(): void
    {
        [$tenantA, $userA, $warehouseA] = $this->makeTenant('A');
        [$tenantB] = $this->makeTenant('B');

        $variant = $this->stockedVariant($tenantA, $warehouseA, sku: 'PAYLASIM-1', onHand: 5);

        $this->channelType('woocommerce');

        $this->asTenant($tenantB, function () use ($variant, $tenantB): void {
            $listing = Listing::factory()->create([
                'channel_connection_id' => ChannelConnection::factory()->create(['channel_type_code' => 'woocommerce'])->id,
                'variant_id' => $variant->id,
                'lifecycle_status' => 'rejected',
            ]);

            ListingSyncState::query()->create([
                'tenant_id' => $tenantB->id,
                'listing_id' => $listing->id,
                'domain' => SyncDomain::INVENTORY,
                'desired_version' => 9,
                'synced_version' => 1,
                'status' => 'error_permanent',
            ]);
        });

        $this->assertSame([], $this->rows($this->actingAs($userA)->get('/products'))[0]['channels']);
        $this->assertSame([], $this->skus('/products?filter=problem', $userA));
    }

    // ─────────────────────────────────────────────────── gruplama, görsel, sayım

    /**
     * Kanalda aynı üst ürünün varyantları AYNI grup anahtarını taşır;
     * başka üst ürün ve listelenmemiş ürün taşımaz.
     */
    #[Test]
    public function siblings_on_the_same_channel_product_share_a_group_key(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $a = $this->stockedVariant($tenant, $warehouseId, sku: 'KAYAK-BUZ', onHand: 1);
        $b = $this->stockedVariant($tenant, $warehouseId, sku: 'KAYAK-KAR', onHand: 1);
        $c = $this->stockedVariant($tenant, $warehouseId, sku: 'BASKA-1', onHand: 1);
        $this->stockedVariant($tenant, $warehouseId, sku: 'YALNIZ-1', onHand: 1);

        $connection = $this->listOn($tenant, $a, 1, 1, 'synced', parent: 'gid://shopify/Product/1')->channel_connection_id;
        $this->listOn($tenant, $b, 1, 1, 'synced', parent: 'gid://shopify/Product/1', connectionId: $connection);
        $this->listOn($tenant, $c, 1, 1, 'synced', parent: 'gid://shopify/Product/2', connectionId: $connection);

        $keys = collect($this->rows($this->actingAs($user)->get('/products')))->pluck('groupKey', 'sku');

        $this->assertNotNull($keys['KAYAK-BUZ']);
        $this->assertSame($keys['KAYAK-BUZ'], $keys['KAYAK-KAR']);
        $this->assertNotSame($keys['KAYAK-BUZ'], $keys['BASKA-1']);
        $this->assertNull($keys['YALNIZ-1']);
    }

    /** Listede ürünün İLK görseli; HTTPS olmayan adres gönderilmez. */
    #[Test]
    public function the_first_https_image_is_shown(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $variant = $this->stockedVariant($tenant, $warehouseId, sku: 'GORSEL-1', onHand: 1);

        $this->asTenant($tenant, function () use ($variant, $tenant): void {
            foreach ([2 => 'https://cdn.example.com/ikinci.jpg', 1 => 'https://cdn.example.com/ilk.jpg'] as $position => $url) {
                ProductImage::query()->create([
                    'tenant_id' => $tenant->id,
                    'product_id' => $variant->product_id,
                    'storage_path' => $url,
                    'position' => $position,
                ]);
            }
        });

        $this->assertSame('https://cdn.example.com/ilk.jpg', $this->rows($this->actingAs($user)->get('/products'))[0]['imageUrl']);
    }

    /**
     * YERİNDE SAYIM: liste sayım hedefini (varyant + varsayılan depodaki
     * adet) verir; sayım stoğu DÜŞÜREBİLİR ve ledger tutarlı kalır.
     */
    #[Test]
    public function inline_count_sets_stock_from_the_list(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $variant = $this->stockedVariant($tenant, $warehouseId, sku: 'SAYIM-1', onHand: 10);

        $row = $this->rows($this->actingAs($user)->get('/products'))[0];

        $this->assertSame($variant->id, $row['countVariantId']);
        $this->assertSame(10, $row['countOnHand']);

        $this->actingAs($user)->from('/products')
            ->post('/inventory/adjust', ['variant_id' => $row['countVariantId'], 'target' => 4])
            ->assertRedirect('/products')
            ->assertSessionHas('success');

        $this->assertSame(4, $this->rows($this->actingAs($user)->get('/products'))[0]['available']);
        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @return array<int, array<string, mixed>> */
    private function rows(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('page')['props']['rows'];
    }

    /** @return list<string> */
    private function skus(string $url, User $user): array
    {
        return array_column($this->rows($this->actingAs($user)->get($url)), 'sku');
    }

    /** @return array<string, string> SKU → tek kanalın durumu */
    private function chipStates(User $user): array
    {
        $states = [];

        foreach ($this->rows($this->actingAs($user)->get('/products')) as $row) {
            $states[$row['sku']] = $row['channels'][0]['state'] ?? 'none';
        }

        return $states;
    }

    /** @return array{0: Tenant, 1: User, 2: string} */
    private function makeTenant(string $name = 'Stok'): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: $name.' '.uniqid(), owner: $user);
        $warehouseId = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse()->id);

        return [$tenant, $user, $warehouseId];
    }

    /** Açılış stoğu LEDGER üzerinden girer (IMPORT hareketi). */
    private function stockedVariant(
        Tenant $tenant,
        string $warehouseId,
        string $sku,
        int $onHand,
        string $currency = 'TRY',
        string $price = '10.00',
    ): Variant {
        return $this->asTenant($tenant, function () use ($warehouseId, $sku, $onHand, $currency, $price): Variant {
            $variant = Variant::factory()->create(['sku' => $sku, 'currency' => $currency, 'price' => $price]);
            $variant->product->forceFill(['sku' => $sku, 'title' => 'Ürün '.$sku])->save();

            DB::transaction(function () use ($warehouseId, $variant, $onHand): void {
                (new LockInventoryRows)->run($warehouseId, [$variant->id]);

                if ($onHand > 0) {
                    (new ApplyMovement)->run(
                        warehouseId: $warehouseId,
                        variantId: $variant->id,
                        type: MovementType::IMPORT,
                        quantity: $onHand,
                        idempotencyKey: MovementKey::import((string) new UuidV7),
                        sourceType: 'import_row',
                    );
                }
            });

            return $variant;
        });
    }

    private function sell(Tenant $tenant, string $warehouseId, Variant $variant, int $quantity): void
    {
        $this->asTenant($tenant, fn () => DB::transaction(function () use ($warehouseId, $variant, $quantity): void {
            (new LockInventoryRows)->run($warehouseId, [$variant->id]);

            (new ApplyMovement)->run(
                warehouseId: $warehouseId,
                variantId: $variant->id,
                type: MovementType::SALE,
                quantity: $quantity,
                idempotencyKey: MovementKey::sale((string) new UuidV7),
                sourceType: 'order_line',
            );
        }));
    }

    private function channelType(string $code): void
    {
        $this->asSystem(fn () => ChannelType::query()->firstOrCreate(
            ['code' => $code],
            ['name' => ucfirst($code), 'kind' => 'storefront', 'adapter_class' => WooCommerceAdapter::class, 'is_active' => true],
        ));
    }

    /** Varyantı bir kanalda listeler ve stok senkron durumunu kurar. */
    private function listOn(
        Tenant $tenant,
        Variant $variant,
        int $desiredVersion,
        int $syncedVersion,
        string $status,
        string $lifecycle = 'live',
        ?string $parent = null,
        ?string $connectionId = null,
    ): Listing {
        $this->channelType('woocommerce');

        return $this->asTenant($tenant, function () use (
            $variant, $desiredVersion, $syncedVersion, $status, $lifecycle, $parent, $connectionId,
        ): Listing {
            $listing = Listing::factory()->create([
                'channel_connection_id' => $connectionId
                    ?? ChannelConnection::factory()->create(['channel_type_code' => 'woocommerce'])->id,
                'variant_id' => $variant->id,
                'lifecycle_status' => $lifecycle,
                'external_parent_id' => $parent,
            ]);

            ListingSyncState::query()->create([
                'tenant_id' => $listing->tenant_id,
                'listing_id' => $listing->id,
                'domain' => SyncDomain::INVENTORY,
                'desired_version' => $desiredVersion,
                'synced_version' => $syncedVersion,
                'status' => $status,
            ]);

            return $listing;
        });
    }

    private function operation(Tenant $tenant, Listing $listing, SyncOperationStatus $status): void
    {
        $domain = SyncDomain::INVENTORY;

        $this->asTenant($tenant, fn () => SyncOperation::query()->create([
            'tenant_id' => $tenant->id,
            'channel_connection_id' => $listing->channel_connection_id,
            'operation_type' => $domain->operationType(),
            'intent' => 'NORMAL_SYNC',
            'entity_type' => 'listing',
            'entity_id' => $listing->id,
            'entity_version' => 1,
            'idempotency_key' => $domain->keyPrefix().':'.$listing->id.':1:'.uniqid(),
            'status' => $status->value,
            'attempt_count' => 1,
        ]));
    }
}
