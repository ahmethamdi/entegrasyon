<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Models\BillingRefund;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\AuditLog;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\StripeWebhookController;
use App\Mail\PaymentFailedMail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * S14 · abonelik yaşam döngüsü — gerçek imzalı webhook'larla (Stripe'a
 * istek yok; ağ geçidi `FakePaymentGateway`).
 *
 * Kapsanan: olay tekilliği, başarısız ödeme postası, alınan ödeme, tam ve
 * kısmi iade (tam iade → hemen ücretsiz), dönem sonu iptali, aktivasyon /
 * plan değişimi / past_due / iptal ve hepsinin denetim kaydı.
 */
final class BillingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_s14_test';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('entegrasyon.stripe.webhook_secret', self::SECRET);
        config()->set('entegrasyon.stripe.secret', 'sk_test_dummy');
        Mail::fake();

        $owner = User::factory()->create(['email' => 'sahip@magaza.com.tr']);
        $this->tenant = (new CreateTenant)->run(name: 'Mağaza A', owner: $owner);

        Plan::create(['code' => 'free', 'name' => 'Ücretsiz', 'price_monthly' => 0, 'limits' => ['products' => 2]]);
        Plan::create(['code' => 'pro', 'name' => 'Pro', 'price_monthly' => 1499, 'limits' => ['products' => 5000]]);
        Plan::create(['code' => 'starter', 'name' => 'Başlangıç', 'price_monthly' => 499, 'limits' => ['products' => 500]]);

        TenantContext::runAsSystem(fn () => Tenant::query()->whereKey($this->tenant->id)->update(['billing_customer_ref' => 'cus_A']));

    }

    #[Test]
    public function the_webhook_listens_to_exactly_the_documented_events(): void
    {
        // Dashboard'daki uç nokta BU listeyle eşleşmeli (RUNBOOK §3.5, Step 7 raporu).
        $this->assertSame([
            'checkout.session.completed',
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'invoice.payment_failed',
            'invoice.paid',
            'charge.refunded',
        ], StripeWebhookController::HANDLED_EVENTS);
    }

    #[Test]
    public function checkout_activation_plan_change_past_due_and_cancel_are_all_recorded(): void
    {
        $this->activate();
        $this->assertSame('active', $this->subscription()->status);
        $this->assertSame('pro', $this->tenantPlan());

        $this->send('evt_up', 'customer.subscription.updated', ['id' => 'sub_A', 'status' => 'active', 'metadata' => ['plan_code' => 'starter']]);
        $this->assertSame('starter', $this->subscription()->plan_code);

        $this->send('evt_pd', 'customer.subscription.updated', ['id' => 'sub_A', 'status' => 'past_due']);
        $this->assertSame('past_due', $this->subscription()->status);
        // past_due KOTA VERMEZ: limitler ücretsize düşer, veri durur.
        $this->assertSame('free', TenantContext::runFor($this->tenant->id, fn () => app(EnforceQuota::class)->planForCurrentTenant()?->code));

        $this->send('evt_del', 'customer.subscription.deleted', ['id' => 'sub_A', 'status' => 'canceled']);
        $this->assertSame('cancelled', $this->subscription()->status);

        $this->assertSame(
            ['billing.subscription_activated', 'billing.subscription_plan_changed', 'billing.subscription_cancelled'],
            $this->auditActions(['billing.subscription_activated', 'billing.subscription_plan_changed', 'billing.subscription_cancelled']),
        );
    }

    #[Test]
    public function a_repeated_event_is_processed_once(): void
    {
        $this->activate();
        $invoice = $this->failedInvoice();

        $this->send('evt_fail_1', 'invoice.payment_failed', $invoice)->assertOk();
        $this->send('evt_fail_1', 'invoice.payment_failed', $invoice)->assertOk();

        Mail::assertSent(PaymentFailedMail::class, 1);
        $this->assertCount(1, $this->auditActions(['billing.payment_failed']));
    }

    #[Test]
    public function an_event_that_failed_midway_is_processed_again_on_retry(): void
    {
        $this->activate();
        // İlk denemede posta sunucusu düşer → olay damgalanmaz → Stripe tekrar dener.
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp yok'));

        try {
            $this->send('evt_fail_retry', 'invoice.payment_failed', $this->failedInvoice());
        } catch (RuntimeException) {
            // Laravel test istemcisi istisnayı yükseltir; gerçek istekte 500 olur.
        }

        $this->assertNull(TenantContext::runAsSystem(fn () => \DB::table('billing_webhook_events')->where('event_id', 'evt_fail_retry')->value('processed_at')));

        Mail::swap(new MailFake(new MailManager($this->app)));
        $this->send('evt_fail_retry', 'invoice.payment_failed', $this->failedInvoice())->assertOk();

        Mail::assertSent(PaymentFailedMail::class, 1);
        $this->assertSame(2, (int) \DB::table('billing_webhook_events')->where('event_id', 'evt_fail_retry')->value('attempts'));
    }

    #[Test]
    public function failed_payment_mails_the_owner_in_turkish_with_the_card_hint(): void
    {
        $this->activate();

        $this->send('evt_fail_2', 'invoice.payment_failed', $this->failedInvoice())->assertOk();

        Mail::assertSent(PaymentFailedMail::class, function (PaymentFailedMail $mail): bool {
            $html = $mail->render();

            return $mail->hasTo('sahip@magaza.com.tr')
                && $mail->amount === '1.499,00 ₺'
                && $mail->payUrl === 'https://invoice.stripe.com/i/acct_x/inv_1'
                && str_contains($mail->envelope()->subject, 'ödemen alınamadı')
                && str_contains($html, 'yurt dışı ve internet alışverişi');
        });
    }

    #[Test]
    public function failed_payment_resolves_the_tenant_through_the_subscription_when_the_customer_is_unknown(): void
    {
        $this->activate();
        $invoice = $this->failedInvoice();
        $invoice['customer'] = 'cus_bilinmeyen';
        unset($invoice['subscription']);
        $invoice['parent'] = ['subscription_details' => ['subscription' => 'sub_A']]; // 2025-03-31 basil biçimi

        $this->send('evt_fail_3', 'invoice.payment_failed', $invoice)->assertOk();

        Mail::assertSent(PaymentFailedMail::class, 1);
    }

    #[Test]
    public function an_unknown_tenant_gets_no_mail_and_stripe_still_gets_2xx(): void
    {
        $invoice = $this->failedInvoice();
        $invoice['customer'] = 'cus_kimse';
        $invoice['subscription'] = 'sub_kimse';

        $this->send('evt_fail_4', 'invoice.payment_failed', $invoice)->assertOk();

        Mail::assertNothingSent();
    }

    #[Test]
    public function a_paid_invoice_is_audited_and_a_zero_invoice_is_not(): void
    {
        $this->activate();

        $this->send('evt_paid_1', 'invoice.paid', ['id' => 'in_1', 'customer' => 'cus_A', 'amount_paid' => 149900, 'currency' => 'try', 'billing_reason' => 'subscription_cycle'])->assertOk();
        $this->send('evt_paid_0', 'invoice.paid', ['id' => 'in_0', 'customer' => 'cus_A', 'amount_paid' => 0, 'currency' => 'try'])->assertOk();

        $this->assertCount(1, $this->auditActions(['billing.payment_succeeded']));
    }

    #[Test]
    public function a_full_refund_downgrades_to_free_at_once_and_cancels_without_proration(): void
    {
        $this->activate();

        $this->send('evt_ref_1', 'charge.refunded', $this->charge(refunded: 149900, full: true))->assertOk();

        $this->assertSame('cancelled', $this->subscription()->status);
        $this->assertSame('free', $this->tenantPlan());
        $this->assertSame([['ref' => 'sub_A', 'prorate' => false]], $this->payments->immediateCancellations);

        $refund = TenantContext::runAsSystem(fn () => BillingRefund::withoutGlobalScopes()->first());
        $this->assertSame('1499.00', (string) $refund->amount);
        $this->assertTrue($refund->full);
        $this->assertFalse($refund->guarantee);

        // Aynı ödeme için ikinci olay (farklı evt_): ikinci iptal/düşürme yok.
        $this->send('evt_ref_1b', 'charge.refunded', $this->charge(refunded: 149900, full: true))->assertOk();
        $this->assertCount(1, $this->payments->immediateCancellations);
        $this->assertCount(1, $this->auditActions(['billing.downgraded_to_free']));
    }

    #[Test]
    public function a_partial_refund_is_recorded_but_keeps_the_plan(): void
    {
        $this->activate();

        $this->send('evt_ref_2', 'charge.refunded', $this->charge(refunded: 50000, full: false))->assertOk();

        $this->assertSame('active', $this->subscription()->status);
        $this->assertSame('pro', $this->tenantPlan());
        $this->assertSame([], $this->payments->immediateCancellations);
        $this->assertSame('500.00', (string) TenantContext::runAsSystem(fn () => BillingRefund::withoutGlobalScopes()->value('amount')));
    }

    #[Test]
    public function a_refund_for_another_tenant_never_touches_this_one(): void
    {
        $this->activate();
        $other = (new CreateTenant)->run(name: 'Mağaza B', owner: User::factory()->create());
        TenantContext::runAsSystem(fn () => Tenant::query()->whereKey($other->id)->update(['billing_customer_ref' => 'cus_B']));

        $charge = $this->charge(refunded: 149900, full: true);
        $charge['customer'] = 'cus_B';
        $this->send('evt_ref_b', 'charge.refunded', $charge)->assertOk();

        $this->assertSame('active', $this->subscription()->status);
        $this->assertSame('pro', $this->tenantPlan());
        $this->assertSame([], $this->payments->immediateCancellations);
    }

    #[Test]
    public function cancel_at_period_end_is_stored_and_cleared_on_resume(): void
    {
        $this->activate();
        $end = time() + 20 * 86400;

        $this->send('evt_c1', 'customer.subscription.updated', ['id' => 'sub_A', 'status' => 'active', 'cancel_at' => $end, 'cancel_at_period_end' => true, 'current_period_end' => $end]);
        $this->assertSame($end, $this->subscription()->cancel_at?->getTimestamp());
        $this->assertSame('active', $this->subscription()->status, 'dönem sonuna kadar kota sürer');

        $this->send('evt_c2', 'customer.subscription.updated', ['id' => 'sub_A', 'status' => 'active', 'cancel_at' => null, 'cancel_at_period_end' => false, 'current_period_end' => $end]);
        $this->assertNull($this->subscription()->cancel_at);

        $this->assertCount(1, $this->auditActions(['billing.subscription_cancel_scheduled']));
    }

    // ---------------------------------------------------------------- yardımcılar

    private function activate(): void
    {
        $this->send('evt_co', 'checkout.session.completed', [
            'id' => 'cs_1',
            'subscription' => 'sub_A',
            'customer' => 'cus_A',
            'metadata' => ['tenant_id' => $this->tenant->id, 'plan_code' => 'pro'],
        ])->assertOk();
    }

    /** @return array<string, mixed> */
    private function failedInvoice(): array
    {
        return [
            'id' => 'in_fail',
            'customer' => 'cus_A',
            'subscription' => 'sub_A',
            'amount_due' => 149900,
            'currency' => 'try',
            'attempt_count' => 1,
            'next_payment_attempt' => time() + 3 * 86400,
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_x/inv_1',
        ];
    }

    /** @return array<string, mixed> */
    private function charge(int $refunded, bool $full): array
    {
        return ['id' => 'ch_A1', 'customer' => 'cus_A', 'amount' => 149900, 'amount_refunded' => $refunded, 'refunded' => $full, 'currency' => 'try'];
    }

    /** @param array<string, mixed> $object */
    private function send(string $eventId, string $type, array $object): TestResponse
    {
        $payload = ['id' => $eventId, 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = time();

        return $this->call('POST', '/webhooks/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET)),
        ], content: $body);
    }

    private function subscription(): Subscription
    {
        return TenantContext::runAsSystem(fn () => Subscription::withoutGlobalScopes()->where('external_ref', 'sub_A')->firstOrFail());
    }

    private function tenantPlan(): ?string
    {
        return TenantContext::runAsSystem(fn () => Tenant::query()->whereKey($this->tenant->id)->value('plan_code'));
    }

    /** @param list<string> $actions @return list<string> */
    private function auditActions(array $actions): array
    {
        return TenantContext::runAsSystem(fn () => AuditLog::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('action', $actions)
            ->orderBy('occurred_at')->orderBy('id')
            ->pluck('action')->all());
    }
}
