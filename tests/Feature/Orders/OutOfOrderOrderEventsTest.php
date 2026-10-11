<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

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
use App\Domain\Messaging\Actions\IngestInboxMessage;
use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Messaging\Support\InboxRetrySchedule;
use App\Domain\Orders\Models\OrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\Support\Channels\FakeOrderAdapter;
use Tests\TestCase;

/**
 * Sırasız sipariş olayları — mimari denetim P0-1.
 *
 * İptal/iade siparişten ÖNCE gelebilir. Önceden olay "işlendi" sayılıp
 * kayboluyordu: sipariş sonra gelir, stok düşer, iptal hiç uygulanmazdı.
 * Artık olay ertelenir, sipariş gelince uyandırılır; her yol idempotenttir.
 *
 * Her senaryonun sonunda defter = projeksiyon denetlenir.
 */
final class OutOfOrderOrderEventsTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeOrderAdapter::reset();
    }

    #[Test]
    public function order_then_cancellation_restores_stock_once(): void
    {
        [$tenant, $connection, $variant, $warehouse] = $this->context(10);

        $this->process($tenant, $this->record($connection, $this->created('ORD-1', $variant, 4)));
        $this->assertSame(6, $this->onHand($tenant, $warehouse, $variant));

        $this->process($tenant, $this->record($connection, $this->cancelled('ORD-1', 4, 'CAN-1')));

        $this->assertSame(10, $this->onHand($tenant, $warehouse, $variant));
        $this->assertLedgerMatchesProjection($tenant->id, $warehouse, $variant->id);
    }

    #[Test]
    public function cancellation_before_order_is_honoured_when_the_order_arrives(): void
    {
        [$tenant, $connection, $variant, $warehouse] = $this->context(10);

        $cancel = $this->record($connection, $this->cancelled('ORD-2', 4, 'CAN-2'));
        $this->process($tenant, $cancel);

        $this->assertSame('pending', $cancel->fresh()->status, 'İptal kaybolmamalı, beklemeli.');
        $this->assertSame('ORD-2', $cancel->fresh()->awaiting_order_ref);
        $this->assertSame(10, $this->onHand($tenant, $warehouse, $variant));

        // Sipariş gelir: stok düşer ve bekleyen iptal HEMEN uyandırılır
        // (senkron kuyruk) — iptal stoğu geri getirir.
        $this->process($tenant, $this->record($connection, $this->created('ORD-2', $variant, 4)));

        $this->assertSame('processed', $cancel->fresh()->status);
        $this->assertNull($cancel->fresh()->awaiting_order_ref);
        $this->assertSame(10, $this->onHand($tenant, $warehouse, $variant));
        $this->assertSame(4, $this->line($tenant)->quantity_cancelled);
        $this->assertLedgerMatchesProjection($tenant->id, $warehouse, $variant->id);
    }

    #[Test]
    public function order_then_return_restocks_once(): void
    {
        [$tenant, $connection, $variant, $warehouse] = $this->context(10);

        $this->process($tenant, $this->record($connection, $this->created('ORD-3', $variant, 4)));
        $this->process($tenant, $this->record($connection, $this->returned('ORD-3', 2, 'RET-3')));

        $this->assertSame(8, $this->onHand($tenant, $warehouse, $variant));
        $this->assertSame(2, $this->line($tenant)->quantity_returned);
        $this->assertLedgerMatchesProjection($tenant->id, $warehouse, $variant->id);
    }

    #[Test]
    public function return_before_order_is_not_lost(): void
    {
        [$tenant, $connection, $variant, $warehouse] = $this->context(10);

        $return = $this->record($connection, $this->returned('ORD-4', 2, 'RET-4'));
        $this->process($tenant, $return);

        $this->assertSame('pending', $return->fresh()->status);
        $this->assertSame(10, $this->onHand($tenant, $warehouse, $variant));

        $this->process($tenant, $this->record($connection, $this->created('ORD-4', $variant, 4)));

        $this->assertSame('processed', $return->fresh()->status);
        $this->assertSame(8, $this->onHand($tenant, $warehouse, $variant));
        $this->assertSame(2, $this->line($tenant)->quantity_returned);
        $this->assertLedgerMatchesProjection($tenant->id, $warehouse, $variant->id);
    }

    /** Aynı iptal iki farklı teslimatla (iki inbox satırı) gelir — tek etki. */
    #[Test]
    public function duplicate_cancellation_is_harmless(): void
    {
        [$tenant, $connection, $variant, $warehouse] = $this->context(10);

        $this->process($tenant, $this->record($connection, $this->created('ORD-5', $variant, 4)));
        $this->process($tenant, $this->record($connection, $this->cancelled('ORD-5', 4, 'CAN-5')));
        $this->process($tenant, $this->record($connection, $this->cancelled('ORD-5', 4, 'CAN-5')));
        // Farklı olay kimliğiyle gelen ikinci iptal de kalan adete kırpılır.
        $this->process($tenant, $this->record($connection, $this->cancelled('ORD-5', 4, 'CAN-5-BIS')));

        $this->assertSame(10, $this->onHand($tenant, $warehouse, $variant));
        $this->assertSame(4, $this->line($tenant)->quantity_cancelled);
        $this->assertLedgerMatchesProjection($tenant->id, $warehouse, $variant->id);
    }

    /** Aynı iade iki kez gelir, biri sipariş yokken — tek etki. */
    #[Test]
    public function duplicate_return_is_harmless(): void
    {
        [$tenant, $connection, $variant, $warehouse] = $this->context(10);

        $early = $this->record($connection, $this->returned('ORD-6', 2, 'RET-6'));
        $this->process($tenant, $early);

        $this->process($tenant, $this->record($connection, $this->created('ORD-6', $variant, 4)));
        $this->process($tenant, $this->record($connection, $this->returned('ORD-6', 2, 'RET-6')));

        $this->assertSame(8, $this->onHand($tenant, $warehouse, $variant));
        $this->assertSame(2, $this->line($tenant)->quantity_returned);
        $this->assertLedgerMatchesProjection($tenant->id, $warehouse, $variant->id);
    }

    /**
     * EŞZAMANLILIK — iptal işlenirken sipariş gelir.
     *
     * İptalin işleyicisi siparişi göremeden ertelemeye girer ve satır o an
     * `processing`'tir; siparişin uyandırması onu ATLAR. İptal kendi takvimiyle
     * yine işlenir, tek kez. (Aynı mesajı iki işleyicinin alamaması
     * `ProcessInboxMessage` koşullu sahiplenmesiyle ayrıca sınanır.)
     */
    #[Test]
    public function a_cancellation_in_flight_when_the_order_arrives_is_applied_once(): void
    {
        [$tenant, $connection, $variant, $warehouse] = $this->context(10);

        $cancel = $this->record($connection, $this->cancelled('ORD-7', 4, 'CAN-7'));

        // İşleyici satırı almış ama henüz bitirmemiş.
        $this->asSystem(fn () => DB::table('inbox_messages')->where('id', $cancel->id)->update(['status' => 'processing']));

        $this->process($tenant, $this->record($connection, $this->created('ORD-7', $variant, 4)));

        $this->assertSame('processing', $cancel->fresh()->status, 'Uyandırma işlenen satıra dokunmamalı.');
        $this->assertSame(6, $this->onHand($tenant, $warehouse, $variant));

        // İşleyici biter ve satırı geri bırakır; ikinci bir işleyici aynı anda
        // kuyruktan alır — koşullu sahiplenme yalnız birini seçer.
        $this->asSystem(fn () => DB::table('inbox_messages')->where('id', $cancel->id)->update(['status' => 'pending']));
        $this->process($tenant, $cancel);
        $this->process($tenant, $cancel);

        $this->assertSame('processed', $cancel->fresh()->status);
        $this->assertSame(10, $this->onHand($tenant, $warehouse, $variant));
        $this->assertSame(4, $this->line($tenant)->quantity_cancelled);
        $this->assertLedgerMatchesProjection($tenant->id, $warehouse, $variant->id);
    }

    /** Hata bütçesi tükenen iptal yeniden oynatılır; iki kez oynatmak zararsız. */
    #[Test]
    public function a_failed_cancellation_replays_once(): void
    {
        [$tenant, $connection, $variant, $warehouse] = $this->context(10);

        $this->process($tenant, $this->record($connection, $this->created('ORD-8', $variant, 4)));

        $cancel = $this->record($connection, $this->cancelled('ORD-8', 4, 'CAN-8'));
        FakeOrderAdapter::$failuresBeforeSuccess = ProcessInboxMessage::MAX_ATTEMPTS;

        for ($i = 0; $i < ProcessInboxMessage::MAX_ATTEMPTS; $i++) {
            $this->travel(max(InboxRetrySchedule::ERROR_BACKOFF_SECONDS) + 1)->seconds();

            try {
                $this->process($tenant, $cancel);
            } catch (\RuntimeException) {
            }
        }

        $this->assertSame('failed', $cancel->fresh()->status);
        $this->assertSame(6, $this->onHand($tenant, $warehouse, $variant));

        $this->artisan('inbox:replay-failed', ['--connection' => $connection->id])->assertSuccessful();

        $this->assertSame('processed', $cancel->fresh()->status, 'Yeniden oynatma (senkron kuyruk) iptali uygulamalı.');
        $this->assertSame(10, $this->onHand($tenant, $warehouse, $variant));

        // İkinci oynatma: `processed` satıra dokunmaz; elle `failed`'a çekilse
        // bile iptal ikinci kez stok üretmez.
        $this->asSystem(fn () => DB::table('inbox_messages')->where('id', $cancel->id)->update(['status' => 'failed']));
        $this->artisan('inbox:replay-failed')->assertSuccessful();

        $this->assertSame(10, $this->onHand($tenant, $warehouse, $variant));
        $this->assertSame(4, $this->line($tenant)->quantity_cancelled);
        $this->assertLedgerMatchesProjection($tenant->id, $warehouse, $variant->id);
    }

    /** Pencere dolunca `expired` (alarm değil); sipariş sonra gelirse yine uygulanır. */
    #[Test]
    public function an_expired_deferral_is_still_woken_by_a_late_order(): void
    {
        [$tenant, $connection, $variant, $warehouse] = $this->context(10);

        $cancel = $this->record($connection, $this->cancelled('ORD-9', 4, 'CAN-9'));
        $this->process($tenant, $cancel);

        $this->travel(InboxRetrySchedule::DEFER_WINDOW_HOURS + 1)->hours();
        $this->process($tenant, $cancel);

        $this->assertSame('expired', $cancel->fresh()->status);

        $this->process($tenant, $this->record($connection, $this->created('ORD-9', $variant, 4)));

        $this->assertSame('processed', $cancel->fresh()->status);
        $this->assertSame(10, $this->onHand($tenant, $warehouse, $variant));
        $this->assertLedgerMatchesProjection($tenant->id, $warehouse, $variant->id);
    }

    /** Bağlantıdan ÖNCE verilmiş siparişin iptali beklenmez (A14 kuralı). */
    #[Test]
    public function a_cancellation_for_a_pre_connection_order_is_not_deferred(): void
    {
        [$tenant, $connection] = $this->context(10);

        $this->asSystem(fn () => DB::table('channel_connections')
            ->where('id', $connection->id)
            ->update(['connected_at' => now()->addHour()]));

        $cancel = $this->record($connection, $this->cancelled('ORD-ESKI', 1, 'CAN-ESKI'));
        $this->process($tenant, $cancel);

        $this->assertSame('processed', $cancel->fresh()->status);
        $this->assertNull($cancel->fresh()->awaiting_order_ref);
    }

    /** Ertelenmiş ve vakti gelmemiş mesaj kurtarma taramasına girmez. */
    #[Test]
    public function recovery_does_not_redispatch_a_deferred_message_before_its_time(): void
    {
        Queue::fake();

        [$tenant, $connection] = $this->context(10);

        $cancel = $this->record($connection, $this->cancelled('ORD-10', 1, 'CAN-10'));
        $this->process($tenant, $cancel);

        $this->travel(5)->minutes();
        $this->asSystem(fn () => DB::table('inbox_messages')->where('id', $cancel->id)->update(['next_attempt_at' => now()->addMinutes(10)]));

        $this->artisan('inbox:recover')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->travel(11)->minutes();
        $this->artisan('inbox:recover')->assertSuccessful();
        Queue::assertPushed(ProcessInboxMessage::class, 1);
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: ChannelConnection, 2: Variant, 3: string} */
    private function context(int $stock): array
    {
        $tenant = (new CreateTenant)->run(name: 'Sıra '.uniqid(), owner: User::factory()->create());

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'fake-orders'],
            ['name' => 'Sahte Sipariş Kanalı', 'kind' => 'marketplace', 'adapter_class' => FakeOrderAdapter::class, 'is_active' => true],
        ));

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()->create(['channel_type_code' => 'fake-orders']));
        $warehouse = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse()->id);
        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

        $this->asTenant($tenant, fn () => DB::transaction(function () use ($warehouse, $variant, $stock): void {
            (new LockInventoryRows)->run($warehouse, [$variant->id]);
            (new ApplyMovement)->run(
                warehouseId: $warehouse,
                variantId: $variant->id,
                type: MovementType::IMPORT,
                quantity: $stock,
                idempotencyKey: MovementKey::import((string) new UuidV7),
                sourceType: 'import_row',
            );
        }));

        return [$tenant, $connection, $variant, $warehouse];
    }

    /** @return array<string, mixed> */
    private function created(string $order, Variant $variant, int $quantity): array
    {
        return [
            'type' => 'created',
            'external_order_id' => $order,
            'lines' => [['external_line_id' => 'l1', 'sku' => $variant->sku, 'quantity' => $quantity]],
        ];
    }

    /** @return array<string, mixed> */
    private function cancelled(string $order, int $quantity, string $ref): array
    {
        return [
            'type' => 'cancelled',
            'external_order_id' => $order,
            'external_ref' => $ref,
            'lines' => [['external_line_id' => 'l1', 'quantity' => $quantity]],
        ];
    }

    /** @return array<string, mixed> */
    private function returned(string $order, int $quantity, string $ref): array
    {
        return [
            'type' => 'returned',
            'external_order_id' => $order,
            'external_ref' => $ref,
            'lines' => [['external_line_id' => 'l1', 'quantity' => $quantity]],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function record(ChannelConnection $connection, array $payload): InboxMessage
    {
        return (new IngestInboxMessage)->run(
            connection: $connection,
            source: 'webhook',
            externalEventId: 'DLV-'.uniqid('', true),
            eventType: (string) $payload['type'],
            payload: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function process(Tenant $tenant, InboxMessage $message): void
    {
        (new ProcessInboxMessage($tenant->id, $message->id))->handle();
    }

    private function line(Tenant $tenant): OrderLine
    {
        return $this->asTenant($tenant, fn () => OrderLine::query()->firstOrFail());
    }

    private function onHand(Tenant $tenant, string $warehouse, Variant $variant): int
    {
        return (int) $this->asSystem(fn () => DB::table('inventory_levels')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse)
            ->where('variant_id', $variant->id)
            ->value('on_hand'));
    }
}
