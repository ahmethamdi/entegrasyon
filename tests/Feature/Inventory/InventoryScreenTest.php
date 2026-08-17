<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

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
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * Ürün/stok listesi ekranı — satıcının her gün bakacağı ekran.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · faz 1.2 ve 1.5 panel maddeleri,
 * §17 · P0 · "Fazla satış ekranı — negatif available kullanıcıya anlatılmalı;
 * eksik miktar ve düzeltme yolu gösterilmeli".
 *
 * DEĞİŞMEZ KURAL — FAZLA SATIŞ PANELDE GİZLENMEZ:
 *   Negatif `available` KIRPILMADAN gösterilir. Kırpma yalnızca kanala giden
 *   yükte meşrudur (`OutboundQuantity::forChannel()`); panelde gizlemek
 *   satıcıyı eksik miktardan habersiz bırakır ve stoğunun neden tutmadığını
 *   anlamaz.
 *
 * DEĞİŞMEZ KURAL — SENKRON ROZETİ TÜRETİLMİŞ ALANDAN OKUNUR:
 *   `is_dirty` veritabanı tarafından üretilir (`desired > synced`). Ayrı bir
 *   sayaç tutulmadığı için rozet tanım gereği tutarlıdır.
 *
 * DEĞİŞMEZ KURAL — INERTIA'YA MODEL GÖNDERİLMEZ: yalnızca görünen alanlar.
 */
