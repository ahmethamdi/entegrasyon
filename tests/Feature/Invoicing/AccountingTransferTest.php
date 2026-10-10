<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Invoicing\Jobs\IssueInvoice;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Invoicing\Support\InvoiceBuyer;
use App\Domain\Invoicing\Support\InvoiceDraft;
use App\Domain\Invoicing\Support\InvoiceLine;
use App\Domain\Invoicing\Support\InvoiceProviders;
use App\Domain\Messaging\Actions\IngestInboxMessage;
use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Domain\Orders\Actions\RecordPanelShipment;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Support\ChannelErrorText;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Muhasebe 1. dilim — Paraşüt'e tam aktarım.
 *
 * Üç ayar, üç davranış: otomatik aktarım (sipariş kargoya verilince /
 * teslim edilince), kip (e-belge / yalnız muhasebe), tahsilat (kanal →
 * kasa/banka). ÖLÜ AYAR OLMAZ: her ayarın hem açık hem kapalı hâli burada
 * davranışla sınanır.
 *
 * Otomatik tetik GERÇEK yoldan sınanır — inbox mesajı → `ProcessInboxMessage`
 * → `OrderEventRouter` → kanca. Kancanın kendi testi yeşilken router onu hiç
 * çağırmıyor olabilirdi ("yazılı ama hiçbir akıştan çağrılmıyor" dersi).
 */
final class AccountingTransferTest extends TestCase
{
    use InvoicingFixtures;
    use RefreshDatabase;

    /** Fixture siparişi 2026-10-08'de verildi; ayar ondan önce açılmış. */
    private const SINCE_BEFORE_ORDER = '2026-10-01T00:00:00+03:00';

    private const SINCE_AFTER_ORDER = '2026-10-09T00:00:00+03:00';

    // ─────────────────────────────────────────────────── otomatik tetik

    #[Test]
    public function a_shipped_trendyol_package_opens_an_invoice_when_enabled(): void
    {
        Queue::fake();
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant, ['settings' => $this->settings(['auto_issue' => 'shipped'])]);

        $this->deliverStatus($tenant, $order, 'Shipped');

