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
use App\Domain\Orders\Actions\ApplyOrderCancellation;
use App\Domain\Orders\Actions\ApplyOrderReturn;
use App\Domain\Orders\Actions\IngestChannelOrder;
use App\Domain\Orders\Actions\ResolveUnmatchedOrderLines;
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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * A12 · SKU'su sonradan kataloğa giren sipariş satırı.
 *
 * Önceden: satır `variant_id = NULL / PENDING` kalır, stok SONSUZA KADAR
 * düşülmezdi. Üstelik eşleşmemiş satırın iptal/iade sayacı ilerlemediği için
 * sonradan bağlamak iptal edilmiş adedi de satış diye düşerdi.
 */
final class ResolveUnmatchedOrderLinesTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    /** SKU sonradan düzeltilirse (varyant satıştan önce vardı) stok düşülür. */
    #[Test]
    public function line_is_matched_and_stock_deducted_when_sku_is_fixed_later(): void
    {
        [$tenant, $connection, $warehouseId] = $this->makeContext();
        $variant = $this->makeVariant($tenant, $warehouseId, 'ESKI-SKU', stock: 10, createdAt: now()->subDay());

        $order = $this->ingestUnmatched($tenant, $connection, $warehouseId, 'YENI-SKU', 3);

        $this->asTenant($tenant, fn () => $variant->forceFill(['sku' => 'YENI-SKU'])->save());

        $this->assertSame(1, $this->resolve($tenant));

        $line = $this->line($tenant, $order);
        $this->assertSame($variant->id, $line->variant_id);
        $this->assertSame(StockStatus::APPLIED, $line->stock_status);
        $this->assertNotNull($line->stock_applied_at);
        $this->assertSame(7, $this->onHand($tenant, $warehouseId, $variant->id));

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * Varyant satıştan SONRA yaratıldıysa stok DÜŞÜLMEZ — açılış stoğunda
     * zaten sayılmıştır (kanaldan içe aktarılan stok o satışı düşmüştür).
     */
    #[Test]
    public function line_sold_before_variant_existed_is_linked_but_not_deducted(): void
    {
        [$tenant, $connection, $warehouseId] = $this->makeContext();

        $order = $this->ingestUnmatched($tenant, $connection, $warehouseId, 'SONRA-GELEN', 2, placedAt: now()->subHour());

        $variant = $this->makeVariant($tenant, $warehouseId, 'SONRA-GELEN', stock: 5, createdAt: now());

        $this->assertSame(1, $this->resolve($tenant));

        $line = $this->line($tenant, $order);
        $this->assertSame($variant->id, $line->variant_id);
        $this->assertSame(StockStatus::SKIPPED, $line->stock_status);
        $this->assertNull($line->stock_applied_at);
        $this->assertSame(5, $this->onHand($tenant, $warehouseId, $variant->id));
        $this->assertSame(0, $this->saleMovements($line));
    }

    /** Eşleşmeden önce gelen iptal sayaçta tutulur ve düşülmez. */
    #[Test]
    public function cancellation_before_match_is_counted_and_not_deducted_later(): void
    {
        [$tenant, $connection, $warehouseId] = $this->makeContext();
        $variant = $this->makeVariant($tenant, $warehouseId, 'X', stock: 10, createdAt: now()->subDay());

        $order = $this->ingestUnmatched($tenant, $connection, $warehouseId, 'IPTAL-SKU', 3);
        $line = $this->line($tenant, $order);

        $this->cancel($tenant, $order, [[$line->id, 1]], 'CAN-1');

        $line = $this->line($tenant, $order);
        $this->assertSame(1, $line->quantity_cancelled);
        $this->assertSame(10, $this->onHand($tenant, $warehouseId, $variant->id));

        $this->asTenant($tenant, fn () => $variant->forceFill(['sku' => 'IPTAL-SKU'])->save());
        $this->resolve($tenant);

        // 3 − 1 iptal = 2 düşüldü.
        $this->assertSame(8, $this->onHand($tenant, $warehouseId, $variant->id));
        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * Aynı iptal ikinci kez gelirse eşleşmemiş satırın sayacı İKİ KEZ
     * ilerlemez — hareket bırakmayan iptal de tekilleştirilir.
     */
    #[Test]
    public function replayed_cancellation_on_unmatched_line_does_not_double_count(): void
    {
        [$tenant, $connection, $warehouseId] = $this->makeContext();

        $order = $this->ingestUnmatched($tenant, $connection, $warehouseId, 'TEKRAR', 3);
        $line = $this->line($tenant, $order);

        $this->assertNotNull($this->cancel($tenant, $order, [[$line->id, 1]], 'CAN-AYNI'));
        $this->assertNull($this->cancel($tenant, $order, [[$line->id, 1]], 'CAN-AYNI'));

        $this->assertSame(1, $this->line($tenant, $order)->quantity_cancelled);
    }

    /** Eşleşmeden önce gelen iade de sayaçta tutulur. */
    #[Test]
    public function return_before_match_is_counted_and_not_deducted_later(): void
    {
        [$tenant, $connection, $warehouseId] = $this->makeContext();
        $variant = $this->makeVariant($tenant, $warehouseId, 'X', stock: 10, createdAt: now()->subDay());

        $order = $this->ingestUnmatched($tenant, $connection, $warehouseId, 'IADE-SKU', 4);
        $line = $this->line($tenant, $order);

        $event = new ReturnEvent(
            orderId: $order->id,
            externalRef: 'RET-1',
            lines: [new ReturnedLine($line->id, 1)],
        );
        $this->asTenant($tenant, fn () => (new ApplyOrderReturn)->run($event));

        $this->assertSame(1, $this->line($tenant, $order)->quantity_returned);
        $this->assertSame(10, $this->onHand($tenant, $warehouseId, $variant->id));

        $this->asTenant($tenant, fn () => $variant->forceFill(['sku' => 'IADE-SKU'])->save());
        $this->resolve($tenant);

        $this->assertSame(7, $this->onHand($tenant, $warehouseId, $variant->id));
    }

    /** Tamamen iptal edilmiş satır bağlanır ama stok düşülmez. */
    #[Test]
    public function fully_cancelled_line_is_linked_without_movement(): void
    {
        [$tenant, $connection, $warehouseId] = $this->makeContext();
        $variant = $this->makeVariant($tenant, $warehouseId, 'X', stock: 10, createdAt: now()->subDay());

        $order = $this->ingestUnmatched($tenant, $connection, $warehouseId, 'TAM-IPTAL', 2);
        $line = $this->line($tenant, $order);
        $this->cancel($tenant, $order, [[$line->id, 2]], 'CAN-TAM');

        $this->asTenant($tenant, fn () => $variant->forceFill(['sku' => 'TAM-IPTAL'])->save());
        $this->resolve($tenant);

        $line = $this->line($tenant, $order);
        $this->assertSame(StockStatus::SKIPPED, $line->stock_status);
        $this->assertSame(10, $this->onHand($tenant, $warehouseId, $variant->id));
    }

    /**
     * Stoğu düşülmemiş (SKIPPED) satırın iptali stok ÜRETMEZ — hiç
     * almadığımız stoğu geri veremeyiz.
     */
    #[Test]
    public function cancelling_a_skipped_line_does_not_create_stock(): void
    {
        [$tenant, $connection, $warehouseId] = $this->makeContext();

        $order = $this->ingestUnmatched($tenant, $connection, $warehouseId, 'ATLANAN', 2, placedAt: now()->subHour());
        $variant = $this->makeVariant($tenant, $warehouseId, 'ATLANAN', stock: 5, createdAt: now());
        $this->resolve($tenant);

        $line = $this->line($tenant, $order);
        $this->assertSame(StockStatus::SKIPPED, $line->stock_status);

        $this->cancel($tenant, $order, [[$line->id, 2]], 'CAN-ATLANAN');

        $this->assertSame(2, $this->line($tenant, $order)->quantity_cancelled);
        $this->assertSame(5, $this->onHand($tenant, $warehouseId, $variant->id));
        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /** Bağlandıktan sonraki iptal düşülen stoğu geri verir. */
    #[Test]
    public function cancellation_after_match_restores_deducted_stock(): void
    {
        [$tenant, $connection, $warehouseId] = $this->makeContext();
        $variant = $this->makeVariant($tenant, $warehouseId, 'X', stock: 10, createdAt: now()->subDay());

        $order = $this->ingestUnmatched($tenant, $connection, $warehouseId, 'SONRA-IPTAL', 3);
        $this->asTenant($tenant, fn () => $variant->forceFill(['sku' => 'SONRA-IPTAL'])->save());
        $this->resolve($tenant);
        $this->assertSame(7, $this->onHand($tenant, $warehouseId, $variant->id));

        $this->cancel($tenant, $order, [[$this->line($tenant, $order)->id, 3]], 'CAN-SONRA');

        $this->assertSame(10, $this->onHand($tenant, $warehouseId, $variant->id));
        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /** İkinci tur aynı satırı ikinci kez düşmez. */
    #[Test]
    public function resolving_twice_does_not_double_deduct(): void
    {
        [$tenant, $connection, $warehouseId] = $this->makeContext();
        $variant = $this->makeVariant($tenant, $warehouseId, 'IKI-TUR', stock: 10, createdAt: now()->subDay());

        $order = $this->ingestUnmatched($tenant, $connection, $warehouseId, 'IKI-TUR', 3);

        // Varyant zaten vardı ama alımda eşleşmemiş gibi kuruldu: doğrudan tur.
        $this->assertSame(1, $this->resolve($tenant));
        $this->assertSame(0, $this->resolve($tenant));

        $this->assertSame(7, $this->onHand($tenant, $warehouseId, $variant->id));
        $this->assertSame(1, $this->saleMovements($this->line($tenant, $order)));
    }

    /** Stok yetmezse satır OVERSOLD olur ve denetim olayı yazılır. */
    #[Test]
    public function late_match_with_insufficient_stock_marks_oversold(): void
    {
        [$tenant, $connection, $warehouseId] = $this->makeContext();
        $variant = $this->makeVariant($tenant, $warehouseId, 'AZ', stock: 1, createdAt: now()->subDay());

        $order = $this->ingestUnmatched($tenant, $connection, $warehouseId, 'AZ', 3);
        $this->resolve($tenant);

        $this->assertSame(StockStatus::OVERSOLD, $this->line($tenant, $order)->stock_status);
        $this->assertSame(-2, $this->onHand($tenant, $warehouseId, $variant->id));
        $this->assertTrue($this->asTenant($tenant, fn () => OrderEvent::query()
            ->where('order_id', $order->id)
            ->where('type', OrderEventType::OVERSELL_DETECTED->value)
            ->exists()));
    }

    /** Aynı siparişte aynı varyanta giden iki satır bakiyeyi sırayla görür. */
    #[Test]
    public function two_lines_of_same_variant_see_running_balance(): void
    {
        [$tenant, $connection, $warehouseId] = $this->makeContext();
        $variant = $this->makeVariant($tenant, $warehouseId, 'CIFT', stock: 3, createdAt: now()->subDay());

        $incoming = new IncomingOrder(
            channelConnectionId: $connection->id,
            externalId: 'ORD-'.uniqid(),
            lines: [
                new IncomingOrderLine('a', 'CIFT', 'Bir', 2, variantId: null),
                new IncomingOrderLine('b', 'CIFT', 'İki', 2, variantId: null),
            ],
            placedAt: now(),
        );
        $order = $this->asTenant($tenant, fn () => (new IngestChannelOrder)->run($incoming, $warehouseId));

        $this->resolve($tenant);

        $statuses = $this->asTenant($tenant, fn () => $order->lines()->orderBy('id')->pluck('stock_status')->all());
        $this->assertSame([StockStatus::APPLIED, StockStatus::OVERSOLD], $statuses);
        $this->assertSame(-1, $this->onHand($tenant, $warehouseId, $variant->id));
    }

    /** Başka kiracının aynı SKU'lu varyantı satırı BAĞLAMAZ; komut her kiracıyı kendi kataloğuyla çözer. */
    #[Test]
    public function command_resolves_each_tenant_against_its_own_catalog_only(): void
    {
        [$tenantA, $connectionA, $warehouseA] = $this->makeContext();
        [$tenantB, $connectionB, $warehouseB] = $this->makeContext();

        $orderA = $this->ingestUnmatched($tenantA, $connectionA, $warehouseA, 'ORTAK', 1);
        $orderB = $this->ingestUnmatched($tenantB, $connectionB, $warehouseB, 'ORTAK', 1);

        // Yalnız B'de ORTAK var.
        $variantB = $this->makeVariant($tenantB, $warehouseB, 'ORTAK', stock: 4, createdAt: now()->subDay());

        // A'nın turu B'nin satırını ADAY bile görmez (görseydi B'nin
        // siparişini A kapsamında aramaya kalkıp patlardı).
        $this->assertSame(0, $this->resolve($tenantA));

        $this->artisan('orders:resolve-unmatched')->assertSuccessful();

        $this->assertNull($this->line($tenantA, $orderA)->variant_id);
        $this->assertSame($variantB->id, $this->line($tenantB, $orderB)->variant_id);
        $this->assertSame(3, $this->onHand($tenantB, $warehouseB, $variantB->id));
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: ChannelConnection, 2: string} */
    private function makeContext(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Eşleşme '.uniqid(),
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

        return [$tenant, $connection, $warehouseId];
    }

    private function makeVariant(Tenant $tenant, string $warehouseId, string $sku, int $stock, \DateTimeInterface $createdAt): Variant
    {
        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create([
            'sku' => $sku,
            'created_at' => $createdAt,
        ]));

        $this->asTenant($tenant, fn () => DB::transaction(function () use ($warehouseId, $variant, $stock): void {
            (new LockInventoryRows)->run($warehouseId, [$variant->id]);

            (new ApplyMovement)->run(
                warehouseId: $warehouseId,
                variantId: $variant->id,
                type: MovementType::IMPORT,
                quantity: $stock,
                idempotencyKey: MovementKey::import((string) new UuidV7),
                sourceType: 'import_row',
            );
        }));

        return $variant;
    }

    /** Alımda eşleşmemiş tek satırlı sipariş. */
    private function ingestUnmatched(
        Tenant $tenant,
        ChannelConnection $connection,
        string $warehouseId,
        string $sku,
        int $quantity,
        ?\DateTimeInterface $placedAt = null,
    ): Order {
        $incoming = new IncomingOrder(
            channelConnectionId: $connection->id,
            externalId: 'ORD-'.uniqid(),
            lines: [new IncomingOrderLine('l1', $sku, 'Ürün', $quantity, variantId: null)],
            placedAt: $placedAt !== null ? CarbonImmutable::instance($placedAt) : now(),
        );

        return $this->asTenant($tenant, fn () => (new IngestChannelOrder)->run($incoming, $warehouseId));
    }

    /** @param list<array{0: string, 1: int}> $lines [orderLineId, quantity] */
    private function cancel(Tenant $tenant, Order $order, array $lines, string $externalRef): ?OrderEvent
    {
        $event = new CancellationEvent(
            orderId: $order->id,
            externalRef: $externalRef,
            lines: array_map(
                static fn (array $pair): CancelledLine => new CancelledLine($pair[0], $pair[1]),
                $lines,
            ),
        );

        return $this->asTenant($tenant, fn () => (new ApplyOrderCancellation)->run($event));
    }

    private function resolve(Tenant $tenant): int
    {
        return $this->asTenant($tenant, fn () => (new ResolveUnmatchedOrderLines)->run());
    }

    private function line(Tenant $tenant, Order $order): OrderLine
    {
        return $this->asTenant($tenant, fn () => $order->lines()->firstOrFail());
    }

    private function saleMovements(OrderLine $line): int
    {
        return $this->asSystem(fn () => DB::table('inventory_movements')
            ->where('idempotency_key', MovementKey::sale($line->id))
            ->count());
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
