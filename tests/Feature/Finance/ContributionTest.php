<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Domain\Catalog\Actions\SetVariantCost;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Finance\Actions\IngestFinancialTransactions;
use App\Domain\Finance\Support\ContributionCalculator;
use App\Domain\Finance\Support\ContributionView;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Orders\Actions\RecordOrderCharge;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderLine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Support\Finance\BuildsFinanceScenario;
use Tests\TestCase;

/**
 * S1 · Katkı (KDV dahil) — beklenen ve gerçekleşen, çift sayım korumaları,
 * eksik durumu, iz ve rapor ekranı.
 *
 * Canlı satış satırı: alacak 1.789,00 · komisyon 339,91 · hakediş 1.449,09.
 */
final class ContributionTest extends TestCase
{
    use BuildsFinanceScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-11 12:00:00');
    }

    // ---------------------------------------------------- satış hakedişi

    #[Test]
    public function the_live_sale_settles_sale_and_commission_but_stays_incomplete_without_verified_cargo(): void
    {
        [$tenant, $connection, $order] = $this->settledScenario(net: '1789.00', cost: '1000.00');

        $expected = $this->expected($tenant, $order);
        $this->assertSame(['1789.00', '0.00', null, null, '0.00', '-1000.00'], $this->parts($expected));
        $this->assertSame(ContributionView::INCOMPLETE, $expected->status, 'Beklenen komisyon/kargo bilinmiyor.');
        $this->assertNull($expected->contribution);
        $this->assertSame('789.00', $expected->partial);

        $settled = $this->settled($tenant, $order);
        $this->assertSame(['1789.00', '0.00', '-339.91', null, '0.00', '-1000.00'], $this->parts($settled));
        $this->assertSame(ContributionView::INCOMPLETE, $settled->status);
        $this->assertNull($settled->contribution, 'Eksik katkı son rakam olarak verilmez.');
        $this->assertSame('449.09', $settled->partial, '1789 − 339.91 − 1000');
        $this->assertContains('Kargo kesintisi henüz doğrulanmadı (kargo faturası örneği yok)', $settled->reasons);
    }

    #[Test]
    public function the_commission_invoice_never_deducts_the_same_commission_twice(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->trendyolOrder($tenant, $connection, '3000000001', '10000000001', [['net' => '1789.00', 'cost' => '1000.00']]);
        // Satış satırı + aynı komisyonun faturası (otherfinancials, borç 339.91).
        $this->fakeFinance($this->livePage('settlements'), $this->livePage('otherfinancials'));
        app(IngestFinancialTransactions::class)->forConnection($connection->fresh(['channelType']));

        $settled = $this->settled($tenant, $order);

        $this->assertSame('-339.91', $settled->commission, 'Komisyon BİR kez.');
        $this->assertSame('449.09', $settled->partial);

        $invoice = collect($settled->trace)->firstWhere('ref', 'DCA2026000453682');
        $this->assertNotNull($invoice, 'Fatura izde görünür.');
        $this->assertFalse($invoice['included'], 'Fatura katkıya girmez.');
        $this->assertSame('-339.91', $invoice['amount']);
    }

    /**
     * Gelecekte bir eşleme stratejisi komisyon faturasını siparişe bağlasa
     * bile komisyon iki kez düşülmez (S1 kararı 5).
     */
    #[Test]
    public function a_commission_invoice_matched_to_the_order_is_still_not_a_second_deduction(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->trendyolOrder($tenant, $connection, '3000000001', '10000000001', [['net' => '1789.00', 'cost' => '1000.00']]);
        $this->fakeFinance($this->livePage('settlements'), $this->livePage('otherfinancials'));
        app(IngestFinancialTransactions::class)->forConnection($connection->fresh(['channelType']));

        // Yorum katmanında elle bağla (ham olgu değişmez).
        DB::table('channel_financial_transactions')->where('external_id', 'DCA2026000453682')
            ->update(['match_status' => 'matched', 'order_id' => $order->id]);

        $settled = $this->settled($tenant, $order);

        $this->assertSame('-339.91', $settled->commission);
        $this->assertSame('449.09', $settled->partial);
        $this->assertNotContains('1 eşleşmiş işlem doğrulanmamış ya da tanınmayan türde', $settled->reasons);
    }

    #[Test]
    public function expected_contribution_ignores_actual_charges(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->trendyolOrder($tenant, $connection, 'P-8', 'N-8', [['net' => '100.00', 'cost' => '10.00']]);
        TenantContext::runFor($tenant->id, function () use ($order): void {
            $record = app(RecordOrderCharge::class);
            $record->run($order, 'commission', 'expected', '-10.00', 'TRY', 'order', 'CE', now());
            $record->run($order, 'commission', 'actual', '-12.00', 'TRY', 'settlement', 'CA', now());
        });

        $this->assertSame('-10.00', $this->expected($tenant, $order)->commission, 'Beklenen yalnız beklenen kesintiden.');
    }

    #[Test]
    public function an_unverified_or_unknown_matched_transaction_makes_settled_incomplete_and_is_excluded(): void
    {
        [$tenant, $connection, $order] = $this->settledScenario(net: '1789.00', cost: '1000.00', extraRows: [
            $this->saleRow(['id' => 'NEG', 'transactionType' => 'CommissionNegative', 'credit' => 0, 'debt' => 50, 'commissionAmount' => null, 'sellerRevenue' => null]),
            $this->saleRow(['id' => 'UNK', 'transactionType' => 'Hizmet Bedeli', 'credit' => 0, 'debt' => 9.9, 'commissionAmount' => null, 'sellerRevenue' => null]),
        ]);

        $settled = $this->settled($tenant, $order);

        $this->assertSame('1789.00', $settled->sale, 'Doğrulanmamış satırlar satışa eklenmez.');
        $this->assertSame('-339.91', $settled->commission);
        $this->assertNull($settled->other, 'Doğrulanmamış kesinti BİLİNMİYOR, 0 değil.');
        $this->assertSame(ContributionView::INCOMPLETE, $settled->status);
        $this->assertContains('2 eşleşmiş işlem doğrulanmamış ya da tanınmayan türde', $settled->reasons);

        $excluded = collect($settled->trace)->where('included', false)->pluck('ref')->all();
        $this->assertContains('NEG', $excluded);
        $this->assertContains('UNK', $excluded);
    }

    #[Test]
    public function an_order_without_settlement_is_not_available_not_zero(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->trendyolOrder($tenant, $connection, '3000000777', '777', [['net' => '50.00', 'cost' => '20.00']]);

        $settled = $this->settled($tenant, $order);

        $this->assertSame(ContributionView::NOT_AVAILABLE, $settled->status);
        $this->assertNull($settled->sale);
        $this->assertNull($settled->contribution);
    }

    // ------------------------------------------------------ iade/iptal

    #[Test]
    public function no_return_means_a_known_zero_settled_refund(): void
    {
        [$tenant, , $order] = $this->settledScenario(net: '1789.00', cost: '1000.00');

        $this->assertSame('0.00', $this->settled($tenant, $order)->refund);
    }

    #[Test]
    public function a_full_refund_is_expected_only_and_never_deducted_twice(): void
    {
        [$tenant, $connection, $order] = $this->settledScenario(net: '1789.00', cost: '1000.00', returned: 1);
        $this->refund($tenant, $order, '1789.00', 'R-FULL');

        $expected = $this->expected($tenant, $order);
        $this->assertSame('-1789.00', $expected->refund);
        $this->assertSame('0.00', $expected->cost, 'İade edilen adet maliyete girmez.');

        $settled = $this->settled($tenant, $order);
        $this->assertNull($settled->refund, 'Trendyol iade anlamı doğrulanmadı → bilinmiyor.');
        $this->assertSame(ContributionView::INCOMPLETE, $settled->status);
        $this->assertSame('1449.09', $settled->partial, 'İç iade kaydı gerçekleşene EKLENMEZ: 1789 − 339.91 − 0 maliyet.');
    }

    #[Test]
    public function a_partial_monetary_refund_is_carried_exactly(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->trendyolOrder($tenant, $connection, 'P-1', 'N-1', [['net' => '200.00', 'cost' => '80.00', 'qty' => 2]]);
        $this->refund($tenant, $order, '33.33', 'R-PART');

        $expected = $this->expected($tenant, $order);

        $this->assertSame(['200.00', '-33.33', '-160.00'], [$expected->sale, $expected->refund, $expected->cost]);
        $this->assertSame('6.67', $expected->partial);
    }

    #[Test]
    public function a_partial_quantity_return_after_settlement_makes_settled_incomplete(): void
    {
        [$tenant, , $order] = $this->settledScenario(net: '1789.00', cost: '500.00', qty: 2);
        $this->assertSame('-1000.00', $this->settled($tenant, $order)->cost);

        TenantContext::runFor($tenant->id, fn () => OrderLine::query()->where('order_id', $order->id)->update(['quantity_returned' => 1]));

        $settled = $this->settled($tenant, $order);
        $this->assertNull($settled->refund);
        $this->assertSame('-500.00', $settled->cost, 'Stoğa dönen adet maliyetten düşer.');
        $this->assertContains('İade/iptal var; Trendyol iade hakedişi henüz doğrulanmadı', $settled->reasons);
    }

    #[Test]
    public function a_settlement_adjustment_after_return_is_not_double_counted(): void
    {
        [$tenant, , $order] = $this->settledScenario(net: '1789.00', cost: '1000.00', returned: 1, extraRows: [
            $this->saleRow(['id' => 'RET', 'transactionType' => 'Return', 'credit' => 0, 'debt' => 1789, 'commissionAmount' => 339.91, 'sellerRevenue' => -1449.09]),
        ]);
        $this->refund($tenant, $order, '1789.00', 'R-1');

        $settled = $this->settled($tenant, $order);
        $expected = $this->expected($tenant, $order);

        $this->assertSame('1789.00', $settled->sale, 'Doğrulanmamış Return satışı düşürmez.');
        $this->assertNull($settled->refund);
        $this->assertSame('-1789.00', $expected->refund, 'Beklenen iade bir kez.');
        $this->assertFalse(collect($settled->trace)->firstWhere('ref', 'RET')['included']);
    }

    #[Test]
    public function a_duplicate_refund_record_is_counted_once(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->trendyolOrder($tenant, $connection, 'P-2', 'N-2', [['net' => '100.00', 'cost' => '10.00']]);
        $this->refund($tenant, $order, '40.00', 'R-DUP');
        $this->refund($tenant, $order, '40.00', 'R-DUP');

        $this->assertSame('-40.00', $this->expected($tenant, $order)->refund);
    }

    // ------------------------------------------------------- para doğruluğu

    #[Test]
    public function multiple_expected_deductions_negative_adjustment_and_fractional_rate_reconcile_to_the_cent(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->trendyolOrder($tenant, $connection, 'P-3', 'N-3', [['net' => '120.00', 'cost' => '50.00']]);

        TenantContext::runFor($tenant->id, function () use ($order): void {
            $record = app(RecordOrderCharge::class);
            $record->run($order, 'commission', 'expected', '-15.11', 'TRY', 'order', 'C-1', now(), rate: '12.5917');
            $record->run($order, 'shipping_fee', 'expected', '-29.99', 'TRY', 'order', 'S-1', now());
            $record->run($order, 'service_fee', 'expected', '-1.50', 'TRY', 'order', 'F-1', now());
            $record->run($order, 'withholding', 'expected', '-0.75', 'TRY', 'order', 'W-1', now());
            $record->run($order, 'other', 'expected', '2.00', 'TRY', 'order', 'A-1', now());
        });

        $expected = $this->expected($tenant, $order);

        $this->assertSame(['120.00', '0.00', '-15.11', '-29.99', '-0.25', '-50.00'], $this->parts($expected));
        $this->assertSame(ContributionView::COMPLETE, $expected->status);
        $this->assertSame('24.65', $expected->contribution, '120 − 15.11 − 29.99 − 1.50 − 0.75 + 2.00 − 50');
    }

    #[Test]
    public function multi_quantity_cancellation_and_cost_rounding_are_exact(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->trendyolOrder($tenant, $connection, 'P-4', 'N-4', [['net' => '99.99', 'cost' => '33.3333', 'qty' => 3, 'cancelled' => 1]]);

        $expected = $this->expected($tenant, $order);

        $this->assertSame('66.66', $expected->sale, '99.99 × 2/3, HALF_UP');
        $this->assertSame('-66.67', $expected->cost, '33.3333 × 2 = 66.6666 → 66.67');
        $this->assertSame('-0.01', $expected->partial);
    }

    #[Test]
    public function zero_is_known_and_absent_is_unknown(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->trendyolOrder($tenant, $connection, 'P-5', 'N-5', [['net' => null, 'cost' => null]]);
        TenantContext::runFor($tenant->id, fn () => app(RecordOrderCharge::class)->run($order, 'shipping_fee', 'expected', '0.00', 'TRY', 'order', 'S-0', now()));

        $expected = $this->expected($tenant, $order);

        $this->assertSame('0.00', $expected->shipping, 'Kayıtlı sıfır = bilinen sıfır.');
        $this->assertNull($expected->commission, 'Kayıt yok = bilinmiyor.');
        $this->assertNull($expected->sale, 'Satış anı net yok = bilinmiyor.');
        $this->assertNull($expected->cost);
        $this->assertSame(ContributionView::INCOMPLETE, $expected->status);
    }

    #[Test]
    public function amounts_stay_vat_inclusive_and_are_never_split(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->trendyolOrder($tenant, $connection, 'P-6', 'N-6', [['net' => '100.01', 'cost' => '40.00']]);
        TenantContext::runFor($tenant->id, fn () => OrderLine::query()->where('order_id', $order->id)->update(['vat_rate' => '20.00']));

        $this->assertSame('100.01', $this->expected($tenant, $order)->sale, 'KDV ayrıştırılmaz (S1 kararı 1).');
    }

    #[Test]
    public function historical_cost_does_not_change_when_the_product_cost_changes(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->trendyolOrder($tenant, $connection, 'P-7', 'N-7', [['net' => '300.00', 'cost' => '100.00']]);

        TenantContext::runFor($tenant->id, function () use ($order): void {
            $variant = Variant::factory()->create(['product_id' => Product::factory()->create()->id, 'cost_price' => '100.00']);
            OrderLine::query()->where('order_id', $order->id)->update(['variant_id' => $variant->id]);
            app(SetVariantCost::class)->run($variant, '250.00');
        });

        $this->assertSame('-100.00', $this->expected($tenant, $order)->cost, 'Satış anı maliyeti, bugünkü değil.');
    }

    // ----------------------------------------------------------- rapor

    #[Test]
    public function the_report_shows_both_bases_marks_incomplete_and_lists_unmatched(): void
    {
        [$tenant, $connection, $order] = $this->settledScenario(net: '1789.00', cost: '1000.00', extraRows: [
            $this->saleRow(['id' => 'YETIM', 'shipmentPackageId' => 3999999999, 'orderNumber' => '1999']),
        ]);

        $this->actingAs($this->owner)
            ->get('/reports/contribution?from=2026-10-01&to=2026-10-11')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Contribution')
                ->where('rows.0.id', $order->id)
                ->where('rows.0.settled.commission', '-339.91')
                ->where('rows.0.settled.contribution', null)
                ->where('rows.0.settled.status', 'incomplete')
                ->where('totals.settled.TRY.incomplete', 1)
                ->where('totals.settled.TRY.contribution', null)
                ->where('totals.settled.TRY.unknown.shipping', 1)
                ->where('unmatched.count', 1)
                ->where('unmatched.rows.0.externalId', 'YETIM'));

        $this->actingAs($this->owner)
            ->get("/reports/contribution/{$order->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/ContributionShow')
                ->where('settled.sale', '1789.00')
                ->has('settled.trace', 1)
                ->where('settled.trace.0.ref', '14124406379'));
    }

    #[Test]
    public function a_period_without_complete_orders_has_no_aggregate_contribution_not_zero(): void
    {
        // TD-40 (A): tamam sipariş yok → toplam katkı YOK (null), "0.00" değil.
        $this->settledScenario(net: '1789.00', cost: '1000.00');

        $this->actingAs($this->owner)
            ->get('/reports/contribution?from=2026-10-01&to=2026-10-11')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('totals.settled.TRY.complete', 0)
                ->where('totals.settled.TRY.contribution', null)
                ->where('totals.expected.TRY.complete', 0)
                ->where('totals.expected.TRY.contribution', null));
    }

    #[Test]
    public function a_complete_contribution_that_is_exactly_zero_is_shown_as_zero(): void
    {
        // TD-40 (B): tam veri var ve matematik sıfır veriyor → "0.00" (gerçek sonuç).
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->trendyolOrder($tenant, $connection, 'P-Z', 'N-Z', [['net' => '100.00', 'cost' => '60.00']]);
        TenantContext::runFor($tenant->id, function () use ($order): void {
            $record = app(RecordOrderCharge::class);
            $record->run($order, 'commission', 'expected', '-25.00', 'TRY', 'order', 'C-Z', now());
            $record->run($order, 'shipping_fee', 'expected', '-15.00', 'TRY', 'order', 'S-Z', now());
        });

        $this->assertSame(ContributionView::COMPLETE, $this->expected($tenant, $order)->status);

        $this->actingAs($this->owner)
            ->get('/reports/contribution?from=2026-10-01&to=2026-10-11')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.0.expected.contribution', '0.00')
                ->where('totals.expected.TRY.complete', 1)
                ->where('totals.expected.TRY.contribution', '0.00'));
    }

    #[Test]
    public function the_report_never_shows_another_tenants_orders_or_transactions(): void
    {
        [, , $orderA] = $this->settledScenario(net: '10.00', cost: '1.00');
        [$tenantB] = $this->trendyolTenant(seller: '777');
        $ownerB = $this->owner;

        $this->actingAs($ownerB)->get("/reports/contribution/{$orderA->id}")->assertNotFound();
        $this->actingAs($ownerB)
            ->get('/reports/contribution?from=2026-10-01&to=2026-10-11')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rows', [])->where('unmatched.count', 0));
        $this->assertNotNull($tenantB);
    }

    // ---------------------------------------------------------------- iç

    /**
     * Canlı satış satırıyla eşleşmiş sipariş.
     *
     * @param  list<array<string, mixed>>  $extraRows
     * @return array{0: Tenant, 1: ChannelConnection, 2: Order}
     */
    private function settledScenario(string $net, string $cost, int $qty = 1, int $returned = 0, array $extraRows = []): array
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->trendyolOrder($tenant, $connection, '3000000001', '10000000001', [['net' => $net, 'cost' => $cost, 'qty' => $qty, 'returned' => $returned]]);
        $this->fakeFinance($this->page([$this->saleRow(), ...$extraRows]));
        app(IngestFinancialTransactions::class)->forConnection($connection->fresh(['channelType']));

        return [$tenant, $connection, $order];
    }

    private function refund(Tenant $tenant, Order $order, string $amount, string $key): void
    {
        TenantContext::runFor($tenant->id, fn () => DB::table('order_refunds')->insertOrIgnore([
            'id' => (string) new UuidV7,
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'channel_connection_id' => $order->channel_connection_id,
            'idempotency_key' => $key,
            'external_refund_id' => $key,
            'kind' => 'refund',
            'amount' => $amount,
            'currency' => 'TRY',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function expected(Tenant $tenant, Order $order): ContributionView
    {
        return TenantContext::runFor($tenant->id, fn () => app(ContributionCalculator::class)->expected($order->fresh()));
    }

    private function settled(Tenant $tenant, Order $order): ContributionView
    {
        return TenantContext::runFor($tenant->id, fn () => app(ContributionCalculator::class)->settled($order->fresh()));
    }

    /** @return list<?string> sale, refund, commission, shipping, other, cost */
    private function parts(ContributionView $view): array
    {
        return [$view->sale, $view->refund, $view->commission, $view->shipping, $view->other, $view->cost];
    }
}
