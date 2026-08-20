<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Exceptions\QuotaExceededException;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Catalog\Models\Product;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kota uygulaması — §13 · Faz 4 · "Planlar, abonelik, kota".
 *
 * Mimari Karar Dokümanı v2.2 · §3 · Domain/Billing/Actions/EnforceQuota,
 * §4 · plans.limits (JSONB).
 *
 * KULLANICI KARARI — İKİ KOTA: ürün sayısı ve kanal bağlantısı sayısı.
 * İkisi de ANLIK sayımdır (COUNT), dönemsel birikim değil; bu yüzden
 * `usage_records` bu turda YAZILMADI. Sipariş/senkron başına
 * ücretlendirmeye geçilirse o tablo eklenir.
 *
 * DEĞİŞMEZ KURAL — KOTA YARATMAYI ENGELLER, VAR OLANI SİLMEZ:
 *   Plan düşürüldüğünde limitin üstünde kalan ürünler SİLİNMEZ ve
 *   senkronları DURDURULMAZ; yalnızca YENİSİ eklenemez. Silmek geri
 *   alınamaz ve satıcının kanaldaki listelemeleri de giderdi. Aynı
 *   gerekçeyle stok akışı da kotadan ETKİLENMEZ.
 *
 * DEĞİŞMEZ KURAL — KOTA STOK VE SİPARİŞ AKIŞINA DOKUNMAZ:
 *   Sipariş ASLA reddedilmez (§ sipariş kuralları) — pazaryeri onu kabul
 *   etmiştir ve bu otoriter gerçektir. Kota bir ÖDEME sorunudur; ödeme
 *   sorunu yüzünden sipariş kaybetmek veya stoğu bozmak, çözdüğünden
 *   büyük zarar verir. Ön koşul kapısının (§14) stok akışına dokunmama
 *   kuralıyla aynı tasarım hedefi.
 *
 * DEĞİŞMEZ KURAL — ABONELİĞİ OLMAYAN KİRACI VARSAYILAN PLANA DÜŞER:
 *   Sınırsız sayılsaydı hiç ödeme yapmayan kiracı sınırsız kaynak
 *   kullanırdı; sıfır sayılsaydı kayıt olan herkes daha ilk ürününde
 *   duvara toslardı ve onboarding'in ikinci adımı geçilemezdi.
 *
 * DEĞİŞMEZ KURAL — LİMİT YOKSA SINIRSIZDIR, SIFIR DEĞİL:
 *   `limits` JSONB'sinde bulunmayan anahtar "bu planda o kota YOK"
 *   demektir. Sıfır sayılsaydı yeni bir kota türü eklendiği anda TÜM
 *   mevcut planlar o kotada sıfıra düşer ve bütün kiracılar aniden
 *   engellenirdi.
 */
