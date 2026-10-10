<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Exceptions\InvoiceProviderException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Invoicing\Providers\Parasut\ParasutClient;
use App\Domain\Invoicing\Providers\Parasut\ParasutInvoiceProvider;
use App\Domain\Invoicing\Support\InvoiceBuyer;
use App\Domain\Invoicing\Support\InvoiceDraft;
use App\Domain\Invoicing\Support\InvoiceLine;
use App\Domain\Sync\Enums\ErrorClass;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Paraşüt akışı — cari → satış faturası → e-arşiv/e-fatura → sonuç.
 *
 * Asıl korunan şey İDEMPOTENSTİR: her adımın kimliği adım biter bitmez
 * yazılır, yarıda kalan iş kaldığı yerden devam eder ve ikinci RESMÎ belge
 * açılmaz.
 */
final class ParasutInvoiceProviderTest extends TestCase
{
    use InvoicingFixtures;
    use RefreshDatabase;

    /** Bireysel alıcı, mükellef değil → e-arşiv; internet satışı bilgisi gider. */
    #[Test]
    public function a_consumer_gets_an_e_archive_with_internet_sale_details(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->parasutAccount($tenant);
        $this->fakeApis();

        $invoice = $this->asTenant($tenant, function () use ($order, $account): Invoice {
            $invoice = $this->newInvoice($order->id);
            $this->provider($account)->submit($invoice, $this->draft());

            return $invoice->fresh();
        });

        $this->assertSame(Invoice::STATUS_ISSUING, $invoice->status);
        $this->assertSame(Invoice::TYPE_E_ARCHIVE, $invoice->document_type);
        $this->assertSame('c-1', $invoice->provider_contact_id);
        $this->assertSame('si-1', $invoice->provider_invoice_id);
        $this->assertSame('job-1', $invoice->provider_job_id);

        $contact = $this->sentTo('/contacts', 'POST')[0]->data()['data']['attributes'];
        $this->assertSame('person', $contact['contact_type']);
        $this->assertSame('12345678901', $contact['tax_number']);

        $sales = $this->sentTo('/sales_invoices', 'POST')[0]->data()['data'];
        $this->assertSame('TRL', $sales['attributes']['currency']);
        $this->assertSame('10500001', $sales['attributes']['order_no']);
        $detail = $sales['relationships']['details']['data'][0]['attributes'];
        // 120 TL KDV dahil, %20 → 100 TL KDV hariç.
        $this->assertSame('100.0000', $detail['unit_price']);
        $this->assertSame(20, $detail['vat_rate']);
        $this->assertSame(2, $detail['quantity']);

        $archive = $this->sentTo('/e_archives', 'POST')[0]->data()['data'];
        $this->assertSame('Trendyol', $archive['attributes']['internet_sale']['payment_platform']);
        $this->assertSame('ODEMEARACISI', $archive['attributes']['internet_sale']['payment_type']);
        $this->assertSame('si-1', $archive['relationships']['sales_invoice']['data']['id']);

        $this->assertCount(0, $this->sentTo('/e_invoices'));
    }

    /** e-fatura mükellefi → e-fatura alıcının posta kutusuna; var olan cari kullanılır. */
    #[Test]
    public function a_registered_taxpayer_gets_an_e_invoice_and_its_contact_is_reused(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->parasutAccount($tenant);
        $this->fakeApis(['mailbox' => 'urn:mail:defaultpk@firma.com', 'contact' => 'c-var']);

        $invoice = $this->asTenant($tenant, function () use ($order, $account): Invoice {
            $invoice = $this->newInvoice($order->id);
            $this->provider($account)->submit($invoice, $this->draft(company: true));

            return $invoice->fresh();
        });

        $this->assertSame(Invoice::TYPE_E_INVOICE, $invoice->document_type);
        $this->assertSame('c-var', $invoice->provider_contact_id);
        $this->assertCount(0, $this->sentTo('/contacts', 'POST'), 'Aynı VKN için ikinci cari açılmamalı.');

        $eInvoice = $this->sentTo('/e_invoices', 'POST')[0]->data()['data'];
        $this->assertSame('urn:mail:defaultpk@firma.com', $eInvoice['attributes']['to']);
        $this->assertSame('basic', $eInvoice['attributes']['scenario']);
        $this->assertCount(0, $this->sentTo('/e_archives'));
    }

