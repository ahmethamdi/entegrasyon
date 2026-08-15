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
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Inventory\Support\OutboundQuantity;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Orders\Actions\ApplyOrderCancellation;
use App\Domain\Orders\Actions\ApplyOrderReturn;
use App\Domain\Orders\Actions\IngestChannelOrder;
use App\Domain\Orders\Enums\OrderEventType;
use App\Domain\Orders\Enums\StockStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderEvent;
use App\Domain\Orders\Models\OrderLine;
use App\Domain\Orders\Support\CancellationEvent;
use App\Domain\Orders\Support\CancelledLine;
use App\Domain\Orders\Support\IncomingOrder;
use App\Domain\Orders\Support\IncomingOrderLine;
use App\Domain\Orders\Support\ReturnedLine;
use App\Domain\Orders\Support\ReturnEvent;
use App\Support\Tenancy\Exceptions\MissingTenantContextException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * Sipariş alımı, iade ve iptal — doğruluk kuralları.
 *
 * Mimari Karar Dokümanı v2.2 · §5 · Sipariş alımı, §1 · Kararlar 07, 10, 24.
 */
final class IngestChannelOrderTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    /** Sipariş stoğu düşürür ve satır APPLIED işaretlenir. */
    #[Test]
    public function order_reduces_stock_and_marks_line_applied(): void
    {
        [$tenant, $connection, $warehouseId, $variant] = $this->makeContext(stock: 10);

        $order = $this->ingest($tenant, $connection, $warehouseId, [[$variant->id, 3]]);

        $this->assertSame(7, $this->onHand($tenant, $warehouseId, $variant->id));

        $line = $this->asTenant($tenant, fn () => $order->lines()->firstOrFail());
        $this->assertSame(StockStatus::APPLIED, $line->stock_status);
        $this->assertNotNull($line->stock_applied_at);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * SİPARİŞ ASLA REDDEDİLMEZ — yetersiz stokta bakiye negatife düşer.
     *
     * Pazaryeri siparişi otoriter gerçektir. Satır OVERSOLD işaretlenir ve
     * ayrıca denetim olayı yazılır.
     */
    #[Test]
    public function insufficient_stock_still_accepts_order_and_marks_oversold(): void
    {
        [$tenant, $connection, $warehouseId, $variant] = $this->makeContext(stock: 2);

        $order = $this->ingest($tenant, $connection, $warehouseId, [[$variant->id, 5]]);

        // Kanonik durum kırpılmaz.
        $this->assertSame(-3, $this->onHand($tenant, $warehouseId, $variant->id));

        $line = $this->asTenant($tenant, fn () => $order->lines()->firstOrFail());
        $this->assertSame(StockStatus::OVERSOLD, $line->stock_status);

        // Fazla satış denetim olayı yazıldı.
        $event = $this->asTenant($tenant, fn () => OrderEvent::query()
            ->where('order_id', $order->id)
            ->where('type', OrderEventType::OVERSELL_DETECTED->value)
            ->firstOrFail());

        $this->assertSame(2, $event->payload['available_before']);
        $this->assertSame(-3, $event->payload['available_after']);

        // Denetim olayı external_ref TAŞIMAZ — kısmi tekillik onu kapsamaz.
        $this->assertNull($event->external_ref);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /** Fazla satışta kanala giden değer 0'a kırpılır, kanonik −3 kalır. */
    #[Test]
    public function oversold_outbound_quantity_is_clamped_but_canonical_is_not(): void
    {
        [$tenant, $connection, $warehouseId, $variant] = $this->makeContext(stock: 0);

        $this->ingest($tenant, $connection, $warehouseId, [[$variant->id, 3]]);

        $level = $this->asTenant($tenant, fn () => InventoryLevel::query()
            ->where('variant_id', $variant->id)
            ->firstOrFail());

        $this->assertSame(-3, $level->available);
        $this->assertSame(0, OutboundQuantity::forChannel($level));
        $this->assertDatabaseHas('inventory_levels', ['id' => $level->id, 'on_hand' => -3]);
    }

    /**
     * Aynı sipariş iki kez gelirse stok İKİ KEZ DÜŞMEZ.
     *
     * (channel_connection_id, external_id) tekilliği çıpadır.
     */
    #[Test]
    public function replaying_same_order_does_not_double_apply_stock(): void
    {
        [$tenant, $connection, $warehouseId, $variant] = $this->makeContext(stock: 10);

        $externalId = 'ORD-'.uniqid();

        $first = $this->ingest($tenant, $connection, $warehouseId, [[$variant->id, 4]], $externalId);
        $second = $this->ingest($tenant, $connection, $warehouseId, [[$variant->id, 4]], $externalId);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(6, $this->onHand($tenant, $warehouseId, $variant->id));

        $this->assertSame(1, $this->asTenant($tenant, fn () => Order::query()->count()));
        $this->assertSame(1, $this->asTenant($tenant, fn () => OrderLine::query()->count()));

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * Eşleşmemiş SKU siparişi kaybettirmez — satır PENDING kalır.
     *
     * Sipariş kaybetmek, stok tutarsızlığından daha kötüdür.
     */
    #[Test]
    public function unmatched_sku_is_recorded_without_stock_movement(): void
    {
        [$tenant, $connection, $warehouseId, $variant] = $this->makeContext(stock: 5);

        $incoming = new IncomingOrder(
            channelConnectionId: $connection->id,
            externalId: 'ORD-'.uniqid(),
            lines: [
                new IncomingOrderLine('l1', 'SKU-VAR', 'Eşleşen', 2, variantId: $variant->id),
                new IncomingOrderLine('l2', 'SKU-YOK', 'Eşleşmeyen', 3, variantId: null),
            ],
        );

        $order = $this->asTenant($tenant, fn () => (new IngestChannelOrder)->run($incoming, $warehouseId));

        $lines = $this->asTenant($tenant, fn () => $order->lines()->get()->keyBy('external_line_id'));

        $this->assertSame(StockStatus::APPLIED, $lines['l1']->stock_status);
        $this->assertSame(StockStatus::PENDING, $lines['l2']->stock_status);
        $this->assertNull($lines['l2']->variant_id);

        // Yalnızca eşleşen satır stok düşürdü.
        $this->assertSame(3, $this->onHand($tenant, $warehouseId, $variant->id));
    }

    /** Sipariş, satır ve outbox olayı aynı transaction'da yazılır. */
    #[Test]
    public function order_and_outbox_event_are_written_together(): void
    {
        [$tenant, $connection, $warehouseId, $variant] = $this->makeContext(stock: 5);

        $this->ingest($tenant, $connection, $warehouseId, [[$variant->id, 1]]);

        // Açılış IMPORT'u + satış → iki olay.
        $events = $this->asSystem(fn () => OutboxEvent::query()
            ->where('event_type', 'InventoryLevelChanged')
            ->get());

        $this->assertCount(2, $events);

        // Satış olayı kaynak bağlantıyı taşır — yankı bastırma için.
        $sale = $events->last();
        $this->assertSame($variant->id, $sale->payload['variant_id']);
    }

    /** İade stoğu geri getirir ve sayacı ilerletir. */
    #[Test]
    public function return_restores_stock_and_increments_counter(): void
    {
        [$tenant, $connection, $warehouseId, $variant] = $this->makeContext(stock: 10);

        $order = $this->ingest($tenant, $connection, $warehouseId, [[$variant->id, 4]]);
        $line = $this->asTenant($tenant, fn () => $order->lines()->firstOrFail());

        $this->applyReturn($tenant, $order, [[$line->id, 3]]);

        $this->assertSame(9, $this->onHand($tenant, $warehouseId, $variant->id));
        $this->assertSame(3, $this->asTenant($tenant, fn () => $line->fresh()->quantity_returned));

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * Aynı iade olayı iki kez gelirse stok İKİ KEZ GERİ GELMEZ.
     *
     * order_events (order_id, type, external_ref) kısmi tekilliği çıpadır.
     */
    #[Test]
    public function replaying_same_return_event_does_not_double_restore(): void
    {
        [$tenant, $connection, $warehouseId, $variant] = $this->makeContext(stock: 10);

        $order = $this->ingest($tenant, $connection, $warehouseId, [[$variant->id, 5]]);
        $line = $this->asTenant($tenant, fn () => $order->lines()->firstOrFail());

        $ref = 'RET-'.uniqid();

        $this->applyReturn($tenant, $order, [[$line->id, 2]], $ref);
        $second = $this->applyReturn($tenant, $order, [[$line->id, 2]], $ref);

        $this->assertNull($second, 'İkinci kez işlenen iade null dönmeli.');
        $this->assertSame(7, $this->onHand($tenant, $warehouseId, $variant->id));
        $this->assertSame(2, $this->asTenant($tenant, fn () => $line->fresh()->quantity_returned));

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * İKİ FARKLI kısmi iade ayrışır — biri diğerini yutmaz.
     *
     * Anahtar OLAY kimliğine bağlı olduğu için farklı external_ref'ler farklı
     * olay ve farklı hareket üretir. Satır kimliğine bağlansaydı ikinci kısmi
     * iade ON CONFLICT DO NOTHING ile sessizce yutulur ve stok geri gelmezdi.
     */
    #[Test]
    public function two_partial_returns_produce_two_movements(): void
    {
        [$tenant, $connection, $warehouseId, $variant] = $this->makeContext(stock: 10);

        $order = $this->ingest($tenant, $connection, $warehouseId, [[$variant->id, 5]]);
        $line = $this->asTenant($tenant, fn () => $order->lines()->firstOrFail());

        $this->applyReturn($tenant, $order, [[$line->id, 2]], 'RET-A');
        $this->applyReturn($tenant, $order, [[$line->id, 3]], 'RET-B');

        $this->assertSame(10, $this->onHand($tenant, $warehouseId, $variant->id));
        $this->assertSame(5, $this->asTenant($tenant, fn () => $line->fresh()->quantity_returned));

        $returns = $this->asTenant($tenant, fn () => InventoryMovement::query()
            ->where('type', MovementType::RETURN->value)
            ->count());

        $this->assertSame(2, $returns, 'İki kısmi iade iki ayrı hareket üretmeli.');

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /** İptal fazla satışı düzeltir — bakiye 0'a döner. */
    #[Test]
    public function cancellation_recovers_from_oversold(): void
    {
        [$tenant, $connection, $warehouseId, $variant] = $this->makeContext(stock: 0);

        $order = $this->ingest($tenant, $connection, $warehouseId, [[$variant->id, 2]]);
        $line = $this->asTenant($tenant, fn () => $order->lines()->firstOrFail());

        $this->assertSame(-2, $this->onHand($tenant, $warehouseId, $variant->id));

        $event = new CancellationEvent(
            orderId: $order->id,
            externalRef: 'CAN-'.uniqid(),
            lines: [new CancelledLine($line->id, 2)],
        );

        $this->asTenant($tenant, fn () => (new ApplyOrderCancellation)->run($event, $warehouseId));

        $this->assertSame(0, $this->onHand($tenant, $warehouseId, $variant->id));
        $this->assertSame(2, $this->asTenant($tenant, fn () => $line->fresh()->quantity_cancelled));

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /** İptal + iade toplamı sipariş miktarını aşamaz — DB kısıtı. */
    #[Test]
    public function cancelled_plus_returned_cannot_exceed_quantity(): void
    {
        [$tenant, $connection, $warehouseId, $variant] = $this->makeContext(stock: 10);

        $order = $this->ingest($tenant, $connection, $warehouseId, [[$variant->id, 3]]);
        $line = $this->asTenant($tenant, fn () => $order->lines()->firstOrFail());

        $this->expectException(QueryException::class);

        $this->asTenant($tenant, fn () => $line->forceFill([
            'quantity_returned' => 2,
            'quantity_cancelled' => 2,        // 2 + 2 > 3
        ])->save());
    }

    /**
     * Sipariş alımı kilidi TEK sorguda, variant_id sırasıyla alır.
     *
     * Bu iddia LockInventoryRows'un kendi testinden AYRIDIR: orada sınıfın
     * doğru davrandığı, burada sipariş yolunun onu gerçekten KULLANDIĞI
     * doğrulanır. Kilit bu yoldan çıkarılırsa çok kalemli eşzamanlı siparişler
     * ABBA deadlock üretir ve o hata yalnızca üretimde görülür.
     */
    #[Test]
    public function ingest_locks_all_variants_in_a_single_ordered_query(): void
    {
        [$tenant, $connection, $warehouseId] = $this->makeContext(stock: 5);

        $variantIds = $this->asTenant($tenant, fn () => collect(range(1, 3))
            ->map(fn () => Variant::factory()->create()->id)
            ->all());

        foreach ($variantIds as $variantId) {
            $this->seedStock($tenant, $warehouseId, $variantId, 5);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        // Varyantlar kasten karışık sırada verilir.
        $this->ingest($tenant, $connection, $warehouseId, [
            [$variantIds[2], 1],
            [$variantIds[0], 1],
            [$variantIds[1], 1],
        ]);

        $locking = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains(strtolower($sql), 'for update'),
        ));

        $this->assertCount(
            1,
            $locking,
            'Sipariş alımı kilidi TEK sorguda almalı — satır başına kilit deadlock penceresi açar.',
        );

        $sql = strtolower($locking[0]);

        $this->assertStringContainsString('order by', $sql);
        $this->assertStringContainsString('variant_id', $sql);
        $this->assertLessThan(
            strpos($sql, 'for update'),
            strpos($sql, 'order by'),
            'ORDER BY, FOR UPDATE\'ten önce gelmeli.',
        );
    }

    /**
     * İade yolu da kilidi TEK sorguda ve sıralı alır.
     *
     * Aynı gerekçe: kural sipariş alımına özgü değildir.
     */
    #[Test]
    public function return_locks_all_variants_in_a_single_ordered_query(): void
    {
        [$tenant, $connection, $warehouseId] = $this->makeContext(stock: 5);

        $variantIds = $this->asTenant($tenant, fn () => collect(range(1, 2))
            ->map(fn () => Variant::factory()->create()->id)
            ->all());

        foreach ($variantIds as $variantId) {
            $this->seedStock($tenant, $warehouseId, $variantId, 5);
        }

        $order = $this->ingest($tenant, $connection, $warehouseId, [
            [$variantIds[0], 2],
            [$variantIds[1], 2],
        ]);

        $lines = $this->asTenant($tenant, fn () => $order->lines()->get());

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->applyReturn($tenant, $order, $lines->map(fn ($l): array => [$l->id, 1])->all());

        $locking = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains(strtolower($sql), 'for update'),
        ));

        $this->assertCount(1, $locking, 'İade kilidi TEK sorguda almalı.');
        $this->assertStringContainsString('order by', strtolower($locking[0]));
    }

    /** Kiracı bağlamı yokken sipariş alınamaz. */
    #[Test]
    public function ingest_without_tenant_context_throws(): void
    {
        [, $connection, $warehouseId, $variant] = $this->makeContext(stock: 5);

        $this->expectException(MissingTenantContextException::class);

        (new IngestChannelOrder)->run(
            new IncomingOrder(
                channelConnectionId: $connection->id,
                externalId: 'ORD-X',
                lines: [new IncomingOrderLine('l1', 'SKU', 'T', 1, variantId: $variant->id)],
            ),
            $warehouseId,
        );
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: ChannelConnection, 2: string, 3: Variant} */
    private function makeContext(int $stock): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Sipariş '.uniqid(),
            owner: User::factory()->create(),
        );

        $warehouseId = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse()->id);

        $this->asSystem(fn () => ChannelType::query()->firstOrCreate(
            ['code' => 'woocommerce'],
            [
                'name' => 'WooCommerce',
                'kind' => 'storefront',
                'adapter_class' => 'App\\Domain\\Channels\\Adapters\\WooCommerceAdapter',
                'is_active' => true,
            ],
        ));

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()
            ->create(['channel_type_code' => 'woocommerce']));

        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

        if ($stock > 0) {
            $this->seedStock($tenant, $warehouseId, $variant->id, $stock);
        }

        return [$tenant, $connection, $warehouseId, $variant];
    }

    /** Açılış stoğu LEDGER üzerinden girer — invariant baştan korunur. */
    private function seedStock(Tenant $tenant, string $warehouseId, string $variantId, int $stock): void
    {
        $this->asTenant($tenant, fn () => DB::transaction(function () use ($warehouseId, $variantId, $stock): void {
            (new LockInventoryRows)->run($warehouseId, [$variantId]);

            (new ApplyMovement)->run(
                warehouseId: $warehouseId,
                variantId: $variantId,
                type: MovementType::IMPORT,
                quantity: $stock,
                idempotencyKey: MovementKey::import((string) new UuidV7),
                sourceType: 'import_row',
            );
        }));
    }

    /** @param list<array{0: string, 1: int}> $lines [variantId, quantity] */
    private function ingest(
        Tenant $tenant,
        ChannelConnection $connection,
        string $warehouseId,
        array $lines,
        ?string $externalId = null,
    ): Order {
        $incoming = new IncomingOrder(
            channelConnectionId: $connection->id,
            externalId: $externalId ?? 'ORD-'.uniqid(),
            lines: array_map(
                static fn (array $pair, int $i): IncomingOrderLine => new IncomingOrderLine(
                    externalLineId: 'line-'.$i,
                    sku: 'SKU-'.$i,
                    title: 'Ürün '.$i,
                    quantity: $pair[1],
                    variantId: $pair[0],
                ),
                $lines,
                array_keys($lines),
            ),
            placedAt: now(),
        );

        return $this->asTenant($tenant, fn () => (new IngestChannelOrder)->run($incoming, $warehouseId));
    }

    /** @param list<array{0: string, 1: int}> $lines [orderLineId, quantity] */
    private function applyReturn(
        Tenant $tenant,
        Order $order,
        array $lines,
        ?string $externalRef = null,
    ): ?OrderEvent {
        $event = new ReturnEvent(
            orderId: $order->id,
            externalRef: $externalRef ?? 'RET-'.uniqid(),
            lines: array_map(
                static fn (array $pair): ReturnedLine => new ReturnedLine($pair[0], $pair[1]),
                $lines,
            ),
        );

        return $this->asTenant($tenant, fn () => (new ApplyOrderReturn)->run($event));
    }

    private function onHand(Tenant $tenant, string $warehouseId, string $variantId): int
    {
        return (int) $this->asSystem(fn () => DB::table('inventory_levels')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouseId)
            ->where('variant_id', $variantId)
            ->value('on_hand'));
    }
}
