<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Finance\Actions\ApplyChargeRules;
use App\Domain\Finance\Models\ChannelChargeRule;
use App\Domain\Finance\Support\ContributionCalculator;
use App\Domain\Finance\Support\ContributionView;
use App\Domain\Identity\Enums\TenantRole;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Orders\Actions\ApplyOrderCancellation;
use App\Domain\Orders\Actions\IngestChannelOrder;
use App\Domain\Orders\Actions\RecordOrderCharge;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderLine;
use App\Domain\Orders\Support\CancellationEvent;
use App\Domain\Orders\Support\CancelledLine;
use App\Domain\Orders\Support\IncomingOrder;
use App\Domain\Orders\Support\IncomingOrderLine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Finance\BuildsFinanceScenario;
use Tests\TestCase;

/**
 * Kâr paneli B — kanal kesinti varsayılanlarından beklenen kesinti.
 */
final class ChargeRulesTest extends TestCase
{
    use BuildsFinanceScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-11 12:00:00');
    }

    #[Test]
    public function a_new_order_gets_expected_commission_shipping_and_packaging_and_becomes_complete(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->rule($tenant, $connection, commission: '18.00', shipping: '42.90', packaging: '5.00');

        $order = $this->ingest($tenant, $connection, 'P-1', [['line' => '1', 'net' => '1000.00', 'qty' => 1]]);

        $charges = $this->charges($tenant, $order);
        $this->assertSame(['commission' => '-180.00', 'other' => '-5.00', 'shipping_fee' => '-42.90'], $charges);

        // Maliyet de bilinince katkı TAMAM: 1000 − 180 − 42.90 − 5 − 400 = 372.10.
        DB::table('order_lines')->where('order_id', $order->id)->update(['unit_cost' => '400.00', 'cost_currency' => 'TRY', 'cost_captured_at' => now()]);
        $view = TenantContext::runFor($tenant->id, fn () => app(ContributionCalculator::class)->expected($order->fresh()));
        $this->assertSame(ContributionView::COMPLETE, $view->status);
        $this->assertSame('372.10', $view->contribution);
    }

    #[Test]
    public function without_a_rule_nothing_is_written(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();

        $order = $this->ingest($tenant, $connection, 'P-2', [['line' => '1', 'net' => '100.00', 'qty' => 1]]);

        $this->assertSame([], $this->charges($tenant, $order));
    }

    #[Test]
    public function a_category_exception_overrides_the_default_rate_per_line(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->rule($tenant, $connection, commission: '20.00');
        $this->rule($tenant, $connection, commission: '10.00', category: 'Lastik');

        $order = $this->trendyolOrder($tenant, $connection, 'P-3', 'N-3', [
            ['sku' => 'A', 'net' => '500.00'],
            ['sku' => 'B', 'net' => '300.00'],
        ]);
        TenantContext::runFor($tenant->id, function () use ($order): void {
            $variant = Variant::factory()->create(['product_id' => Product::factory()->create(['internal_category_id' => 'Lastik'])->id]);
            OrderLine::query()->where('order_id', $order->id)->where('sku', 'A')->update(['variant_id' => $variant->id]);
        });

        $this->apply($tenant, $order);

        // A: 500 × %10 = 50 · B (kategorisiz): 300 × %20 = 60.
        $this->assertSame('-110.00', $this->charges($tenant, $order)['commission']);
    }

    #[Test]
    public function an_unknown_rate_for_any_line_writes_no_commission_at_all(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        // Varsayılan YOK, yalnız bir kategori istisnası; kargo var.
        $this->rule($tenant, $connection, shipping: '30.00');
        $this->rule($tenant, $connection, commission: '10.00', category: 'Lastik');

        $order = $this->trendyolOrder($tenant, $connection, 'P-4', 'N-4', [['sku' => 'X', 'net' => '100.00']]);
        $this->apply($tenant, $order);

        $this->assertSame(['shipping_fee' => '-30.00'], $this->charges($tenant, $order), 'Eksik komisyon bilinen gibi yazılmaz.');
    }

    #[Test]
    public function applying_twice_writes_once_and_a_channel_commission_is_never_overridden(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->rule($tenant, $connection, commission: '18.00', shipping: '40.00');
        $order = $this->trendyolOrder($tenant, $connection, 'P-5', 'N-5', [['net' => '100.00']]);
        TenantContext::runFor($tenant->id, fn () => app(RecordOrderCharge::class)->run($order, 'commission', 'expected', '-12.00', 'TRY', 'order', 'KANAL-5', now()));

        $this->apply($tenant, $order);
        $this->apply($tenant, $order);

        $this->assertSame(['commission' => '-12.00', 'shipping_fee' => '-40.00'], $this->charges($tenant, $order));
    }

    #[Test]
    public function a_partial_cancellation_refunds_commission_and_a_full_one_reverses_shipping_and_packaging(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->rule($tenant, $connection, commission: '10.00', shipping: '40.00', packaging: '5.00');
        $order = $this->ingest($tenant, $connection, 'P-6', [['line' => '1', 'net' => '200.00', 'qty' => 2]]);
        $lineId = (string) DB::table('order_lines')->where('order_id', $order->id)->value('id');

        $this->cancel($tenant, $order, 'IPT-1', $lineId, 1);
        $this->assertSame(['commission' => '-20.00', 'commission_refund' => '10.00', 'other' => '-5.00', 'shipping_fee' => '-40.00'], $this->charges($tenant, $order));

        $this->cancel($tenant, $order, 'IPT-2', $lineId, 1);
        $this->assertSame(['commission' => '-20.00', 'commission_refund' => '20.00', 'other' => '0.00', 'shipping_fee' => '0.00'], $this->charges($tenant, $order), 'Tam iptal: kesinti toplamı sıfır.');
    }

    #[Test]
    public function saving_rules_applies_them_to_recent_orders_without_charges(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->trendyolOrder($tenant, $connection, 'P-7', 'N-7', [['net' => '250.00']]);

        $this->actingAs($this->owner)
            ->from('/settings/charge-rules')
            ->post('/settings/charge-rules', [
                'connection_id' => $connection->id,
                'commission_percent' => '20',
                'shipping_fee' => '35.5',
                'packaging_fee' => null,
                'categories' => [['category' => 'Lastik', 'commission_percent' => '12']],
            ])
            ->assertRedirect('/settings/charge-rules')
            ->assertSessionHasNoErrors();

        $this->assertSame(['commission' => '-50.00', 'shipping_fee' => '-35.50'], $this->charges($tenant, $order));

        $this->actingAs($this->owner)
            ->get('/settings/charge-rules')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/ChargeRules')
                ->where('connections.0.commissionPercent', '20.00')
                ->where('connections.0.shippingFee', '35.50')
                ->where('connections.0.categories.0.category', 'Lastik'));
    }

    #[Test]
    public function another_tenants_connection_cannot_be_targeted_and_viewers_cannot_save(): void
    {
        [, $foreign] = $this->trendyolTenant();
        [$tenant, $own] = $this->trendyolTenant(seller: '777');

        $this->actingAs($this->owner)
            ->post('/settings/charge-rules', ['connection_id' => $foreign->id, 'commission_percent' => '10'])
            ->assertNotFound();

        DB::table('tenant_users')->where('tenant_id', $tenant->id)->update(['role' => TenantRole::VIEWER->value]);
        $this->actingAs($this->owner)->get('/settings/charge-rules')->assertOk();
        $this->actingAs($this->owner)
            ->post('/settings/charge-rules', ['connection_id' => $own->id, 'commission_percent' => '10'])
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ iç

    private function rule(Tenant $tenant, ChannelConnection $connection, ?string $commission = null, ?string $shipping = null, ?string $packaging = null, string $category = ''): void
    {
        TenantContext::runFor($tenant->id, fn () => ChannelChargeRule::query()->create([
            'channel_connection_id' => $connection->id, 'internal_category_id' => $category,
            'commission_percent' => $commission, 'shipping_fee' => $shipping, 'packaging_fee' => $packaging,
        ]));
    }

    /** @param list<array{line: string, net: string, qty: int}> $lines */
    private function ingest(Tenant $tenant, ChannelConnection $connection, string $package, array $lines): Order
    {
        $warehouse = (string) TenantContext::runAsSystem(fn () => DB::table('warehouses')->where('tenant_id', $tenant->id)->where('is_default', true)->value('id'));

        return TenantContext::runFor($tenant->id, fn () => (new IngestChannelOrder)->run(new IncomingOrder(
            channelConnectionId: $connection->id,
            externalId: $package,
            lines: array_map(static fn (array $l): IncomingOrderLine => new IncomingOrderLine(
                externalLineId: $l['line'],
                sku: 'YOK-'.$l['line'],
                title: 'Ürün',
                quantity: $l['qty'],
                netLineTotal: $l['net'],
                grossLineTotal: $l['net'],
                amountsIncludeVat: true,
            ), $lines),
            externalNumber: 'N-'.$package,
            placedAt: now()->subDay(),
        ), $warehouse));
    }

    private function apply(Tenant $tenant, Order $order): void
    {
        TenantContext::runFor($tenant->id, fn () => app(ApplyChargeRules::class)->forOrder($order->fresh()));
    }

    private function cancel(Tenant $tenant, Order $order, string $ref, string $lineId, int $qty): void
    {
        TenantContext::runFor($tenant->id, fn () => (new ApplyOrderCancellation)->run(new CancellationEvent(
            orderId: $order->id,
            externalRef: $ref,
            lines: [new CancelledLine($lineId, $qty)],
        )));
    }

    /** @return array<string, string> tür → toplam (beklenen) */
    private function charges(Tenant $tenant, Order $order): array
    {
        return TenantContext::runAsSystem(fn () => DB::table('order_charges')
            ->where('tenant_id', $tenant->id)->where('order_id', $order->id)->where('basis', 'expected')
            ->groupBy('kind')->orderBy('kind')
            ->selectRaw('kind, sum(amount) AS total')
            ->pluck('total', 'kind')
            ->map(fn ($v): string => number_format((float) $v, 2, '.', ''))
            ->all());
    }
}
