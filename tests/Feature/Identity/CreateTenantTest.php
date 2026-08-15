<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Test C — CreateTenant tam bir varsayılan depo yaratır.
 *
 * Mimari Karar Dokümanı v2.2 · §19 · sınıf 5 ve §4 · DDL Correction.
 *
 * "En az bir varsayılan depo bulunmalı" garantisi veritabanı kısıtından
 * DEĞİL, bu action'dan gelir.
 */
final class CreateTenantTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_exactly_one_default_warehouse(): void
    {
        $owner = User::factory()->create();

        $tenant = (new CreateTenant)->run(name: 'Test Mağaza', owner: $owner);

        $warehouses = $this->asSystem(
            fn () => Warehouse::where('tenant_id', $tenant->id)->get()
        );

        $this->assertCount(1, $warehouses);
        $this->assertTrue($warehouses->first()->is_default);
        $this->assertTrue($warehouses->first()->is_active);
        $this->assertSame(CreateTenant::DEFAULT_WAREHOUSE_CODE, $warehouses->first()->code);
        $this->assertSame('Default Warehouse', $warehouses->first()->name);
    }

    #[Test]
    public function it_creates_owner_membership(): void
    {
        $owner = User::factory()->create();

        $tenant = (new CreateTenant)->run(name: 'Sahiplik Testi', owner: $owner);

        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
    }

    #[Test]
    public function tenant_default_warehouse_accessor_resolves(): void
    {
        $owner = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Erişim Testi', owner: $owner);

        $default = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse());

        $this->assertNotNull($default);
        $this->assertTrue($default->is_default);
    }

    #[Test]
    public function slug_collisions_are_resolved(): void
    {
        $owner = User::factory()->create();
        $action = new CreateTenant;

        $first = $action->run(name: 'Aynı İsim', owner: $owner);
        $second = $action->run(name: 'Aynı İsim', owner: $owner);

        $this->assertNotSame($first->slug, $second->slug);
        $this->assertSame(2, Tenant::whereIn('id', [$first->id, $second->id])->count());
    }

    #[Test]
    public function running_twice_for_same_tenant_does_not_duplicate_warehouse(): void
    {
        $owner = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Tekrar Testi', owner: $owner);

        // Aynı kod ile ikinci kez yaratma denemesi — firstOrCreate koruması.
        $this->asSystem(function () use ($tenant): void {
            Warehouse::firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => CreateTenant::DEFAULT_WAREHOUSE_CODE],
                ['name' => 'Default Warehouse', 'is_default' => true, 'is_active' => true],
            );
        });

        $count = $this->asSystem(
            fn () => Warehouse::where('tenant_id', $tenant->id)->count()
        );

        $this->assertSame(1, $count);
    }
}
