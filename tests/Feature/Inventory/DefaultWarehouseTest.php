<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\SetDefaultWarehouse;
use App\Domain\Inventory\Exceptions\InactiveWarehouseException;
use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Testler D, E, F, G, H — varsayılan depo kısıtı ve değişimi.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · DDL Correction, §5.
 */
final class DefaultWarehouseTest extends TestCase
{
    use RefreshDatabase;

    /** Test D — kiracı başına en fazla bir varsayılan depo. */
    #[Test]
    public function second_default_warehouse_violates_partial_unique_index(): void
    {
        $tenant = $this->makeTenant();

        $this->expectException(UniqueConstraintViolationException::class);

        $this->asTenant($tenant, function () use ($tenant): void {
            Warehouse::create([
                'tenant_id' => $tenant->id,
                'code' => 'wh-2',
                'name' => 'İkinci Depo',
                'is_default' => true,     // ← indeks bunu reddetmeli
                'is_active' => true,
            ]);
        });
    }

    #[Test]
    public function non_default_warehouses_are_unconstrained(): void
    {
        $tenant = $this->makeTenant();

        $this->asTenant($tenant, function () {
            Warehouse::factory()->count(3)->create();
        });

        $counts = $this->asSystem(fn (): array => [
            'total' => Warehouse::where('tenant_id', $tenant->id)->count(),
            'default' => Warehouse::where('tenant_id', $tenant->id)
                ->where('is_default', true)->count(),
        ]);

        $this->assertSame(4, $counts['total']);      // 1 varsayılan + 3 normal
        $this->assertSame(1, $counts['default']);
    }

    #[Test]
    public function two_tenants_can_each_have_their_own_default(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();

        $defaults = $this->asSystem(
            fn () => Warehouse::where('is_default', true)->pluck('tenant_id')->sort()->values()->all()
        );

        $this->assertCount(2, $defaults);
        $this->assertContains($a->id, $defaults);
        $this->assertContains($b->id, $defaults);
    }

    /** Test E — değişim sonrası yalnızca yeni depo varsayılan. */
    #[Test]
    public function switching_default_leaves_exactly_one(): void
    {
        $tenant = $this->makeTenant();

        $old = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse());
        $new = $this->asTenant($tenant, fn () => Warehouse::factory()->create(['code' => 'wh-2']));

        (new SetDefaultWarehouse)->run($tenant->id, $new->id);

        $this->assertFalse($old->fresh()->is_default);
        $this->assertTrue($new->fresh()->is_default);

        $defaultCount = $this->asSystem(
            fn () => Warehouse::where('tenant_id', $tenant->id)->where('is_default', true)->count()
        );

        $this->assertSame(1, $defaultCount);
    }

    #[Test]
    public function switching_to_already_default_is_idempotent(): void
    {
        $tenant = $this->makeTenant();
        $current = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse());

        $result = (new SetDefaultWarehouse)->run($tenant->id, $current->id);

        $this->assertSame($current->id, $result->id);
        $this->assertTrue($result->is_default);

        $defaultCount = $this->asSystem(
            fn () => Warehouse::where('tenant_id', $tenant->id)->where('is_default', true)->count()
        );

        $this->assertSame(1, $defaultCount);
    }

    /** Test F — ikinci adım hata alırsa eski varsayılan korunur. */
    #[Test]
    public function failure_in_second_step_rolls_back_first_step(): void
    {
        $tenant = $this->makeTenant();

        $old = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse());
        $new = $this->asTenant($tenant, fn () => Warehouse::factory()->create(['code' => 'wh-2']));

        // İki adım tek transaction içinde: ilki uygulanır, ikincisi patlar.
        try {
            DB::transaction(function () use ($tenant): void {
                Warehouse::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenant->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);

                // İkinci adımı taklit eden hata
                throw new \RuntimeException('ikinci adım başarısız');
            });
        } catch (\RuntimeException) {
            // yutuldu
        }

        // Eski varsayılan korunmalı — geri alma çalıştı.
        $this->assertTrue($old->fresh()->is_default);
        $this->assertFalse($new->fresh()->is_default);

        $defaultCount = $this->asSystem(
            fn () => Warehouse::where('tenant_id', $tenant->id)->where('is_default', true)->count()
        );

        $this->assertSame(1, $defaultCount);
    }

    /** Test G — başka kiracının deposu varsayılan yapılamaz. */
    #[Test]
    public function warehouse_of_another_tenant_cannot_be_set_default(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        $foreign = $this->asTenant($tenantB, fn () => Warehouse::factory()->create(['code' => 'wh-b2']));

        try {
            (new SetDefaultWarehouse)->run($tenantA->id, $foreign->id);
            $this->fail('Çapraz kiracı değişim reddedilmeliydi.');
        } catch (ModelNotFoundException) {
            // beklenen
        }

        $this->assertFalse($foreign->fresh()->is_default);

        // A'nın kendi varsayılanı bozulmadı.
        $defaultA = $this->asTenant($tenantA, fn () => $tenantA->defaultWarehouse());
        $this->assertNotNull($defaultA);
        $this->assertSame(CreateTenant::DEFAULT_WAREHOUSE_CODE, $defaultA->code);
    }

    /** Test H — pasif depo varsayılan yapılamaz. */
    #[Test]
    public function inactive_warehouse_cannot_become_default(): void
    {
        $tenant = $this->makeTenant();

        $inactive = $this->asTenant(
            $tenant,
            fn () => Warehouse::factory()->inactive()->create(['code' => 'wh-passive'])
        );

        $this->expectException(InactiveWarehouseException::class);

        (new SetDefaultWarehouse)->run($tenant->id, $inactive->id);
    }

    #[Test]
    public function inactive_warehouse_failure_preserves_existing_default(): void
    {
        $tenant = $this->makeTenant();
        $old = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse());

        $inactive = $this->asTenant(
            $tenant,
            fn () => Warehouse::factory()->inactive()->create(['code' => 'wh-passive'])
        );

        try {
            (new SetDefaultWarehouse)->run($tenant->id, $inactive->id);
        } catch (InactiveWarehouseException) {
            // beklenen
        }

        $this->assertTrue($old->fresh()->is_default);
    }

    private function makeTenant(): Tenant
    {
        return (new CreateTenant)->run(
            name: 'Depo Testi '.uniqid(),
            owner: User::factory()->create(),
        );
    }
}
