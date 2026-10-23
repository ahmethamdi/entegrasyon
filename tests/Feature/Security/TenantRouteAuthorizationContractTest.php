<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\TenantRole;
use App\Domain\Identity\Support\RoutePermissions;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S7 · HER kiracı rotası rol kapısından geçer ve tabloda yazılıdır.
 *
 * `HorizonQueueContractTest` ile aynı ders: sınıfın varlığı onun her yerde
 * uygulandığı anlamına gelmez. Yeni rota `tenant` grubunun dışına (kendi
 * `middleware(['auth','tenant'])` zinciriyle) eklenirse kapı ATLANIR ve
 * depo üyesi sessizce yazma izni kazanırdı; tabloya yazılmazsa sahibe bile
 * 403 verir. İki durum da burada adıyla düşer.
 */
final class TenantRouteAuthorizationContractTest extends TestCase
{
    #[Test]
    public function every_tenant_route_passes_the_role_gate_and_is_mapped(): void
    {
        $unguarded = [];
        $unmapped = [];
        $seen = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            if (! in_array('tenant', $middleware, true)) {
                continue;
            }

            $name = (string) $route->getName();
            $seen[] = $name;

            if (! in_array('tenant.can', $middleware, true)) {
                $unguarded[] = $name ?: $route->uri();
            }

            if (RoutePermissions::for($name) === null) {
                $unmapped[] = $name ?: $route->uri();
            }
        }

        $this->assertNotEmpty($seen, 'Kiracı rotası bulunamadı — test yanlış şeyi tarıyor.');
        $this->assertSame([], $unguarded, '`tenant.can` olmadan kiracı rotası.');
        $this->assertSame([], $unmapped, '`RoutePermissions` tablosunda olmayan kiracı rotası (403 verir).');

        // Tersi: tabloda olup rotası olmayan ad — yazım hatası ya da ölü satır.
        $this->assertSame([], array_values(array_diff(RoutePermissions::routeNames(), $seen)), 'Tabloda var, rotası yok.');
    }

    #[Test]
    public function the_gate_runs_after_the_tenant_context(): void
    {
        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            if (! in_array('tenant.can', $middleware, true)) {
                continue;
            }

            $this->assertGreaterThan(
                array_search('tenant', $middleware, true),
                array_search('tenant.can', $middleware, true),
                "{$route->getName()}: rol kapısı kiracı bağlamından ÖNCE çalışıyor.",
            );
        }
    }

    /** Değerler Inertia'ya METİN olarak gider ve menü onları okur. */
    #[Test]
    public function permission_and_role_values_are_a_frozen_contract(): void
    {
        $this->assertSame(
            ['panel', 'orders.view', 'orders.work', 'stock.view', 'catalog.view', 'catalog.manage', 'channels.view',
                'channels.manage', 'finance.view', 'finance.manage', 'settings.view', 'settings.manage', 'billing.manage', 'team.manage'],
            array_map(static fn (Permission $p): string => $p->value, Permission::cases()),
        );
        $this->assertSame(['owner', 'admin', 'warehouse', 'viewer'], array_map(static fn (TenantRole $r): string => $r->value, TenantRole::cases()));
        // DispatchAlerts sahip adresini bu metinle arar.
        $this->assertSame('owner', TenantRole::OWNER->value);
    }

    #[Test]
    public function only_the_owner_manages_billing_and_team(): void
    {
        foreach (TenantRole::cases() as $role) {
            $this->assertSame($role === TenantRole::OWNER, $role->allows(Permission::BILLING_MANAGE), $role->value);
            $this->assertSame($role === TenantRole::OWNER, $role->allows(Permission::TEAM_MANAGE), $role->value);
        }

        foreach ([TenantRole::WAREHOUSE, TenantRole::VIEWER] as $role) {
            foreach ([Permission::CATALOG_MANAGE, Permission::CHANNELS_MANAGE, Permission::SETTINGS_MANAGE, Permission::FINANCE_MANAGE] as $p) {
                $this->assertFalse($role->allows($p), "{$role->value} → {$p->value}");
            }
        }
    }
}
