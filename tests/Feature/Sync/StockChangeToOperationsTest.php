<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

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
use App\Domain\Messaging\Jobs\ConsumeOutboxEvent;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Messaging\Support\OutboxRelay;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * Uçtan uca zincir: stok hareketi → outbox → relay → fan-out → operasyonlar.
 *
 * Mimari Karar Dokümanı v2.2 · §6, §8, §19 · dikey dilim.
 *
 * Bu test parçaların birbirine bağlandığını doğrular. Tek tek her parça
 * kendi dosyasında sınanıyor; buradaki iddia zincirin kopmadığıdır —
 * ApplyMovement'ın yazdığı yük, tüketicinin okuduğu yükle aynı biçimde mi?
 */
final class StockChangeToOperationsTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    /**
     * Bir satış üç kanalda üç senkron operasyonu doğurur.
     */
    #[Test]
    public function a_sale_produces_one_sync_operation_per_live_listing(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $this->listVariantOn($tenant, $variant, ['woocommerce', 'trendyol', 'shopify']);

        // (1) Stok hareketi — ledger + projeksiyon + outbox aynı transaction'da.
        $this->asTenant($tenant, fn () => DB::transaction(function () use ($warehouseId, $variant): void {
            (new LockInventoryRows)->run($warehouseId, [$variant->id]);

            (new ApplyMovement)->run(
                warehouseId: $warehouseId,
                variantId: $variant->id,
                type: MovementType::IMPORT,
                quantity: 10,
                idempotencyKey: MovementKey::import((string) new UuidV7),
                sourceType: 'import_row',
            );
        }));

        $this->asTenant($tenant, fn () => DB::transaction(function () use ($warehouseId, $variant): void {
            (new LockInventoryRows)->run($warehouseId, [$variant->id]);

            (new ApplyMovement)->run(
                warehouseId: $warehouseId,
                variantId: $variant->id,
                type: MovementType::SALE,
                quantity: 3,
                idempotencyKey: MovementKey::sale((string) new UuidV7),
                sourceType: 'order_line',
            );
        }));

        // İki hareket, iki olay.
        $this->assertSame(2, $this->asSystem(fn () => OutboxEvent::query()->count()));

        // (2) Relay yayınlar — kuyruk yerine doğrudan tüketiciye köprüler.
        $published = $this->runRelayInline();

        $this->assertSame(2, $published);

        // (3) Fan-out: her olay üç listing'e yayıldı.
        $operations = $this->asTenant($tenant, fn () => SyncOperation::query()
            ->where('operation_type', 'INVENTORY_PUSH')
            ->get());

        // İlk olay (IMPORT v1) üç operasyon açar; ikinci olay (SALE v2) daha
        // yeni sürüm taşıdığı için ilkleri geçersiz kılar ve üç yeni açar.
        $this->assertCount(6, $operations);

        $active = $operations->where('status', SyncOperationStatus::PENDING);
        $superseded = $operations->where('status', SyncOperationStatus::SUPERSEDED);

        $this->assertCount(3, $active, 'Son sürüm için üç bekleyen operasyon.');
        $this->assertCount(3, $superseded, 'Eski sürümün operasyonları geçersiz kılınmalı.');

        // Bekleyenlerin hepsi SON sürümü taşır ve üç ayrı kanala dağılmıştır.
        $this->assertCount(1, $active->pluck('entity_version')->unique());
        $this->assertCount(3, $active->pluck('channel_connection_id')->unique());

        // (4) Her olay tüketilmiş damgalandı.
        $this->assertSame(0, $this->asSystem(fn () => OutboxEvent::query()
            ->whereNull('consumed_at')
            ->count()));

        // (5) Kanonik durum bozulmadı.
        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * Fazla satış zinciri kırmaz: kanonik −1 kalır, operasyonlar yine açılır.
     */
    #[Test]
    public function oversold_stock_still_fans_out_with_canonical_negative(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $this->listVariantOn($tenant, $variant, ['woocommerce']);

        $this->asTenant($tenant, fn () => DB::transaction(function () use ($warehouseId, $variant): void {
            (new LockInventoryRows)->run($warehouseId, [$variant->id]);

            (new ApplyMovement)->run(
                warehouseId: $warehouseId,
                variantId: $variant->id,
                type: MovementType::SALE,
                quantity: 1,                    // sıfır stokta satış
                idempotencyKey: MovementKey::sale((string) new UuidV7),
                sourceType: 'order_line',
            );
        }));

        $this->runRelayInline();

        $this->assertSame(1, $this->asTenant($tenant, fn () => SyncOperation::query()->count()));

        // Olay yükü KANONİK değeri taşır; kırpma giden dönüşümde yapılır.
        $event = $this->asSystem(fn () => OutboxEvent::query()->firstOrFail());

        $this->assertSame(-1, $event->payload['available']);
        $this->assertSame(-1, $event->payload['on_hand']);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    // ---------------------------------------------------------------- yardımcılar

    /**
     * Relay'i çalıştırır ve yayınlanan olayları AYNI süreçte tüketir.
     *
     * Kuyruk yerine doğrudan çağrı: bu test zincirin bağlandığını doğruluyor,
     * Horizon'un çalıştığını değil.
     */
    private function runRelayInline(): int
    {
        $jobs = [];

        $relay = new OutboxRelay(
            dispatcher: function (string $tenantId, string $eventId) use (&$jobs): void {
                $jobs[] = new ConsumeOutboxEvent($tenantId, $eventId);
            },
        );

        $published = $relay->run();

        foreach ($jobs as $job) {
            $job->handle();
        }

        return $published;
    }

    /** @return array{0: Tenant, 1: Variant, 2: string} */
    private function makeContext(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Zincir '.uniqid(),
            owner: User::factory()->create(),
        );

        $warehouseId = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse()->id);
        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

        return [$tenant, $variant, $warehouseId];
    }

    /** @param  list<string>  $channelTypeCodes */
    private function listVariantOn(Tenant $tenant, Variant $variant, array $channelTypeCodes): void
    {
        $this->asTenant($tenant, function () use ($variant, $channelTypeCodes): void {
            foreach ($channelTypeCodes as $code) {
                $this->asSystem(fn () => ChannelType::query()->firstOrCreate(
                    ['code' => $code],
                    [
                        'name' => ucfirst($code),
                        'kind' => 'marketplace',
                        'adapter_class' => 'App\\Domain\\Channels\\Adapters\\'.ucfirst($code).'Adapter',
                        'is_active' => true,
                    ],
                ));

                Listing::factory()->create([
                    'channel_connection_id' => ChannelConnection::factory()
                        ->create(['channel_type_code' => $code])->id,
                    'variant_id' => $variant->id,
                ]);
            }
        });
    }
}
