<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciliation;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Messaging\Actions\IngestInboxMessage;
use App\Domain\Orders\Support\PollChannelOrders;
use App\Domain\Reconciliation\Actions\ReconcileConnection;
use App\Domain\Reconciliation\Enums\ReconciliationScope;
use App\Domain\Reconciliation\Models\ReconciliationItem;
use App\Domain\Reconciliation\Models\ReconciliationRun;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Channels\ProgrammableInventoryAdapter;
use Tests\TestCase;

/**
 * Mutabakat: kanal stoğu BEKLENENDEN DÜŞÜK — alınmamış satış kapısı (P0-2).
 *
 * TEMEL KURAL: bilinmeyen düşük kanal stoğu ASLA fazla satış riski
 * yaratmamalı. Önceden fark ilk turda yukarı "onarılıyordu": kanalda az önce
 * satılmış ama siparişi bize ulaşmamış ürün yeniden satışa açılırdı.
 */
final class UnrecordedSaleGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ProgrammableInventoryAdapter::reset();
        Queue::fake();
    }

    #[Test]
    public function reconciliation_does_not_overwrite_an_unreceived_channel_sale(): void
    {
        [$tenant, $connection, $listing] = $this->context(local: 12, remote: 9);

        $run = $this->reconcile($tenant, $connection);

        $this->assertSame('SUSPECTED_SALE', $this->item($tenant, $listing)->status);
        $this->assertSame(0, $run->drift_count, 'Bekleyen kalem onarım sayılmamalı.');
        $this->assertSame(0, $this->repairs($tenant), 'Kanalı yukarı çeken onarım AÇILMAMALI.');
    }

    /** Sipariş alımı yetişti, değer aynı, bekleyen inbox yok → onarım. */
    #[Test]
    public function a_confirmed_discrepancy_is_repaired_once_order_intake_has_caught_up(): void
    {
        [$tenant, $connection, $listing] = $this->context(local: 12, remote: 9);

        $this->reconcile($tenant, $connection);
        $this->travel(6)->minutes();
        $this->markOrdersPolled($connection);

        $this->reconcile($tenant, $connection);

        $this->assertSame('REPAIR_QUEUED', $this->item($tenant, $listing)->status);
        $this->assertSame(1, $this->repairs($tenant));
    }

    #[Test]
    public function an_unprocessed_inbox_message_keeps_the_hold(): void
    {
        [$tenant, $connection, $listing] = $this->context(local: 12, remote: 9);

        $this->reconcile($tenant, $connection);
        $this->travel(6)->minutes();
        $this->markOrdersPolled($connection);

        // Gelmiş ama henüz işlenmemiş bir sipariş farkı açıklayabilir.
        app(IngestInboxMessage::class)->run($connection, 'polling', 'TY-1:Created', 'order.polled', '{"id":1}');

        $this->reconcile($tenant, $connection);

        $this->assertSame('SUSPECTED_SALE', $this->item($tenant, $listing)->status);
        $this->assertSame(0, $this->repairs($tenant));
    }

    /** İlk gözlemden sonra sipariş turu tamamlanmadıysa beklenir. */
    #[Test]
    public function a_stale_order_cursor_keeps_the_hold(): void
    {
        [$tenant, $connection, $listing] = $this->context(local: 12, remote: 9);

        $this->markOrdersPolled($connection);
        $this->travel(6)->minutes();
        $this->reconcile($tenant, $connection);
        $this->travel(6)->minutes();

        $this->reconcile($tenant, $connection);

        $this->assertSame('SUSPECTED_SALE', $this->item($tenant, $listing)->status);
    }

    /** Sipariş alımını kanıtlayacak imleç yoksa (ör. Woo) onarım hiç açılmaz. */
    #[Test]
    public function without_an_order_intake_watermark_the_channel_is_never_raised(): void
    {
        [$tenant, $connection, $listing] = $this->context(local: 12, remote: 9);

        for ($i = 0; $i < 4; $i++) {
            $this->reconcile($tenant, $connection);
            $this->travel(10)->minutes();
        }

        $this->assertSame('SUSPECTED_SALE', $this->item($tenant, $listing)->status);
        $this->assertSame(0, $this->repairs($tenant));
    }

    /** Değer turlar arasında yine düştü — yeni bir satış olabilir, sayaç baştan. */
    #[Test]
    public function a_changed_remote_value_restarts_the_hold(): void
    {
        [$tenant, $connection, $listing] = $this->context(local: 12, remote: 9);

        $this->reconcile($tenant, $connection);
        $this->travel(6)->minutes();
        $this->markOrdersPolled($connection);
        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 8);

        $this->reconcile($tenant, $connection);

        $this->assertSame('SUSPECTED_SALE', $this->item($tenant, $listing)->status);
        $this->assertSame(0, $this->repairs($tenant));
    }

    /** Sipariş gelince fark kendiliğinden kapanır — onarım gerekmez. */
    #[Test]
    public function the_hold_resolves_when_the_missing_order_arrives(): void
    {
        [$tenant, $connection, $listing, $variant] = $this->context(local: 12, remote: 9);

        $this->reconcile($tenant, $connection);

        $this->asTenant($tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: $this->warehouse($tenant)->id,
            variantId: $variant->id,
            type: MovementType::SALE,
            quantity: 3,
            idempotencyKey: 'sale:gec-gelen',
            sourceType: 'test',
        ));

        $this->reconcile($tenant, $connection);

        $this->assertSame('MATCHED', $this->item($tenant, $listing)->status);
        $this->assertSame(0, $this->repairs($tenant));
    }

    /** Ters yön (kanal YÜKSEK = fazla satış riski) bugünkü gibi hemen onarılır. */
    #[Test]
    public function a_higher_channel_stock_is_repaired_immediately(): void
    {
        [$tenant, $connection, $listing] = $this->context(local: 12, remote: 15);

        $this->reconcile($tenant, $connection);

        $this->assertSame('REPAIR_QUEUED', $this->item($tenant, $listing)->status);
        $this->assertSame(1, $this->repairs($tenant));
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: ChannelConnection, 2: Listing, 3: Variant} */
    private function context(int $local, int $remote): array
    {
        $tenant = (new CreateTenant)->run(name: 'Kapı '.uniqid(), owner: User::factory()->create());

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'woocommerce'], [
            'name' => 'Woocommerce', 'kind' => 'marketplace',
            'adapter_class' => ProgrammableInventoryAdapter::class, 'is_active' => true,
        ]));

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()->create(['channel_type_code' => 'woocommerce']));
        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());
        $listing = $this->asTenant($tenant, fn () => Listing::factory()->create([
            'channel_connection_id' => $connection->id,
            'variant_id' => $variant->id,
            'external_id' => '10',
            'lifecycle_status' => 'live',
        ]));

        $this->asTenant($tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: $this->warehouse($tenant)->id,
            variantId: $variant->id,
            type: MovementType::IMPORT,
            quantity: $local,
            idempotencyKey: 'import:'.$variant->id,
            sourceType: 'test',
        ));

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', $remote);

        return [$tenant, $connection, $listing, $variant];
    }

    private function markOrdersPolled(ChannelConnection $connection): void
    {
        $this->asSystem(fn () => DB::table('channel_connections')->where('id', $connection->id)->update([
            'settings' => json_encode([PollChannelOrders::CURSOR_KEY => now()->toIso8601String()]),
        ]));
    }

    private function reconcile(Tenant $tenant, ChannelConnection $connection): ReconciliationRun
    {
        return $this->asTenant($tenant, fn () => app(ReconcileConnection::class)->run(
            connection: $connection->fresh(),
            scope: ReconciliationScope::COLD,
        ));
    }

    private function item(Tenant $tenant, Listing $listing): ReconciliationItem
    {
        return $this->asTenant($tenant, fn () => ReconciliationItem::query()
            ->where('listing_id', $listing->id)
            ->orderByDesc('id')
            ->firstOrFail());
    }

    private function repairs(Tenant $tenant): int
    {
        return $this->asTenant($tenant, fn (): int => SyncOperation::query()->count());
    }

    private function warehouse(Tenant $tenant): Warehouse
    {
        return $this->asTenant($tenant, fn () => Warehouse::query()->where('is_default', true)->firstOrFail());
    }
}
