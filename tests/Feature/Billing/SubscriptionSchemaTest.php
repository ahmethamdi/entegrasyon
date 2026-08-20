<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Abonelik şeması — §4'ün kısıtları DB tarafından zorlanır.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · subscriptions ·
 * UNIQUE(tenant_id) WHERE status = 'active'.
 *
 * Bu testler UYGULAMA MANTIĞINI değil VERİTABANI KISITINI sınar:
 * uygulama katmanındaki bir kontrol unutulabilir veya atlanabilir
 * (kuyruk işi, konsol komutu, elle SQL), kısıt atlanamaz.
 */
final class SubscriptionSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * KİRACI BAŞINA EN FAZLA BİR AKTİF ABONELİK.
     *
     * İkincisi açılabilseydi kiracı iki kez ücretlendirilir ve hangi
     * planın geçerli olduğu belirsiz kalırdı.
     */
    #[Test]
    public function a_tenant_cannot_hold_two_active_subscriptions(): void
    {
        [$tenant, $plan] = $this->context();

        $this->subscribe($tenant, $plan, 'active');

        $this->expectException(QueryException::class);

        $this->subscribe($tenant, $plan, 'active');
    }

    /**
     * `trialing` DE AKTİF SAYILIR.
     *
     * Sayılmasaydı deneme süresindeki kiracı ikinci bir abonelik açar
     * ve iki kez ücretlendirilirdi.
     */
    #[Test]
    public function a_trialing_subscription_blocks_a_second_active_one(): void
    {
        [$tenant, $plan] = $this->context();

        $this->subscribe($tenant, $plan, 'trialing');

        $this->expectException(QueryException::class);

        $this->subscribe($tenant, $plan, 'active');
    }

    /**
     * İPTAL EDİLMİŞ ABONELİK YENİSİNİ ENGELLEMEZ — ve SİLİNMEZ.
     *
     * Kısmi tekilliğin varlık sebebi budur: tarihçe korunurken yeni
     * aboneliğe izin verilir.
     */
    #[Test]
    public function a_cancelled_subscription_leaves_room_for_a_new_one(): void
    {
        [$tenant, $plan] = $this->context();

        $old = $this->subscribe($tenant, $plan, 'cancelled');

        $new = $this->subscribe($tenant, $plan, 'active');

        $this->assertNotSame($old->id, $new->id);

        // Eskisi DURUYOR — gelir geçmişi silinmedi.
        $this->assertDatabaseHas('subscriptions', [
            'id' => $old->id,
            'status' => 'cancelled',
        ]);
    }

    /**
     * UZAK ABONELİK KİMLİĞİ TEKİLDİR — webhook tekrarına karşı çıpa.
     *
     * Stripe olayları EN AZ BİR KEZ gönderilir; aynı `sub_...` iki kez
     * işlenirse ikinci bir abonelik satırı doğar ve kiracı iki planda
     * görünürdü.
     */
    #[Test]
    public function the_same_external_reference_cannot_be_stored_twice(): void
    {
        [$tenant, $plan] = $this->context();

        $this->subscribe($tenant, $plan, 'cancelled', externalRef: 'sub_ABC123');

        $this->expectException(QueryException::class);

        $this->subscribe($tenant, $plan, 'cancelled', externalRef: 'sub_ABC123');
    }

    /**
     * BOŞ `external_ref` TEKİLLİĞİ İHLAL ETMEZ.
     *
     * Kısmi indeks olmasaydı ücretsiz plandaki ikinci kiracı
     * kaydedilemezdi — ikisi de NULL taşır.
     */
    #[Test]
    public function multiple_subscriptions_may_have_no_external_reference(): void
    {
        [$tenant, $plan] = $this->context();

        $this->subscribe($tenant, $plan, 'cancelled');
        $this->subscribe($tenant, $plan, 'cancelled');

        $this->assertSame(2, TenantContext::runAsSystem(
            fn (): int => Subscription::withoutGlobalScopes()->count(),
        ));
    }

    /** Plan kataloğu KİRACIYA AİT DEĞİLDİR — bağlamsız okunabilir. */
    #[Test]
    public function plans_are_readable_without_a_tenant_context(): void
    {
        Plan::create([
            'code' => 'starter',
            'name' => 'Başlangıç',
            'price_monthly' => 199,
            'limits' => ['products' => 100],
        ]);

        TenantContext::clear();

        $plan = Plan::query()->find('starter');

        $this->assertNotNull($plan, 'Plan kataloğu bağlamsız okunabilmeli.');
        $this->assertSame(100, $plan->limitFor(QuotaMetric::PRODUCTS));
    }

    /** Fiyat kuruşa çevrilirken kayma olmaz — Stripe en küçük birimi ister. */
    #[Test]
    public function the_price_converts_to_minor_units_without_drift(): void
    {
        $plan = Plan::create([
            'code' => 'kurus',
            'name' => 'Kuruş Testi',
            'price_monthly' => '499.90',
            'limits' => [],
        ]);

        $this->assertSame(49990, $plan->fresh()->priceInMinorUnits());
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: Plan} */
    private function context(): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Abonelik Testi', owner: $user);

        $plan = Plan::create([
            'code' => 'pro',
            'name' => 'Pro',
            'price_monthly' => 499,
            'limits' => ['products' => 1000],
        ]);

        return [$tenant, $plan];
    }

    private function subscribe(
        Tenant $tenant,
        Plan $plan,
        string $status,
        ?string $externalRef = null,
    ): Subscription {
        return TenantContext::runAsSystem(fn (): Subscription => Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_code' => $plan->code,
            'status' => $status,
            'started_at' => now(),
            'external_ref' => $externalRef,
        ]));
    }
}
