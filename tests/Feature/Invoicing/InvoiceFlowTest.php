<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Invoicing\Jobs\IssueInvoice;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Invoicing\Support\InvoiceProviders;
use App\Domain\Sync\Support\ChannelErrorText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * e-fatura uçtan uca — PANEL → İŞ → KANAL → ENTEGRATÖR.
 *
 * Adapter ve entegratör testleri parçaları tek başına sınar; bu dosya
 * zinciri birlikte sınar (kargo bildiriminde "yazılı ama hiçbir akıştan
 * çağrılmıyor" dersi). Ayrıca kullanıcı kararını korur: alıcının kişisel
 * verisi hiçbir tabloya ve hiçbir ekrana düşmez.
 */
final class InvoiceFlowTest extends TestCase
{
    use InvoicingFixtures;
    use RefreshDatabase;

    // ─────────────────────────────────────────────────── panel

    #[Test]
    public function requesting_from_the_panel_records_and_queues_the_invoice(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);

        $this->actingAs($user)->post("/orders/{$order->id}/invoice")->assertRedirect()->assertSessionHasNoErrors();

        $invoice = $this->asTenant($tenant, fn (): Invoice => Invoice::query()->sole());
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->status);
        $this->assertSame($user->id, $invoice->requested_by);

        Queue::assertPushedOn('orders:high', IssueInvoice::class, fn (IssueInvoice $job): bool => $job->invoiceId === $invoice->id);
    }

    #[Test]
    public function without_a_connected_account_no_invoice_is_opened(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->trendyolOrder();

        $this->actingAs($user)->post("/orders/{$order->id}/invoice")->assertSessionHasErrors('invoice');

        $this->parasutAccount($tenant, ['status' => InvoiceAccount::STATUS_NEEDS_COMPANY, 'company_id' => null]);
        $this->actingAs($user)->post("/orders/{$order->id}/invoice")->assertSessionHasErrors('invoice');

        $this->assertSame(0, $this->asTenant($tenant, fn (): int => Invoice::query()->count()));
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_channel_without_invoice_data_is_refused(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->trendyolOrder('woocommerce');
        $this->parasutAccount($tenant);

        $this->actingAs($user)->post("/orders/{$order->id}/invoice")->assertSessionHasErrors('invoice');
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_second_request_for_the_same_order_is_refused(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);

        $this->actingAs($user)->post("/orders/{$order->id}/invoice");
        $this->actingAs($user)->post("/orders/{$order->id}/invoice")->assertSessionHasErrors('invoice');

        $this->assertSame(1, $this->asTenant($tenant, fn (): int => Invoice::query()->count()));
    }

    // ─────────────────────────────────────────────────── iş

    /** İki tur: gönder → sor → kesildi. Alıcı verisi HİÇBİR satıra yazılmaz. */
    #[Test]
    public function the_job_issues_the_invoice_end_to_end_without_storing_the_buyer(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->fakeApis();
        $invoice = $this->pending($tenant, $order->id);

        $first = $this->runJob($tenant, $invoice);
        $first->assertReleased(IssueInvoice::POLL_SECONDS);
        $this->assertSame(Invoice::STATUS_ISSUING, $this->reload($tenant, $invoice)->status);

        $this->runJob($tenant, $invoice)->assertNotReleased();

        $done = $this->reload($tenant, $invoice);
        $this->assertSame(Invoice::STATUS_ISSUED, $done->status);
        $this->assertSame(Invoice::TYPE_E_ARCHIVE, $done->document_type);
        $this->assertSame('ea-1', $done->provider_document_id);
        $this->assertSame('GIB2026000000123', $done->invoice_number);
        $this->assertNotNull($done->issued_at);

        // Kullanıcı kararı: "anlık çek, saklama" — hiçbir tabloda iz yok.
        foreach (['invoices', 'invoice_accounts', 'orders', 'order_events', 'audit_logs'] as $table) {
            $dump = json_encode(DB::table($table)->get(), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('Ayşe', (string) $dump, "{$table} alıcı adını taşıyor.");
            $this->assertStringNotContainsString('12345678901', (string) $dump, "{$table} TCKN taşıyor.");
            $this->assertStringNotContainsString('Atatürk Cad', (string) $dump, "{$table} adres taşıyor.");
        }
    }

    #[Test]
    public function a_cancelled_package_fails_without_touching_the_provider(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->fakeApis(['package' => $this->trendyolPackage(['shipmentPackageStatus' => 'Cancelled'])]);
        $invoice = $this->pending($tenant, $order->id);

        $this->runJob($tenant, $invoice)->assertNotReleased();

        $failed = $this->reload($tenant, $invoice);
        $this->assertSame(Invoice::STATUS_FAILED, $failed->status);
        $this->assertStringContainsString('iptal', (string) $failed->error);
        $this->assertCount(0, $this->sentTo('api.parasut.com'));
    }

    #[Test]
    public function a_revoked_account_fails_with_a_reconnect_hint(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant, ['status' => InvoiceAccount::STATUS_REVOKED]);
        $this->fakeApis();
        $invoice = $this->pending($tenant, $order->id);

        $this->runJob($tenant, $invoice);

        $failed = $this->reload($tenant, $invoice);
        $this->assertSame(Invoice::STATUS_FAILED, $failed->status);
        $this->assertStringContainsString('e-fatura ayarlarından', (string) $failed->error);
    }

    /**
     * Entegratör belgeyi reddetti → iş kimliği silinir; yeniden deneme YENİ
     * e-belge isteği gönderir ama taslak faturayı ikinci kez AÇMAZ.
     */
    #[Test]
    public function a_rejected_document_can_be_retried_without_a_second_draft(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->fakeApis(['job' => 'error']);
        $invoice = $this->pending($tenant, $order->id);

        $this->runJob($tenant, $invoice);
        $this->runJob($tenant, $invoice);

        $failed = $this->reload($tenant, $invoice);
        $this->assertSame(Invoice::STATUS_FAILED, $failed->status);
        $this->assertNull($failed->provider_job_id);
        $this->assertSame('si-1', $failed->provider_invoice_id);
        $this->assertStringContainsString('Alıcı VKN geçersiz', (string) $failed->error);

        $this->actingAs($user)->post("/orders/{$order->id}/invoice/retry")->assertSessionHasNoErrors();
        $this->assertSame(Invoice::STATUS_PENDING, $this->reload($tenant, $invoice)->status);

        $this->fakeApis(['job' => 'done']);
        $this->runJob($tenant, $invoice);
        $this->runJob($tenant, $invoice);

        $this->assertSame(Invoice::STATUS_ISSUED, $this->reload($tenant, $invoice)->status);
        $this->assertCount(0, $this->sentTo('/sales_invoices', 'POST'), 'Taslak fatura ikinci kez açılmamalı.');
        $this->assertCount(1, $this->sentTo('/e_archives', 'POST'));
    }

    /**
     * Zaman aşımıyla düşen fatura: iş kimliği KORUNUR ve yeniden deneme
     * önce onu sorar — belge sonradan oluşmuşsa ikinci resmî belge açılmaz.
     */
    #[Test]
    public function retrying_a_timed_out_invoice_asks_the_existing_job_first(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $invoice = $this->pending($tenant, $order->id);

        $this->asTenant($tenant, fn () => Invoice::query()->whereKey($invoice->id)->update([
            'status' => Invoice::STATUS_FAILED,
            'provider_contact_id' => 'c-1',
            'provider_invoice_id' => 'si-1',
            'provider_job_id' => 'job-1',
            'document_type' => Invoice::TYPE_E_ARCHIVE,
        ]));

        $this->actingAs($user)->post("/orders/{$order->id}/invoice/retry");
        $this->assertSame(Invoice::STATUS_ISSUING, $this->reload($tenant, $invoice)->status);

        $this->fakeApis(['job' => 'done']);
        $this->runJob($tenant, $invoice);

        $this->assertSame(Invoice::STATUS_ISSUED, $this->reload($tenant, $invoice)->status);
        $this->assertCount(0, $this->sentTo('/e_archives', 'POST'));
    }

    // ─────────────────────────────────────────────────── ekran

    #[Test]
    public function the_order_screen_shows_invoice_state_but_no_buyer_data(): void
    {
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $invoice = $this->pending($tenant, $order->id);
        $this->asTenant($tenant, fn () => Invoice::query()->whereKey($invoice->id)->update([
            'status' => Invoice::STATUS_ISSUED, 'document_type' => Invoice::TYPE_E_ARCHIVE, 'invoice_number' => 'GIB2026000000123',
        ]));

        $this->actingAs($user)->get("/orders/{$order->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.invoice.status', 'issued')
            ->where('order.invoice.number', 'GIB2026000000123')
            ->where('order.invoiceChannelSupported', true)
            ->where('order.invoiceAccountReady', true)
            ->missing('order.invoice.provider_contact_id'));
    }

    #[Test]
    public function the_pdf_link_is_fetched_fresh_and_redirected(): void
    {
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->fakeApis();
        $invoice = $this->pending($tenant, $order->id);
        $this->asTenant($tenant, fn () => Invoice::query()->whereKey($invoice->id)->update([
            'status' => Invoice::STATUS_ISSUED, 'document_type' => Invoice::TYPE_E_ARCHIVE, 'provider_document_id' => 'ea-1',
        ]));

        $this->actingAs($user)->get("/orders/{$order->id}/invoice/pdf")->assertRedirect('https://parasut-pdf.example.com/ea-1.pdf');
        $this->assertStringContainsString('/e_archives/ea-1/pdf', $this->sentTo('/pdf')[0]->url());
    }

    // ─────────────────────────────────────────────────── bağlantı (OAuth)

    #[Test]
    public function the_callback_rejects_a_mismatched_state(): void
    {
        [$tenant, $user] = $this->trendyolOrder();
        $this->configureParasutApp();
        $this->fakeApis();

        $this->actingAs($user)
            ->withSession(['parasut.oauth.state' => 'dogru'])
            ->get('/settings/invoicing/parasut/callback?state=sahte&code=kod')
            ->assertRedirect('/settings/invoicing');

        $this->assertCount(0, $this->sentTo('/oauth/token'));
        $this->assertSame(0, $this->asTenant($tenant, fn (): int => InvoiceAccount::query()->count()));
    }

    #[Test]
    public function a_single_company_account_is_connected_with_encrypted_tokens(): void
    {
        [$tenant, $user] = $this->trendyolOrder();
        $this->configureParasutApp();
        $this->fakeMe([['id' => '555', 'name' => 'Örnek Ticaret']]);

        $this->actingAs($user)
            ->withSession(['parasut.oauth.state' => 'dogru'])
            ->get('/settings/invoicing/parasut/callback?state=dogru&code=kod')
            ->assertRedirect('/settings/invoicing');

        $account = $this->asTenant($tenant, fn (): InvoiceAccount => InvoiceAccount::query()->sole());
        $this->assertSame(InvoiceAccount::STATUS_CONNECTED, $account->status);
        $this->assertSame('555', $account->company_id);
        $this->assertSame('erisim-yeni', $account->credentials['access_token']);

        // Kolonda düz metin token YOK.
        $raw = (string) DB::table('invoice_accounts')->value('credentials');
        $this->assertStringNotContainsString('erisim-yeni', $raw);
    }

    #[Test]
    public function with_several_companies_the_seller_chooses_one_from_the_list(): void
    {
        [$tenant, $user] = $this->trendyolOrder();
        $this->configureParasutApp();
        $this->fakeMe([['id' => '555', 'name' => 'Örnek Ticaret'], ['id' => '666', 'name' => 'İkinci Firma']]);

        $this->actingAs($user)
            ->withSession(['parasut.oauth.state' => 'dogru'])
            ->get('/settings/invoicing/parasut/callback?state=dogru&code=kod');

        $this->assertSame(InvoiceAccount::STATUS_NEEDS_COMPANY, $this->asTenant($tenant, fn () => InvoiceAccount::query()->sole())->status);

        // Listede olmayan firma seçilemez — başkasının firmasına fatura kesilmesin.
        $this->actingAs($user)->put('/settings/invoicing', ['company_id' => '999'])->assertSessionHasErrors('company_id');

        $this->actingAs($user)->put('/settings/invoicing', ['company_id' => '666', 'invoice_series' => 'try'])->assertSessionHasNoErrors();

        $account = $this->asTenant($tenant, fn (): InvoiceAccount => InvoiceAccount::query()->sole());
        $this->assertSame(InvoiceAccount::STATUS_CONNECTED, $account->status);
        $this->assertSame('İkinci Firma', $account->company_name);
        $this->assertSame('TRY', $account->setting(InvoiceAccount::SETTING_SERIES));
    }

    #[Test]
    public function the_settings_screen_never_sends_tokens(): void
    {
        [$tenant, $user] = $this->trendyolOrder();
        $this->parasutAccount($tenant);

        $response = $this->actingAs($user)->get('/settings/invoicing');

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Invoicing')
            ->where('available', true)
            ->where('account.status', 'connected'));
        $this->assertStringNotContainsString('erisim-eski', (string) $response->getContent());
        $this->assertStringNotContainsString('yenile-eski', (string) $response->getContent());
    }

    // ─────────────────────────────────────────────────── yardımcılar

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

    /** @param list<array{id: string, name: string}> $companies */
    private function fakeMe(array $companies): void
    {
        Http::fake([
            'api.parasut.com/oauth/token' => Http::response(['access_token' => 'erisim-yeni', 'refresh_token' => 'yenile-yeni', 'expires_in' => 7200]),
            'api.parasut.com/v4/me*' => Http::response([
                'data' => ['id' => 'u-1', 'type' => 'users'],
                'included' => array_map(static fn (array $c): array => ['id' => $c['id'], 'type' => 'companies', 'attributes' => ['name' => $c['name']]], $companies),
            ]),
        ]);
    }
}
