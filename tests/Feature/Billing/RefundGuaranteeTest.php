<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Actions\RefundGuarantee;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Exceptions\QuotaExceededException;
use App\Domain\Billing\Models\BillingRefund;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\AuditLog;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * S14 · 14 gün iade güvencesi, checkout'ta müşteri yeniden kullanımı,
 * satıcının dönem sonu iptali ve plan düşünce kota. Para hareketi YOK:
 * ağ geçidi `FakePaymentGateway`.
 */
final class RefundGuaranteeTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = 'admin@34devs.test';

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['entegrasyon.stripe.secret' => 'sk_test_dummy', 'entegrasyon.super_admin_emails' => self::ADMIN]);

        Plan::create(['code' => 'free', 'name' => 'Ücretsiz', 'price_monthly' => 0, 'limits' => ['products' => 2]]);
        Plan::create(['code' => 'pro', 'name' => 'Pro', 'price_monthly' => 1499, 'limits' => ['products' => 5000]]);

        $this->owner = User::factory()->create();
        $this->tenant = (new CreateTenant)->run(name: 'Güvence Mağaza', owner: $this->owner);
    }

    private function paidSubscription(int $daysAgo = 3, string $ref = 'sub_G', ?string $provider = null, string $status = 'active'): Subscription
    {
        return TenantContext::runAsSystem(function () use ($daysAgo, $ref, $provider, $status) {
            Tenant::query()->whereKey($this->tenant->id)->update(['billing_customer_ref' => 'cus_G', 'plan_code' => 'pro']);

            return Subscription::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id,
                'plan_code' => 'pro',
                'provider' => $provider ?? 'stripe',
                'status' => $status,
                'external_ref' => $ref,
                'started_at' => now()->subDays($daysAgo),
            ]);
        });
    }

    private function guarantee(): RefundGuarantee
    {
        return $this->app->make(RefundGuarantee::class);
    }

    #[Test]
    public function within_14_days_the_first_paid_subscription_is_refunded_and_downgraded_once(): void
    {
        $this->paidSubscription(daysAgo: 13);

        $record = $this->guarantee()->run($this->tenant->id);

        $this->assertSame(['cus_G'], $this->payments->refunds);
        $this->assertSame([['ref' => 'sub_G', 'prorate' => false]], $this->payments->immediateCancellations);
        $this->assertTrue($record->guarantee);
        $this->assertSame('499.00', (string) $record->amount);
        $this->assertSame('free', TenantContext::runAsSystem(fn () => Tenant::query()->whereKey($this->tenant->id)->value('plan_code')));
        $this->assertSame('cancelled', TenantContext::runAsSystem(fn () => Subscription::withoutGlobalScopes()->value('status')));
        $this->assertSame(1, TenantContext::runAsSystem(fn () => AuditLog::withoutGlobalScopes()->where('action', 'billing.refund_recorded')->count()));

        // Hesap başına BİR KEZ — yeni abonelik açsa bile.
        $this->paidSubscription(daysAgo: 1, ref: 'sub_G2');
        $check = $this->guarantee()->eligibility($this->tenant->id);
        $this->assertFalse($check['eligible']);
        $this->assertStringContainsString('daha önce kullandı', (string) $check['reason']);
    }

    #[Test]
    public function after_14_days_it_is_not_eligible(): void
    {
        $this->paidSubscription(daysAgo: 15);

        $check = $this->guarantee()->eligibility($this->tenant->id);

        $this->assertFalse($check['eligible']);
        $this->assertStringContainsString('14 günden fazla', (string) $check['reason']);
        $this->expectException(RuntimeException::class);
        $this->guarantee()->run($this->tenant->id);
    }

    #[Test]
    public function only_the_first_paid_subscription_qualifies(): void
    {
        $this->paidSubscription(daysAgo: 60, ref: 'sub_eski', status: 'cancelled');
        $this->paidSubscription(daysAgo: 2, ref: 'sub_yeni');

        $check = $this->guarantee()->eligibility($this->tenant->id);

        $this->assertFalse($check['eligible']);
        $this->assertStringContainsString('İLK ücretli', (string) $check['reason']);
    }

    #[Test]
    public function shopify_billed_subscriptions_are_refunded_in_shopify_not_here(): void
    {
        $this->paidSubscription(daysAgo: 2, ref: 'gid://shopify/AppSubscription/1', provider: 'shopify');

        $check = $this->guarantee()->eligibility($this->tenant->id);

        $this->assertFalse($check['eligible']);
        $this->assertStringContainsString('Shopify Partner', (string) $check['reason']);
    }

    #[Test]
    public function a_failed_provider_refund_writes_nothing(): void
    {
        $this->paidSubscription(daysAgo: 2);
        $this->payments->failNextCall = true;

        try {
            $this->guarantee()->run($this->tenant->id);
            $this->fail('iade hatası yutuldu');
        } catch (\Throwable) {
            // beklenen
        }

        $this->assertSame(0, TenantContext::runAsSystem(fn () => BillingRefund::withoutGlobalScopes()->count()));
        $this->assertSame('pro', TenantContext::runAsSystem(fn () => Tenant::query()->whereKey($this->tenant->id)->value('plan_code')));
        $this->assertSame([], $this->payments->immediateCancellations);
    }

    #[Test]
    public function only_a_super_admin_can_trigger_the_refund_and_must_confirm(): void
    {
        $this->paidSubscription(daysAgo: 2);
        $url = "/admin/tenants/{$this->tenant->id}/refund-guarantee";

        $this->actingAs($this->owner)->post($url, ['confirm' => true])->assertForbidden();
        $this->assertSame([], $this->payments->refunds);

        $admin = User::factory()->create(['email' => self::ADMIN]);
        $this->actingAs($admin)->post($url, [])->assertSessionHasErrors('confirm');
        $this->assertSame([], $this->payments->refunds);

        $this->actingAs($admin)->post($url, ['confirm' => true])->assertRedirect("/admin/tenants/{$this->tenant->id}")->assertSessionHas('success');
        $this->assertSame(['cus_G'], $this->payments->refunds);

        $page = $this->actingAs($admin)->get("/admin/tenants/{$this->tenant->id}")->assertOk();
        $this->assertFalse($page->viewData('page')['props']['refundGuarantee']['eligible']);
        $this->assertCount(1, $page->viewData('page')['props']['refunds']);
    }

    #[Test]
    public function checkout_reuses_the_stored_customer_instead_of_creating_orphans(): void
    {
        $this->actingAs($this->owner)->post('/billing/checkout', ['plan_code' => 'pro', 'accept_terms' => true]);
        $this->actingAs($this->owner)->post('/billing/checkout', ['plan_code' => 'pro', 'accept_terms' => true]);

        $this->assertSame(1, $this->payments->customersCreated, 'terk edilen her checkout yeni müşteri açtı');
        $this->assertCount(2, $this->payments->checkouts);
        $stored = TenantContext::runAsSystem(fn () => Tenant::query()->whereKey($this->tenant->id)->value('billing_customer_ref'));
        $this->assertSame('cus_test_1', $stored);
        $this->assertSame('cus_test_1', $this->payments->checkouts[1]['customer']);
    }

    #[Test]
    public function choosing_free_with_a_stripe_subscription_cancels_at_period_end_not_now(): void
    {
        $this->paidSubscription(daysAgo: 20);

        $this->actingAs($this->owner)->post('/billing/checkout', ['plan_code' => 'free'])->assertRedirect('/billing')->assertSessionHas('success');

        $this->assertSame(['sub_G'], $this->payments->periodEndCancellations);
        $this->assertSame([], $this->payments->immediateCancellations, 'satıcının iptali hemen kesmemeli');
        $this->assertSame('active', TenantContext::runAsSystem(fn () => Subscription::withoutGlobalScopes()->value('status')), 'yerel durumu webhook yazar');

        TenantContext::runAsSystem(fn () => Subscription::withoutGlobalScopes()->update(['cancel_at' => now()->addDays(10)]));
        $this->actingAs($this->owner)->post('/billing/checkout', ['plan_code' => 'free'])->assertSessionHasErrors('plan_code');
        $this->assertCount(1, $this->payments->periodEndCancellations);
    }

    #[Test]
    public function after_downgrade_existing_products_stay_and_only_new_ones_are_blocked(): void
    {
        $this->paidSubscription(daysAgo: 2);
        TenantContext::runFor($this->tenant->id, fn () => Product::factory()->count(5)->create());

        $this->guarantee()->run($this->tenant->id);

        TenantContext::runFor($this->tenant->id, function (): void {
            $this->assertSame(5, Product::query()->count(), 'plan düşünce ürün silindi');
            $this->assertSame('free', app(EnforceQuota::class)->planForCurrentTenant()?->code);

            $this->expectException(QuotaExceededException::class);
            app(EnforceQuota::class)->check(QuotaMetric::PRODUCTS);
        });
    }
}
