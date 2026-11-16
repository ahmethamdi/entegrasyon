<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Finance\Models\ChannelChargeRule;
use App\Domain\Finance\Support\UnitEconomics;
use App\Domain\Identity\Enums\TenantRole;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Sync\Models\Listing;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Finance\BuildsFinanceScenario;
use Tests\TestCase;

/**
 * Fiyat ve kâr — adet başı katkı, zararsız/hedef fiyat, durum, yetki.
 */
final class PricingReportTest extends TestCase
{
    use BuildsFinanceScenario;
    use RefreshDatabase;

    #[Test]
    public function unit_economics_matches_the_formula_and_rounds_suggested_prices_up(): void
    {
        // 200 − 36 (%18) − 42.90 − 5 − 100 = 16.10 → marj %8.1
        $e = UnitEconomics::of('200.00', '100.00', '18', '47.90', '15');

        $this->assertSame('16.10', $e['contribution']);
        $this->assertSame(8.1, $e['margin']);
        $this->assertSame('180.37', $e['breakEven'], '147.90 / 0.82 = 180.365… → yukarı');
        $this->assertSame('220.75', $e['target'], '147.90 / 0.67 = 220.746… → yukarı');

        $this->assertNull(UnitEconomics::of('200.00', null, '18', '0', '15')['contribution'], 'Maliyet yoksa hesap yok.');
        $this->assertNull(UnitEconomics::of('200.00', '10.00', '60', '0', '45')['target'], 'Oran %100+ → hiçbir fiyat yetmez.');
    }

    #[Test]
    public function the_screen_flags_loss_below_target_and_unknown_listings(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->rule($tenant, $connection);
        $this->listing($tenant, $connection, 'ZARAR', '150.00', '120.00');   // 150−27−47.90−120 < 0
        $this->listing($tenant, $connection, 'DUSUK', '200.00', '100.00');   // %8.1 < %15
        $this->listing($tenant, $connection, 'IYI', '400.00', '100.00');     // %51
        $this->listing($tenant, $connection, 'MALIYETSIZ', '300.00', null);

        $this->actingAs($this->owner)
            ->get("/reports/pricing?connection={$connection->id}&target=15")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Pricing')
                ->where('report.total', 4)
                ->where('report.counts', ['loss' => 1, 'below_target' => 1, 'ok' => 1, 'unknown' => 1])
                ->where('report.rows.0.sku', 'ZARAR')
                ->where('report.rows.0.status', 'loss')
                ->where('report.rows.1.contribution', '16.10')
                ->where('report.rule.commissionPercent', '18.00'));
    }

    #[Test]
    public function without_a_commission_rule_nothing_is_calculated_and_other_tenants_are_invisible(): void
    {
        [$tenantA, $connA] = $this->trendyolTenant();
        $this->listing($tenantA, $connA, 'A', '100.00', '10.00');

        $this->actingAs($this->owner)
            ->get('/reports/pricing')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report.counts.unknown', 1)
                ->where('report.rule.commissionPercent', null));

        $this->trendyolTenant(seller: '777');
        $this->actingAs($this->owner)
            ->get("/reports/pricing?connection={$connA->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('report.total', 0)->where('filters.connection', fn ($id) => $id !== $connA->id));
    }

    #[Test]
    public function a_role_without_finance_permission_cannot_open_it(): void
    {
        [$tenant] = $this->trendyolTenant();
        DB::table('tenant_users')->where('tenant_id', $tenant->id)->update(['role' => TenantRole::WAREHOUSE->value]);

        $this->actingAs($this->owner)->get('/reports/pricing')->assertForbidden();
    }

    private function rule(Tenant $tenant, ChannelConnection $connection): void
    {
        TenantContext::runFor($tenant->id, fn () => ChannelChargeRule::query()->create([
            'channel_connection_id' => $connection->id, 'internal_category_id' => '',
            'commission_percent' => '18.00', 'shipping_fee' => '42.90', 'packaging_fee' => '5.00',
        ]));
    }

    private function listing(Tenant $tenant, ChannelConnection $connection, string $sku, string $price, ?string $cost): void
    {
        TenantContext::runFor($tenant->id, function () use ($connection, $sku, $price, $cost): void {
            $variant = Variant::factory()->create([
                'product_id' => Product::factory()->create()->id,
                'sku' => $sku, 'price' => $price, 'currency' => 'TRY', 'cost_price' => $cost,
            ]);
            Listing::factory()->create(['channel_connection_id' => $connection->id, 'variant_id' => $variant->id]);
        });
    }
}
