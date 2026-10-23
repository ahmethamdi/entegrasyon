<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Identity\Models\AuditLog;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S7 · rol kapısı (`AuthorizeTenantRoute`) gerçek rotalarda.
 *
 * Yetki VERİLEN durum "403 değil" ile ölçülür: kapıyı geçen istek var
 * olmayan kayıtta 404 alır. Yalnız "403 alır" sınansaydı kapıyı herkese
 * kapatan bir hata da yeşil kalırdı.
 */
final class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->tenant = (new CreateTenant)->run('Kavlak', $this->owner);
    }

    #[Test]
    public function the_warehouse_role_works_orders_but_cannot_touch_catalog_channels_money_or_team(): void
    {
        $depo = $this->member('warehouse');
        $missing = (string) Str::uuid();

        foreach (['/orders', '/returns', '/questions', '/products', '/panel', '/help'] as $url) {
            $this->actingAs($depo)->get($url)->assertOk();
        }

        // Kapıdan GEÇER (kayıt yok → 404/302, ama 403 değil).
        $this->assertNotSame(403, $this->actingAs($depo)->post("/returns/{$missing}/approve")->status());
        $this->assertNotSame(403, $this->actingAs($depo)->post("/questions/{$missing}/answer", ['body' => 'x'])->status());

        foreach (['/products/create', '/channels', '/suppliers', '/campaigns', '/reports/contribution', '/settings/invoicing', '/billing', '/settings/team', '/metrics'] as $url) {
            $this->actingAs($depo)->get($url)->assertForbidden();
        }

        $this->actingAs($depo)->post('/inventory/adjust', [])->assertForbidden();
        $this->actingAs($depo)->post('/products', [])->assertForbidden();
        $this->actingAs($depo)->post('/channels', [])->assertForbidden();
        $this->actingAs($depo)->post('/billing/checkout', [])->assertForbidden();
        $this->actingAs($depo)->post("/orders/{$missing}/invoice/cancel")->assertForbidden();
    }

    #[Test]
    public function the_viewer_sees_everything_but_writes_nothing(): void
    {
        $viewer = $this->member('viewer');
        $missing = (string) Str::uuid();

        foreach (['/orders', '/products', '/channels', '/reports/contribution', '/settings/invoicing', '/reconciliation', '/failures'] as $url) {
            $this->actingAs($viewer)->get($url)->assertOk();
        }

        $this->actingAs($viewer)->post("/returns/{$missing}/approve")->assertForbidden();
        $this->actingAs($viewer)->post("/orders/{$missing}/shipments", [])->assertForbidden();
        $this->actingAs($viewer)->post('/inventory/adjust', [])->assertForbidden();
        $this->actingAs($viewer)->post('/failures/retry', [])->assertForbidden();
        $this->actingAs($viewer)->put('/settings/invoicing', [])->assertForbidden();
        $this->actingAs($viewer)->get('/billing')->assertForbidden();
        $this->actingAs($viewer)->get('/settings/team')->assertForbidden();
    }

    #[Test]
    public function the_admin_does_everything_except_billing_and_team(): void
    {
        $admin = $this->member('admin');

        $this->actingAs($admin)->get('/channels')->assertOk();
        $this->actingAs($admin)->get('/products/create')->assertOk();
        $this->actingAs($admin)->get('/billing')->assertForbidden();
        $this->actingAs($admin)->get('/settings/team')->assertForbidden();
        $this->actingAs($admin)->post('/settings/team/invitations', ['email' => 'x@y.com', 'role' => 'viewer'])->assertForbidden();

        $this->actingAs($this->owner)->get('/billing')->assertOk();
        $this->actingAs($this->owner)->get('/settings/team')->assertOk();
    }

    #[Test]
    public function the_menu_receives_exactly_the_role_permissions(): void
    {
        $this->actingAs($this->member('warehouse'))->get('/panel')->assertInertia(fn ($page) => $page
            ->where('permissions', ['panel', 'orders.view', 'orders.work', 'stock.view'])
            ->where('onboarding', null));
    }

    #[Test]
    public function a_role_change_applies_on_the_next_request_without_logging_out(): void
    {
        $member = $this->member('admin');

        $this->actingAs($member)->get('/channels')->assertOk();

        $this->actingAs($this->owner)
            ->put("/settings/team/members/{$member->id}/role", ['role' => 'warehouse'])
            ->assertSessionHasNoErrors();

        $this->actingAs($member)->get('/channels')->assertForbidden();

        $log = $this->asSystem(fn () => AuditLog::query()->where('action', AuditAction::MEMBER_ROLE_CHANGED->value)->firstOrFail());
        $this->assertEquals(['from' => 'admin', 'to' => 'warehouse'], $log->changes);
    }

    #[Test]
    public function the_last_owner_can_neither_be_demoted_nor_removed(): void
    {
        $this->actingAs($this->owner)
            ->put("/settings/team/members/{$this->owner->id}/role", ['role' => 'admin'])
            ->assertSessionHasErrors('member');
        $this->actingAs($this->owner)
            ->delete("/settings/team/members/{$this->owner->id}")
            ->assertSessionHasErrors('member');

        $this->assertSame('owner', $this->roleOf($this->owner));

        // İkinci sahip varken düşürülebilir.
        $second = $this->member('owner');
        $this->actingAs($second)
            ->put("/settings/team/members/{$this->owner->id}/role", ['role' => 'admin'])
            ->assertSessionHasNoErrors();
        $this->assertSame('admin', $this->roleOf($this->owner));
    }

    #[Test]
    public function a_removed_member_is_logged_out_instead_of_looping(): void
    {
        $member = $this->member('warehouse');

        $this->actingAs($this->owner)->delete("/settings/team/members/{$member->id}")->assertSessionHasNoErrors();

        $this->assertNull($this->roleOf($member));
        $this->actingAs($member)->get('/panel')->assertRedirect('/login')->assertSessionHas('warning');
        $this->assertGuest();

        $this->assertTrue($this->asSystem(fn () => AuditLog::query()->where('action', AuditAction::MEMBER_REMOVED->value)->exists()));
    }

    #[Test]
    public function another_tenants_member_cannot_be_touched(): void
    {
        $foreignOwner = User::factory()->create();
        (new CreateTenant)->run('Yabancı', $foreignOwner);

        $this->actingAs($this->owner)
            ->put("/settings/team/members/{$foreignOwner->id}/role", ['role' => 'viewer'])
            ->assertNotFound();
        $this->actingAs($this->owner)->delete("/settings/team/members/{$foreignOwner->id}")->assertNotFound();
    }

    #[Test]
    public function an_unknown_stored_role_falls_to_viewer_not_owner(): void
    {
        $member = $this->member('owner');
        DB::table('tenant_users')->where('user_id', $member->id)->update(['role' => 'süper']);

        $this->actingAs($member)->get('/orders')->assertOk();
        $this->actingAs($member)->post('/inventory/adjust', [])->assertForbidden();
        $this->actingAs($member)->get('/billing')->assertForbidden();
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();

        TenantUser::query()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => $role,
            'accepted_at' => now(),
        ]);

        return $user;
    }

    private function roleOf(User $user): ?string
    {
        $role = DB::table('tenant_users')->where('tenant_id', $this->tenant->id)->where('user_id', $user->id)->value('role');

        return $role === null ? null : (string) $role;
    }
}
