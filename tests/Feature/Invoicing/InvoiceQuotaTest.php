<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Exceptions\QuotaExceededException;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Fulfillment\Enums\FulfillmentReason;
use App\Domain\Fulfillment\Support\InvoiceDocuments;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Invoicing\Actions\MaybeAutoInvoice;
use App\Domain\Invoicing\Actions\RequestInvoice;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Orders\Enums\OrderState;
use App\Domain\Orders\Models\Order;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Aylık fatura kotası (S6) — plan sınırı üç yolda da aynı kapıdan uygulanır.
 *
 * Sipariş ekranı, toplu "fatura kes" ve otomatik kesim `RequestInvoice`
 * üzerinden geçer; kota orada sorulur ve her yol istisnayı kendi dilinde
 * karşılar. Var olan fatura ve yeniden deneme hak harcamaz.
 */
final class InvoiceQuotaTest extends TestCase
{
    use InvoicingFixtures;
    use RefreshDatabase;

    #[Test]
    public function the_order_screen_refuses_a_new_invoice_when_the_monthly_quota_is_full(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->onPlan($tenant, 0);

        $this->actingAs($user)->post("/orders/{$order->id}/invoice")->assertSessionHasErrors('invoice');

        $this->assertSame(0, $this->asTenant($tenant, fn (): int => Invoice::query()->count()));
        Queue::assertNothingPushed();
    }

    #[Test]
    public function below_the_quota_the_invoice_is_opened(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->onPlan($tenant, 1);

        $this->actingAs($user)->post("/orders/{$order->id}/invoice")->assertSessionHasNoErrors();

        $this->assertSame(1, $this->asTenant($tenant, fn (): int => Invoice::query()->count()));
    }

    #[Test]
    public function an_existing_invoice_is_returned_without_spending_the_quota(): void
    {
        Queue::fake();
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->parasutAccount($tenant);
        $existing = $this->invoiceFor($tenant, $order, Invoice::STATUS_ISSUED, now());
        $this->onPlan($tenant, 0);

        $returned = $this->asTenant($tenant, fn (): Invoice => app(RequestInvoice::class)->run($order, $account));

        $this->assertSame($existing->id, $returned->id);
    }

    #[Test]
    public function only_this_months_non_failed_invoices_are_counted_in_istanbul_time(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->onPlan($tenant, 5);
        $usage = fn (): int => $this->asTenant($tenant, fn (): int => app(EnforceQuota::class)->currentUsage(QuotaMetric::INVOICES));

        // 1 Kasım 01:30 İstanbul = 31 Ekim 22:30 UTC — İstanbul'da yeni ay.
        $this->travelTo('2026-10-31 22:30:00');

        // 2 saat önce = 31 Ekim 23:30 İstanbul → geçen ay. UTC ile hesaplayan
        // kod bunu (ikisi de 31 Ekim UTC) bu aya sayardı.
        $invoice = $this->invoiceFor($tenant, $order, Invoice::STATUS_ISSUED, now()->subHours(2));
        $this->assertSame(0, $usage(), 'İstanbul saatiyle geçen aya ait fatura bu aya sayılmamalı.');

        $this->asTenant($tenant, fn () => $invoice->forceFill(['created_at' => now()])->save());
        $this->assertSame(1, $usage());

        $this->asTenant($tenant, fn () => $invoice->forceFill(['status' => Invoice::STATUS_FAILED])->save());
        $this->assertSame(0, $usage(), 'Kesilemeyen fatura hak harcamamalı.');
    }

    #[Test]
    public function bulk_invoicing_reports_the_quota_instead_of_queueing(): void
    {
        Queue::fake();
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->parasutAccount($tenant);
        $this->onPlan($tenant, 0);

        $this->asTenant($tenant, fn () => $order->forceFill(['state' => OrderState::AWAITING_SHIPMENT->value])->save());

        $decision = $this->asTenant($tenant, fn () => app(InvoiceDocuments::class)->issue(
            Order::query()->with('connection.channelType')->findOrFail($order->id),
            $account,
            null,
        ));

        $this->assertSame(FulfillmentReason::INVOICE_QUOTA_EXCEEDED, $decision->reason);
        $this->assertSame(0, $this->asTenant($tenant, fn (): int => Invoice::query()->count()));
    }

    #[Test]
    public function automatic_invoicing_skips_quietly_when_the_quota_is_full(): void
    {
        Queue::fake();
        Log::spy();
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant, ['settings' => [
            'companies' => [['id' => self::COMPANY, 'name' => 'Örnek Ticaret']],
            InvoiceAccount::SETTING_AUTO_ISSUE => InvoiceAccount::AUTO_SHIPPED,
            InvoiceAccount::SETTING_AUTO_ISSUE_SINCE => now()->subYear()->toIso8601String(),
        ]]);
        $this->onPlan($tenant, 0);

        $result = $this->asTenant($tenant, fn () => app(MaybeAutoInvoice::class)->run(
            Order::query()->findOrFail($order->id),
            MaybeAutoInvoice::STAGE_SHIPPED,
        ));

        $this->assertNull($result);
        $this->assertSame(0, $this->asTenant($tenant, fn (): int => Invoice::query()->count()));
        Log::shouldHaveReceived('info')->withArgs(fn (string $key): bool => $key === 'invoicing.auto_issue_quota_exceeded')->once();
        Log::shouldNotHaveReceived('error');
    }

    #[Test]
    public function the_exception_names_the_invoice_limit(): void
    {
        $e = new QuotaExceededException(QuotaMetric::INVOICES, limit: 100, current: 100, planCode: 'starter');

        $this->assertStringContainsString('100/100', $e->userMessage());
        $this->assertStringContainsString('fatura', $e->userMessage());
    }

    // ---------------------------------------------------------------- yardımcılar

    private function onPlan(Tenant $tenant, ?int $invoiceLimit): void
    {
        Plan::firstOrCreate(
            ['code' => EnforceQuota::DEFAULT_PLAN_CODE],
            ['name' => 'Ücretsiz', 'price_monthly' => 0, 'limits' => []],
        );

        $plan = Plan::create([
            'code' => 'fatura-'.uniqid(),
            'name' => 'Fatura testi',
            'price_monthly' => 99,
            'limits' => [QuotaMetric::INVOICES->value => $invoiceLimit],
        ]);

        TenantContext::runAsSystem(fn () => Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_code' => $plan->code,
            'status' => 'active',
            'started_at' => now(),
        ]));
    }

    private function invoiceFor(Tenant $tenant, Order $order, string $status, mixed $createdAt): Invoice
    {
        return $this->asTenant($tenant, function () use ($tenant, $order, $status, $createdAt): Invoice {
            $invoice = Invoice::query()->create([
                'tenant_id' => $tenant->id,
                'order_id' => $order->id,
                'provider' => InvoiceAccount::PROVIDER_PARASUT,
                'status' => $status,
            ]);
            $invoice->forceFill(['created_at' => $createdAt])->save();

            return $invoice;
        });
    }
}
