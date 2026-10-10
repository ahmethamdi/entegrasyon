<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Invoicing\Exceptions\InvoiceProviderException;
use App\Domain\Invoicing\Jobs\IssueInvoice;
use App\Domain\Invoicing\Jobs\UploadInvoiceToChannel;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Invoicing\Support\InvoicePdfFetcher;
use App\Domain\Invoicing\Support\InvoiceProviders;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Support\ChannelErrorText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * e-fatura 2. dilim — kesilen faturanın PDF'i Trendyol paketine yüklenir.
 *
 * Zincir: `IssueInvoice` faturayı `issued` yapar → kanal dosya alıyorsa
 * `upload_status = pending` + `UploadInvoiceToChannel` → Paraşüt'ten TAZE
 * PDF bağlantısı → dosya belleğe → `seller-invoice-file`.
 *
 * Kargo dersi burada da geçerli: adapter metodu tek başına sınanmaz,
 * kesimden yüklemeye zincir birlikte sınanır ("yazılı ama hiçbir akıştan
 * çağrılmıyor").
 */
final class InvoiceUploadTest extends TestCase
{
    use InvoicingFixtures;
    use RefreshDatabase;

    private const NUMBER = 'GIB2026000000123';

    // ─────────────────────────────────────────────────── adapter

    /** Belgeden ölçülen uç ve alanlar: önek yok, `file`, numara + saniye. */
    #[Test]
    public function the_adapter_posts_the_pdf_as_multipart_to_seller_invoice_file(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->fakeApis();
        $invoice = $this->issued($tenant, $order->id);

        $result = $this->asTenant($tenant, fn () => $this->adapterFor($order)->uploadInvoice($order, $invoice, self::PDF_BYTES, 'fatura-'.self::NUMBER.'.pdf'));

        $this->assertTrue($result->successful);
        $this->assertFalse($result->data['already_uploaded']);

        $requests = $this->sentTo('seller-invoice-file', 'POST');
        $this->assertCount(1, $requests);
        $this->assertSame('https://apigw.trendyol.com/integration/sellers/777/seller-invoice-file', $requests[0]->url());
        $this->assertTrue($requests[0]->isMultipart());
        $this->assertStringStartsWith('777 - ', $requests[0]->header('User-Agent')[0]);

        $parts = $this->parts($requests[0]);
        $this->assertSame('9001', $parts['shipmentPackageId']['contents']);
        $this->assertSame(self::NUMBER, $parts['invoiceNumber']['contents']);
        $this->assertSame((string) $invoice->issued_at->getTimestamp(), $parts['invoiceDateTime']['contents']);
        $this->assertSame(10, strlen($parts['invoiceDateTime']['contents']), 'Tarih SANİYE (10 hane) gitmeli.');
        $this->assertSame(self::PDF_BYTES, $parts['file']['contents']);
        $this->assertSame('fatura-'.self::NUMBER.'.pdf', $parts['file']['filename']);
    }

    /** Biçime uymayan numara gönderilmez — yanlış biçim 400'dür ve kalıcıdır. */
    #[Test]
    public function a_number_outside_the_trendyol_format_is_not_sent(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->fakeApis();
        $invoice = $this->issued($tenant, $order->id, ['invoice_number' => 'FTR-12']);

        $this->asTenant($tenant, fn () => $this->adapterFor($order)->uploadInvoice($order, $invoice, self::PDF_BYTES, 'f.pdf'));

        $parts = $this->parts($this->sentTo('seller-invoice-file')[0]);
        $this->assertArrayHasKey('shipmentPackageId', $parts);
        $this->assertArrayNotHasKey('invoiceNumber', $parts);
        $this->assertArrayNotHasKey('invoiceDateTime', $parts);
    }

    // ─────────────────────────────────────────────────── uçtan uca

    /** Kesim iki turda biter; kesildiği anda PDF Trendyol'a gider. */
    #[Test]
    public function an_issued_invoice_is_uploaded_to_trendyol_end_to_end(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->fakeApis();
        $invoice = $this->asTenant($tenant, fn (): Invoice => Invoice::query()->create([
            'order_id' => $order->id,
            'provider' => InvoiceAccount::PROVIDER_PARASUT,
            'status' => Invoice::STATUS_PENDING,
        ]));

        // Kuyruk `sync`: kesim işi yükleme işini commit sonrası doğrudan koşturur.
        $this->issueJob($tenant, $invoice);
        $this->assertCount(0, $this->sentTo('seller-invoice-file'), 'Kesilmeden yükleme olmaz.');
        $this->issueJob($tenant, $invoice);

        $done = $this->reload($tenant, $invoice);
        $this->assertSame(Invoice::STATUS_ISSUED, $done->status);
        $this->assertSame(Invoice::UPLOAD_SENT, $done->upload_status);
        $this->assertNotNull($done->uploaded_at);
        $this->assertNull($done->upload_error);
        $this->assertSame(1, $done->upload_attempts);

        $this->assertCount(1, $this->sentTo('/e_archives/ea-1/pdf'), 'PDF bağlantısı entegratörden istenmeli.');
        $this->assertCount(1, $this->sentTo(self::PDF_URL), 'PDF indirilmeli.');
        $upload = $this->sentTo('seller-invoice-file', 'POST');
        $this->assertCount(1, $upload);
        $this->assertSame(self::PDF_BYTES, $this->parts($upload[0])['file']['contents']);
    }

    /** Kesim ANINDA bekleme işaretlenir ve iş kuyruğa düşer. */
    #[Test]
    public function issuing_marks_the_upload_pending_and_queues_it(): void
    {
        Queue::fake();
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->fakeApis();
        $invoice = $this->issuing($tenant, $order->id);

        $this->issueJob($tenant, $invoice);

        $this->assertSame(Invoice::UPLOAD_PENDING, $this->reload($tenant, $invoice)->upload_status);
        Queue::assertPushedOn('orders:high', UploadInvoiceToChannel::class, fn (UploadInvoiceToChannel $job): bool => $job->invoiceId === $invoice->id);
    }

    /** Dosya almayan kanal: durum NULL kalır, iş açılmaz, rozet çıkmaz. */
    #[Test]
    public function a_channel_without_invoice_upload_leaves_the_status_null(): void
    {
        Queue::fake();
        [$tenant, , $order] = $this->trendyolOrder('woocommerce');
        $this->parasutAccount($tenant);
        $this->fakeApis();
        $invoice = $this->issuing($tenant, $order->id);

        $this->issueJob($tenant, $invoice);

        $done = $this->reload($tenant, $invoice);
        $this->assertSame(Invoice::STATUS_ISSUED, $done->status);
        $this->assertNull($done->upload_status);
        Queue::assertNotPushed(UploadInvoiceToChannel::class);
    }

    // ─────────────────────────────────────────────────── iş

    /** Belge imzalanıyor, bağlantı yok: kısa bekleme, deneme SAYILMAZ, istek gitmez. */
    #[Test]
    public function a_pdf_that_is_not_ready_yet_releases_without_counting(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->fakeApis(['pdfUrl' => null]);
        $invoice = $this->issued($tenant, $order->id);

        $this->uploadJob($tenant, $invoice)->assertReleased(UploadInvoiceToChannel::PDF_WAIT_SECONDS);

        $row = $this->reload($tenant, $invoice);
        $this->assertSame(Invoice::UPLOAD_PENDING, $row->upload_status);
        $this->assertSame(0, $row->upload_attempts);
        $this->assertCount(0, $this->sentTo('seller-invoice-file'));
    }

    /**
     * Kanal kalıcı reddetti → `failed`, fatura KESİLMİŞ kalır; panelden
     * yeniden yükleme yalnız yüklemeyi dener, kesimi değil.
     */
    #[Test]
    public function a_permanent_rejection_fails_and_the_panel_retries_only_the_upload(): void
    {
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->fakeApis(['upload' => [['errors' => [['message' => 'File type (.txt) is not supported']]], 400]]);
        $invoice = $this->issued($tenant, $order->id);

        $this->uploadJob($tenant, $invoice)->assertNotReleased();

        $failed = $this->reload($tenant, $invoice);
        $this->assertSame(Invoice::UPLOAD_FAILED, $failed->upload_status);
        $this->assertSame(Invoice::STATUS_ISSUED, $failed->status, 'Yükleme hatası faturayı "kesilemedi" yapmamalı.');
        $this->assertStringContainsString('reddetti', (string) $failed->upload_error);

        $this->actingAs($user)->get("/orders/{$order->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.invoice.status', 'issued')
            ->where('order.invoice.uploadStatus', 'failed')
            ->where('order.invoice.uploadError', $failed->upload_error));

        Queue::fake();
        $this->actingAs($user)->post("/orders/{$order->id}/invoice/upload/retry")->assertRedirect()->assertSessionHasNoErrors();

        $retried = $this->reload($tenant, $invoice);
        $this->assertSame(Invoice::UPLOAD_PENDING, $retried->upload_status);
        $this->assertSame(0, $retried->upload_attempts);
        $this->assertNull($retried->upload_error);
        Queue::assertPushedOn('orders:high', UploadInvoiceToChannel::class);
        Queue::assertNotPushed(IssueInvoice::class);

        $this->fakeApis();
        $this->uploadJob($tenant, $invoice);

        $this->assertSame(Invoice::UPLOAD_SENT, $this->reload($tenant, $invoice)->upload_status);
    }

    /** Yalnız BAŞARISIZ yükleme yeniden denenir; gönderilmiş olan 404. */
    #[Test]
    public function a_sent_upload_cannot_be_retried_from_the_panel(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->issued($tenant, $order->id, ['upload_status' => Invoice::UPLOAD_SENT]);

        $this->actingAs($user)->post("/orders/{$order->id}/invoice/upload/retry")->assertNotFound();
        Queue::assertNothingPushed();
    }

    /** 409 = paketin faturası zaten var (kaybolan yanıt) → başarı, hata değil. */
    #[Test]
    public function an_already_uploaded_package_counts_as_sent(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->fakeApis(['upload' => [['message' => 'The invoice for the package number (9001) has already been sent'], 409]]);
        $invoice = $this->issued($tenant, $order->id);

        $this->uploadJob($tenant, $invoice)->assertNotReleased();

        $this->assertSame(Invoice::UPLOAD_SENT, $this->reload($tenant, $invoice)->upload_status);
    }

    /** Geçici kanal hatası: satır BEKLİYOR kalır, son hata görünür. */
    #[Test]
    public function a_server_error_is_retried_later(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->fakeApis(['upload' => [['message' => 'bakımda'], 503]]);
        $invoice = $this->issued($tenant, $order->id);

        $this->uploadJob($tenant, $invoice)->assertReleased();

        $row = $this->reload($tenant, $invoice);
        $this->assertSame(Invoice::UPLOAD_PENDING, $row->upload_status);
        $this->assertSame(1, $row->upload_attempts);
        $this->assertNotNull($row->upload_error);
    }

    // ─────────────────────────────────────────────────── PDF indirici

    #[Test]
    public function the_fetcher_refuses_plain_http_links(): void
    {
        $this->fakeApis();

        $e = $this->fetchFails('http://parasut-pdf.example.com/ea-1.pdf', 1024);

        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertCount(0, $this->sent, 'https olmayan adrese istek gitmemeli.');
    }

    #[Test]
    public function the_fetcher_stops_at_the_channel_size_limit(): void
    {
        $this->fakeApis();

        $e = $this->fetchFails(self::PDF_URL, 10);

        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertStringContainsString('sınırını', $e->getMessage());
    }

    /** Depolama servisinin HTML hata sayfası "fatura" diye pakete eklenmemeli. */
    #[Test]
    public function the_fetcher_refuses_a_body_that_is_not_a_pdf(): void
    {
        $this->fakeApis(['pdf' => ['<html>AccessDenied</html>', 200, ['Content-Type' => 'application/pdf']]]);

        $e = $this->fetchFails(self::PDF_URL, 1024 * 1024);

        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertStringContainsString('PDF değil', $e->getMessage());
    }

    #[Test]
    public function the_fetcher_returns_the_pdf_in_memory(): void
    {
        $this->fakeApis();

        $this->assertSame(self::PDF_BYTES, app(InvoicePdfFetcher::class)->fetch(self::PDF_URL, 1024 * 1024));
    }

    // ─────────────────────────────────────────────────── yardımcılar

    private function adapterFor(Order $order): TrendyolAdapter
    {
        $adapter = app(AdapterRegistry::class)->for($order->connection);
        $this->assertInstanceOf(TrendyolAdapter::class, $adapter);

        return $adapter;
    }

    /** @return array<string, array{name: string, contents: string, filename?: string}> */
    private function parts(Request $request): array
    {
        $parts = [];

        foreach ($request->data() as $part) {
            $parts[$part['name']] = $part;
        }

        return $parts;
    }

    private function fetchFails(string $url, int $max): InvoiceProviderException
    {
        try {
            app(InvoicePdfFetcher::class)->fetch($url, $max);
        } catch (InvoiceProviderException $e) {
            return $e;
        }

        $this->fail('İndirici istisna fırlatmalıydı.');
    }

    /** Kesilmiş, yüklemesi bekleyen fatura. */
    private function issued(Tenant $tenant, string $orderId, array $overrides = []): Invoice
    {
        return $this->asTenant($tenant, fn (): Invoice => Invoice::query()->create([
            'order_id' => $orderId,
            'provider' => InvoiceAccount::PROVIDER_PARASUT,
            'status' => Invoice::STATUS_ISSUED,
            'document_type' => Invoice::TYPE_E_ARCHIVE,
            'provider_invoice_id' => 'si-1',
            'provider_document_id' => 'ea-1',
            'invoice_number' => self::NUMBER,
            'issued_at' => now()->subMinute()->startOfSecond(),
            'upload_status' => Invoice::UPLOAD_PENDING,
            ...$overrides,
        ]));
    }

    /** e-belge isteği gitmiş, sonucu bekleyen fatura — sonraki tur keser. */
    private function issuing(Tenant $tenant, string $orderId): Invoice
    {
        return $this->asTenant($tenant, fn (): Invoice => Invoice::query()->create([
            'order_id' => $orderId,
            'provider' => InvoiceAccount::PROVIDER_PARASUT,
            'status' => Invoice::STATUS_ISSUING,
            'document_type' => Invoice::TYPE_E_ARCHIVE,
            'provider_invoice_id' => 'si-1',
            'provider_job_id' => 'job-1',
        ]));
    }

    private function issueJob(Tenant $tenant, Invoice $invoice): IssueInvoice
    {
        $job = (new IssueInvoice($invoice->id, $tenant->id))->withFakeQueueInteractions();

        $job->handle(app(AdapterRegistry::class), app(InvoiceProviders::class), app(ChannelErrorText::class));

        return $job;
    }

    private function uploadJob(Tenant $tenant, Invoice $invoice): UploadInvoiceToChannel
    {
        $job = (new UploadInvoiceToChannel($invoice->id, $tenant->id))->withFakeQueueInteractions();

        $job->handle(app(AdapterRegistry::class), app(InvoiceProviders::class), app(InvoicePdfFetcher::class), app(ChannelErrorText::class));

        return $job;
    }

    private function reload(Tenant $tenant, Invoice $invoice): Invoice
    {
        return $this->asTenant($tenant, fn (): Invoice => Invoice::query()->findOrFail($invoice->id));
    }
}