    /** TCKN'siz alıcı: mükellef sorgusu ve cari araması boşa yapılmaz. */
    #[Test]
    public function an_anonymous_buyer_skips_the_taxpayer_lookup(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->parasutAccount($tenant);
        $this->fakeApis();

        $this->asTenant($tenant, function () use ($order, $account): void {
            $this->provider($account)->submit($this->newInvoice($order->id), $this->draft(taxNumber: InvoiceBuyer::ANONYMOUS_TCKN));
        });

        $this->assertCount(0, $this->sentTo('/e_invoice_inboxes'));
        $this->assertCount(0, $this->sentTo('/contacts', 'GET'));
        $this->assertCount(1, $this->sentTo('/e_archives', 'POST'));
    }

    /** Yarıda kalan iş kaldığı yerden devam eder: cari ve fatura İKİNCİ KEZ açılmaz. */
    #[Test]
    public function submit_resumes_from_the_last_finished_step(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->parasutAccount($tenant);
        $this->fakeApis();

        $this->asTenant($tenant, function () use ($order, $account): void {
            $invoice = $this->newInvoice($order->id, ['provider_contact_id' => 'c-9', 'provider_invoice_id' => 'si-9']);
            $this->provider($account)->submit($invoice, $this->draft());
        });

        $this->assertCount(0, $this->sentTo('/contacts'));
        $this->assertCount(0, $this->sentTo('/sales_invoices'));
        $this->assertSame('si-9', $this->sentTo('/e_archives', 'POST')[0]->data()['data']['relationships']['sales_invoice']['data']['id']);
    }

    #[Test]
    public function poll_reads_the_document_and_its_number_when_done(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->parasutAccount($tenant);
        $this->fakeApis(['job' => 'done']);

        $outcome = $this->asTenant($tenant, fn () => $this->provider($account)->poll(
            $this->newInvoice($order->id, ['provider_invoice_id' => 'si-1', 'provider_job_id' => 'job-1', 'status' => Invoice::STATUS_ISSUING]),
        ));

        $this->assertTrue($outcome->isIssued());
        $this->assertSame('ea-1', $outcome->documentId);
        $this->assertSame('GIB2026000000123', $outcome->invoiceNumber);
    }

    #[Test]
    public function poll_reports_running_and_failed_jobs(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->parasutAccount($tenant);

        $invoice = $this->asTenant($tenant, fn () => $this->newInvoice($order->id, ['provider_invoice_id' => 'si-1', 'provider_job_id' => 'job-1', 'status' => Invoice::STATUS_ISSUING]));

        $this->fakeApis(['job' => 'running']);
        $this->assertTrue($this->asTenant($tenant, fn () => $this->provider($account)->poll($invoice))->isPending());

        $this->fakeApis(['job' => 'error']);
        $failed = $this->asTenant($tenant, fn () => $this->provider($account)->poll($invoice));
        $this->assertFalse($failed->isPending());
        $this->assertFalse($failed->isIssued());
        $this->assertStringContainsString('Alıcı VKN geçersiz', (string) $failed->error);
    }

    /** Süresi dolan token yenilenir; DÖNEN yeni yenileme anahtarı saklanır (rotasyon). */
    #[Test]
    public function an_expired_token_is_refreshed_and_the_rotated_refresh_token_is_kept(): void
    {
        [$tenant] = $this->trendyolOrder();
        $account = $this->parasutAccount($tenant, ['token_expires_at' => now()->subMinute()]);
        $this->fakeApis();

        $this->asTenant($tenant, fn () => (new ParasutClient($account))->get('trackable_jobs/job-1'));

        $token = $this->sentTo('/oauth/token')[0]->data();
        $this->assertSame('refresh_token', $token['grant_type']);
        $this->assertSame('yenile-eski', $token['refresh_token']);

        $this->assertSame('Bearer erisim-yeni', $this->sentTo('/trackable_jobs')[0]->header('Authorization')[0]);

        $fresh = $this->asTenant($tenant, fn () => InvoiceAccount::query()->sole());
        $this->assertSame('yenile-yeni', $fresh->credentials['refresh_token']);
        $this->assertTrue($fresh->token_expires_at->isFuture());
    }

