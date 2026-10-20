<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Invoicing\Jobs\IssueInvoice;
use App\Domain\Invoicing\Jobs\IssueInvoiceCorrection;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Invoicing\Support\InvoiceProviders;
use App\Domain\Orders\Models\Order;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Sync\Support\ChannelErrorText;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S6 · ADR-007 — satış faturasının düzeltmeleri: iptal ve iade kaydı.
 *
 * Korunan kurallar:
 *   · düzeltme satış faturası DEĞİLDİR (sipariş ekranı, yeniden deneme,
 *     PDF, kota ve satış işi onu görmez)
 *   · iptal yalnız iptal edilen siparişte, e-fatura hiç; süresi geçmiş
 *     e-arşive istek GİTMEZ; yeniden deneme ikinci kez iptal istemez
 *   · iade yalnız sonuçlanmış talepte, kalemler kanonik kayıttan; satış
 *     anı tutarı bilinmeyen kalem kaydı açtırmaz
 *   · iptal ↔ iade birbirini dışlar
 */
final class InvoiceCorrectionTest extends TestCase
{
    use InvoicingFixtures;
    use RefreshDatabase;

    // ─────────────────────────────────────────────────── şema

    #[Test]
    public function an_order_keeps_one_sale_invoice_but_can_carry_corrections(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $sale = $this->issuedSale($tenant, $order);

        $this->asTenant($tenant, function () use ($order, $sale): void {
            Invoice::query()->create(['order_id' => $order->id, 'provider' => 'parasut', 'status' => 'pending', 'kind' => 'cancel', 'parent_invoice_id' => $sale->id]);

            $this->assertSame($sale->id, $order->fresh()->invoice->id, 'Satış faturası ilişkisi iptal kaydını gösterdi.');
            $this->assertCount(1, $order->fresh()->invoiceCorrections);

            $this->expectException(QueryException::class);
            Invoice::query()->create(['order_id' => $order->id, 'provider' => 'parasut', 'status' => 'pending']);
        });
    }

    #[Test]
    public function a_correction_without_a_parent_is_refused_by_the_database(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();

        $this->expectException(QueryException::class);

        $this->asTenant($tenant, fn () => Invoice::query()->create(['order_id' => $order->id, 'provider' => 'parasut', 'status' => 'pending', 'kind' => 'return', 'ref' => 'x']));
    }

    #[Test]
    public function corrections_do_not_spend_the_monthly_invoice_quota(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $sale = $this->issuedSale($tenant, $order);

        $usage = $this->asTenant($tenant, function () use ($order, $sale): int {
            Invoice::query()->create(['order_id' => $order->id, 'provider' => 'parasut', 'status' => 'issued', 'kind' => 'cancel', 'parent_invoice_id' => $sale->id]);

            return app(EnforceQuota::class)->currentUsage(QuotaMetric::INVOICES);
        });

        $this->assertSame(1, $usage);
    }

    #[Test]
    public function the_sale_job_ignores_a_correction_row(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->fakeApis();
        $sale = $this->issuedSale($tenant, $order);
        $cancel = $this->asTenant($tenant, fn (): Invoice => Invoice::query()->create(['order_id' => $order->id, 'provider' => 'parasut', 'status' => 'pending', 'kind' => 'cancel', 'parent_invoice_id' => $sale->id]));

        (new IssueInvoice($cancel->id, $tenant->id))->withFakeQueueInteractions()->handle(
            app(AdapterRegistry::class),
            app(InvoiceProviders::class),
            app(ChannelErrorText::class),
        );

        $this->assertCount(0, $this->sent);
        $this->assertSame(Invoice::STATUS_PENDING, $this->reload($tenant, $cancel)->status);
    }

    // ─────────────────────────────────────────────────── iptal

