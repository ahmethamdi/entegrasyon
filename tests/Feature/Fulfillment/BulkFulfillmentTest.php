<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfillment;

use App\Domain\Fulfillment\Enums\FulfillmentReason;
use App\Domain\Fulfillment\Jobs\PrepareShippingLabel;
use App\Domain\Fulfillment\Models\FulfillmentBatch;
use App\Domain\Fulfillment\Models\ShippingLabel;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Invoicing\Jobs\IssueInvoice;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Orders\Models\Order;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\TestCase;

/**
 * S2 · Toplu depo işlemi — kalem başına sonuç, çift gönderim, kiracı
 * sınırı, toplama listesi (varyant doğruluğu) ve fatura belgeleri.
 */
final class BulkFulfillmentTest extends TestCase
{
    use BuildsFulfillmentScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFulfillment();
    }

    // ─────────────────────────────────────────────── toplu etiket

    #[Test]
    public function a_batch_with_partial_failures_reports_every_order_explicitly(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $owner = $this->owner;
        $ok = $this->awaitingOrder($tenant, $connection, '6001');
        $yurtici = $this->awaitingOrder($tenant, $connection, '6002', packageOverrides: ['cargoProviderName' => 'Yurtiçi Kargo Marketplace']);
        $cancelled = $this->awaitingOrder($tenant, $connection, '6003', state: 'cancelled');
        $pending = $this->awaitingOrder($tenant, $connection, '6004');

        $this->actingAs($owner)->post('/orders/bulk', $this->form('labels', [$ok, $yurtici, $cancelled, $pending]))->assertRedirect();
        $batch = TenantContext::runFor($tenant->id, fn () => FulfillmentBatch::query()->sole());

        // Kuyruk: üç uygun sipariş için üç iş, iptal için iş yok.
        Queue::assertPushed(PrepareShippingLabel::class, 3);
        $this->labelGets = [$this->zplResponse(self::ZPL_1)];
        $this->runLabelJob($tenant, $this->labelFor($tenant, $ok)->id);
        $this->runLabelJob($tenant, $this->labelFor($tenant, $yurtici)->id);

        $this->actingAs($owner)->get("/fulfillment/batches/{$batch->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Fulfillment/Batch')
                ->where('batch.total', 4)
                ->where('counts', ['succeeded' => 1, 'in_progress' => 1, 'action_required' => 2, 'failed' => 0])
                ->has('items', 4)
                ->where('items.0.outcome', 'succeeded')
                ->where('items.1.reasonCode', FulfillmentReason::CARGO_PROVIDER_UNSUPPORTED->value)
                ->where('items.2.reasonCode', FulfillmentReason::INVALID_ORDER_STATE->value)
                ->where('items.3.outcome', 'in_progress')
                ->where('readyLabels', 1));
    }

    #[Test]
    public function a_double_submitted_form_opens_one_batch_and_one_label_job(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenant, $connection, '6101');
        $form = $this->form('labels', [$order]);

        $first = $this->actingAs($this->owner)->post('/orders/bulk', $form);
        $second = $this->actingAs($this->owner)->post('/orders/bulk', $form);

        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'), 'İkinci gönderim AYNI toplu işe döner.');
        $this->assertSame(1, TenantContext::runFor($tenant->id, fn () => FulfillmentBatch::query()->count()));
        Queue::assertPushed(PrepareShippingLabel::class, 1);

        // Yeni anahtarla ikinci toplu işlem bile ikinci etiket açmaz.
        $this->actingAs($this->owner)->post('/orders/bulk', $this->form('labels', [$order]));
        Queue::assertPushed(PrepareShippingLabel::class, 1);
        $this->assertSame(1, TenantContext::runFor($tenant->id, fn () => ShippingLabel::query()->count()));
    }

    #[Test]
    public function ready_labels_download_as_one_untouched_zpl_file(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $a = $this->awaitingOrder($tenant, $connection, '6201');
        $b = $this->awaitingOrder($tenant, $connection, '6202');
        $this->actingAs($this->owner)->post('/orders/bulk', $this->form('labels', [$a, $b]));
        $batch = TenantContext::runFor($tenant->id, fn () => FulfillmentBatch::query()->sole());
        $this->labelGets = [$this->zplResponse(self::ZPL_1), $this->zplResponse(self::ZPL_2)];
        $this->runLabelJob($tenant, $this->labelFor($tenant, $a)->id);
        $this->runLabelJob($tenant, $this->labelFor($tenant, $b)->id);

        $response = $this->actingAs($this->owner)->get("/fulfillment/batches/{$batch->id}/labels.zpl")->assertOk();

        $this->assertSame(self::ZPL_1."\n".self::ZPL_2."\n", $response->getContent());
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    #[Test]
    public function a_ready_label_does_not_ship_the_order_or_touch_stock(): void
    {
        // DEĞİŞMEZ: ETİKET HAZIR ≠ KARGOLANDI. Etiketi alınan sipariş kargo
        // hazırlığı listesinde kalır, durumu değişmez, stok hareketi olmaz.
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenant, $connection, '6250');
        $movementsBefore = DB::table('inventory_movements')->count();

        $this->actingAs($this->owner)->post('/orders/bulk', $this->form('labels', [$order]));
        $this->labelGets = [$this->zplResponse(self::ZPL_1)];
        $this->runLabelJob($tenant, $this->labelFor($tenant, $order)->id);
        $this->assertSame(ShippingLabel::STATUS_READY, $this->labelFor($tenant, $order)->status);

        $this->assertSame('awaiting_shipment', TenantContext::runFor($tenant->id, fn () => Order::query()->findOrFail($order->id)->state));
        $this->assertTrue(TenantContext::runFor($tenant->id, fn () => Order::query()->awaitingShipment()->whereKey($order->id)->exists()));
        $this->assertSame(0, DB::table('fulfillments')->where('order_id', $order->id)->count());
        $this->assertSame($movementsBefore, DB::table('inventory_movements')->count(), 'Etiket işlemi stoğa dokunmaz.');

        $this->actingAs($this->owner)->get('/orders?filter=awaiting_shipment')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rows.0.id', $order->id));
    }

    #[Test]
    public function an_unauthorized_common_label_account_does_not_stop_the_rest_of_the_batch(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $owner = $this->owner;
        $a = $this->awaitingOrder($tenant, $connection, '6260');
        $b = $this->awaitingOrder($tenant, $connection, '6261');
        $this->actingAs($owner)->post('/orders/bulk', $this->form('labels', [$a, $b]));
        $batch = TenantContext::runFor($tenant->id, fn () => FulfillmentBatch::query()->sole());
        $this->labelGets = [Http::response(self::NOT_ALLOWED, 400), $this->zplResponse(self::ZPL_1)];

        $this->runLabelJob($tenant, $this->labelFor($tenant, $a)->id);
        $this->runLabelJob($tenant, $this->labelFor($tenant, $b)->id);

        $this->actingAs($owner)->get("/fulfillment/batches/{$batch->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Yetki bir BAĞLANTI kısıtıdır: "işlem gerekli", açıklamalı; sistem hatası değil.
                ->where('items.0.outcome', 'action_required')
                ->where('items.0.reasonCode', FulfillmentReason::COMMON_LABEL_NOT_AUTHORIZED->value)
                ->where('items.0.reason', FulfillmentReason::COMMON_LABEL_NOT_AUTHORIZED->label())
                ->where('items.1.outcome', 'succeeded'));
        $this->assertSame(0, $this->labelPostCount, 'Yetkisiz hesapta istek zorlanmaz.');

        // Yetki sonradan açılırsa aynı sipariş yeniden denenebilir — kalıcı "yetkisiz" varsayımı yok.
        $this->actingAs($owner)->post('/orders/bulk', $this->form('labels', [$a]));
        $this->assertSame(ShippingLabel::STATUS_PENDING, $this->labelFor($tenant, $a)->status);
        $this->labelGets = [Http::response(['data' => []]), $this->zplResponse(self::ZPL_2)];
        $this->runLabelJob($tenant, $this->labelFor($tenant, $a)->id);
        $this->runLabelJob($tenant, $this->labelFor($tenant, $a)->id);
        $this->assertSame(ShippingLabel::STATUS_READY, $this->labelFor($tenant, $a)->status);
        $this->assertSame(1, $this->labelPostCount);
    }

    #[Test]
    public function invoice_document_actions_never_reach_another_tenants_order(): void
    {
        Queue::fake();
        [$tenantA, $connectionA] = $this->trendyolTenant();
        $ownerA = $this->owner;
        $mine = $this->awaitingOrder($tenantA, $connectionA, '6270');
        [$tenantB, $connectionB] = $this->trendyolTenant(seller: '779');
        $foreign = $this->awaitingOrder($tenantB, $connectionB, '6271');
        $this->invoice($tenantB, $foreign, Invoice::STATUS_ISSUED, 'e_archive');

        foreach (['invoice_documents', 'issue_invoices', 'picking'] as $action) {
            $this->actingAs($ownerA)->from('/orders')->post('/orders/bulk', $this->form($action, [$mine, $foreign]))->assertSessionHasErrors('bulk');
        }

        $this->assertSame(0, DB::table('fulfillment_batches')->count());
        $this->assertSame(1, DB::table('invoices')->count());
        Queue::assertNothingPushed();
    }

    // ─────────────────────────────────────────────── kiracı sınırı

    #[Test]
    public function another_tenants_order_in_the_selection_rejects_the_whole_request_without_leaking(): void
    {
        Queue::fake();
        [$tenantA, $connectionA] = $this->trendyolTenant();
        $ownerA = $this->owner;
        $mine = $this->awaitingOrder($tenantA, $connectionA, '6301');
        [$tenantB, $connectionB] = $this->trendyolTenant(seller: '777');
        $foreign = $this->awaitingOrder($tenantB, $connectionB, '6302');

        $withForeign = $this->actingAs($ownerA)->from('/orders')->post('/orders/bulk', $this->form('labels', [$mine, $foreign]));
        $withMissing = $this->actingAs($ownerA)->from('/orders')->post('/orders/bulk', [
            ...$this->form('labels', [$mine]),
            'order_ids' => [$mine->id, (string) new UuidV7],
        ]);

        $withForeign->assertRedirect('/orders')->assertSessionHasErrors('bulk');
        $this->assertSame(
            session('errors')->get('bulk'),
            $withMissing->assertSessionHasErrors('bulk')->getSession()->get('errors')->get('bulk'),
            'Başka kiracının siparişi ile olmayan sipariş AYNI cevabı alır.',
        );
        $this->assertSame(0, DB::table('fulfillment_batches')->count());
        $this->assertSame(0, DB::table('shipping_labels')->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function another_tenants_batch_and_its_documents_are_not_found(): void
    {
        Queue::fake();
        [$tenantA, $connectionA] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenantA, $connectionA, '6401');
        $this->actingAs($this->owner)->post('/orders/bulk', $this->form('labels', [$order]));
        $batch = TenantContext::runFor($tenantA->id, fn () => FulfillmentBatch::query()->sole());

        $this->trendyolTenant(seller: '778');
        $ownerB = $this->owner;

        $this->actingAs($ownerB)->get("/fulfillment/batches/{$batch->id}")->assertNotFound();
        $this->actingAs($ownerB)->get("/fulfillment/batches/{$batch->id}/picking")->assertNotFound();
        $this->actingAs($ownerB)->get("/fulfillment/batches/{$batch->id}/labels.zpl")->assertNotFound();
    }

    // ─────────────────────────────────────────────── toplama listesi

    #[Test]
    public function a_single_variant_picking_list_shows_sku_barcode_and_quantity(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        [$red] = $this->tshirtVariants($tenant);
        $order = $this->awaitingOrder($tenant, $connection, '6501', [['sku' => 'x']]);
        $this->linkLine($tenant, $order, 1, $red, 2);

        $this->picking($tenant, [$order])
            ->where('totalUnits', 2)
            ->where('summary.0.product', 'Tişört')
            ->where('summary.0.options', 'Renk: Kırmızı · Beden: M')
            ->where('summary.0.sku', 'TS-KIR-M')
            ->where('summary.0.barcode', '8690000000011')
            ->where('summary.0.quantity', 2);
    }

    #[Test]
    public function multi_variant_orders_are_picked_per_variant_aggregated_and_broken_down_per_order(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        [$red, $blue] = $this->tshirtVariants($tenant);
        $one = $this->awaitingOrder($tenant, $connection, '6601', [['sku' => 'a'], ['sku' => 'b']]);
        $this->linkLine($tenant, $one, 1, $red, 1);
        $this->linkLine($tenant, $one, 2, $blue, 2);
        $two = $this->awaitingOrder($tenant, $connection, '6602', [['sku' => 'c', 'cancelled' => 0]]);
        $this->linkLine($tenant, $two, 1, $red, 3);
        $unmatched = $this->awaitingOrder($tenant, $connection, '6603', [['sku' => 'KANAL-ONLY', 'qty' => 1]]);

        $this->picking($tenant, [$one, $two, $unmatched])
            ->where('totalUnits', 7)
            ->has('summary', 3)
            // Aynı ürünün iki varyantı AYRI satır — "Tişört × 6" değil.
            ->where('summary.0.sku', 'KANAL-ONLY')
            ->where('summary.0.reason', FulfillmentReason::VARIANT_UNMATCHED->value)
            ->where('summary.0.barcode', null)
            ->where('summary.1.sku', 'TS-KIR-M')
            ->where('summary.1.quantity', 4)
            ->where('summary.1.orders', 2)
            ->where('summary.2.sku', 'TS-MAV-M')
            ->where('summary.2.options', 'Renk: Mavi · Beden: M')
            ->where('summary.2.quantity', 2)
            ->where('orders.0.package', '6601')
            ->where('orders.0.units', 3)
            ->where('orders.0.lines.0.sku', 'TS-KIR-M')
            ->where('orders.0.lines.0.quantity', 1)
            ->where('orders.0.lines.1.sku', 'TS-MAV-M')
            ->where('orders.0.lines.1.quantity', 2)
            ->where('orders.1.units', 3);
    }

    #[Test]
    public function cancelled_quantities_and_non_pickable_orders_are_left_out(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        [$red] = $this->tshirtVariants($tenant);
        $partly = $this->awaitingOrder($tenant, $connection, '6701', [['sku' => 'a', 'qty' => 3, 'cancelled' => 1]]);
        $this->linkLine($tenant, $partly, 1, $red, 3);
        $shipped = $this->awaitingOrder($tenant, $connection, '6702', state: 'shipped');

        $this->picking($tenant, [$partly, $shipped])
            ->where('totalUnits', 2)
            ->has('orders', 1)
            ->where('summary.0.quantity', 2);
    }

    // ─────────────────────────────────────────────── fatura belgeleri

    #[Test]
    public function invoice_documents_show_available_and_missing_documents_without_side_effects(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $issued = $this->awaitingOrder($tenant, $connection, '6801');
        $booksOnly = $this->awaitingOrder($tenant, $connection, '6802');
        $missing = $this->awaitingOrder($tenant, $connection, '6803');
        $this->invoice($tenant, $issued, Invoice::STATUS_ISSUED, 'e_archive');
        $this->invoice($tenant, $booksOnly, Invoice::STATUS_ISSUED, null);

        $this->actingAs($this->owner)->post('/orders/bulk', $this->form('invoice_documents', [$issued, $booksOnly, $missing]));
        $batch = TenantContext::runFor($tenant->id, fn () => FulfillmentBatch::query()->sole());

        $this->actingAs($this->owner)->get("/fulfillment/batches/{$batch->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('items.0.outcome', 'succeeded')
                ->where('items.0.downloadable', true)
                ->where('items.1.reasonCode', FulfillmentReason::INVOICE_BOOKS_ONLY->value)
                ->where('items.1.downloadable', false)
                ->where('items.2.reasonCode', FulfillmentReason::INVOICE_DOCUMENT_MISSING->value));

        Queue::assertNothingPushed();
        $this->assertSame(2, TenantContext::runFor($tenant->id, fn () => Invoice::query()->count()), 'Belge görünümü fatura KESMEZ.');
    }

    #[Test]
    public function repeated_invoice_issuing_never_opens_a_second_invoice(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenant, $connection, '6901');
        $this->account($tenant);

        $this->actingAs($this->owner)->post('/orders/bulk', $this->form('issue_invoices', [$order]));
        $this->actingAs($this->owner)->post('/orders/bulk', $this->form('issue_invoices', [$order]));

        $this->assertSame(1, TenantContext::runFor($tenant->id, fn () => Invoice::query()->where('order_id', $order->id)->count()));
        Queue::assertPushed(IssueInvoice::class, 1);
    }

    #[Test]
    public function issuing_without_an_invoice_account_requires_action(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenant, $connection, '6902');

        $this->actingAs($this->owner)->post('/orders/bulk', $this->form('issue_invoices', [$order]));
        $batch = TenantContext::runFor($tenant->id, fn () => FulfillmentBatch::query()->sole());

        $this->actingAs($this->owner)->get("/fulfillment/batches/{$batch->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('items.0.outcome', 'action_required')
                ->where('items.0.reasonCode', FulfillmentReason::INVOICE_ACCOUNT_MISSING->value));
        Queue::assertNothingPushed();
    }

    // ─────────────────────────────────────────────── iç

    /** @param list<Order> $orders */
    private function form(string $action, array $orders): array
    {
        return ['action' => $action, 'order_ids' => array_map(fn (Order $o) => $o->id, $orders), 'idempotency_key' => (string) Str::uuid()];
    }

    /** @param list<Order> $orders */
    private function picking(Tenant $tenant, array $orders): AssertableInertia
    {
        $this->actingAs($this->owner)->post('/orders/bulk', $this->form('picking', $orders));
        $batch = TenantContext::runFor($tenant->id, fn () => FulfillmentBatch::query()->latest('id')->firstOrFail());
        $captured = null;

        $this->actingAs($this->owner)->get("/fulfillment/batches/{$batch->id}/picking")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$captured) {
                $captured = $page->component('Fulfillment/Picking');
            });

        return $captured;
    }

    private function invoice(Tenant $tenant, Order $order, string $status, ?string $type): void
    {
        TenantContext::runFor($tenant->id, fn () => Invoice::query()->forceCreate([
            'tenant_id' => $tenant->id, 'order_id' => $order->id, 'provider' => 'parasut',
            'status' => $status, 'document_type' => $type, 'invoice_number' => 'GIB2026000000001',
            'issued_at' => now(),
        ]));
    }

    private function account(Tenant $tenant): void
    {
        config(['services.parasut.client_id' => 'pazar-istemci', 'services.parasut.client_secret' => 'pazar-sir']);
        TenantContext::runFor($tenant->id, fn () => InvoiceAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => InvoiceAccount::PROVIDER_PARASUT,
            'credentials' => ['access_token' => 'a', 'refresh_token' => 'r'],
            'token_expires_at' => now()->addHour(),
            'company_id' => '1',
            'company_name' => 'Örnek',
            'settings' => ['companies' => [['id' => '1', 'name' => 'Örnek']]],
            'status' => InvoiceAccount::STATUS_CONNECTED,
        ]));
    }
}
