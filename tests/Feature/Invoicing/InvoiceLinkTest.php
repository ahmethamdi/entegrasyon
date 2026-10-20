<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Invoicing\Jobs\UploadInvoiceToChannel;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Invoicing\Support\InvoicePdfFetcher;
use App\Domain\Invoicing\Support\InvoiceProviders;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Support\ChannelErrorText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S6 · faturanın kalıcı vekil bağlantısı (`/f/{token}.pdf`) ve onun
 * Hepsiburada / n11'e iletilmesi.
 *
 * Kullanıcı kararı (20 Eki 2026): PDF SAKLANMAZ — istek gelince Paraşüt'ten
 * taze adres alınır ve yönlendirilir; iptal edilen fatura 410; Paraşüt
 * vermiyorsa açıklayıcı 404; noindex, no-referrer, IP başına hız sınırı.
 */
final class InvoiceLinkTest extends TestCase
{
    use InvoicingFixtures;
    use RefreshDatabase;

    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    // ─────────────────────────────────────────────────── vekil adres

    #[Test]
    public function an_issued_invoice_redirects_to_a_fresh_provider_link(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->issued($tenant, $order);
        $this->fakeApis();

        $response = $this->get('/f/'.self::TOKEN.'.pdf');

        $response->assertStatus(302)->assertRedirect(self::PDF_URL);
        $this->assertPrivate($response);
        $this->assertCount(1, $this->sentTo('e_archives/ea-1/pdf'), 'Bağlantı her istekte entegratörden taze alınmalı.');
    }

    #[Test]
    public function unknown_malformed_or_unissued_tokens_get_the_explanatory_404(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $this->issued($tenant, $order, ['status' => Invoice::STATUS_FAILED]);
        $this->fakeApis();

        foreach ([str_repeat('f', 64), 'kisa', self::TOKEN] as $token) {
            $response = $this->get("/f/{$token}.pdf");
            $response->assertStatus(404)->assertSee('Fatura şu an görüntülenemiyor');
            $this->assertPrivate($response);
        }

        $this->assertCount(0, $this->sentTo('api.parasut.com'));
    }

    #[Test]
    public function a_cancelled_invoice_is_gone_but_a_returned_one_still_opens(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->parasutAccount($tenant);
        $sale = $this->issued($tenant, $order);
        $this->fakeApis();

        $this->asTenant($tenant, fn () => Invoice::query()->create(['order_id' => $order->id, 'provider' => 'parasut', 'status' => 'issued', 'kind' => 'return', 'ref' => 'r-1', 'parent_invoice_id' => $sale->id]));
        $this->get('/f/'.self::TOKEN.'.pdf')->assertStatus(302);

        $this->asTenant($tenant, fn () => Invoice::query()->create(['order_id' => $order->id, 'provider' => 'parasut', 'status' => 'issued', 'kind' => 'cancel', 'parent_invoice_id' => $sale->id]));
        $response = $this->get('/f/'.self::TOKEN.'.pdf');

        $response->assertStatus(410)->assertSee('Bu fatura iptal edildi');
        $this->assertPrivate($response);
    }

    #[Test]
    public function a_broken_provider_connection_or_missing_pdf_is_a_404_not_an_error(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->parasutAccount($tenant);
        $this->issued($tenant, $order);

        $this->fakeApis(['pdfUrl' => null]);
        $this->get('/f/'.self::TOKEN.'.pdf')->assertStatus(404);

        $this->fakeApis();
        $this->asTenant($tenant, fn () => $account->forceFill(['status' => InvoiceAccount::STATUS_REVOKED])->save());
        $this->get('/f/'.self::TOKEN.'.pdf')->assertStatus(404);
        $this->assertCount(0, $this->sentTo('/pdf'));
    }

    #[Test]
    public function the_link_is_rate_limited_per_ip(): void
    {
        $this->fakeApis();

        for ($i = 0; $i < 30; $i++) {
            $this->get('/f/'.str_repeat('0', 64).'.pdf')->assertStatus(404);
        }

        $this->get('/f/'.str_repeat('0', 64).'.pdf')->assertStatus(429);
    }

    // ─────────────────────────────────────────────────── kanala iletme

    #[Test]
    public function hepsiburada_gets_the_permanent_link_on_every_package(): void
    {
        [$tenant, , $order] = $this->trendyolOrder('hepsiburada');
        $invoice = $this->issued($tenant, $order, ['public_token' => null]);
        $this->fakeChannel(fn (HttpRequest $r) => $r->method() === 'GET'
            ? Http::response(['items' => [
                ['orderNumber' => '10500001', 'status' => 'Packaged', 'packageNumber' => 'P-1'],
                ['orderNumber' => '10500001', 'status' => 'Packaged', 'packageNumber' => 'P-2'],
                ['orderNumber' => '10500001', 'status' => 'Packaged', 'packageNumber' => 'P-1'],
            ]])
            : Http::response(null, 204));

        $this->runUpload($tenant, $invoice);

        $row = $this->reload($tenant, $invoice);
        $this->assertSame(Invoice::UPLOAD_SENT, $row->upload_status);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $row->public_token);