    #[Test]
    public function a_cancelled_orders_e_archive_is_cancelled_in_parasut(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->issuedSale($tenant, $order);
        $this->cancelOrder($order);

        $this->actingAs($user)->post("/orders/{$order->id}/invoice/cancel")->assertSessionHasNoErrors();

        $cancel = $this->asTenant($tenant, fn (): Invoice => Invoice::query()->where('kind', 'cancel')->sole());
        $this->assertSame($user->id, $cancel->requested_by);
        Queue::assertPushedOn('orders:high', IssueInvoiceCorrection::class, fn (IssueInvoiceCorrection $job): bool => $job->invoiceId === $cancel->id);

        $this->fakeApis();
        $this->runJob($tenant, $cancel)->assertNotReleased();

        $this->assertSame(Invoice::STATUS_ISSUED, $this->reload($tenant, $cancel)->status);
        $this->assertCount(1, $this->sentTo('sales_invoices/si-1/cancel', 'DELETE'));
    }

    #[Test]
    public function an_order_that_is_not_cancelled_cannot_cancel_its_invoice(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->issuedSale($tenant, $order);

        $this->actingAs($user)->post("/orders/{$order->id}/invoice/cancel")->assertSessionHasErrors('invoice');

        $this->assertSame(0, $this->asTenant($tenant, fn (): int => Invoice::query()->where('kind', 'cancel')->count()));
        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_e_invoice_is_never_cancelled_from_here(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->issuedSale($tenant, $order, ['document_type' => Invoice::TYPE_E_INVOICE]);
        $this->cancelOrder($order);

        $this->actingAs($user)->post("/orders/{$order->id}/invoice/cancel")->assertSessionHasErrors('invoice');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_expired_e_archive_is_not_sent_and_tells_the_seller_why(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $cancel = $this->pendingCancel($tenant, $order);
        $this->fakeApis(['cancellableUntil' => now()->subHour()->toIso8601String()]);

        $this->runJob($tenant, $cancel)->assertNotReleased();

        $failed = $this->reload($tenant, $cancel);
        $this->assertSame(Invoice::STATUS_FAILED, $failed->status);
        $this->assertStringContainsString('iptal süresi geçti', (string) $failed->error);
        $this->assertCount(0, $this->sentTo('/cancel', 'DELETE'));
    }

    #[Test]
    public function an_unknown_cancellation_deadline_is_treated_as_expired(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $cancel = $this->pendingCancel($tenant, $order);
        $this->fakeApis(['cancellableUntil' => null]);

        $this->runJob($tenant, $cancel);

        $this->assertSame(Invoice::STATUS_FAILED, $this->reload($tenant, $cancel)->status);
        $this->assertCount(0, $this->sentTo('/cancel', 'DELETE'));
    }

    #[Test]
    public function an_invoice_already_cancelled_in_parasut_is_not_cancelled_twice(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $cancel = $this->pendingCancel($tenant, $order);
        $this->fakeApis(['itemType' => 'cancelled']);

        $this->runJob($tenant, $cancel);

        $this->assertSame(Invoice::STATUS_ISSUED, $this->reload($tenant, $cancel)->status);
        $this->assertCount(0, $this->sentTo('/cancel', 'DELETE'));
    }

    #[Test]
    public function a_books_only_invoice_is_cancelled_without_asking_for_an_e_archive(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $cancel = $this->pendingCancel($tenant, $order, ['document_type' => null, 'provider_document_id' => null]);
        $this->fakeApis(['cancellableUntil' => null]);

        $this->runJob($tenant, $cancel);

        $this->assertSame(Invoice::STATUS_ISSUED, $this->reload($tenant, $cancel)->status);
        $this->assertCount(0, $this->sentTo('e_archives/'));
        $this->assertCount(1, $this->sentTo('sales_invoices/si-1/cancel', 'DELETE'));
    }

    #[Test]
    public function a_failed_cancellation_is_retried_on_the_same_row(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $cancel = $this->pendingCancel($tenant, $order);
        $this->cancelOrder($order);
        $this->asTenant($tenant, fn () => $cancel->forceFill(['status' => 'failed', 'error' => 'x'])->save());

        $this->actingAs($user)->post("/orders/{$order->id}/invoice/corrections/{$cancel->id}/retry")->assertSessionHasNoErrors();

        $this->assertSame(Invoice::STATUS_PENDING, $this->reload($tenant, $cancel)->status);
        $this->assertSame(1, $this->asTenant($tenant, fn (): int => Invoice::query()->where('kind', 'cancel')->count()));
        Queue::assertPushed(IssueInvoiceCorrection::class);
    }

    // ─────────────────────────────────────────────────── iade

    #[Test]
    public function an_accepted_return_is_recorded_from_canonical_lines(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->issuedSale($tenant, $order);
        $this->snapshot($order, net: '240.00', vat: '20.00', includesVat: true);
        $claim = $this->returnRequest($tenant, $order, [['status' => 'accepted', 'quantity' => 1], ['status' => 'rejected', 'quantity' => 1]]);

        $this->actingAs($user)->post("/orders/{$order->id}/invoice/returns/{$claim->id}")->assertSessionHasNoErrors();

        $return = $this->asTenant($tenant, fn (): Invoice => Invoice::query()->where('kind', 'return')->sole());
        $this->assertSame($claim->id, $return->ref);

        $this->fakeApis();
        $this->runJob($tenant, $return)->assertNotReleased();

        $done = $this->reload($tenant, $return);
        $this->assertSame(Invoice::STATUS_ISSUED, $done->status);
        $this->assertSame('pb-1', $done->provider_invoice_id);

        $body = $this->sentTo('purchase_bills', 'POST')[0]->data()['data'];
        $this->assertSame('refund', $body['attributes']['item_type']);
        $this->assertSame('c-1', $body['relationships']['supplier']['data']['id']);
        $this->assertCount(1, $body['relationships']['details']['data'], 'Reddedilen kalem iadeye girdi.');
        $line = $body['relationships']['details']['data'][0]['attributes'];
        $this->assertSame(1, $line['quantity']);
        // 240,00 / 2 adet = 120,00 KDV dahil → %20 hariç 100,0000
        $this->assertSame('100.0000', $line['unit_price']);
        $this->assertSame(20, $line['vat_rate']);
    }

    #[Test]
    public function a_vat_exclusive_snapshot_is_grossed_up_before_the_net_price(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->issuedSale($tenant, $order);
        $this->snapshot($order, net: '200.00', vat: '20.00', includesVat: false);
        $claim = $this->returnRequest($tenant, $order, [['status' => 'accepted', 'quantity' => 2]]);
        $return = $this->pendingReturn($tenant, $order, $claim);
        $this->fakeApis();

        $this->runJob($tenant, $return);

        $line = $this->sentTo('purchase_bills', 'POST')[0]->data()['data']['relationships']['details']['data'][0]['attributes'];
        $this->assertSame(2, $line['quantity']);
        $this->assertSame('100.0000', $line['unit_price']);
    }

    #[Test]
    public function an_unsettled_return_request_is_not_recorded(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->issuedSale($tenant, $order);
        $this->snapshot($order, net: '240.00', vat: '20.00', includesVat: true);
        $claim = $this->returnRequest($tenant, $order, [['status' => 'accepted', 'quantity' => 1], ['status' => 'awaiting_action', 'quantity' => 1]]);

        $this->actingAs($user)->post("/orders/{$order->id}/invoice/returns/{$claim->id}")->assertSessionHasErrors('invoice');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_line_without_a_sale_snapshot_is_refused_instead_of_guessed(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->issuedSale($tenant, $order);
        $claim = $this->returnRequest($tenant, $order, [['status' => 'accepted', 'quantity' => 1]]);

        $this->actingAs($user)->post("/orders/{$order->id}/invoice/returns/{$claim->id}")
            ->assertSessionHasErrors(['invoice' => __('":title" kaleminin satış anı tutarı ya da KDV oranı bilinmiyor; iadeyi Paraşüt üzerinden elle girin.', ['title' => 'Kahve'])]);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_taxpayer_buyers_return_is_left_to_the_buyers_invoice(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->issuedSale($tenant, $order, ['document_type' => Invoice::TYPE_E_INVOICE]);
        $this->snapshot($order, net: '240.00', vat: '20.00', includesVat: true);
        $claim = $this->returnRequest($tenant, $order, [['status' => 'accepted', 'quantity' => 1]]);

        $this->actingAs($user)->post("/orders/{$order->id}/invoice/returns/{$claim->id}")->assertSessionHasErrors('invoice');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_retried_return_does_not_open_a_second_record(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->issuedSale($tenant, $order);
        $this->snapshot($order, net: '240.00', vat: '20.00', includesVat: true);
        $claim = $this->returnRequest($tenant, $order, [['status' => 'accepted', 'quantity' => 1]]);
        $return = $this->pendingReturn($tenant, $order, $claim, ['provider_invoice_id' => 'pb-1']);
        $this->fakeApis();

        $this->runJob($tenant, $return);

        $this->assertSame(Invoice::STATUS_ISSUED, $this->reload($tenant, $return)->status);
        $this->assertCount(0, $this->sentTo('purchase_bills'));
    }

    #[Test]
    public function a_temporary_provider_error_is_retried_later(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->issuedSale($tenant, $order);
        $this->snapshot($order, net: '240.00', vat: '20.00', includesVat: true);
        $claim = $this->returnRequest($tenant, $order, [['status' => 'accepted', 'quantity' => 1]]);
        $return = $this->pendingReturn($tenant, $order, $claim);
        $this->fakeApis(['purchaseBill' => [['errors' => [['detail' => 'bakımda']]], 503]]);

        $this->runJob($tenant, $return)->assertReleased();

        $row = $this->reload($tenant, $return);
        $this->assertSame(Invoice::STATUS_PENDING, $row->status);
        $this->assertSame(1, $row->attempts);
    }

    // ─────────────────────────────────────────────────── iptal ↔ iade

    #[Test]
    public function a_cancelled_invoice_takes_no_return_and_a_returned_one_no_cancel(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $sale = $this->issuedSale($tenant, $order);
        $this->snapshot($order, net: '240.00', vat: '20.00', includesVat: true);
        $claim = $this->returnRequest($tenant, $order, [['status' => 'accepted', 'quantity' => 1]]);
        $this->cancelOrder($order);

        $return = $this->pendingReturn($tenant, $order, $claim);
        $this->actingAs($user)->post("/orders/{$order->id}/invoice/cancel")->assertSessionHasErrors('invoice');

        $this->asTenant($tenant, fn () => $return->delete());
        $this->asTenant($tenant, fn () => Invoice::query()->create(['order_id' => $order->id, 'provider' => 'parasut', 'status' => 'issued', 'kind' => 'cancel', 'parent_invoice_id' => $sale->id]));
        $this->actingAs($user)->post("/orders/{$order->id}/invoice/returns/{$claim->id}")->assertSessionHasErrors('invoice');
    }

    // ─────────────────────────────────────────────────── ekran

    #[Test]
    public function the_order_screen_offers_only_the_corrections_that_apply(): void
    {
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->issuedSale($tenant, $order);
        $this->snapshot($order, net: '240.00', vat: '20.00', includesVat: true);
        $settled = $this->returnRequest($tenant, $order, [['status' => 'accepted', 'quantity' => 1]], 'C-1');
        $this->returnRequest($tenant, $order, [['status' => 'requested', 'quantity' => 1]], 'C-2');

        $this->actingAs($user)->get("/orders/{$order->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.canCancelInvoice', false)
            ->where('order.invoice.status', 'issued')
            ->has('order.returnInvoiceCandidates', 2)
            ->where('order.returnInvoiceCandidates.0.id', $settled->id)
            ->where('order.returnInvoiceCandidates.0.recordable', true)
            ->where('order.returnInvoiceCandidates.1.recordable', false)
            ->has('order.invoiceCorrections', 0));

        $this->cancelOrder($order);

        $this->actingAs($user)->get("/orders/{$order->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.canCancelInvoice', true));
    }

    // ─────────────────────────────────────────────────── yardımcılar

    private function issuedSale(Tenant $tenant, Order $order, array $overrides = []): Invoice
    {
        return $this->asTenant($tenant, fn (): Invoice => Invoice::query()->create([
            'order_id' => $order->id,
            'provider' => InvoiceAccount::PROVIDER_PARASUT,
            'status' => Invoice::STATUS_ISSUED,
            'document_type' => Invoice::TYPE_E_ARCHIVE,
            'provider_contact_id' => 'c-1',
            'provider_invoice_id' => 'si-1',
            'provider_document_id' => 'ea-1',
            'invoice_number' => 'GIB2026000000123',
            'issued_at' => now(),
            ...$overrides,
        ]));
    }

    private function pendingCancel(Tenant $tenant, Order $order, array $saleOverrides = []): Invoice
    {
        $sale = $this->issuedSale($tenant, $order, $saleOverrides);

        return $this->asTenant($tenant, fn (): Invoice => Invoice::query()->create([
            'order_id' => $order->id,
            'provider' => InvoiceAccount::PROVIDER_PARASUT,
            'status' => Invoice::STATUS_PENDING,
            'kind' => Invoice::KIND_CANCEL,
            'parent_invoice_id' => $sale->id,
        ]));
    }

    private function pendingReturn(Tenant $tenant, Order $order, ReturnRequest $claim, array $overrides = []): Invoice
    {
        return $this->asTenant($tenant, fn (): Invoice => Invoice::query()->create([
            'order_id' => $order->id,
            'provider' => InvoiceAccount::PROVIDER_PARASUT,
            'status' => Invoice::STATUS_PENDING,
            'kind' => Invoice::KIND_RETURN,
            'parent_invoice_id' => Invoice::query()->sales()->where('order_id', $order->id)->value('id'),
            'ref' => $claim->id,
            ...$overrides,
        ]));
    }

    private function cancelOrder(Order $order): void
    {
        DB::table('orders')->where('id', $order->id)->update(['state' => 'cancelled']);
    }

    private function snapshot(Order $order, string $net, string $vat, bool $includesVat): void
    {
        DB::table('order_lines')->where('order_id', $order->id)->update([
            'net_line_total' => $net,
            'vat_rate' => $vat,
            'amounts_include_vat' => $includesVat,
        ]);
    }

    /** @param list<array{status: string, quantity: int}> $lines */
    private function returnRequest(Tenant $tenant, Order $order, array $lines, string $claimId = 'C-1'): ReturnRequest
    {
        return $this->asTenant($tenant, function () use ($order, $lines, $claimId): ReturnRequest {
            $orderLine = $order->lines()->firstOrFail();

            $claim = ReturnRequest::query()->create([
                'channel_connection_id' => $order->channel_connection_id,
                'channel_type_code' => 'trendyol',
                'external_claim_id' => $claimId,
                'order_id' => $order->id,
                'match_status' => 'matched',
                'status' => $lines[0]['status'],
            ]);

            foreach ($lines as $i => $line) {
                $claim->lines()->create([
                    'channel_connection_id' => $order->channel_connection_id,
                    'external_item_id' => "{$claimId}-{$i}",
                    'sku' => 'KHV-1',
                    'quantity' => $line['quantity'],
                    'order_line_id' => $orderLine->id,
                    'match_status' => 'matched',
                    'status' => $line['status'],
                ]);
            }

            return $claim;
        });
    }

    private function runJob(Tenant $tenant, Invoice $invoice): IssueInvoiceCorrection
    {
        $job = (new IssueInvoiceCorrection($invoice->id, $tenant->id))->withFakeQueueInteractions();

        $job->handle(app(InvoiceProviders::class));

        return $job;
    }

    private function reload(Tenant $tenant, Invoice $invoice): Invoice
    {
        return $this->asTenant($tenant, fn (): Invoice => Invoice::query()->findOrFail($invoice->id));
    }
}