final class InventoryScreenTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    // ─────────────────────────────────────────────────── erişim

    /** Misafir stok ekranını göremez. */
    #[Test]
    public function guest_cannot_reach_the_inventory_screen(): void
    {
        $this->get('/inventory')->assertRedirect('/login');
    }

    /** Başka kiracının varyantı listede GÖRÜNMEZ. */
    #[Test]
    public function variants_of_other_tenants_are_not_visible(): void
    {
        [$tenantA, $userA, $warehouseA] = $this->makeTenant('A');
        [$tenantB, , $warehouseB] = $this->makeTenant('B');

        $this->stockedVariant($tenantA, $warehouseA, sku: 'BENIM-1', onHand: 5);
        $this->stockedVariant($tenantB, $warehouseB, sku: 'BASKASININ-1', onHand: 5);

        $rows = $this->rows($this->actingAs($userA)->get('/inventory'));

        $this->assertCount(1, $rows);
        $this->assertSame('BENIM-1', $rows[0]['sku']);
    }

    // ─────────────────────────────────────────────────── liste

    /** Liste SKU, bakiye ve depo bilgisini gösterir. */
    #[Test]
    public function list_shows_sku_and_balances(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $this->stockedVariant($tenant, $warehouseId, sku: 'ELMA-1', onHand: 12);

        $rows = $this->rows($this->actingAs($user)->get('/inventory'));

        $this->assertCount(1, $rows);
        $this->assertSame('ELMA-1', $rows[0]['sku']);
        $this->assertSame(12, $rows[0]['onHand']);
        $this->assertSame(12, $rows[0]['available']);
        $this->assertSame(0, $rows[0]['reserved']);
        $this->assertFalse($rows[0]['isOversold']);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $rows[0]['variantId']);
    }

    /**
     * FAZLA SATIŞ KIRPILMADAN GÖSTERİLİR (§17 · P0).
     *
     * Sıfır stokta iki satış: kanonik bakiye −2. Panel bunu −2 olarak
     * göstermeli ve eksik miktarı (2) açıkça söylemeli.
     */
    #[Test]
    public function oversold_variant_is_shown_unclamped_with_shortfall(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $variant = $this->stockedVariant($tenant, $warehouseId, sku: 'FAZLA-1', onHand: 0);
        $this->sell($tenant, $warehouseId, $variant, quantity: 2);

        $rows = $this->rows($this->actingAs($user)->get('/inventory'));

        $this->assertSame(-2, $rows[0]['available'], 'Negatif bakiye kırpılmamalı.');
        $this->assertSame(-2, $rows[0]['onHand']);
        $this->assertTrue($rows[0]['isOversold']);
        $this->assertSame(2, $rows[0]['shortfall'], 'Eksik miktar açıkça söylenmeli.');

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /** Fazla satış özeti üstte gösterilir — satıcı önce onu görmeli. */
    #[Test]
    public function summary_counts_oversold_variants(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $a = $this->stockedVariant($tenant, $warehouseId, sku: 'F-1', onHand: 0);
        $b = $this->stockedVariant($tenant, $warehouseId, sku: 'F-2', onHand: 0);
        $this->stockedVariant($tenant, $warehouseId, sku: 'IYI-1', onHand: 7);

        $this->sell($tenant, $warehouseId, $a, quantity: 3);
        $this->sell($tenant, $warehouseId, $b, quantity: 1);

        $summary = $this->actingAs($user)->get('/inventory')
            ->viewData('page')['props']['summary'];

        $this->assertSame(3, $summary['variantCount']);
        $this->assertSame(2, $summary['oversoldCount']);
        // Toplam eksik: 3 + 1. §10 metriği SUM(-available) ile aynı tanım.
        $this->assertSame(4, $summary['totalShortfall']);
    }

    /** Fazla satış filtresi yalnızca negatif bakiyeleri getirir. */
    #[Test]
    public function oversold_filter_narrows_the_list(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $oversold = $this->stockedVariant($tenant, $warehouseId, sku: 'F-1', onHand: 0);
        $this->stockedVariant($tenant, $warehouseId, sku: 'IYI-1', onHand: 9);

        $this->sell($tenant, $warehouseId, $oversold, quantity: 1);

        $rows = $this->rows($this->actingAs($user)->get('/inventory?filter=oversold'));

        $this->assertCount(1, $rows);
        $this->assertSame('F-1', $rows[0]['sku']);
    }

    /** SKU arama çalışır. */
    #[Test]
    public function search_matches_sku(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $this->stockedVariant($tenant, $warehouseId, sku: 'ARANAN-42', onHand: 3);
        $this->stockedVariant($tenant, $warehouseId, sku: 'DIGER-7', onHand: 3);

        $rows = $this->rows($this->actingAs($user)->get('/inventory?search=aranan'));

        $this->assertCount(1, $rows);
        $this->assertSame('ARANAN-42', $rows[0]['sku']);
    }

    // ─────────────────────────────────────────────────── senkron rozeti

    /**
     * SENKRON ROZETİ listing durumlarından türer.
     *
     * `is_dirty` üretilmiş kolondur: `desired_version > synced_version`.
     * Bekleyen iş varsa rozet "bekliyor" der; ayrı sayaç tutulmadığı için
     * rozet tutarsızlaşamaz.
     */
    #[Test]
    public function sync_badge_reflects_pending_work(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $variant = $this->stockedVariant($tenant, $warehouseId, sku: 'ROZET-1', onHand: 5);

        // Bir kanalda listeli ve bekleyen işi var: desired > synced.
        $this->listOn($tenant, $variant, desiredVersion: 4, syncedVersion: 2, status: 'pending');

        $rows = $this->rows($this->actingAs($user)->get('/inventory'));

        $this->assertSame(1, $rows[0]['sync']['total'], 'Bir kanalda listeli.');
        $this->assertSame(1, $rows[0]['sync']['dirty'], 'Bekleyen iş sayılmalı.');
        $this->assertSame(0, $rows[0]['sync']['errors']);
        $this->assertSame('pending', $rows[0]['sync']['badge']);
    }

    /** Tüm kanallar güncelse rozet "senkron" der. */
    #[Test]
    public function sync_badge_is_clean_when_everything_is_synced(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $variant = $this->stockedVariant($tenant, $warehouseId, sku: 'ROZET-2', onHand: 5);

        $this->listOn($tenant, $variant, desiredVersion: 3, syncedVersion: 3, status: 'synced');

        $rows = $this->rows($this->actingAs($user)->get('/inventory'));

        $this->assertSame(0, $rows[0]['sync']['dirty']);
        $this->assertSame('synced', $rows[0]['sync']['badge']);
    }

    /**
     * KALICI HATA ROZETİ BEKLEYENDEN ÖNCE GELİR.
     *
     * `error_permanent` kullanıcı müdahalesi bekler; "bekliyor" demek onu
     * kendiliğinden düzelecek sanmaya yol açar ve satıcı hiç bakmaz.
     */
    #[Test]
    public function permanent_error_outranks_pending_in_the_badge(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $variant = $this->stockedVariant($tenant, $warehouseId, sku: 'ROZET-3', onHand: 5);

        $this->listOn($tenant, $variant, desiredVersion: 5, syncedVersion: 1,
            status: 'error_permanent', channelCode: 'woocommerce');

        $rows = $this->rows($this->actingAs($user)->get('/inventory'));

        $this->assertSame(1, $rows[0]['sync']['errors']);
        $this->assertSame(
            'error',
            $rows[0]['sync']['badge'],
            'Kalıcı hata varken rozet "bekliyor" DEĞİL "hata" olmalı.',
        );
    }

    /**
     * ROZET SAYILARI BAŞKA KİRACININ LİSTELERİNİ SAYMAZ.
     *
     * Rozet sorgusu `DB::table()` üzerinden gider ve `DB::table()` Eloquent
     * global scope'una TABİ DEĞİLDİR — kiracı filtresi orada AÇIKÇA
     * yazılmak zorundadır. Yazılmazsa iki kiracı aynı varyant kimliğini
     * paylaşmasa bile sorgu diğerinin satırlarını sayabilir; kiracı
     * izolasyonu ekranın en sessiz köşesinden delinir.
     *
     * Testin kurgusu bunu görünür kılar: iki kiracıda AYNI listing satırları
     * varmış gibi davranmak yerine, B kiracısının listing'i A'nın varyant
     * kimliğine bağlanır. Filtre yoksa A'nın satırında B'nin kanalı sayılır.
     */
    #[Test]
    public function sync_counts_never_include_another_tenants_listings(): void
    {
        [$tenantA, $userA, $warehouseA] = $this->makeTenant('A');
        [$tenantB] = $this->makeTenant('B');

        $variant = $this->stockedVariant($tenantA, $warehouseA, sku: 'PAYLASIM-1', onHand: 5);

        // A'nın kendi kanalı yok. B kiracısı A'nın varyant kimliğine listing
        // açıyor — çapraz kiracı sızıntısının gerçek biçimi bu.
        $this->asSystem(fn () => ChannelType::query()->firstOrCreate(
            ['code' => 'woocommerce'],
            [
                'name' => 'WooCommerce',
                'kind' => 'storefront',
                'adapter_class' => WooCommerceAdapter::class,
                'is_active' => true,
            ],
        ));

        $this->asTenant($tenantB, function () use ($variant, $tenantB): void {
            $listing = Listing::factory()->create([
                'channel_connection_id' => ChannelConnection::factory()
                    ->create(['channel_type_code' => 'woocommerce'])->id,
                // A'nın varyantı — FK kiracı sınırını zorlamıyor.
                'variant_id' => $variant->id,
                'lifecycle_status' => 'live',
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

        $rows = $this->rows($this->actingAs($userA)->get('/inventory'));

        $this->assertSame(
            0,
            $rows[0]['sync']['total'],
            'Başka kiracının listing\'i sayılmamalı — DB::table() global scope\'a tabi değil.',
        );
        $this->assertSame(0, $rows[0]['sync']['errors']);
        $this->assertSame(
            'unlisted',
            $rows[0]['sync']['badge'],
            'A kiracısının kendi kanalı yok; rozet "listelenmedi" olmalı.',
        );
    }

    /**
     * ROZET YALNIZCA CANLI LİSTELERİ SAYAR.
     *
     * Taslak ve listeden çıkarılmış satırlara stok GÖNDERİLMEZ (fan-out
     * `live()` scope'u kullanır). Onları saymak satıcıya "bir kanalda
     * bekleyen işin var" derdi; oysa o kanala hiçbir şey gitmeyecek ve
     * rozet asla temizlenmezdi.
     */
    #[Test]
    public function sync_counts_ignore_delisted_listings(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $variant = $this->stockedVariant($tenant, $warehouseId, sku: 'DELIST-1', onHand: 5);

        $listing = $this->listOn($tenant, $variant, desiredVersion: 7, syncedVersion: 1,
            status: 'error_permanent');

        // Listing listeden çıkarıldı: artık stok gönderilmiyor.
        $this->asTenant($tenant, fn () => $listing->forceFill([
            'lifecycle_status' => 'delisted',
            'delisted_at' => now(),
        ])->save());

        $rows = $this->rows($this->actingAs($user)->get('/inventory'));

        $this->assertSame(
            0,
            $rows[0]['sync']['total'],
            'Listeden çıkarılmış satır sayılmamalı — o kanala stok gitmiyor.',
        );
        $this->assertSame(
            'unlisted',
            $rows[0]['sync']['badge'],
            'Tek listesi delisted olan varyant "listelenmedi" sayılır.',
        );
    }

    /** Hiç listelenmemiş varyant rozeti "listelenmedi" olur. */
    #[Test]
    public function unlisted_variant_has_its_own_badge(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $this->stockedVariant($tenant, $warehouseId, sku: 'YOK-1', onHand: 5);

        $rows = $this->rows($this->actingAs($user)->get('/inventory'));

        $this->assertSame(0, $rows[0]['sync']['total']);
        $this->assertSame('unlisted', $rows[0]['sync']['badge']);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @return array<int, array<string, mixed>> */
    private function rows(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('page')['props']['rows'];
    }

    /** @return array{0: Tenant, 1: User, 2: string} */
    private function makeTenant(string $name = 'Stok'): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: $name.' '.uniqid(), owner: $user);
        $warehouseId = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse()->id);

        return [$tenant, $user, $warehouseId];
    }

    /**
     * Açılış stoğu LEDGER üzerinden girer.
     *
     * `InventoryLevel::create(['on_hand' => 5])` ile seed etmek
     * `on_hand = Σ on_hand_delta` eşitliğini bozar; seed IMPORT hareketiyle
     * yapılır.
     */
    private function stockedVariant(
        Tenant $tenant,
        string $warehouseId,
        string $sku,
        int $onHand,
    ): Variant {
        return $this->asTenant($tenant, function () use ($warehouseId, $sku, $onHand): Variant {
            $variant = Variant::factory()->create(['sku' => $sku]);

            if ($onHand > 0) {
                DB::transaction(function () use ($warehouseId, $variant, $onHand): void {
                    (new LockInventoryRows)->run($warehouseId, [$variant->id]);

                    (new ApplyMovement)->run(
                        warehouseId: $warehouseId,
                        variantId: $variant->id,
                        type: MovementType::IMPORT,
                        quantity: $onHand,
                        idempotencyKey: MovementKey::import((string) new UuidV7),
                        sourceType: 'import_row',
                    );
                });
            }

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

    /** Varyantı bir kanalda listeler ve senkron durumunu kurar. */
    private function listOn(
        Tenant $tenant,
        Variant $variant,
        int $desiredVersion,
        int $syncedVersion,
        string $status,
        string $channelCode = 'woocommerce',
    ): Listing {
        $this->asSystem(fn () => ChannelType::query()->firstOrCreate(
            ['code' => $channelCode],
            [
                'name' => ucfirst($channelCode),
                'kind' => 'storefront',
                'adapter_class' => WooCommerceAdapter::class,
                'is_active' => true,
            ],
        ));

        return $this->asTenant($tenant, function () use (
            $variant, $desiredVersion, $syncedVersion, $status, $channelCode,
        ): Listing {
            $listing = Listing::factory()->create([
                'channel_connection_id' => ChannelConnection::factory()
                    ->create(['channel_type_code' => $channelCode])->id,
                'variant_id' => $variant->id,
                'lifecycle_status' => 'live',
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

    protected function setUp(): void
    {
        parent::setUp();

        // Stok hareketi outbox olayı yazar; relay/tüketici bu ekranın konusu
        // değil ve sync sürücüde iş derhal çalışıp bağlamı temizlerdi.
        Queue::fake();
    }
}