        $puts = array_values(array_filter($this->sent, static fn (HttpRequest $r): bool => $r->method() === 'PUT'));
        $this->assertCount(2, $puts, 'Her paket bir kez.');
        $this->assertStringEndsWith('/packages/merchantid/hb-merchant-1/packagenumber/P-1/invoice', $puts[0]->url());
        $this->assertSame(config('app.url').'/f/'.$row->public_token.'.pdf', $puts[0]->data()['invoiceLink']);
        $this->assertSame('GIB', $puts[0]->data()['serialNumber']);
        $this->assertSame('2026000000123', $puts[0]->data()['rowNumber']);
    }

    #[Test]
    public function an_unpackaged_hepsiburada_order_fails_with_a_reason_and_sends_nothing(): void
    {
        [$tenant, , $order] = $this->trendyolOrder('hepsiburada');
        $invoice = $this->issued($tenant, $order, ['public_token' => null]);
        $this->fakeChannel(fn () => Http::response(['items' => [['orderNumber' => '10500001', 'status' => 'Open']]]));

        $this->runUpload($tenant, $invoice);

        $row = $this->reload($tenant, $invoice);
        $this->assertSame(Invoice::UPLOAD_FAILED, $row->upload_status);
        $this->assertStringContainsString('paketlenmedi', (string) $row->upload_error);
        $this->assertCount(0, array_filter($this->sent, static fn (HttpRequest $r): bool => $r->method() === 'PUT'));
    }

    #[Test]
    public function n11_gets_the_link_by_soap_and_the_same_token_is_reused(): void
    {
        [$tenant, , $order] = $this->trendyolOrder('n11');
        $invoice = $this->issued($tenant, $order);
        $this->fakeChannel(fn () => Http::response($this->n11Response('success'), 200, ['Content-Type' => 'text/xml']));

        $this->runUpload($tenant, $invoice);

        $this->assertSame(Invoice::UPLOAD_SENT, $this->reload($tenant, $invoice)->upload_status);
        $this->assertSame('https://api.n11.com/ws/sellerInvoiceService/', $this->sent[0]->url());

        $body = $this->sent[0]->body();
        $this->assertStringContainsString('<url>'.config('app.url').'/f/'.self::TOKEN.'.pdf</url>', $body);
        $this->assertStringContainsString('<orderNumber>10500001</orderNumber>', $body);
        $this->assertStringContainsString('<invoiceNumber>GIB2026000000123</invoiceNumber>', $body);
        $this->assertStringContainsString('<appKey>ANAHTAR-1</appKey>', $body);
    }

    #[Test]
    public function an_n11_failure_status_is_not_counted_as_sent(): void
    {
        [$tenant, , $order] = $this->trendyolOrder('n11');
        $invoice = $this->issued($tenant, $order);
        $this->fakeChannel(fn () => Http::response($this->n11Response('failure'), 200, ['Content-Type' => 'text/xml']));

        $this->runUpload($tenant, $invoice);

        $this->assertSame(Invoice::UPLOAD_FAILED, $this->reload($tenant, $invoice)->upload_status);
    }

    #[Test]
    public function only_link_or_file_channels_get_an_upload_status(): void
    {
        foreach (['hepsiburada' => true, 'n11' => true, 'trendyol' => true, 'shopify' => false] as $channel => $accepts) {
            [$tenant, , $order] = $this->trendyolOrder($channel);

            $this->assertSame($accepts, $this->asTenant($tenant, fn (): bool => UploadInvoiceToChannel::channelAccepts(
                Order::query()->with('connection.channelType')->findOrFail($order->id),
            )), $channel);
        }
    }

    // ─────────────────────────────────────────────────── yardımcılar

    private function issued(Tenant $tenant, Order $order, array $overrides = []): Invoice
    {
        return $this->asTenant($tenant, fn (): Invoice => Invoice::query()->create([
            'order_id' => $order->id,
            'provider' => InvoiceAccount::PROVIDER_PARASUT,
            'status' => Invoice::STATUS_ISSUED,
            'document_type' => Invoice::TYPE_E_ARCHIVE,
            'provider_invoice_id' => 'si-1',
            'provider_document_id' => 'ea-1',
            'invoice_number' => 'GIB2026000000123',
            'issued_at' => now()->subMinute()->startOfSecond(),
            'upload_status' => Invoice::UPLOAD_PENDING,
            'public_token' => self::TOKEN,
            ...$overrides,
        ]));
    }

    private function assertPrivate(TestResponse $response): void
    {
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    private function fakeChannel(callable $respond): void
    {
        $this->sent = [];

        Http::fake(function (HttpRequest $request) use ($respond) {
            $this->sent[] = $request;

            return $respond($request);
        });
    }

    private function n11Response(string $status): string
    {
        return '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"><SOAP-ENV:Body>'
            .'<ns3:SaveLinkSellerInvoiceResponse xmlns:ns3="http://www.n11.com/ws/schemas"><result><status>'.$status.'</status>'
            .($status === 'success' ? '' : '<errorMessage>Geçersiz url</errorMessage>')
            .'</result></ns3:SaveLinkSellerInvoiceResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>';
    }

    private function runUpload(Tenant $tenant, Invoice $invoice): void
    {
        $job = (new UploadInvoiceToChannel($invoice->id, $tenant->id))->withFakeQueueInteractions();
        $job->handle(app(AdapterRegistry::class), app(InvoiceProviders::class), app(InvoicePdfFetcher::class), app(ChannelErrorText::class));
    }

    private function reload(Tenant $tenant, Invoice $invoice): Invoice
    {
        return $this->asTenant($tenant, fn (): Invoice => Invoice::query()->findOrFail($invoice->id));
    }
}
