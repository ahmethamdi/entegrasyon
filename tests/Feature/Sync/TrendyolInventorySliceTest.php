<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
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
use App\Domain\Sync\Actions\OpenSyncOperation;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Jobs\PushInventory;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\InventoryBatchBuilder;
use App\Domain\Sync\Support\SyncResultRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * ÇEKİRDEK GERÇEK TRENDYOL ADAPTER'INI SÜRÜYOR MU — dikey dilim.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 2 ("Stok ve fiyat itme"), §8.
 *
 * NEDEN AYRI BİR TEST: `TrendyolInventoryPricingTest` adapter'ı DOĞRUDAN
 * çağırır ve yükün doğru kurulduğunu sınar; `PushInventoryTest` ise
 * çekirdeği sınar ama `ProgrammableInventoryAdapter` (sahte) ile. İkisi de
 * yeşilken aradaki SÖZLEŞME yanlış olabilir — bu projede tam olarak bu
 * biçimde iki ölümcül hata bulundu (`origin_connection_id` yazılmıyordu;
 * kimlik bilgisi bağlam dışında gönderilmiyordu). Parçalar tek tek
 * doğruyken aralarındaki sözleşmenin yanlış olması, ancak uçtan uca test
 * ile görünür.
 *
 * Bu test zinciri gerçek sınıflarla yürütür:
 *   satış (ledger) → operasyon → `PushInventory` → `InventoryBatchBuilder`
 *   → `AdapterRegistry` → **gerçek `TrendyolAdapter`** → HTTP → başarı →
 *   `SyncResultRecorder`.
 */