        $invoice = $this->asTenant($tenant, fn (): ?Invoice => Invoice::query()->where('order_id', $order->id)->first());
        $this->assertNotNull($invoice, 'Kargoya verilen sipariş otomatik faturalanmalı.');
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->status);
        $this->assertNull($invoice->requested_by, 'Otomatik istek bir kullanıcıya yazılmaz.');

        Queue::assertPushedOn('orders:high', IssueInvoice::class, fn (IssueInvoice $job): bool => $job->invoiceId === $invoice->id);
    }

    #[Test]
    public function nothing_is_opened_while_auto_issue_is_off(): void
    {
        Queue::fake();
        [$tenant, , $order] = $this->trendyolOrder();
        // Kapalı ama başlangıç anı duruyor: kararı `auto_issue` vermeli.
        $this->parasutAccount($tenant, ['settings' => $this->settings(['auto_issue' => 'off'])]);

        $this->deliverStatus($tenant, $order, 'Shipped');
        $this->deliverStatus($tenant, $order, 'Delivered');

        $this->assertSame(0, $this->invoiceCount($tenant));
        Queue::assertNotPushed(IssueInvoice::class);
    }

    /** ⚠️ GERİYE DÖNÜK FATURA YOK — ayar açılmadan önce verilen sipariş. */
    #[Test]
    public function an_order_placed_before_the_setting_was_enabled_is_not_invoiced(): void
    {
        Queue::fake();
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant, ['settings' => $this->settings(['auto_issue' => 'shipped', 'auto_issue_since' => self::SINCE_AFTER_ORDER])]);

        $this->deliverStatus($tenant, $order, 'Shipped');

        $this->assertSame(0, $this->invoiceCount($tenant));
    }

    #[Test]
    public function the_delivered_setting_waits_for_delivery(): void
    {
        Queue::fake();
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant, ['settings' => $this->settings(['auto_issue' => 'delivered'])]);

        $this->deliverStatus($tenant, $order, 'Shipped');
        $this->assertSame(0, $this->invoiceCount($tenant), 'Teslim beklenirken kargo aşaması fatura açmamalı.');

        $this->deliverStatus($tenant, $order, 'Delivered');
        $this->assertSame(1, $this->invoiceCount($tenant));
    }

    #[Test]
    public function a_cancelled_order_is_not_invoiced(): void
    {
        Queue::fake();
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant, ['settings' => $this->settings(['auto_issue' => 'shipped'])]);

        $this->deliverStatus($tenant, $order, 'Cancelled');
        $this->assertSame('Cancelled', $this->asTenant($tenant, fn () => Order::query()->findOrFail($order->id)->status));

        // Satıcı iptal edilmiş siparişe panelden kargo girse bile.
        $this->asTenant($tenant, fn () => app(RecordPanelShipment::class)->run(Order::query()->findOrFail($order->id), 'Yurtiçi', 'YK123'));

        $this->assertSame(0, $this->invoiceCount($tenant));
    }

    #[Test]
    public function a_panel_shipment_counts_as_shipped(): void
    {
        Queue::fake();
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant, ['settings' => $this->settings(['auto_issue' => 'shipped'])]);

        $this->asTenant($tenant, fn () => app(RecordPanelShipment::class)->run(Order::query()->findOrFail($order->id), 'Yurtiçi', 'YK123'));

        $this->assertSame(1, $this->invoiceCount($tenant));
    }

    /** Yoklama aynı durumu defalarca görür; panel kargosu da üstüne gelir. */
    #[Test]
    public function triggering_again_is_harmless(): void
    {
        Queue::fake();
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant, ['settings' => $this->settings(['auto_issue' => 'shipped'])]);

        $this->deliverStatus($tenant, $order, 'Shipped');
        $this->deliverStatus($tenant, $order, 'Shipped', eventId: '9001:Shipped:ikinci');
        $this->deliverStatus($tenant, $order, 'Delivered');
        $this->asTenant($tenant, fn () => app(RecordPanelShipment::class)->run(Order::query()->findOrFail($order->id), null, 'YK123'));

        $this->assertSame(1, $this->invoiceCount($tenant));
        Queue::assertPushed(IssueInvoice::class, 1);
    }

    #[Test]
    public function a_channel_without_invoice_data_is_not_auto_invoiced(): void
    {
        Queue::fake();
        [$tenant, , $order] = $this->trendyolOrder('woocommerce');
        $this->parasutAccount($tenant, ['settings' => $this->settings(['auto_issue' => 'shipped'])]);

        $this->asTenant($tenant, fn () => app(RecordPanelShipment::class)->run(Order::query()->findOrFail($order->id), null, 'YK123'));

        $this->assertSame(0, $this->invoiceCount($tenant));
    }

    #[Test]
    public function an_unusable_account_does_not_auto_invoice(): void
    {
        Queue::fake();
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant, ['status' => InvoiceAccount::STATUS_REVOKED, 'settings' => $this->settings(['auto_issue' => 'shipped'])]);

        $this->deliverStatus($tenant, $order, 'Shipped');

        $this->assertSame(0, $this->invoiceCount($tenant));
    }

    // ─────────────────────────────────────────────────── yalnız muhasebe

    #[Test]
    public function books_only_records_the_invoice_without_an_e_document_or_upload(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant, ['settings' => $this->settings(['mode' => 'books_only'])]);
        $this->fakeApis();
        $invoice = $this->pending($tenant, $order->id);

        $this->runJob($tenant, $invoice)->assertNotReleased();

        $done = $this->reload($tenant, $invoice);
        $this->assertSame(Invoice::STATUS_ISSUED, $done->status);
        $this->assertNull($done->document_type);
        $this->assertNull($done->provider_job_id);
        $this->assertNull($done->upload_status, 'Bu kipte kanala dosya yüklenmez.');
        $this->assertNotNull($done->issued_at);
        $this->assertSame('si-1', $done->provider_invoice_id, 'Satış faturası muhasebeye işlenmeli.');

        $this->assertCount(1, $this->sentTo('/sales_invoices', 'POST'));
        $this->assertCount(0, $this->sentTo('/e_archives'));
        $this->assertCount(0, $this->sentTo('/e_invoices'));
        $this->assertCount(0, $this->sentTo('/e_invoice_inboxes'));
        $this->assertCount(0, $this->sentTo('/trackable_jobs'));
        $this->assertCount(0, $this->sentTo('seller-invoice-file'));
    }

    #[Test]
    public function e_document_mode_still_issues_the_e_archive(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant, ['settings' => $this->settings(['mode' => 'e_document'])]);
        $this->fakeApis();
        $invoice = $this->pending($tenant, $order->id);

        $this->runJob($tenant, $invoice)->assertReleased(IssueInvoice::POLL_SECONDS);

        $this->assertSame(Invoice::STATUS_ISSUING, $this->reload($tenant, $invoice)->status);
        $this->assertCount(1, $this->sentTo('/e_archives', 'POST'));
    }

    // ─────────────────────────────────────────────────── tahsilat

    #[Test]
    public function a_mapped_channel_records_the_payment_into_the_chosen_account(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant, ['settings' => $this->settings(['payment_accounts' => ['trendyol' => 'acc-banka']])]);
        $this->fakeApis();
        $invoice = $this->pending($tenant, $order->id);

        $this->runJob($tenant, $invoice);

        $payments = $this->sentTo('/payments', 'POST');
        $this->assertCount(1, $payments);
        $this->assertStringEndsWith('/sales_invoices/si-1/payments', $payments[0]->url());

        $body = $payments[0]->data()['data'];
        $this->assertSame('payments', $body['type']);
        $this->assertSame('acc-banka', $body['attributes']['account_id']);
        // 2 × 120,00 TL KDV dahil.
        $this->assertSame('240.00', $body['attributes']['amount']);
        // Siparişin Türkiye saatiyle günü — e-arşivin ödeme tarihiyle aynı.
        $archive = $this->sentTo('/e_archives', 'POST')[0]->data()['data']['attributes']['internet_sale'];
        $this->assertSame($archive['payment_date'], $body['attributes']['date']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $body['attributes']['date']);
        $this->assertStringContainsString('10500001', $body['attributes']['description']);

        $this->assertSame('pay-1', $this->reload($tenant, $invoice)->provider_payment_id);
    }

    /** e-belge reddedilip yeniden denense de İKİNCİ tahsilat açılmaz. */
    #[Test]
    public function a_retried_invoice_does_not_record_a_second_payment(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant, ['settings' => $this->settings(['payment_accounts' => ['trendyol' => 'acc-kasa']])]);
        $this->fakeApis(['job' => 'error']);
        $invoice = $this->pending($tenant, $order->id);

        $this->runJob($tenant, $invoice);
        $this->runJob($tenant, $invoice);
        $this->assertSame(Invoice::STATUS_FAILED, $this->reload($tenant, $invoice)->status);
        $this->assertCount(1, $this->sentTo('/payments', 'POST'));

        $this->actingAs($user)->post("/orders/{$order->id}/invoice/retry")->assertSessionHasNoErrors();
        $this->fakeApis(['job' => 'done']);
        $this->runJob($tenant, $invoice);

        $this->assertCount(0, $this->sentTo('/payments', 'POST'), 'Yeniden deneme ikinci tahsilat açmamalı.');
        $this->assertCount(1, $this->sentTo('/e_archives', 'POST'));
    }

    #[Test]
    public function without_a_mapping_no_payment_is_recorded(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        // Başka kanalın eşlemesi bu siparişe uygulanmaz.
        $this->parasutAccount($tenant, ['settings' => $this->settings(['payment_accounts' => ['hepsiburada' => 'acc-kasa']])]);
        $this->fakeApis();
        $invoice = $this->pending($tenant, $order->id);

        $this->runJob($tenant, $invoice);

        $this->assertCount(0, $this->sentTo('/payments'));
        $this->assertNull($this->reload($tenant, $invoice)->provider_payment_id);
        $this->assertSame('si-1', $this->reload($tenant, $invoice)->provider_invoice_id);
    }

    /** Kayan nokta toplamı kuruş kaçırmaz (0,1 + 0,2 ≠ 0,30000000000000004). */
    #[Test]
    public function the_payment_amount_is_summed_to_the_cent(): void
    {
        $draft = new InvoiceDraft(
            buyer: new InvoiceBuyer('Ayşe', false, InvoiceBuyer::ANONYMOUS_TCKN, null, 'Adres', null, null),
            lines: [
                new InvoiceLine('A', 1, '0.10', 20),
                new InvoiceLine('B', 1, '0.20', 20),
                new InvoiceLine('C', 3, '33.33', 10),
                new InvoiceLine('D', 7, '1999.99', 20),
            ],
            orderNumber: '1',
            orderedAt: new DateTimeImmutable('2026-10-08 10:00:00'),
            platformName: 'Trendyol',
            platformUrl: 'https://www.trendyol.com',
        );

        $this->assertSame('14100.22', $draft->grossTotal());
    }

    // ─────────────────────────────────────────────────── ayar ekranı

    #[Test]
    public function the_settings_screen_lists_ledger_accounts_and_invoicing_channels(): void
    {
        [$tenant, $user] = $this->trendyolOrder();
        $this->parasutAccount($tenant, ['settings' => $this->settings(['payment_accounts' => ['trendyol' => 'acc-banka'], 'mode' => 'books_only', 'auto_issue' => 'delivered'])]);
        $this->fakeApis();

        $this->actingAs($user)->get('/settings/invoicing')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Invoicing')
            ->has('ledgerAccounts', 2)
            ->where('ledgerAccounts.0.id', 'acc-kasa')
            ->where('ledgerAccounts.1.name', 'Ziraat Bankası')
            ->where('ledgerAccountsError', null)
            ->has('channels', 1)
            ->where('channels.0.code', 'trendyol')
            ->where('account.mode', 'books_only')
            ->where('account.autoIssue', 'delivered')
            ->where('account.paymentAccounts.trendyol', 'acc-banka'));

        $this->assertCount(1, $this->sentTo('/accounts', 'GET'));
    }

    #[Test]
    public function the_settings_screen_still_opens_when_accounts_cannot_be_read(): void
    {
        [$tenant, $user] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->fakeApis(['accounts' => [['errors' => [['detail' => 'bakımda']]], 503]]);

        $this->actingAs($user)->get('/settings/invoicing')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('ledgerAccounts', 0)
            ->where('ledgerAccountsError', fn (?string $error): bool => $error !== null && $error !== '')
            ->where('account.status', 'connected'));
    }

    #[Test]
    public function saving_turns_auto_issue_on_from_now_and_keeps_the_start_when_switching_stage(): void
    {
        [$tenant, $user] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->fakeApis();

        Carbon::setTestNow('2026-10-10 12:00:00');
        $this->save($user, ['mode' => 'books_only', 'auto_issue' => 'shipped', 'payment_accounts' => ['trendyol' => 'acc-banka']])->assertSessionHasNoErrors();

        $account = $this->account($tenant);
        $this->assertSame('books_only', $account->mode());
        $this->assertSame('shipped', $account->autoIssue());
        $this->assertSame('acc-banka', $account->paymentAccountFor('trendyol'));
        $since = $account->autoIssueSince();
        $this->assertNotNull($since);
        $this->assertTrue($since->equalTo(Carbon::parse('2026-10-10 12:00:00')));

        // Kargoda → teslimde: ayar zaten açıktı, başlangıç KORUNUR.
        Carbon::setTestNow('2026-10-12 09:00:00');
        $this->save($user, ['auto_issue' => 'delivered'])->assertSessionHasNoErrors();
        $this->assertTrue($this->account($tenant)->autoIssueSince()?->equalTo(Carbon::parse('2026-10-10 12:00:00')));

        // Kapat → aç: yeni başlangıç; aradaki dönem kapsam dışı.
        $this->save($user, ['auto_issue' => 'off'])->assertSessionHasNoErrors();
        $this->assertNull($this->account($tenant)->autoIssueSince());

        Carbon::setTestNow('2026-10-13 08:00:00');
        $this->save($user, ['auto_issue' => 'shipped'])->assertSessionHasNoErrors();
        $this->assertTrue($this->account($tenant)->autoIssueSince()?->equalTo(Carbon::parse('2026-10-13 08:00:00')));

        // Boş seçim eşlemeyi kaldırır.
        $this->save($user, ['payment_accounts' => ['trendyol' => '']])->assertSessionHasNoErrors();
        $this->assertNull($this->account($tenant)->paymentAccountFor('trendyol'));

        Carbon::setTestNow();
    }

    #[Test]
    public function unknown_modes_stages_channels_and_accounts_are_refused(): void
    {
        [$tenant, $user] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->fakeApis();

        $this->save($user, ['mode' => 'kagit'])->assertSessionHasErrors('mode');
        $this->save($user, ['auto_issue' => 'weekly'])->assertSessionHasErrors('auto_issue');
        // Paraşüt'ün listesinde olmayan hesap — başka bir kasaya tahsilat işlenmesin.
        $this->save($user, ['payment_accounts' => ['trendyol' => 'acc-yok']])->assertSessionHasErrors('payment_accounts');
        // Kiracının faturalanabilir kanalı olmayan anahtar.
        $this->save($user, ['payment_accounts' => ['hepsiburada' => 'acc-kasa']])->assertSessionHasErrors('payment_accounts');

        $account = $this->account($tenant);
        $this->assertSame('e_document', $account->mode());
        $this->assertSame('off', $account->autoIssue());
        $this->assertNull($account->paymentAccountFor('trendyol'));
        $this->assertNull($account->paymentAccountFor('hepsiburada'));
    }

    #[Test]
    public function a_payment_mapping_is_not_saved_when_accounts_cannot_be_verified(): void
    {
        [$tenant, $user] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->fakeApis(['accounts' => [['errors' => [['detail' => 'bakımda']]], 503]]);

        $this->save($user, ['payment_accounts' => ['trendyol' => 'acc-banka']])->assertSessionHasErrors('payment_accounts');

        $this->assertNull($this->account($tenant)->paymentAccountFor('trendyol'));
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @return array<string, mixed> */
    private function settings(array $overrides = []): array
    {
        return [
            'companies' => [['id' => self::COMPANY, 'name' => 'Örnek Ticaret']],
            'auto_issue_since' => self::SINCE_BEFORE_ORDER,
            ...$overrides,
        ];
    }

    /**
     * Paketin yeni durumunu GERÇEK yoldan teslim eder: inbox → işleyici →
     * router → kanca. Yoklamanın yazdığı mesajla aynı biçim.
     */
    private function deliverStatus(Tenant $tenant, Order $order, string $status, ?string $eventId = null): void
    {
        $connection = $this->asTenant($tenant, fn (): ChannelConnection => ChannelConnection::query()->findOrFail($order->channel_connection_id));

        $message = app(IngestInboxMessage::class)->run(
            connection: $connection,
            source: 'polling',
            externalEventId: $eventId ?? "9001:{$status}",
            eventType: 'order.polled',
            payload: json_encode($this->trendyolPackage([
                'shipmentPackageStatus' => $status,
                'status' => $status,
                'lines' => [['orderLineItemStatusName' => $status]],
            ]), JSON_THROW_ON_ERROR),
        );

        (new ProcessInboxMessage($tenant->id, $message->id))->handle();
    }

    private function invoiceCount(Tenant $tenant): int
    {
        return $this->asTenant($tenant, fn (): int => Invoice::query()->count());
    }

    private function account(Tenant $tenant): InvoiceAccount
    {
        return $this->asTenant($tenant, fn (): InvoiceAccount => InvoiceAccount::query()->sole());
    }

    /** Firma her kayıtta zorunludur; diğer alanlar gönderilmezse korunur. */
    private function save(mixed $user, array $fields): TestResponse
    {
        return $this->actingAs($user)->put('/settings/invoicing', ['company_id' => self::COMPANY, ...$fields]);
    }

    private function pending(Tenant $tenant, string $orderId): Invoice
    {
        return $this->asTenant($tenant, fn (): Invoice => Invoice::query()->create([
            'order_id' => $orderId,
            'provider' => InvoiceAccount::PROVIDER_PARASUT,
            'status' => Invoice::STATUS_PENDING,
        ]));
    }

    private function runJob(Tenant $tenant, Invoice $invoice): IssueInvoice
    {
        $job = (new IssueInvoice($invoice->id, $tenant->id))->withFakeQueueInteractions();

        $job->handle(app(AdapterRegistry::class), app(InvoiceProviders::class), app(ChannelErrorText::class));

        return $job;
    }

    private function reload(Tenant $tenant, Invoice $invoice): Invoice
    {
        return $this->asTenant($tenant, fn (): Invoice => Invoice::query()->findOrFail($invoice->id));
    }
}