    /** Yenileme reddedildi → hesap "koptu", satıcıya yeniden bağla denir. */
    #[Test]
    public function a_rejected_refresh_marks_the_account_revoked(): void
    {
        [$tenant] = $this->trendyolOrder();
        $account = $this->parasutAccount($tenant, ['token_expires_at' => now()->subMinute()]);
        $this->fakeApis(['token' => [['error' => 'invalid_grant', 'error_description' => 'revoked'], 400]]);

        try {
            $this->asTenant($tenant, fn () => (new ParasutClient($account))->get('trackable_jobs/job-1'));
            $this->fail('Kimlik hatası fırlatılmalıydı.');
        } catch (InvoiceProviderException $e) {
            $this->assertSame(ErrorClass::AUTHENTICATION, $e->class);
        }

        $this->assertSame(InvoiceAccount::STATUS_REVOKED, $this->asTenant($tenant, fn () => InvoiceAccount::query()->sole())->status);
    }

    /** Paraşüt'ün doğrulama metni satıcıya taşınır; kalıcıdır. */
    #[Test]
    public function a_validation_error_carries_the_providers_detail(): void
    {
        [$tenant] = $this->trendyolOrder();
        $account = $this->parasutAccount($tenant);

        Http::fake(['*' => Http::response(['errors' => [['title' => 'Geçersiz', 'detail' => 'Vergi numarası geçersiz']]], 422)]);

        try {
            $this->asTenant($tenant, fn () => (new ParasutClient($account))->post('contacts', []));
            $this->fail('Doğrulama hatası fırlatılmalıydı.');
        } catch (InvoiceProviderException $e) {
            $this->assertSame(ErrorClass::VALIDATION, $e->class);
            $this->assertStringContainsString('Vergi numarası geçersiz', $e->getMessage());
        }
    }

    #[Test]
    public function net_unit_price_keeps_the_gross_total(): void
    {
        $line = new InvoiceLine('Ürün', 3, '99.99', 20);

        $this->assertSame('83.3250', $line->netUnitPrice());
        $this->assertSame('299.97', number_format(3 * (float) $line->netUnitPrice() * 1.2, 2, '.', ''));
    }

    // ─────────────────────────────────────────────────── yardımcılar

    private function provider(InvoiceAccount $account): ParasutInvoiceProvider
    {
        return new ParasutInvoiceProvider(new ParasutClient($account));
    }

    private function newInvoice(string $orderId, array $attributes = []): Invoice
    {
        return Invoice::query()->create([
            'order_id' => $orderId,
            'provider' => InvoiceAccount::PROVIDER_PARASUT,
            'status' => Invoice::STATUS_PENDING,
            ...$attributes,
        ]);
    }

    private function draft(bool $company = false, string $taxNumber = '12345678901'): InvoiceDraft
    {
        return new InvoiceDraft(
            buyer: new InvoiceBuyer(
                name: $company ? 'Örnek Ltd. Şti.' : 'Ayşe Yılmaz',
                isCompany: $company,
                taxNumber: $company ? '1234567890' : $taxNumber,
                taxOffice: $company ? 'Kadıköy' : null,
                address: 'Atatürk Cad. No:1',
                city: 'İstanbul',
                district: 'Kadıköy',
            ),
            lines: [new InvoiceLine('Kahve 250 g', 2, '120.00', 20, 'KHV-1')],
            orderNumber: '10500001',
            orderedAt: new DateTimeImmutable('2026-10-08 10:00:00'),
            platformName: 'Trendyol',
            platformUrl: 'https://www.trendyol.com',
        );
    }
}
