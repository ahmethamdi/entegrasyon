<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Abonelik ekranı — plan seçimi ve kullanım görünürlüğü.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 4.
 *
 * DEĞİŞMEZ KURAL — KULLANIM VE LİMİT BİRLİKTE GÖSTERİLİR:
 *   "Kotan doldu" tek başına ne yapacağını söylemez; satıcı hangi
 *   sınıra hangi sayıyla dayandığını görmeli. Uyarı e-postalarının ve
 *   ölü mektup ekranının "değer + eşik" kuralının aynısı.
 *
 * DEĞİŞMEZ KURAL — ÖDEME EKRANDAN "TAMAMLANMIŞ" SAYILMAZ:
 *   Panel yalnızca Stripe'a YÖNLENDİRİR; aboneliği webhook açar.
 *   Ekran abonelik yazsaydı ödeme alınmadan kota açılırdı ve satıcı
 *   ücretsiz kullanmaya başlardı.
 *
 * DEĞİŞMEZ KURAL — GİZLİ PLANLAR LİSTELENMEZ (`is_public = false`):
 *   Eski/özel anlaşmalı planlar silinmez ama satışa da açılmaz.
 */
final class BillingScreenTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- erişim

    /** Misafir abonelik ekranını göremez. */
    #[Test]
    public function a_guest_cannot_reach_the_billing_screen(): void
    {
        $this->get('/billing')->assertRedirect('/login');
    }

    // ---------------------------------------------------------------- içerik

    /** Açık planlar fiyat ve limitleriyle listelenir. */
    #[Test]
    public function the_public_plans_are_listed_with_their_limits(): void
    {
        [, $user] = $this->context();

        (new PlanSeeder)->run();

        $plans = $this->props($this->actingAs($user)->get('/billing'))['plans'];

        $this->assertCount(4, $plans);

        $starter = collect($plans)->firstWhere('code', 'starter');

        $this->assertSame('Başlangıç', $starter['name']);
        $this->assertSame(500, $starter['limits']['products']);
        $this->assertSame(2, $starter['limits']['channels']);
    }

    /** GİZLİ PLAN LİSTELENMEZ — satışa kapalıdır ama silinmemiştir. */
    #[Test]
    public function a_private_plan_is_never_listed(): void
    {
        [, $user] = $this->context();

        Plan::create([
            'code' => 'ozel-anlasma',
            'name' => 'Özel',
            'price_monthly' => 99,
            'limits' => [],
            'is_public' => false,
        ]);

        $plans = $this->props($this->actingAs($user)->get('/billing'))['plans'];

        $this->assertNotContains(
            'ozel-anlasma',
            array_column($plans, 'code'),
            'Gizli plan listelenmemeli.',
        );
    }

    /**
     * KULLANIM VE LİMİT BİRLİKTE GÖSTERİLİR.
     *
     * Satıcı "6/25 ürün" görmeli; yalnızca "kotan doldu" demek ne
     * yapacağını söylemez.
     */
    #[Test]
    public function the_current_usage_is_shown_next_to_the_limit(): void
    {
        [$tenant, $user] = $this->context();

        Plan::create([
            'code' => 'free',
            'name' => 'Ücretsiz',
            'price_monthly' => 0,
            'limits' => ['products' => 25, 'channels' => 1],
        ]);

        Product::factory()->count(3)->create([
            'tenant_id' => $tenant->id,
        ]);

        $usage = $this->props($this->actingAs($user)->get('/billing'))['usage'];

        $this->assertSame(3, $usage['products']['current']);
        $this->assertSame(25, $usage['products']['limit']);
        $this->assertSame(0, $usage['channels']['current']);
        $this->assertSame(1, $usage['channels']['limit']);
    }

    /** Sınırsız limit `null` olarak taşınır — sıfır DEĞİL. */
    #[Test]
    public function an_unlimited_plan_reports_a_null_limit(): void
    {
        [, $user] = $this->context();

        Plan::create([
            'code' => 'free',
            'name' => 'Ücretsiz',
            'price_monthly' => 0,
            'limits' => ['products' => null, 'channels' => null],
        ]);

        $usage = $this->props($this->actingAs($user)->get('/billing'))['usage'];

        $this->assertNull($usage['products']['limit']);
    }

    /** Aktif abonelik ekranda görünür. */
    #[Test]
    public function the_active_subscription_is_shown(): void
    {
        [$tenant, $user] = $this->context();

        $plan = Plan::create([
            'code' => 'pro',
            'name' => 'Pro',
            'price_monthly' => 499,
            'limits' => ['products' => 5000],
        ]);

        TenantContext::runAsSystem(fn () => Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_code' => $plan->code,
            'status' => 'active',
            'started_at' => now(),
            'current_period_end' => now()->addMonth(),
        ]));

        $current = $this->props($this->actingAs($user)->get('/billing'))['current'];

        $this->assertSame('pro', $current['planCode']);
        $this->assertSame('active', $current['status']);
        $this->assertNotNull($current['currentPeriodEnd']);
    }

    /** Aboneliği olmayan kiracı varsayılan plana düşer ve bunu görür. */
    #[Test]
    public function a_tenant_without_a_subscription_sees_the_default_plan(): void
    {
        [, $user] = $this->context();

        Plan::create([
            'code' => 'free',
            'name' => 'Ücretsiz',
            'price_monthly' => 0,
            'limits' => ['products' => 25],
        ]);

        $current = $this->props($this->actingAs($user)->get('/billing'))['current'];

        $this->assertSame('free', $current['planCode']);
        $this->assertNull($current['status'], 'Abonelik yokken durum boş olmalı.');
    }

    /** BAŞKA KİRACININ ABONELİĞİ GÖRÜNMEZ. */
    #[Test]
    public function another_tenants_subscription_never_leaks(): void
    {
        [, $userA] = $this->context();
        [$tenantB] = $this->context();

        $plan = Plan::create([
            'code' => 'pro',
            'name' => 'Pro',
            'price_monthly' => 499,
            'limits' => [],
        ]);

        TenantContext::runAsSystem(fn () => Subscription::create([
            'tenant_id' => $tenantB->id,
            'plan_code' => $plan->code,
            'status' => 'active',
            'started_at' => now(),
        ]));

        $current = $this->props($this->actingAs($userA)->get('/billing'))['current'];

        $this->assertNull($current['status'], 'Başka kiracının aboneliği sızmamalı.');
    }

    // ---------------------------------------------------------------- ödeme başlatma

    /**
     * PANEL ABONELİK YAZMAZ — yalnızca Stripe'a yönlendirir.
     *
     * Yazsaydı ödeme alınmadan kota açılırdı.
     */
    #[Test]
    public function starting_a_checkout_never_creates_a_subscription_locally(): void
    {
        [, $user] = $this->context();

        Plan::create([
            'code' => 'pro',
            'name' => 'Pro',
            'price_monthly' => 499,
            'limits' => [],
        ]);

        // Stripe anahtarı yok: istek başarısız olur ama ASLA yerel
        // abonelik yazılmamalıdır.
        $this->actingAs($user)->post('/billing/checkout', ['plan_code' => 'pro']);

        $this->assertSame(0, TenantContext::runAsSystem(
            fn (): int => Subscription::withoutGlobalScopes()->count(),
        ), 'Panel abonelik YAZMAMALI.');
    }

    /** Ücretsiz plan için ödeme başlatılamaz. */
    #[Test]
    public function a_free_plan_cannot_be_purchased(): void
    {
        [, $user] = $this->context();

        Plan::create([
            'code' => 'free',
            'name' => 'Ücretsiz',
            'price_monthly' => 0,
            'limits' => [],
        ]);

        $this->actingAs($user)
            ->post('/billing/checkout', ['plan_code' => 'free'])
            ->assertSessionHasErrors('plan_code');
    }

    /**
     * GİZLİ PLAN SATIN ALINAMAZ — listede olmayan plana ödeme açılmaz.
     *
     * STRIPE YAPILANDIRILMIŞ GİBİ DAVRANILIR ve mesaj BEKLENEN METİNLE
     * sınanır. Aksi halde istek "ödeme altyapısı yok" kapısına takılır,
     * o da `plan_code` hatası üretir ve test GİZLİ PLAN KAPISI HİÇ
     * ÇALIŞMASA DA yeşil kalırdı — iki savunma mutasyonu gizler
     * (bu projede daha önce yaşanan tuzağın aynısı, mutasyonla bulundu).
     */
    #[Test]
    public function a_private_plan_cannot_be_purchased(): void
    {
        [, $user] = $this->context();

        config()->set('entegrasyon.stripe.secret', 'sk_test_dummy');

        Plan::create([
            'code' => 'gizli',
            'name' => 'Gizli',
            'price_monthly' => 999,
            'limits' => [],
            'is_public' => false,
        ]);

        $this->actingAs($user)
            ->post('/billing/checkout', ['plan_code' => 'gizli'])
            ->assertSessionHasErrors(['plan_code' => 'Bu plan satın alınamaz.']);
    }

    /**
     * ÜCRETSİZ PLAN KAPISI DA AYRI SINANIR — aynı gerekçeyle Stripe
     * yapılandırılmış varsayılır ve mesaj beklenen metinle doğrulanır.
     */
    #[Test]
    public function the_free_plan_gate_reports_its_own_reason(): void
    {
        [, $user] = $this->context();

        config()->set('entegrasyon.stripe.secret', 'sk_test_dummy');

        Plan::create([
            'code' => 'free',
            'name' => 'Ücretsiz',
            'price_monthly' => 0,
            'limits' => [],
        ]);

        $this->actingAs($user)
            ->post('/billing/checkout', ['plan_code' => 'free'])
            ->assertSessionHasErrors(['plan_code' => 'Ücretsiz plan için ödeme gerekmez.']);
    }

    /**
     * VAR OLMAYAN PLAN DA REDDEDİLİR — uydurma kodla ödeme açılmaz.
     */
    #[Test]
    public function an_unknown_plan_cannot_be_purchased(): void
    {
        [, $user] = $this->context();

        config()->set('entegrasyon.stripe.secret', 'sk_test_dummy');

        $this->actingAs($user)
            ->post('/billing/checkout', ['plan_code' => 'hic-yok'])
            ->assertSessionHasErrors(['plan_code' => 'Bu plan satın alınamaz.']);
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: User} */
    private function context(): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Abonelik Ekranı', owner: $user);

        return [$tenant, $user];
    }

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('page')['props'];
    }
}
