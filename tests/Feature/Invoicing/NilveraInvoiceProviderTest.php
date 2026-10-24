<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Domain\Catalog\Models\ExchangeRate;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Invoicing\Actions\RequestInvoiceCorrection;
use App\Domain\Invoicing\Exceptions\InvoiceCorrectionRefused;
use App\Domain\Invoicing\Exceptions\InvoiceProviderException;
use App\Domain\Invoicing\Jobs\IssueInvoice;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Invoicing\Providers\Nilvera\NilveraClient;
use App\Domain\Invoicing\Providers\Nilvera\NilveraInvoiceProvider;
use App\Domain\Invoicing\Support\InvoiceBuyer;
use App\Domain\Invoicing\Support\InvoiceDraft;
use App\Domain\Invoicing\Support\InvoiceLine;
use App\Domain\Invoicing\Support\InvoiceProviders;
use App\Domain\Invoicing\Support\SubmitOptions;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Support\ChannelErrorText;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Nilvera akışı — mükellef sorgusu → e-fatura / e-arşiv → durum → PDF.
 *
 * Sahte yanıtlar developer.nilvera.com'daki OpenAPI gövdeleridir
 * (SendResponse, StatusResponse, GlobalCompanyInfoResponse, hata gövdesi
 * `{Message, Errors: [{Code, Description, Detail}]}`), 24.10.2026.
 *
 * Asıl korunan şey İDEMPOTENSTİR: ETTN bizde üretilir ve istekten ÖNCE
 * satıra yazılır; yeniden deneme aynı ETTN'yi gönderir, Nilvera 409 der ve
 * ikinci RESMÎ belge açılmaz.
 */
final class NilveraInvoiceProviderTest extends TestCase
{
    use InvoicingFixtures;
    use RefreshDatabase;

    /** Belgedeki örnek anahtar biçimi (Persisted Access Token). */
    private const KEY = '9EE05B6564525810C86A32646DB46A26E20F4BCE32C0B13AB22AA78E70DC4F69';

    private const TOKEN = 'b1b2b3b4b5b6b7b8b9b0c1c2c3c4c5c6c7c8c9c0d1d2d3d4d5d6d7d8d9d0e1e2';

    /** Bireysel alıcı → e-arşiv; internet satışı bilgisi, toplamlar kuruşu kuruşuna. */
    #[Test]
    public function a_consumer_gets_an_e_archive_with_internet_sale_details_and_exact_totals(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->nilveraAccount($tenant);
        $this->fakeNilvera();

        $invoice = $this->asTenant($tenant, function () use ($order, $account): Invoice {
            $invoice = $this->newInvoice($order->id);
            $this->provider($account)->submit($invoice, $this->draft());

            return $invoice->fresh();
        });

        $this->assertSame(Invoice::STATUS_ISSUING, $invoice->status);
        $this->assertSame(Invoice::TYPE_E_ARCHIVE, $invoice->document_type);
        $this->assertSame('EAR2026000000007', $invoice->invoice_number);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $invoice->provider_job_id);

        $body = $this->sentTo('earchive/Send/Model', 'POST')[0]->data()['ArchiveInvoice'];
        $this->assertSame($invoice->provider_job_id, $body['InvoiceInfo']['UUID'], 'Gönderilen ETTN satırdaki ETTN olmalı.');
        $this->assertSame('SATIS', $body['InvoiceInfo']['InvoiceType']);
        $this->assertSame('INTERNET', $body['InvoiceInfo']['SalesPlatform']);
        $this->assertSame('ELEKTRONIK', $body['InvoiceInfo']['SendType']);
        $this->assertSame('ODEMEARACISI', $body['InvoiceInfo']['InternetInfo']['PaymentMethod']);
        $this->assertSame('Trendyol', $body['InvoiceInfo']['InternetInfo']['PaymentAgentName']);
        $this->assertSame('10500001', $body['InvoiceInfo']['OrderReference']['Value']);
        $this->assertArrayNotHasKey('CompanyInfo', $body, 'Gönderici bilgisi Nilvera\'daki aktif firmadan gelmeli.');

        // 2 × 120 TL KDV dahil, %20 → net 200, KDV 40, ödenecek 240.
        $this->assertEquals(200, $body['InvoiceInfo']['LineExtensionAmount']);
        $this->assertEquals(40, $body['InvoiceInfo']['KdvTotal']);
        $this->assertEquals(240, $body['InvoiceInfo']['PayableAmount']);
        $this->assertEquals(40, $body['InvoiceInfo']['GeneralKDV20Total']);

