<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\RemoteProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Uid\UuidV7;
use Tests\Support\Channels\FakePilotAdapter;
use Tests\TestCase;

/**
 * Sprint 2 · pilot satıcı doğrulaması (`channels:pilot-check`).
 *
 * Gerçek satıcı hesabında çalışacağı için üç şey kanıtlanır: kanala YAZMAZ
 * (sahte adapter'ın yazma yöntemleri patlar), bizim veritabanımıza
 * sipariş/inbox YAZMAZ, rapora kişisel veri GİRMEZ.
 */
final class PilotCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakePilotAdapter::reset();
        Storage::fake('local');
    }

    #[Test]
    public function it_reads_orders_catalog_and_stock_and_reports_matches(): void
    {
        [, $connection] = $this->context();

        FakePilotAdapter::$orders = [
            ['external_order_id' => 'HB-1', 'status' => 'Open', 'grand_total' => '199.90', 'currency' => 'TRY',
                'customer' => ['name' => 'Ayşe Yılmaz', 'address' => 'Atatürk Cad. 5'],
                'lines' => [['external_line_id' => 'a', 'sku' => 'PILOT-1', 'quantity' => 1], ['external_line_id' => 'b', 'sku' => 'YABANCI-9', 'quantity' => 2]]],
            ['external_order_id' => 'HB-2', 'status' => 'Open',
                'lines' => [['external_line_id' => 'c', 'sku' => 'PILOT-2', 'quantity' => 1]]],
        ];
        FakePilotAdapter::$products = [
            new RemoteProduct(externalId: 'R1', sku: 'PILOT-1'),
            new RemoteProduct(externalId: 'R2', sku: 'KANALDA-YALNIZ'),
        ];
        FakePilotAdapter::$remoteStock = ['EXT-1' => 10, 'EXT-2' => 3];

        $before = $this->rowCounts();

        $this->artisan('channels:pilot-check', ['connection' => $connection->id])
            ->expectsOutputToContain('✓ Bağlantı (AUTH) çalışıyor')
            ->expectsOutputToContain('Sipariş: 2/2 ayrıştı, satır eşleşmesi 2/3')
            ->expectsOutputToContain('Stok okuma: 2 ilanın 1 tanesi bizimle aynı')
            ->assertSuccessful();

        // Kanala yalnız okuma çağrıları gitti; yazma yöntemi çağrılsaydı patlardı.
        $this->assertEqualsCanonicalizing(['healthCheck', 'fetchOrders', 'fetchProductPage', 'fetchInventory'], FakePilotAdapter::$calls);
        // Bizim tarafta hiçbir şey yazılmadı.
        $this->assertSame($before, $this->rowCounts());

        $files = Storage::disk('local')->files('pilot-check');
        $this->assertCount(1, $files);
        $raw = Storage::disk('local')->get($files[0]);
        $report = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['YABANCI-9'], $report['orders']['unmatched_skus']);
        $this->assertSame(['KANALDA-YALNIZ'], $report['catalog']['sku_missing_here']);
        $this->assertSame([['sku' => 'PILOT-2', 'here' => 7, 'channel' => 3]], $report['inventory']['different']);
        $this->assertFalse($report['connection']['writes_enabled']['stock_sync']);

        // KVKK: alıcı adı ve adresi rapora girmez.
        $this->assertStringNotContainsString('Ayşe', $raw);
        $this->assertStringNotContainsString('Atatürk', $raw);
    }

    #[Test]
    public function a_failing_section_is_reported_without_stopping_the_others(): void
    {
        [, $connection] = $this->context();
        FakePilotAdapter::$ordersFail = new RuntimeException('HB 503');
        FakePilotAdapter::$remoteStock = ['EXT-1' => 10, 'EXT-2' => 7];

        $this->artisan('channels:pilot-check', ['connection' => $connection->id])
            ->expectsOutputToContain('orders: '.RuntimeException::class.': HB 503')
            ->expectsOutputToContain('✗ Sipariş okunamadı')
            ->expectsOutputToContain('✓ Stok okuma: 2 ilanın 2 tanesi bizimle aynı')
            ->assertSuccessful();
    }

    #[Test]
    public function a_broken_login_fails_the_command(): void
    {
        [, $connection] = $this->context();
        FakePilotAdapter::$healthy = false;

        $this->artisan('channels:pilot-check', ['connection' => $connection->id])
            ->expectsOutputToContain('✗ Bağlantı kurulamadı')
            ->assertFailed();
    }

    #[Test]
    public function an_unknown_connection_is_refused(): void
    {
        $this->artisan('channels:pilot-check', ['connection' => (string) new UuidV7])
            ->expectsOutputToContain('Bağlantı bulunamadı.')
            ->assertFailed();
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: ChannelConnection} */
    private function context(): array
    {
        $tenant = (new CreateTenant)->run(name: 'Pilot '.uniqid(), owner: User::factory()->create());

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'fake-pilot'], [
            'name' => 'Sahte pilot', 'kind' => 'marketplace', 'adapter_class' => FakePilotAdapter::class, 'is_active' => true,
        ]));

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()->create(['channel_type_code' => 'fake-pilot', 'status' => 'active', 'stock_sync_enabled' => false]));
        $warehouse = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse()->id);

        foreach ([['PILOT-1', 'EXT-1', 10], ['PILOT-2', 'EXT-2', 7]] as [$sku, $external, $qty]) {
            $this->asTenant($tenant, function () use ($connection, $warehouse, $sku, $external, $qty): void {
                $variant = Variant::factory()->create(['sku' => $sku]);
                Listing::factory()->create(['channel_connection_id' => $connection->id, 'variant_id' => $variant->id, 'external_id' => $external]);
                DB::transaction(function () use ($warehouse, $variant, $qty): void {
                    (new LockInventoryRows)->run($warehouse, [$variant->id]);
                    (new ApplyMovement)->run($warehouse, $variant->id, MovementType::IMPORT, $qty, MovementKey::import((string) new UuidV7), 'import_row');
                });
            });
        }

        return [$tenant, $connection];
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        return collect(['inbox_messages', 'orders', 'order_lines', 'sync_operations', 'listings', 'inventory_movements'])
            ->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()])
            ->all();
    }
}
