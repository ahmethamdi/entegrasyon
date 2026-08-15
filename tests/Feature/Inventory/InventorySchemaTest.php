<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Inventory\Support\OutboundQuantity;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Testler J, K, L — stok şema invariantları.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · Inventory, §1 · Kararlar 04–07.
 */
final class InventorySchemaTest extends TestCase
{
    use RefreshDatabase;

    /** Test J — available generated column. */
    #[Test]
    public function available_is_generated_from_on_hand_minus_reserved(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $level = $this->asTenant($tenant, fn () => InventoryLevel::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouseId,
            'variant_id' => $variant->id,
            'on_hand' => 5,
            'reserved' => 2,
        ]));

        $level->refresh();

        $this->assertSame(5, $level->on_hand);
        $this->assertSame(2, $level->reserved);
        $this->assertSame(3, $level->available);
    }

    #[Test]
    public function available_recomputes_after_update(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $level = $this->asTenant($tenant, fn () => InventoryLevel::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouseId,
            'variant_id' => $variant->id,
            'on_hand' => 10,
            'reserved' => 0,
        ]));

        $this->asTenant($tenant, function () use ($level): void {
            $level->forceFill(['on_hand' => 4, 'reserved' => 1])->save();
        });

        $this->assertSame(3, $level->fresh()->available);
    }

    /** Test K — negatif stok kabul edilir. */
    #[Test]
    public function negative_on_hand_and_available_are_accepted(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $level = $this->asTenant($tenant, fn () => InventoryLevel::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouseId,
            'variant_id' => $variant->id,
            'on_hand' => -1,          // CHECK (on_hand >= 0) YOK
            'reserved' => 0,
        ]));

        $level->refresh();

        $this->assertSame(-1, $level->on_hand);
        $this->assertSame(-1, $level->available);
        $this->assertTrue($level->isOversold());
    }

    #[Test]
    public function negative_available_with_reservation_is_accepted(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $level = $this->asTenant($tenant, fn () => InventoryLevel::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouseId,
            'variant_id' => $variant->id,
            'on_hand' => 1,
            'reserved' => 3,          // available = -2
        ]));

        $this->assertSame(-2, $level->fresh()->available);
    }

    #[Test]
    public function negative_reserved_is_rejected(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $this->expectException(QueryException::class);

        $this->asTenant($tenant, fn () => InventoryLevel::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouseId,
            'variant_id' => $variant->id,
            'on_hand' => 5,
            'reserved' => -1,         // CHECK (reserved >= 0) reddetmeli
        ]));
    }

    #[Test]
    public function outbound_quantity_clamps_without_touching_canonical_state(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $level = $this->asTenant($tenant, fn () => InventoryLevel::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouseId,
            'variant_id' => $variant->id,
            'on_hand' => -3,
            'reserved' => 0,
        ]));

        $level->refresh();

        // Kanala giden değer kırpılır...
        $this->assertSame(0, OutboundQuantity::forChannel($level));
        $this->assertSame(0, OutboundQuantity::fromAvailable($level->available));

        // ...ama kanonik durum DEĞİŞMEZ.
        $this->assertSame(-3, $level->fresh()->on_hand);
        $this->assertSame(-3, $level->fresh()->available);
        $this->assertDatabaseHas('inventory_levels', [
            'id' => $level->id,
            'on_hand' => -3,
        ]);
    }

    /** Test L — hareket idempotency anahtarı tekilliği. */
    #[Test]
    public function duplicate_idempotency_key_is_rejected_for_same_tenant(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $key = MovementKey::sale('11111111-1111-7111-8111-111111111111');

        $this->asTenant($tenant, fn () => $this->makeMovement($tenant, $variant, $warehouseId, $key));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->asTenant($tenant, fn () => $this->makeMovement($tenant, $variant, $warehouseId, $key));
    }

    #[Test]
    public function same_idempotency_key_is_allowed_across_tenants(): void
    {
        [$tenantA, $variantA, $warehouseA] = $this->makeContext();
        [$tenantB, $variantB, $warehouseB] = $this->makeContext();

        $key = MovementKey::sale('22222222-2222-7222-8222-222222222222');

        $this->asTenant($tenantA, fn () => $this->makeMovement($tenantA, $variantA, $warehouseA, $key));
        $this->asTenant($tenantB, fn () => $this->makeMovement($tenantB, $variantB, $warehouseB, $key));

        // Tekillik (tenant_id, idempotency_key) çifti üzerindedir.
        $this->assertSame(2, $this->asSystem(
            fn () => InventoryMovement::where('idempotency_key', $key)->count()
        ));
    }

    #[Test]
    public function movement_stores_unclamped_after_values(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $movement = $this->asTenant($tenant, fn () => InventoryMovement::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouseId,
            'variant_id' => $variant->id,
            'type' => MovementType::SALE,
            'on_hand_delta' => -1,
            'reserved_delta' => 0,
            'on_hand_after' => -1,      // kırpılmamış gerçek bakiye
            'reserved_after' => 0,
            'source_type' => 'order_line',
            'source_id' => null,
            'idempotency_key' => MovementKey::sale('33333333-3333-7333-8333-333333333333'),
            'occurred_at' => now(),
        ]));

        $this->assertSame(-1, $movement->fresh()->on_hand_after);
        $this->assertSame(MovementType::SALE, $movement->fresh()->type);
    }

    #[Test]
    public function movement_type_declares_stock_sufficiency_requirement(): void
    {
        // Bizim kararımız — reddedilebilir
        $this->assertTrue(MovementType::RESERVATION->requiresSufficientStock());
        $this->assertTrue(MovementType::TRANSFER_OUT->requiresSufficientStock());

        // Dış gerçeklik — asla reddedilmez
        $this->assertFalse(MovementType::SALE->requiresSufficientStock());
        $this->assertFalse(MovementType::RETURN->requiresSufficientStock());
        $this->assertFalse(MovementType::CANCELLATION->requiresSufficientStock());
    }

    /** @return array{0: Tenant, 1: Variant, 2: string} */
    private function makeContext(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Stok Testi '.uniqid(),
            owner: User::factory()->create(),
        );

        $warehouseId = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse()->id);
        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

        return [$tenant, $variant, $warehouseId];
    }

    private function makeMovement(
        Tenant $tenant,
        Variant $variant,
        string $warehouseId,
        string $key,
    ): InventoryMovement {
        return InventoryMovement::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouseId,
            'variant_id' => $variant->id,
            'type' => MovementType::SALE,
            'on_hand_delta' => -1,
            'reserved_delta' => 0,
            'on_hand_after' => 0,
            'reserved_after' => 0,
            'source_type' => 'order_line',
            'source_id' => null,
            'idempotency_key' => $key,
            'occurred_at' => now(),
        ]);
    }
}