        $line = $body['InvoiceLines'][0];
        $this->assertSame('C62', $line['UnitType']);
        $this->assertEquals(100, $line['Price']);
        $this->assertEquals(2, $line['Quantity']);
        $this->assertEquals(40, $line['KDVTotal']);

        $this->assertSame('12345678901', $body['CustomerInfo']['TaxNumber']);
        $this->assertSame('Kadıköy', $body['CustomerInfo']['District']);
        $this->assertSame('Türkiye', $body['CustomerInfo']['Country']);

        $this->assertCount(0, $this->sentTo('einvoice/'));
        $this->assertSame('Bearer '.self::KEY, $this->sentTo('earchive/Send/Model')[0]->header('Authorization')[0]);
    }

    /**
     * Ödenecek tutar müşterinin ödediğine EŞİT kalır — net ve KDV ayrı ayrı
     * yuvarlansaydı 99,99 TL'lik üç kalemde fatura 1 kuruş sapardı.
     */
    #[Test]
    public function the_payable_amount_matches_what_the_buyer_paid_to_the_kurus(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->nilveraAccount($tenant);
        $this->fakeNilvera();

        $this->asTenant($tenant, function () use ($order, $account): void {
            $this->provider($account)->submit($this->newInvoice($order->id), $this->draft(lines: [
                new InvoiceLine('Çay', 3, '99.99', 20),
                new InvoiceLine('Bardak', 1, '45.50', 10),
            ]));
        });

        $info = $this->sentTo('earchive/Send/Model')[0]->data()['ArchiveInvoice']['InvoiceInfo'];
        $this->assertEquals(345.47, $info['PayableAmount']);
        $this->assertEqualsWithDelta(345.47, $info['LineExtensionAmount'] + $info['KdvTotal'], 0.0001);
    }

    /** e-fatura mükellefi → e-fatura posta kutusu (PK) etiketine; e-arşiv gitmez. */
    #[Test]
    public function a_registered_taxpayer_gets_an_e_invoice_to_the_mailbox_alias(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->nilveraAccount($tenant);
        $this->fakeNilvera(['aliases' => [
            ['TaxNumber' => '1234567890', 'Title' => 'Örnek Ltd.', 'DocumentType' => 'Invoice', 'Name' => 'urn:mail:defaultgb@firma.com', 'Type' => 'GB'],
            ['TaxNumber' => '1234567890', 'Title' => 'Örnek Ltd.', 'DocumentType' => 'Invoice', 'Name' => 'urn:mail:defaultpk@firma.com', 'Type' => 'PK'],
        ]]);

        $invoice = $this->asTenant($tenant, function () use ($order, $account): Invoice {
            $invoice = $this->newInvoice($order->id);
            $this->provider($account)->submit($invoice, $this->draft(company: true));

            return $invoice->fresh();
        });

        $this->assertSame(Invoice::TYPE_E_INVOICE, $invoice->document_type);
        $this->assertSame('urn:mail:defaultpk@firma.com', $invoice->provider_contact_id);

        $sent = $this->sentTo('einvoice/Send/Model', 'POST')[0]->data();
        $this->assertSame('urn:mail:defaultpk@firma.com', $sent['CustomerAlias']);
        $this->assertSame('TEMELFATURA', $sent['EInvoice']['InvoiceInfo']['InvoiceProfile']);
        $this->assertArrayNotHasKey('InternetInfo', $sent['EInvoice']['InvoiceInfo']);
        $this->assertCount(0, $this->sentTo('earchive/'));
    }

    /** TCKN'siz alıcı: mükellef sorgusu boşa yapılmaz. */
    #[Test]
    public function an_anonymous_buyer_skips_the_taxpayer_lookup(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->nilveraAccount($tenant);
        $this->fakeNilvera();

        $this->asTenant($tenant, function () use ($order, $account): void {
            $this->provider($account)->submit($this->newInvoice($order->id), $this->draft(taxNumber: InvoiceBuyer::ANONYMOUS_TCKN));
        });

        $this->assertCount(0, $this->sentTo('GlobalCompany'));
        $this->assertCount(1, $this->sentTo('earchive/Send/Model', 'POST'));
    }

    /**
     * İş yanıt ile kayıt arasında öldü: yeniden deneme AYNI ETTN'yi gönderir,
     * Nilvera 409/1001 "ETTN kayıtlı" der ve bu BAŞARIDIR — ikinci belge yok,
     * tür yeniden sorulmaz.
     */
    #[Test]
    public function a_retry_resends_the_same_ettn_and_treats_already_registered_as_sent(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->nilveraAccount($tenant);
        $this->fakeNilvera(['send' => [self::nilveraError(1001, 'ETTN Numarası Sistemde Kayıtlıdır.'), 409]]);

        $invoice = $this->asTenant($tenant, function () use ($order, $account): Invoice {
            $invoice = $this->newInvoice($order->id, ['provider_job_id' => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa', 'document_type' => Invoice::TYPE_E_ARCHIVE]);
            $this->provider($account)->submit($invoice, $this->draft(company: true));

            return $invoice->fresh();
        });

        $this->assertSame(Invoice::STATUS_ISSUING, $invoice->status);
        $this->assertSame('aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa', $invoice->provider_job_id);
        $this->assertSame('aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa', $this->sentTo('earchive/Send/Model')[0]->data()['ArchiveInvoice']['InvoiceInfo']['UUID']);
        $this->assertCount(0, $this->sentTo('GlobalCompany'), 'Tür ETTN ile birlikte sabitlendi; yeniden sorulmaz.');
    }

    /** 409'un başka bir sebebi (ör. seri çakışması) başarı SAYILMAZ. */
    #[Test]
    public function another_conflict_is_not_mistaken_for_already_sent(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->nilveraAccount($tenant);
        $this->fakeNilvera(['send' => [self::nilveraError(1005, 'E-Fatura Serisi Daha Önce Kullanılmıştır.'), 409]]);

        $this->expectExceptionObject(new InvoiceProviderException(ErrorClass::CONFLICT, 'Nilvera belgeyi reddetti (çakışma, kod 1005).'));

        $this->asTenant($tenant, function () use ($order, $account): void {
            $this->provider($account)->submit($this->newInvoice($order->id), $this->draft());
        });
    }

    /** Kontör bitti (422/2000): Nilvera'nın metni satıcıya gider, kalıcıdır. */
    #[Test]
    public function an_out_of_credit_rejection_is_a_permanent_error_with_nilveras_text(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->nilveraAccount($tenant);
        $this->fakeNilvera(['send' => [self::nilveraError(2000, 'Yeterli Kontörünüz Bulunmamaktadır.', 'Yeterli Kontörünüz Bulunmamaktadır. Lütfen Kontör Alımı Yapınız.'), 422]]);

        try {
            $this->asTenant($tenant, function () use ($order, $account): void {
                $this->provider($account)->submit($this->newInvoice($order->id), $this->draft());
            });
            $this->fail('Kontör hatası istisna olmalı.');
        } catch (InvoiceProviderException $e) {
            $this->assertSame(ErrorClass::VALIDATION, $e->class);
            $this->assertStringContainsString('Lütfen Kontör Alımı Yapınız', $e->getMessage());
        }
    }

    /** Muhasebesi olmayan sağlayıcıda "yalnız muhasebe" kipi sessizce geçmez. */
    #[Test]
    public function books_only_mode_is_refused_instead_of_silently_doing_nothing(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->nilveraAccount($tenant, ['settings' => ['mode' => InvoiceAccount::MODE_BOOKS_ONLY, 'companies' => []]]);
        $this->fakeNilvera();

        $this->assertSame(InvoiceAccount::MODE_E_DOCUMENT, $account->mode(), 'Nilvera hesabında kip her zaman e-belge.');
        $this->assertNull($account->paymentAccountFor('trendyol'));

        $this->expectException(InvoiceProviderException::class);

        $this->asTenant($tenant, function () use ($order, $account): void {
            $this->provider($account)->submit($this->newInvoice($order->id), $this->draft(), new SubmitOptions(issueEDocument: false));
        });
    }

    /** Döviz faturası TCMB alış kuruyla; kur yoksa fatura KESİLMEZ. */
    #[Test]
    public function a_foreign_currency_invoice_needs_the_tcmb_rate(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->nilveraAccount($tenant);
        $this->fakeNilvera();

        try {
            $this->asTenant($tenant, function () use ($order, $account): void {
                $this->provider($account)->submit($this->newInvoice($order->id), $this->draft(currency: 'EUR'));
            });
            $this->fail('Kursuz döviz faturası kesilmemeli.');
        } catch (InvoiceProviderException $e) {
            $this->assertSame(ErrorClass::VALIDATION, $e->class);
        }

        $this->assertCount(0, $this->sentTo('Send/Model'));

        ExchangeRate::query()->create(['source' => ExchangeRate::SOURCE_TCMB, 'rate_date' => now()->subDay()->toDateString(), 'currency' => 'EUR', 'unit' => 1, 'forex_buying' => '38.1234', 'forex_selling' => '38.2000', 'fetched_at' => now()]);

        $this->asTenant($tenant, function () use ($order, $account): void {
            $this->provider($account)->submit($this->newInvoice($order->id, ['ref' => 'eur']), $this->draft(currency: 'EUR'));
        });

        $info = $this->sentTo('earchive/Send/Model')[0]->data()['ArchiveInvoice']['InvoiceInfo'];
        $this->assertSame('EUR', $info['CurrencyCode']);
        $this->assertEquals(38.1234, $info['ExchangeRate']);
    }

    #[Test]
    public function poll_maps_e_archive_and_e_invoice_statuses(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->nilveraAccount($tenant);

        $outcome = fn (array $opts, array $attributes) => $this->asTenant($tenant, function () use ($opts, $attributes, $order, $account) {
            $this->fakeNilvera($opts);

            return $this->provider($account)->poll($this->newInvoice($order->id, ['ref' => uniqid(), 'status' => Invoice::STATUS_ISSUING, 'provider_job_id' => 'u-1', ...$attributes]));
        });

        // e-arşiv: `waiting` (GİB raporu bekliyor) belge kesilmiş SAYILIR.
        $archive = $outcome(['archiveStatus' => 'waiting'], ['document_type' => Invoice::TYPE_E_ARCHIVE, 'invoice_number' => 'EAR2026000000007']);
        $this->assertTrue($archive->isIssued());
        $this->assertSame('u-1', $archive->documentId);
        $this->assertSame('EAR2026000000007', $archive->invoiceNumber);

        $this->assertFalse($outcome(['archiveStatus' => 'error'], ['document_type' => Invoice::TYPE_E_ARCHIVE])->isIssued());

        // e-fatura: alıcıya ulaşana kadar bekler.
        $this->assertTrue($outcome(['invoiceStatus' => 'waiting'], ['document_type' => Invoice::TYPE_E_INVOICE])->isPending());

        $failed = $outcome(['invoiceStatus' => 'error'], ['document_type' => Invoice::TYPE_E_INVOICE]);
        $this->assertFalse($failed->isIssued());
        $this->assertFalse($failed->isPending());
        $this->assertStringContainsString('Alıcı etiketi geçersiz', (string) $failed->error);

        // 409 yolunda numara yanıtta gelmemişti: detaydan okunur.
        $issued = $outcome(['invoiceStatus' => 'succeed'], ['document_type' => Invoice::TYPE_E_INVOICE]);
        $this->assertTrue($issued->isIssued());
        $this->assertSame('EFT2026000000042', $issued->invoiceNumber);
    }

    /**
     * Uçtan uca iş: kanaldan taslak → Nilvera → durum → kesildi → PDF
     * DOSYA olarak kanala yüklenir (süreli bağlantı yok).
     */
    #[Test]
    public function the_job_issues_and_uploads_the_pdf_file_straight_from_nilvera(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->nilveraAccount($tenant);
        $this->fakeNilvera();

        $invoice = $this->asTenant($tenant, fn (): Invoice => $this->newInvoice($order->id));

        $this->runIssue($tenant, $invoice);
        $this->assertSame(Invoice::STATUS_ISSUING, $this->reload($tenant, $invoice)->status);

        $this->runIssue($tenant, $invoice);
        $issued = $this->reload($tenant, $invoice);
        $this->assertSame(Invoice::STATUS_ISSUED, $issued->status);
        $this->assertSame($issued->provider_job_id, $issued->provider_document_id);

        // Yükleme işi kesimle aynı kayıtta kuyruğa girer (testte senkron koşar).
        $this->assertSame(Invoice::UPLOAD_SENT, $issued->upload_status);
        $this->assertCount(1, $this->sentTo("earchive/Invoices/{$issued->provider_document_id}/pdf"));
        $this->assertStringContainsString(self::PDF_BYTES, (string) $this->sentTo('seller-invoice-file')[0]->body());
    }

    /** Sipariş ekranı ve kalıcı bağlantı PDF'i akıtır; saklanmaz. */
    #[Test]
    public function the_order_screen_and_the_permanent_link_stream_the_pdf(): void
    {
        [$tenant, $user, $order] = $this->trendyolOrder();
        $this->nilveraAccount($tenant);
        $this->fakeNilvera();
        $this->issuedNilvera($tenant, $order);

        $screen = $this->actingAs($user)->get("/orders/{$order->id}/invoice/pdf");
        $screen->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(self::PDF_BYTES, $screen->getContent());
        $this->assertStringContainsString('no-store', (string) $screen->headers->get('Cache-Control'));

        auth()->logout();

        $link = $this->get('/f/'.self::TOKEN.'.pdf');
        $link->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $link->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertSame(self::PDF_BYTES, $link->getContent());
    }

    /** Nilvera'nın döndürdüğü şey PDF değilse (HTML hata sayfası) iletilmez. */
    #[Test]
    public function a_non_pdf_body_is_rejected(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->nilveraAccount($tenant);
        $this->fakeNilvera(['pdf' => '<html>hata</html>']);
        $invoice = $this->issuedNilvera($tenant, $order);

        $this->expectException(InvoiceProviderException::class);

        $this->asTenant($tenant, fn () => $this->provider($account)->pdfContent($invoice, 1024 * 1024));
    }

    #[Test]
    public function cancel_is_idempotent_and_refuses_e_invoices(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->nilveraAccount($tenant);
        $sale = $this->issuedNilvera($tenant, $order);
        $cancellation = new Invoice;

        // Zaten iptal edilmiş: PUT gönderilmez.
        $this->fakeNilvera(['cancelStatus' => true]);
        $this->asTenant($tenant, fn () => $this->provider($account)->cancel($cancellation, $sale));
        $this->assertCount(0, $this->sentTo('/Cancel'));

        // İş PUT'tan sonra ölmüş: Nilvera "daha önce iptal edildi" (2026) — tamam.
        $this->fakeNilvera(['cancel' => [self::nilveraError(2026, 'E-Arşiv Fatura Daha Önce İptal Edilmiştir.'), 422]]);
        $this->asTenant($tenant, fn () => $this->provider($account)->cancel($cancellation, $sale));
        $this->assertCount(1, $this->sentTo('/Cancel', 'PUT'));

        // Başka bir 422 (rapor bekleniyor) iptal SAYILMAZ.
        $this->fakeNilvera(['cancel' => [self::nilveraError(2028, 'Dosya Kuyrukta Veya Durumu Bilinmiyorsa E-Arşiv Fatura İptal Edilemez.'), 422]]);
        try {
            $this->asTenant($tenant, fn () => $this->provider($account)->cancel($cancellation, $sale));
            $this->fail('2028 iptal sayılmamalı.');
        } catch (InvoiceProviderException $e) {
            $this->assertSame(ErrorClass::VALIDATION, $e->class);
        }

        $this->fakeNilvera();
        $eInvoice = $this->asTenant($tenant, function () use ($sale): Invoice {
            $sale->forceFill(['document_type' => Invoice::TYPE_E_INVOICE])->save();

            return $sale;
        });
        $this->expectException(InvoiceProviderException::class);
        $this->asTenant($tenant, fn () => $this->provider($account)->cancel($cancellation, $eInvoice));
    }

    /** İade KAYDI muhasebe işidir; Nilvera hesabında talep aşamasında reddedilir. */
    #[Test]
    public function a_return_record_is_refused_for_nilvera(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->nilveraAccount($tenant);
        $this->issuedNilvera($tenant, $order);

        $request = $this->asTenant($tenant, fn (): ReturnRequest => new ReturnRequest(['order_id' => $order->id]));

        $this->expectException(InvoiceCorrectionRefused::class);
        $this->expectExceptionMessage('Nilvera yalnız e-belge keser');

        $this->asTenant($tenant, fn () => app(RequestInvoiceCorrection::class)->recordReturn($request, $account));
    }

    // ─────────────────────────────────────────────────── bağlantı

    /** Anahtar KAYDEDİLMEDEN önce denenir; firma Nilvera'dan okunur; anahtar ekrana dönmez. */
    #[Test]
    public function connecting_verifies_the_key_and_reads_the_company(): void
    {
        [$tenant, $user] = $this->trendyolOrder();
        $this->fakeNilvera();

        $this->actingAs($user)->post('/settings/invoicing/nilvera', ['api_key' => self::KEY, 'environment' => 'test'])
            ->assertRedirect('/settings/invoicing');

        $account = $this->asTenant($tenant, fn (): InvoiceAccount => InvoiceAccount::query()->sole());
        $this->assertSame(InvoiceAccount::PROVIDER_NILVERA, $account->provider);
        $this->assertSame('9876543210', $account->company_id);
        $this->assertSame('Örnek Ticaret A.Ş.', $account->company_name);
        $this->assertSame(self::KEY, $account->credentials['api_key']);
        $this->assertTrue($account->isUsable());
        $this->assertStringStartsWith('https://apitest.nilvera.com/general/Company', $this->sentTo('general/Company')[0]->url(), 'Test anahtarı test ortamına gitmeli.');

        $page = $this->actingAs($user)->get('/settings/invoicing');
        $page->assertInertia(fn (AssertableInertia $p) => $p
            ->where('account.provider', 'nilvera')
            ->where('account.providerName', 'Nilvera')
            ->where('account.keepsBooks', false)
            ->where('account.credits.0.remaining', 480));
        $this->assertStringNotContainsString(self::KEY, (string) $page->getContent());
    }

    #[Test]
    public function a_rejected_key_is_not_saved(): void
    {
        [$tenant, $user] = $this->trendyolOrder();
        $this->fakeNilvera(['company' => [['Message' => 'Unauthorized'], 401]]);

        $this->actingAs($user)->post('/settings/invoicing/nilvera', ['api_key' => self::KEY, 'environment' => 'live'])
            ->assertSessionHasErrors('api_key');

        $this->assertSame(0, $this->asTenant($tenant, fn (): int => InvoiceAccount::query()->count()));
    }

    // ─────────────────────────────────────────────────── yardımcılar

    private function nilveraAccount(Tenant $tenant, array $overrides = []): InvoiceAccount
    {
        return $this->asTenant($tenant, fn (): InvoiceAccount => InvoiceAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => InvoiceAccount::PROVIDER_NILVERA,
            'credentials' => ['api_key' => self::KEY],
            'company_id' => '9876543210',
            'company_name' => 'Örnek Ticaret A.Ş.',
            'settings' => ['companies' => [['id' => '9876543210', 'name' => 'Örnek Ticaret A.Ş.']]],
            'status' => InvoiceAccount::STATUS_CONNECTED,
            ...$overrides,
        ]));
    }

    private function provider(InvoiceAccount $account): NilveraInvoiceProvider
    {
        return new NilveraInvoiceProvider(new NilveraClient($account));
    }

    private function newInvoice(string $orderId, array $attributes = []): Invoice
    {
        return Invoice::query()->create([
            'order_id' => $orderId,
            'provider' => InvoiceAccount::PROVIDER_NILVERA,
            'status' => Invoice::STATUS_PENDING,
            ...$attributes,
        ]);
    }

    private function issuedNilvera(Tenant $tenant, $order): Invoice
    {
        return $this->asTenant($tenant, fn (): Invoice => Invoice::query()->create([
            'order_id' => $order->id,
            'provider' => InvoiceAccount::PROVIDER_NILVERA,
            'status' => Invoice::STATUS_ISSUED,
            'document_type' => Invoice::TYPE_E_ARCHIVE,
            'provider_job_id' => 'u-9',
            'provider_document_id' => 'u-9',
            'invoice_number' => 'EAR2026000000009',
            'issued_at' => now(),
            'public_token' => self::TOKEN,
        ]));
    }

    /** @param list<InvoiceLine>|null $lines */
    private function draft(bool $company = false, string $taxNumber = '12345678901', ?array $lines = null, string $currency = 'TRY'): InvoiceDraft
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
            lines: $lines ?? [new InvoiceLine('Kahve 250 g', 2, '120.00', 20, 'KHV-1')],
            orderNumber: '10500001',
            orderedAt: new DateTimeImmutable('2026-10-08 10:00:00'),
            platformName: 'Trendyol',
            platformUrl: 'https://www.trendyol.com',
            currency: $currency,
        );
    }

    private function runIssue(Tenant $tenant, Invoice $invoice): void
    {
        $job = (new IssueInvoice($invoice->id, $tenant->id))->withFakeQueueInteractions();
        $job->handle(app(AdapterRegistry::class), app(InvoiceProviders::class), app(ChannelErrorText::class));
    }

    private function reload(Tenant $tenant, Invoice $invoice): Invoice
    {
        return $this->asTenant($tenant, fn (): Invoice => Invoice::query()->findOrFail($invoice->id));
    }

    /** @return array<string, mixed> */
    private static function nilveraError(int $code, string $description, ?string $detail = null): array
    {
        return ['Message' => 'HATALI ISTEK', 'Errors' => [['Code' => $code, 'Description' => $description, 'Detail' => $detail ?? $description]]];
    }

    /**
     * Nilvera + Trendyol sahte API'si — seçenekler İSTEK ANINDA okunur
     * (`fakeApis` kuralı: `Http::fake` üst üste eklenir, ilk eşleşen kazanır).
     *
     * @param  array{aliases?: list<array<string, string>>, send?: array<int, mixed>, archiveStatus?: string, invoiceStatus?: string, cancelStatus?: bool, cancel?: array<int, mixed>, pdf?: string, company?: array<int, mixed>}  $opts
     */
    private function fakeNilvera(array $opts = []): void
    {
        $this->sent = [];
        $this->apiOptions = $opts;

        if ($this->apisFaked) {
            return;
        }

        $this->apisFaked = true;

        Http::fake(function (HttpRequest $request) {
            $opts = $this->apiOptions;
            $this->sent[] = $request;
            $url = $request->url();
            $path = (string) preg_replace('#^https://api(test)?\.nilvera\.com/#', '', $url);
            $nilvera = $path !== $url;

            return match (true) {
                str_contains($url, 'apigw.trendyol.com') && str_ends_with($url, '/seller-invoice-file') => Http::response([], 200),
                str_contains($url, 'apigw.trendyol.com') => Http::response(['content' => [$this->trendyolPackage()], 'totalPages' => 1]),
                ! $nilvera => Http::response('beklenmeyen istek '.$url, 500),
                str_starts_with($path, 'general/Company') => Http::response(...($opts['company'] ?? [['Name' => 'Örnek Ticaret A.Ş.', 'TaxNumber' => '9876543210', 'TaxOffice' => 'Beşiktaş', 'IsActive' => true]])),
                str_starts_with($path, 'general/Credits') => Http::response([['Name' => 'e-Fatura/e-Arşiv', 'TotalCredit' => 500, 'UsedCredit' => 20, 'RemainingCredit' => 480]]),
                str_starts_with($path, 'general/GlobalCompany/Check/TaxNumber/') => ($opts['aliases'] ?? []) === []
                    ? Http::response(self::nilveraError(3004, 'Kayıt Sistemde Bulunamadı.'), 404)
                    : Http::response($opts['aliases']),
                str_ends_with($path, '/Send/Model') => Http::response(...($opts['send'] ?? [['UUID' => $request->data()['ArchiveInvoice']['InvoiceInfo']['UUID'] ?? $request->data()['EInvoice']['InvoiceInfo']['UUID'] ?? null, 'InvoiceNumber' => str_starts_with($path, 'einvoice') ? 'EFT2026000000042' : 'EAR2026000000007']])),
                str_starts_with($path, 'earchive/Invoices/') && str_ends_with($path, '/Status') => Http::response(['StatusCode' => $opts['archiveStatus'] ?? 'waiting', 'StatusDetail' => 'GİB raporu bekleniyor', 'ReportStatus' => 'NotReported', 'CancelStatus' => $opts['cancelStatus'] ?? false]),
                str_starts_with($path, 'einvoice/Sale/') && str_ends_with($path, '/Status') => Http::response(['InvoiceProfile' => 'TEMELFATURA', 'InvoiceStatus' => ['Code' => $opts['invoiceStatus'] ?? 'waiting', 'Description' => 'Hata', 'DetailDescription' => 'Alıcı etiketi geçersiz']]),
                str_ends_with($path, '/Details') => Http::response(['UUID' => 'u-1', 'InvoiceNumber' => str_starts_with($path, 'einvoice') ? 'EFT2026000000042' : 'EAR2026000000007']),
                str_ends_with($path, '/pdf') => Http::response($opts['pdf'] ?? self::PDF_BYTES, 200, ['Content-Type' => 'application/pdf']),
                str_ends_with($path, '/Cancel') => Http::response(...($opts['cancel'] ?? [['İptal edildi'], 200])),
                default => Http::response(self::nilveraError(3030, 'API Bulunamadı.'), 404),
            };
        });
    }
}