final class EnforceQuotaTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- sınır

    /** Limitin altındaki kiracı ürün ekleyebilir. */
    #[Test]
    public function a_tenant_below_the_limit_may_add_a_product(): void
    {
        $tenant = $this->tenantOnPlan(['products' => 3]);

        $this->productsFor($tenant, 2);

        TenantContext::set($tenant->id);

        // İstisna fırlatmamalı.
        (new EnforceQuota)->check(QuotaMetric::PRODUCTS);

        $this->assertTrue(true);
    }

    /**
     * LİMİTE TAM DAYANAN KİRACI YENİSİNİ EKLEYEMEZ.
     *
     * "3 ürün" HAKKI üç üründür; dördüncü kotayı AŞAR. Karşılaştırma
     * `>=` ile yapılır ve bu sınır testi ters operatörle mutasyonlanır.
     */
    #[Test]
    public function a_tenant_exactly_at_the_limit_is_blocked(): void
    {
        $tenant = $this->tenantOnPlan(['products' => 3]);

        $this->productsFor($tenant, 3);

        TenantContext::set($tenant->id);

        $this->expectException(QuotaExceededException::class);

        (new EnforceQuota)->check(QuotaMetric::PRODUCTS);
    }

    /** Limitin üstündeki kiracı da engellenir (plan düşürülmüş olabilir). */
    #[Test]
    public function a_tenant_over_the_limit_is_blocked(): void
    {
        $tenant = $this->tenantOnPlan(['products' => 2]);

        $this->productsFor($tenant, 5);

        TenantContext::set($tenant->id);

        $this->expectException(QuotaExceededException::class);

        (new EnforceQuota)->check(QuotaMetric::PRODUCTS);
    }

    /** Kanal kotası ayrı bir metriktir ve ayrı sayılır. */
    #[Test]
    public function the_channel_quota_is_counted_separately(): void
    {
        $tenant = $this->tenantOnPlan(['products' => 100, 'channels' => 1]);

        $this->connectionsFor($tenant, 1);

        TenantContext::set($tenant->id);

        // Ürün kotası boş — engellenmemeli.
        (new EnforceQuota)->check(QuotaMetric::PRODUCTS);

        $this->expectException(QuotaExceededException::class);

        (new EnforceQuota)->check(QuotaMetric::CHANNELS);
    }

    /**
     * LİMİT TANIMLI DEĞİLSE SINIRSIZDIR.
     *
     * Sıfır sayılsaydı yeni bir kota türü eklendiği an tüm mevcut
     * planlar o kotada sıfıra düşer ve herkes engellenirdi.
     */
    #[Test]
    public function a_missing_limit_means_unlimited(): void
    {
        $tenant = $this->tenantOnPlan(['products' => 5]);   // channels YOK

        $this->connectionsFor($tenant, 3);

        TenantContext::set($tenant->id);

        (new EnforceQuota)->check(QuotaMetric::CHANNELS);

        $this->assertTrue(true);
    }

    /** `null` limit de sınırsız demektir — açıkça "limitsiz" yazan plan. */
    #[Test]
    public function a_null_limit_means_unlimited(): void
    {
        $tenant = $this->tenantOnPlan(['products' => null]);

        $this->productsFor($tenant, 50);

        TenantContext::set($tenant->id);

        (new EnforceQuota)->check(QuotaMetric::PRODUCTS);

        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------- abonelik

    /**
     * ABONELİĞİ OLMAYAN KİRACI VARSAYILAN PLANA DÜŞER — sınırsız DEĞİL.
     */
    #[Test]
    public function a_tenant_without_a_subscription_falls_back_to_the_default_plan(): void
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Aboneliksiz', owner: $user);

        // Varsayılan plan: 1 ürün.
        Plan::create([
            'code' => 'free',
            'name' => 'Ücretsiz',
            'price_monthly' => 0,
            'limits' => ['products' => 1],
        ]);

        $this->productsFor($tenant, 1);

        TenantContext::set($tenant->id);

        $this->expectException(QuotaExceededException::class);

        (new EnforceQuota)->check(QuotaMetric::PRODUCTS);
    }

    /**
     * İPTAL EDİLMİŞ ABONELİK KOTA VERMEZ — varsayılan plana düşülür.
     *
     * Verseydi bir kez abone olup iptal eden kiracı ücretli limitleri
     * SONSUZA KADAR kullanmaya devam ederdi.
     */
    #[Test]
    public function a_cancelled_subscription_does_not_grant_its_limits(): void
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'İptalci', owner: $user);

        Plan::create([
            'code' => 'free',
            'name' => 'Ücretsiz',
            'price_monthly' => 0,
            'limits' => ['products' => 1],
        ]);

        $pro = Plan::create([
            'code' => 'pro',
            'name' => 'Pro',
            'price_monthly' => 499,
            'limits' => ['products' => 1000],
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_code' => $pro->code,
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $this->productsFor($tenant, 1);

        TenantContext::set($tenant->id);

        $this->expectException(QuotaExceededException::class);

        (new EnforceQuota)->check(QuotaMetric::PRODUCTS);
    }

    /** Deneme süresindeki abonelik kotayı VERİR — ücretli gibi davranır. */
    #[Test]
    public function a_trialing_subscription_grants_its_limits(): void
    {
        $tenant = $this->tenantOnPlan(['products' => 100], status: 'trialing');

        $this->productsFor($tenant, 50);

        TenantContext::set($tenant->id);

        (new EnforceQuota)->check(QuotaMetric::PRODUCTS);

        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------- izolasyon

    /**
     * BAŞKA KİRACININ ÜRÜNLERİ KOTAYA SAYILMAZ.
     *
     * Sayılsaydı kalabalık bir kurulumda hiç ürünü olmayan kiracı bile
     * ilk ürününde engellenirdi.
     */
    #[Test]
    public function another_tenants_products_never_count_towards_the_quota(): void
    {
        $tenantA = $this->tenantOnPlan(['products' => 2]);
        $tenantB = $this->tenantOnPlan(['products' => 2], planCode: 'pro-b');

        $this->productsFor($tenantB, 5);

        TenantContext::set($tenantA->id);

        // A'nın hiç ürünü yok — engellenmemeli.
        (new EnforceQuota)->check(QuotaMetric::PRODUCTS);

        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------- mesaj

    /**
     * İSTİSNA LİMİTİ VE MEVCUT SAYIYI TAŞIR.
     *
     * "Kotanı aştın" tek başına kullanıcıya ne yapacağını söylemez;
     * hangi sınıra hangi sayıyla dayandığı gösterilmeli (ölü mektup
     * ekranının "değer ve eşik birlikte" kuralının aynısı).
     */
    #[Test]
    public function the_exception_carries_the_limit_and_the_current_count(): void
    {
        $tenant = $this->tenantOnPlan(['products' => 3]);

        $this->productsFor($tenant, 3);

        TenantContext::set($tenant->id);

        try {
            (new EnforceQuota)->check(QuotaMetric::PRODUCTS);
            $this->fail('Kota aşımında istisna bekleniyordu.');
        } catch (QuotaExceededException $e) {
            $this->assertSame(3, $e->limit);
            $this->assertSame(3, $e->current);
            $this->assertSame(QuotaMetric::PRODUCTS, $e->metric);
        }
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @param array<string, int|null> $limits */
    private function tenantOnPlan(
        array $limits,
        string $status = 'active',
        string $planCode = 'pro',
    ): Tenant {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Kota Testi', owner: $user);

        // Varsayılan plan her zaman bulunur — geri düşüş hedefi.
        Plan::firstOrCreate(
            ['code' => 'free'],
            ['name' => 'Ücretsiz', 'price_monthly' => 0, 'limits' => ['products' => 1]],
        );

        $plan = Plan::create([
            'code' => $planCode,
            'name' => 'Test Planı',
            'price_monthly' => 499,
            'limits' => $limits,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_code' => $plan->code,
            'status' => $status,
            'started_at' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        return $tenant;
    }

    private function productsFor(Tenant $tenant, int $count): void
    {
        TenantContext::runAsSystem(function () use ($tenant, $count): void {
            Product::factory()->count($count)->create(['tenant_id' => $tenant->id]);
        });
    }

    private function connectionsFor(Tenant $tenant, int $count): void
    {
        TenantContext::runAsSystem(function () use ($tenant, $count): void {
            ChannelType::query()->updateOrCreate(
                ['code' => 'woocommerce'],
                [
                    'name' => 'WooCommerce',
                    'kind' => 'marketplace',
                    'adapter_class' => WooCommerceAdapter::class,
                    'is_active' => true,
                ],
            );

            ChannelConnection::factory()->count($count)->create(['tenant_id' => $tenant->id]);
        });
    }
}