final class TrendyolInventorySliceTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Planlama yolu iş atar; gerçek worker asenkrondur ve `sync` sürücü
        // onu taklit etmez. İş bu testte ELLE çağrılır.
        Queue::fake();
    }

    /**
     * ÇEKİRDEK GERÇEK ADAPTER'I SÜRÜYOR VE STOK KANALA MUTLAK GİDİYOR.
     *
     * Zincirin her halkası gerçek sınıf: sahte olan yalnızca HTTP katmanı.
     */
    #[Test]
    public function core_drives_the_real_trendyol_adapter_end_to_end(): void
    {
        [$tenant] = $this->makeTenant();

        $variant = $this->variant($tenant);
        $this->seedStock($tenant, $variant, 20);

        $connection = $this->asTenant($tenant, fn (): ChannelConnection => $this->connection());
        $listing = $this->listing($tenant, $connection, $variant);

        // Satış: kanonik bakiye 20 → 17.
        $this->sell($tenant, $variant, 3);

        $operationId = $this->openInventoryOperation($tenant, $listing);

        Http::fake(['*' => Http::response(['batchRequestId' => 'batch-7'], 200)]);

        $this->runJob($tenant, $operationId);

        // Kanala giden değer MUTLAK ve kanonik bakiyeye eşit.
        Http::assertSent(function (Request $request): bool {
            $item = $request->data()['items'][0] ?? [];

            return str_contains($request->url(), 'v2/products/price-and-inventory')
                && $item['barcode'] === 'BARKOD-1'
                && $item['quantity'] === 17;
        });

        // Operasyon tamamlandı ve sürüm ilerledi.
        $operation = $this->asTenant(
            $tenant,
            fn (): SyncOperation => SyncOperation::query()->findOrFail($operationId),
        );

        $this->assertSame(SyncOperationStatus::COMPLETED, $operation->status);

        $this->assertLedgerMatchesProjection(
            $tenant->id,
            $this->warehouse($tenant)->id,
            $variant->id,
        );
    }

    /**
     * FAZLA SATIŞ KANALA 0 OLARAK GİDER — KANONİK DEĞER NEGATİF KALIR.
     *
     * §1 · Karar 25 ve §17 · P0'ın birlikte çalıştığı yer. Kırpmanın TEK
     * meşru yeri `OutboundQuantity::forChannel()`'dır; kanonik bakiye
     * negatif kalır ve panelde eksik miktarıyla görünür. Adapter kendi
     * kırpmasını yapsaydı ya da çekirdek kırpmayı unutsaydı,
     * `InventoryPushItem` negatifi kurucuda reddeder ve iş patlardı.
     *
     * Bu, dikey dilimde sınanması gereken bir şey: adapter'ı doğrudan
     * çağıran test negatif bir değerin buraya kadar GELMEDİĞİNİ
     * gösteremez.
     */
    #[Test]
    public function oversold_balance_reaches_the_channel_clamped_to_zero(): void
    {
        [$tenant] = $this->makeTenant();

        $variant = $this->variant($tenant);
        $this->seedStock($tenant, $variant, 2);

        $connection = $this->asTenant($tenant, fn (): ChannelConnection => $this->connection());
        $listing = $this->listing($tenant, $connection, $variant);

        // FAZLA SATIŞ: sipariş asla reddedilmez, bakiye −3'e düşer.
        $this->sell($tenant, $variant, 5);

        $operationId = $this->openInventoryOperation($tenant, $listing);

        Http::fake(['*' => Http::response(['batchRequestId' => 'batch-8'], 200)]);

        $this->runJob($tenant, $operationId);

        Http::assertSent(function (Request $request): bool {
            return ($request->data()['items'][0]['quantity'] ?? null) === 0;
        });

        // KANONİK DEĞER KIRPILMAZ: ham satır okunur (Eloquent kimlik
        // haritası bellekteki nesneyi geri verebilir).
        $available = $this->asTenant($tenant, fn () => DB::table('inventory_levels')
            ->where('tenant_id', $tenant->id)
            ->where('variant_id', $variant->id)
            ->value('available'));

        $this->assertSame(-3, (int) $available, 'Kanonik bakiye negatif KALMALI.');
    }

    /**
     * KANAL HATASI OPERASYONU KALICI HATAYA DÜŞÜRÜR VE SÜRÜM İLERLEMEZ.
     *
     * Adapter istisna fırlatır (`failure()` dönmez); sınıflandırma ve
     * yeniden deneme kararı `PushInventory`'deki tek try/catch'te
     * toplanır. Bu testin sınadığı şey o sözleşmenin GERÇEK adapter'la da
     * kurulduğudur: `classifyError()` 400'ü VALIDATION sayar ve VALIDATION
     * KALICIDIR.
     */
    #[Test]
    public function channel_validation_error_marks_the_operation_permanently_failed(): void
    {
        [$tenant] = $this->makeTenant();

        $variant = $this->variant($tenant);
        $this->seedStock($tenant, $variant, 10);

        $connection = $this->asTenant($tenant, fn (): ChannelConnection => $this->connection());
        $listing = $this->listing($tenant, $connection, $variant);

        $operationId = $this->openInventoryOperation($tenant, $listing);

        Http::fake(['*' => Http::response(['errors' => [['message' => 'barkod yok']]], 400)]);

        $this->runJob($tenant, $operationId);

        $operation = $this->asTenant(
            $tenant,
            fn (): SyncOperation => SyncOperation::query()->findOrFail($operationId),
        );

        // KALICI hata `dead`tir: VALIDATION yeniden denenmez.
        $this->assertSame(SyncOperationStatus::DEAD, $operation->status);
        $this->assertGreaterThan(0, $operation->attempt_count, 'Gerçek deneme açılmalı.');
    }

    // ──────────────────────────────────────────────────────── yardımcı

    /** @return array{0: Tenant, 1: User} */
    private function makeTenant(): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Dilim '.uniqid(), owner: $user);

        return [$tenant, $user];
    }

    private function variant(Tenant $tenant): Variant
    {
        return $this->asTenant($tenant, function (): Variant {
            $product = Product::factory()->create();

            return Variant::factory()->create([
                'product_id' => $product->id,
                'sku' => 'BARKOD-1',
                'barcode' => 'BARKOD-1',
            ]);
        });
    }

    /** Gerçek `TrendyolAdapter`'a bağlı bağlantı — sahte adapter YOK. */
    private function connection(string $supplierId = '123456'): ChannelConnection
    {
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
                'rate_limit_profile' => [
                    'requests_per_second' => 5,
                    'burst_capacity' => 10,
                ],
                'supports_webhooks' => false,
                'is_active' => true,
            ],
        ));

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
    }

    private function listing(Tenant $tenant, ChannelConnection $connection, Variant $variant): Listing
    {
        return $this->asTenant($tenant, fn (): Listing => Listing::factory()->create([
            'channel_connection_id' => $connection->id,
            'variant_id' => $variant->id,
            // Kimlik BARKODDUR — `ListingMapper` de böyle yazar.
            'external_id' => 'BARKOD-1',
            'lifecycle_status' => 'live',
        ]));
    }

    /** Açılış stoğu LEDGER üzerinden girer. */
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

    private function sell(Tenant $tenant, Variant $variant, int $quantity): void
    {
        $this->asTenant($tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: $this->warehouse($tenant)->id,
            variantId: $variant->id,
            type: MovementType::SALE,
            quantity: $quantity,
            idempotencyKey: 'sale:'.$variant->id,
            sourceType: 'test',
        ));
    }

    private function warehouse(Tenant $tenant): Warehouse
    {
        return $this->asTenant($tenant, fn (): Warehouse => Warehouse::query()
            ->where('is_default', true)
            ->firstOrFail());
    }

    private function openInventoryOperation(Tenant $tenant, Listing $listing): string
    {
        return $this->asTenant($tenant, function () use ($listing): string {
            $operation = app(OpenSyncOperation::class)->run(
                listing: $listing,
                domain: SyncDomain::INVENTORY,
                eventVersion: 1,
            );

            $this->assertNotNull($operation, 'Operasyon açılmalıydı.');

            return $operation->id;
        });
    }

    /**
     * İşi worker'daki gibi çalıştırır — bağlam sarmalayıcısı YOK.
     *
     * İş kiracı bağlamını KENDİ kurar ve bitişte `finally` ile bırakır;
     * `asTenant()` ile sarmak gerçek worker'ı taklit etmezdi.
     */
    private function runJob(Tenant $tenant, string $operationId): void
    {
        (new PushInventory($operationId, $tenant->id))->handle(
            app(InventoryBatchBuilder::class),
            app(SyncResultRecorder::class),
            app(AdapterRegistry::class),
        );
    }
}
