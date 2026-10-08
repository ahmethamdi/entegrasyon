<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Billing\Actions\AssignPlanManually;
use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Identity\Models\AuditLog;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\SuperAdmin;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Süper admin — platformu işletenin paneli (8 Eki 2026).
 *
 * Yetki rol kolonunda DEĞİL, sunucu ayarındaki e-posta listesinde
 * (`SUPER_ADMIN_EMAILS`). Müşteriye özel plan `is_public = false` yazılır:
 * müşteri onu göremez ve satın alamaz, yalnız yönetici atar.
 */
final class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = 'admin@34devs.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        config(['entegrasyon.super_admin_emails' => self::ADMIN]);
    }

    // ───────────────────────────────────────────────────────────── yetki

    /** ⚠️ Sıradan satıcı yönetim ekranlarına giremez ve plan oluşturamaz. */
    #[Test]
    public function a_regular_seller_gets_403_everywhere(): void
    {
        [, $seller] = $this->tenantWithOwner('satici@x.test');

        foreach (['/admin', '/admin/tenants', '/admin/plans'] as $url) {
            $this->actingAs($seller)->get($url)->assertForbidden();
        }

        $this->actingAs($seller)->post('/admin/plans', [
            'name' => 'Kaçak', 'price_monthly' => 0, 'currency' => 'TRY',
        ])->assertForbidden();

        $this->assertFalse(Plan::query()->where('name', 'Kaçak')->exists());
    }

    /** Listedeki adres doğrulanmamışsa yönetici sayılmaz (adresin sahibi o olmayabilir). */
    #[Test]
    public function an_unverified_listed_email_is_not_an_admin(): void
    {
        $this->assertFalse(SuperAdmin::is(User::factory()->unverified()->create(['email' => self::ADMIN])));
        $this->assertTrue(SuperAdmin::is(User::factory()->create(['email' => 'Admin@34devs.test'])), 'Büyük/küçük harf farkı yöneticiyi dışarıda bıraktı.');
    }

    /** Menü bayrağı yalnız yöneticide açık. */
    #[Test]
    public function the_menu_flag_is_only_shared_with_the_admin(): void
    {
        [, $seller] = $this->tenantWithOwner('satici@x.test');
        $admin = $this->admin();

        $this->actingAs($seller)->get('/panel')->assertInertia(fn ($p) => $p->where('auth.user.isSuperAdmin', false));
        $this->actingAs($admin)->get('/admin')->assertInertia(fn ($p) => $p->where('auth.user.isSuperAdmin', true));
    }

    // ───────────────────────────────────────────────────────────── özet

    /**
     * ⚠️ ÖZET TÜM KİRACILARI SAYAR.
     *
     * Kiracı bağlamında koşsaydı "toplam kayıt" sessizce bir kiracıya
     * daralırdı. Huni kayıt → doğrulanmış ayrımını yapar.
     */
    #[Test]
    public function the_dashboard_counts_every_tenant(): void
    {
        $this->tenantWithOwner('a@x.test');
        $this->tenantWithOwner('b@x.test', verified: false);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin')->assertOk()->assertInertia(fn ($p) => $p
            ->component('Admin/Dashboard')
            ->where('stats.funnel.signups', 3)
            ->where('stats.funnel.verified', 2)
            ->where('stats.tenants', 2)
            ->has('stats.dailySignups', 30));
    }

    /** Müşteri listesi e-postayla aranır. */
    #[Test]
    public function tenants_can_be_searched_by_owner_email(): void
    {
        $this->tenantWithOwner('konfor@x.test', name: 'Konfor Halı');
        $this->tenantWithOwner('baska@x.test', name: 'Başka Mağaza');

        $this->actingAs($this->admin())->get('/admin/tenants?q=konfor@')
            ->assertInertia(fn ($p) => $p
                ->component('Admin/Tenants')
                ->where('tenants.total', 1)
                ->where('tenants.data.0.name', 'Konfor Halı'));
    }

    // ───────────────────────────────────────────────────────────── planlar

    /** Özel plan gizli oluşur, limitleri ve kodu doğru yazılır. */
    #[Test]
    public function a_custom_plan_is_created_hidden(): void
    {
        $this->actingAs($this->admin())->post('/admin/plans', [
            'name' => 'Konfor Halı Özel',
            'price_monthly' => '2500',
            'currency' => 'TRY',
            'max_products' => 20000,
            'max_channels' => null,
        ])->assertRedirect('/admin/plans')->assertSessionHasNoErrors();

        $plan = Plan::query()->findOrFail('ozel-konfor-hali-ozel');
        $this->assertFalse($plan->is_public, 'Özel plan herkese açık oluştu — müşteri kendisi satın alabilirdi.');
        $this->assertSame(20000, $plan->limitFor(QuotaMetric::PRODUCTS));
        $this->assertNull($plan->limitFor(QuotaMetric::CHANNELS), 'Boş limit sınırsız olmalı.');
        $this->assertSame('2500.00', (string) $plan->price_monthly);
    }

    /**
     * ⚠️ ATANAN PLAN KOTAYI BELİRLER, eskisi kapanır, denetim kaydı düşer.
     */
    #[Test]
    public function assigning_a_plan_drives_the_quota(): void
    {
        [$tenant] = $this->tenantWithOwner('konfor@x.test');
        $admin = $this->admin();
        $this->asSystem(fn () => Plan::query()->create([
            'code' => 'ozel-test', 'name' => 'Özel test', 'price_monthly' => '100', 'currency' => 'TRY',
            'limits' => ['products' => 7, 'channels' => 2], 'is_public' => false,
        ]));

        $this->actingAs($admin)->post("/admin/tenants/{$tenant->id}/plan", ['plan_code' => 'starter'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/admin/tenants/{$tenant->id}/plan", ['plan_code' => 'ozel-test'])->assertSessionHasNoErrors();

        $this->asTenant($tenant, function () use ($tenant): void {
            $this->assertSame(1, Subscription::query()->whereIn('status', Subscription::ACTIVE_STATUSES)->count(), 'İki aktif abonelik kaldı.');
            $this->assertSame('ozel-test', app(EnforceQuota::class)->planForCurrentTenant()?->code);
            $this->assertSame(7, app(EnforceQuota::class)->planForCurrentTenant()?->limitFor(QuotaMetric::PRODUCTS));
            $this->assertSame('ozel-test', Tenant::query()->findOrFail($tenant->id)->plan_code);
            $this->assertSame(2, AuditLog::query()->where('action', AuditAction::PLAN_ASSIGNED_BY_ADMIN->value)->count());
        });
    }

    /** ⚠️ Ödeme sağlayıcısının aktif aboneliği varken elle plan atanmaz. */
    #[Test]
    public function a_paid_subscription_blocks_manual_assignment(): void
    {
        [$tenant] = $this->tenantWithOwner('odeyen@x.test');
        $this->asTenant($tenant, fn () => Subscription::query()->create([
            'tenant_id' => $tenant->id, 'plan_code' => 'pro', 'status' => 'active', 'provider' => 'stripe', 'started_at' => now(),
        ]));

        $this->actingAs($this->admin())->post("/admin/tenants/{$tenant->id}/plan", ['plan_code' => 'business'])
            ->assertSessionHasErrors('plan_code');

        $this->asTenant($tenant, fn () => $this->assertSame('pro', app(EnforceQuota::class)->planForCurrentTenant()?->code));
    }

    /** ⚠️ Süresi geçen elle plan kota vermez; kiracı varsayılan plana düşer. */
    #[Test]
    public function an_expired_manual_plan_falls_back_to_the_default(): void
    {
        [$tenant] = $this->tenantWithOwner('suresi@x.test');
        $plan = $this->asSystem(fn () => Plan::query()->findOrFail('business'));
        app(AssignPlanManually::class)->run($tenant, $plan, now()->addDays(10), null);

        $this->asTenant($tenant, fn () => $this->assertSame('business', app(EnforceQuota::class)->planForCurrentTenant()?->code));

        $this->travel(11)->days();

        $this->asTenant($tenant, fn () => $this->assertSame(EnforceQuota::DEFAULT_PLAN_CODE, app(EnforceQuota::class)->planForCurrentTenant()?->code));
    }

    /** Geçmiş tarih kabul edilmez. */
    #[Test]
    public function the_end_date_must_be_in_the_future(): void
    {
        [$tenant] = $this->tenantWithOwner('tarih@x.test');

        $this->actingAs($this->admin())->post("/admin/tenants/{$tenant->id}/plan", ['plan_code' => 'pro', 'ends_at' => now()->subDay()->toDateString()])
            ->assertSessionHasErrors('ends_at');
    }

    /** Müşteri ayrıntı sayfası açılır, özel plan seçeneklerde görünür. */
    #[Test]
    public function the_tenant_page_lists_custom_plans(): void
    {
        [$tenant] = $this->tenantWithOwner('detay@x.test', name: 'Detay Mağaza');
        $this->asSystem(fn () => Plan::query()->create([
            'code' => 'ozel-detay', 'name' => 'Özel detay', 'price_monthly' => '50', 'currency' => 'EUR', 'limits' => [], 'is_public' => false,
        ]));

        $this->actingAs($this->admin())->get("/admin/tenants/{$tenant->id}")->assertOk()->assertInertia(fn ($p) => $p
            ->component('Admin/TenantShow')
            ->where('tenant.name', 'Detay Mağaza')
            ->where('plans', fn ($plans): bool => collect($plans)->contains(fn ($x) => $x['code'] === 'ozel-detay' && $x['isPublic'] === false)));
    }

    // ──────────────────────────────────────────────────────── yardımcılar

    private function admin(): User
    {
        return User::factory()->create(['email' => self::ADMIN]);
    }

    /** @return array{0: Tenant, 1: User} */
    private function tenantWithOwner(string $email, string $name = 'Mağaza', bool $verified = true): array
    {
        $user = $verified ? User::factory()->create(['email' => $email]) : User::factory()->unverified()->create(['email' => $email]);
        $tenant = (new CreateTenant)->run(name: $name.' '.uniqid(), owner: $user);
        $tenant->forceFill(['name' => $name])->save();

        return [$tenant, $user];
    }
}
