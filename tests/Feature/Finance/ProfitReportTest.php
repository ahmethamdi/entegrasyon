<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Finance\Support\ContributionCalculator;
use App\Domain\Finance\Support\ProfitReport;
use App\Domain\Identity\Enums\TenantRole;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Orders\Actions\RecordOrderCharge;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderLine;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Finance\BuildsFinanceScenario;
use Tests\TestCase;

/**
 * Kâr paneli — huni, kapsam, kırılım, önbellek ve yetki.
 *
 * Kural: panel katkı raporuyla AYNI rakamı söyler (hesap tek yerde);
 * bilinmeyen kalem sıfır sayılmaz.
 */
final class ProfitReportTest extends TestCase
{
    use BuildsFinanceScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-11 12:00:00');
    }

    #[Test]
    public function the_funnel_counts_known_items_only_and_contribution_only_from_complete_orders(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        // Tamam: 1000 − 150 − 50 − 400 = 400.
        $complete = $this->trendyolOrder($tenant, $connection, 'P-1', 'N-1', [['net' => '1000.00', 'cost' => '400.00']]);
        $this->charges($tenant, $complete, '-150.00', '-50.00');
        // Eksik: maliyet ve kesinti yok.
        $this->trendyolOrder($tenant, $connection, 'P-2', 'N-2', [['net' => '500.00']]);

        $funnel = $this->report($tenant)['currencies']['TRY']['funnel'];

        $this->assertSame(2, $funnel['orders']);
        $this->assertSame(['amount' => '1500.00', 'known' => 2], $funnel['gross']);
        $this->assertSame(['amount' => '-150.00', 'known' => 1], $funnel['commission'], 'Bilinmeyen komisyon sıfır sayılmaz.');
        $this->assertSame(['amount' => '-400.00', 'known' => 1], $funnel['cost']);
        $this->assertSame(1, $funnel['complete']);
        $this->assertSame('400.00', $funnel['contribution']);

        $calculator = app(ContributionCalculator::class);
        $this->assertSame(
            TenantContext::runFor($tenant->id, fn () => $calculator->expected($complete)->contribution),
            $funnel['contribution'],
            'Kâr paneli ile katkı raporu aynı siparişte aynı rakamı söyler.',
        );
    }

    #[Test]
    public function a_period_without_complete_orders_has_no_contribution_not_zero(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->trendyolOrder($tenant, $connection, 'P-3', 'N-3', [['net' => '100.00', 'cost' => '10.00']]);

        $funnel = $this->report($tenant)['currencies']['TRY']['funnel'];

        $this->assertSame(0, $funnel['complete']);
        $this->assertNull($funnel['contribution']);
        $this->assertSame('90.00', $funnel['partial']);
    }

    #[Test]
    public function a_cancelled_unit_without_refund_shows_as_cancel_step(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->trendyolOrder($tenant, $connection, 'P-4', 'N-4', [['net' => '200.00', 'qty' => 2, 'cancelled' => 1, 'cost' => '30.00']]);

        $funnel = $this->report($tenant)['currencies']['TRY']['funnel'];

        $this->assertSame('200.00', $funnel['gross']['amount']);
        $this->assertSame('-100.00', $funnel['cancel']['amount']);
        $this->assertSame('-30.00', $funnel['cost']['amount'], 'İptal edilen adedin maliyeti düşülmez.');
    }

    #[Test]
    public function category_and_brand_split_allocates_order_charges_by_sales_share(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->trendyolOrder($tenant, $connection, 'P-5', 'N-5', [
            ['sku' => 'A', 'net' => '750.00', 'cost' => '300.00'],
            ['sku' => 'B', 'net' => '250.00', 'cost' => '100.00'],
        ]);
        $this->charges($tenant, $order, '-100.00', '-40.00');

        TenantContext::runFor($tenant->id, function () use ($order): void {
            foreach (['A' => ['Ayakkabı', 'Marka X'], 'B' => ['Çanta', null]] as $sku => [$category, $brand]) {
                $product = Product::factory()->create(['internal_category_id' => $category, 'brand' => $brand]);
                $variant = Variant::factory()->create(['product_id' => $product->id]);
                OrderLine::query()->where('order_id', $order->id)->where('sku', $sku)->update(['variant_id' => $variant->id]);
            }
        });

        $bucket = $this->report($tenant)['currencies']['TRY'];
        $categories = collect($bucket['categories'])->keyBy('key');

        // A: 750 − %75 × 140 − 300 = 345 · B: 250 − %25 × 140 − 100 = 115.
        $this->assertSame('750.00', $categories['Ayakkabı']['sale']);
        $this->assertSame('-105.00', $categories['Ayakkabı']['deductions']);
        $this->assertSame('345.00', $categories['Ayakkabı']['contribution']);
        $this->assertSame('115.00', $categories['Çanta']['contribution']);
        $this->assertSame('460.00', $bucket['funnel']['contribution'], 'Kırılım toplamı huniyle aynı.');

        $brands = collect($bucket['brands'])->keyBy('key');
        $this->assertSame('none', $brands['__none']['kind'], 'Markasız ürün ayrı satır.');
        $this->assertSame('Marka X', $brands['Marka X']['label']);

        $channels = $bucket['channels'];
        $this->assertCount(1, $channels);
        $this->assertSame('460.00', $channels[0]['contribution']);
        $this->assertSame(46.0, $channels[0]['margin']);
    }

    #[Test]
    public function an_unmatched_line_lands_in_its_own_group_and_in_the_gaps(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->trendyolOrder($tenant, $connection, 'P-6', 'N-6', [['net' => '80.00']]);

        $report = $this->report($tenant);

        $this->assertSame('unmatched', $report['currencies']['TRY']['categories'][0]['kind']);
        $this->assertSame(1, $report['gaps']['unmatchedLines']);
        $this->assertSame(1, $report['gaps']['linesWithoutCost']);
        $this->assertSame(1, $report['gaps']['ordersWithoutCommission']);
    }

    #[Test]
    public function the_cache_refreshes_when_a_charge_is_recorded(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->trendyolOrder($tenant, $connection, 'P-7', 'N-7', [['net' => '100.00', 'cost' => '40.00']]);

        $this->assertNull($this->report($tenant)['currencies']['TRY']['funnel']['contribution']);

        $this->charges($tenant, $order, '-10.00', '-5.00');

        $this->assertSame('45.00', $this->report($tenant)['currencies']['TRY']['funnel']['contribution'], 'Önbellek eski rakamı göstermez.');
    }

    #[Test]
    public function the_screen_renders_for_finance_roles_and_only_shows_own_tenant(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->trendyolOrder($tenant, $connection, 'P-8', 'N-8', [['net' => '100.00']]);
        $owner = $this->owner;

        $this->actingAs($owner)
            ->get('/reports/profit?from=2026-10-01&to=2026-10-11')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Profit')
                ->where('filters.from', '2026-10-01')
                ->where('report.orders', 1)
                ->where('report.currencies.TRY.funnel.gross.amount', '100.00'));

        [$tenantB] = $this->trendyolTenant(seller: '777');
        $this->actingAs($this->owner)
            ->get('/reports/profit?from=2026-10-01&to=2026-10-11')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('report.orders', 0)->where('report.currencies', []));
        $this->assertNotNull($tenantB);
    }

    #[Test]
    public function a_role_without_finance_permission_gets_no_screen_and_no_dashboard_card(): void
    {
        [$tenant] = $this->trendyolTenant();
        DB::table('tenant_users')->where('tenant_id', $tenant->id)->update(['role' => TenantRole::WAREHOUSE->value]);

        $this->actingAs($this->owner)->get('/reports/profit')->assertForbidden();
        $this->actingAs($this->owner)
            ->get('/panel')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('profit', null));
    }

    #[Test]
    public function the_dashboard_card_is_deferred_and_uses_the_same_numbers(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->trendyolOrder($tenant, $connection, 'P-9', 'N-9', [['net' => '300.00', 'cost' => '100.00']]);
        Order::query()->withoutGlobalScopes()->whereKey($order->id)->update(['placed_at' => '2026-10-10 10:00:00']);
        $this->charges($tenant, $order, '-30.00', '-20.00');

        $this->actingAs($this->owner)
            ->get('/panel')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('profit')
                ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                    ->where('profit.contribution', '150.00')
                    ->where('profit.complete', 1)
                    ->where('profit.sale', '300.00')));
    }

    // ------------------------------------------------------------------ iç

    /** @return array<string, mixed> */
    private function report(Tenant $tenant): array
    {
        return TenantContext::runFor($tenant->id, fn (): array => app(ProfitReport::class)->build(
            CarbonImmutable::parse('2026-10-01', 'Europe/Istanbul'),
            CarbonImmutable::parse('2026-10-11', 'Europe/Istanbul'),
        ));
    }

    private function charges(Tenant $tenant, Order $order, string $commission, string $shipping): void
    {
        TenantContext::runFor($tenant->id, function () use ($order, $commission, $shipping): void {
            $record = app(RecordOrderCharge::class);
            $record->run($order, 'commission', 'expected', $commission, 'TRY', 'order', 'C-'.$order->id, now());
            $record->run($order, 'shipping_fee', 'expected', $shipping, 'TRY', 'order', 'S-'.$order->id, now());
        });
    }
}
