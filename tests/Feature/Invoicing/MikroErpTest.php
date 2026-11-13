<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\OutboundUrlGuard;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Invoicing\Exceptions\InvoiceProviderException;
use App\Domain\Invoicing\Jobs\IssueInvoice;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Invoicing\Providers\Mikro\MikroInvoiceProvider;
use App\Domain\Invoicing\Support\ErpEndpoint;
use App\Domain\Invoicing\Support\ErpProviders;
use App\Domain\Invoicing\Support\InvoiceBuyer;
use App\Domain\Invoicing\Support\InvoiceDraft;
use App\Domain\Invoicing\Support\InvoiceLine;
use App\Domain\Invoicing\Support\InvoiceProviders;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Support\ChannelErrorText;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Shipping\FakesRestCarriers;
use Tests\TestCase;

/**
 * Mikro ERP (Mikro Desktop API) — sipariş `SiparisKaydetV2` ile aktarılır.
 *
 * ⚠️ MOCK_TESTED: zarf ve hata yanıtları apidocs.mikro.com.tr'deki örnek
 * yanıt modellerinden (başarılı kayıt, Geçersiz ApiKey, Şifre Hatalı, cari
 * mevcut); gerçek bir Mikro kurulumuna istek atılmadı.
 */
final class MikroErpTest extends TestCase
{
    use FakesRestCarriers;
    use InvoicingFixtures;
    use RefreshDatabase;

    private const BASE = 'https://mikro.ornekfirma.com.tr';

    protected function setUp(): void
    {
        parent::setUp();
        $this->carrierHosts = ['mikro.ornekfirma.com.tr'];
        $this->app->instance(OutboundUrlGuard::class, new OutboundUrlGuard(fn (string $host): array => ['93.184.216.34']));
        config(['services.erp.unverified_enabled' => ['mikro']]);
        $this->travelTo(new DateTimeImmutable('2026-11-13 09:30:00', new \DateTimeZone('Europe/Istanbul')));
    }

    #[Test]
    public function the_password_is_hashed_with_todays_date_as_documented(): void
    {
        $this->assertSame(md5('2023-03-09 123asd'), MikroInvoiceProvider::hashPassword('123asd', new DateTimeImmutable('2023-03-09 10:00:00', new \DateTimeZone('Europe/Istanbul'))));
        // Gece yarısından sonra İstanbul tarihi kullanılır (UTC'de hâlâ dün).
        $this->assertSame(md5('2026-11-14 gizli'), MikroInvoiceProvider::hashPassword('gizli', new DateTimeImmutable('2026-11-13 22:30:00', new \DateTimeZone('UTC'))));
        $this->assertSame([20 => 4, 10 => 3, 1 => 2, 0 => 1], MikroInvoiceProvider::parseVatPointers('20:4, 10:3,1=2,0:1'));
        $this->assertNull(MikroInvoiceProvider::parseVatPointers('20:4,yirmi'), 'Bozuk eşleme yarım kabul edilmez.');
    }

    #[Test]
    public function the_order_is_sent_with_documented_fields_and_codes(): void
    {
        $this->restReply('SiparisKaydetV2', $this->ok(['sipGuid' => [['sip_Guid' => '12579367-6376-41DA-9235-77658988D396']]]));
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->mikroAccount($tenant);
        $invoice = $this->pending($tenant, $order->id);

        $this->asTenant($tenant, fn () => $this->provider($account)->submit($invoice, $this->draft()));

        $call = $this->restCalls[0];
        $this->assertSame(self::BASE.'/Api/apiMethods/SiparisKaydetV2', $call['url']);
        $mikro = $call['json']['Mikro'];
        $this->assertSame('mk-api-anahtar', $mikro['ApiKey']);
        $this->assertSame(2026, $mikro['CalismaYili'], 'Sipariş ucunda integer (belge).');
        $this->assertSame('ORNEKFIRMA', $mikro['FirmaKodu']);
        $this->assertSame('SRV', $mikro['KullaniciKodu']);
        $this->assertSame(md5('2026-11-13 mk-sifre'), $mikro['Sifre']);
        $this->assertSame([['aciklama' => 'Trendyol 10500001']], $mikro['evraklar'][0]['evrak_aciklamalari']);
        $this->assertSame([[
            'sip_tarih' => '08.10.2026', 'sip_tip' => '0', 'sip_cins' => '0', 'sip_evrakno_seri' => 'WEB',
            'sip_musteri_kod' => '120.TRENDYOL', 'sip_stok_kod' => 'KHV-1', 'sip_miktar' => 2, 'sip_b_fiyat' => 100,
            'sip_tutar' => 200, 'sip_vergi_pntr' => 4, 'sip_vergisiz_fl' => 0, 'sip_birim_pntr' => 1, 'sip_depono' => 2,
        ]], $mikro['evraklar'][0]['satirlar'], 'sip_tip 0 = Talep, sip_cins 0 = Normal (TABLO NO 21); %20 → işaretçi 4 (satıcının eşlemesi).');
        $this->assertStringNotContainsString('Ayşe', (string) json_encode($call['json'], JSON_UNESCAPED_UNICODE), 'Alıcı Mikro\'ya yazılmaz.');

        $saved = $this->asTenant($tenant, fn (): Invoice => $invoice->fresh());
        $this->assertSame('12579367-6376-41DA-9235-77658988D396', $saved->provider_invoice_id);
        $this->asTenant($tenant, fn () => $this->provider($account)->submit($saved, $this->draft()));
        $this->assertCount(1, $this->restCalls, 'Yeniden deneme ikinci sipariş açmaz.');
    }

    #[Test]
    public function without_an_identifier_in_data_our_reference_marks_the_record(): void
    {
        $this->restReply('SiparisKaydetV2', $this->ok([]));
        [$tenant, , $order] = $this->trendyolOrder();
        $invoice = $this->pending($tenant, $order->id);

        $this->asTenant($tenant, fn () => $this->provider($this->mikroAccount($tenant))->submit($invoice, $this->draft()));

        $saved = $this->asTenant($tenant, fn (): Invoice => $invoice->fresh());
        $this->assertSame('kayit:'.$saved->ref, $saved->provider_invoice_id);
    }

    #[Test]
    public function documented_errors_keep_their_class_and_text(): void
    {
        $this->restReply('SiparisKaydetV2',
            Http::response($this->error('401', 'connection error - Geçersiz ApiKey!'), 401),
            Http::response($this->error('401', 'connection error - Şifre Hatalı!'), 401),
            Http::response($this->error('400-01', '1. Satır : Stok kodu: KHV-1 bulunamadı!'), 400),
            Http::response('<html>IIS</html>', 502),
        );
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->mikroAccount($tenant);
        $invoice = $this->pending($tenant, $order->id);
        $submit = fn () => $this->asTenant($tenant, fn () => $this->provider($account)->submit($invoice, $this->draft()));

        $e = $this->providerError($submit);
        $this->assertSame(ErrorClass::AUTHENTICATION, $e->class);
        $this->assertSame('connection error - Geçersiz ApiKey!', $e->getMessage());
        $this->assertSame(ErrorClass::AUTHENTICATION, $this->providerError($submit)->class);
        $e = $this->providerError($submit);
        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertStringContainsString('KHV-1 bulunamadı', $e->getMessage());
        $this->assertSame(ErrorClass::SERVER_ERROR, $this->providerError($submit)->class);
    }

    #[Test]
    public function an_unmapped_vat_rate_or_missing_sku_never_reaches_mikro(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $invoice = $this->pending($tenant, $order->id);
        $account = $this->mikroAccount($tenant, ['vat_pointers' => '10:3']);

        $e = $this->providerError(fn () => $this->asTenant($tenant, fn () => $this->provider($account)->submit($invoice, $this->draft())));
        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertStringContainsString('%20', $e->getMessage());

        $e = $this->providerError(fn () => $this->asTenant($tenant, fn () => $this->provider($this->mikroAccount($tenant, [], fresh: false))->submit($invoice, $this->draft(sku: null))));
        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertSame([], $this->restCalls);
    }

    #[Test]
    public function connecting_checks_the_customer_code_first_and_hides_secrets(): void
    {
        [$tenant, $user] = $this->trendyolOrder();
        $this->restReply('CariListesiV3', $this->ok(['CariKod' => '120.TRENDYOL']));

        $this->actingAs($user)->post('/settings/invoicing/erp/mikro', $this->form())->assertRedirect()->assertSessionHasNoErrors();

        $probe = $this->restCalls[0];
        $this->assertSame(self::BASE.'/Api/APIMethods/CariListesiV3', $probe['url']);
        $this->assertSame('120.TRENDYOL', $probe['json']['CariKod']);
        $this->assertSame('2026', $probe['json']['Mikro']['CalismaYili'], 'Cari ucunda string (belge).');

        $account = $this->asTenant($tenant, fn (): InvoiceAccount => InvoiceAccount::query()->sole());
        $this->assertSame('mikro', $account->provider);
        $this->assertSame(InvoiceAccount::MODE_BOOKS_ONLY, $account->mode());
        $this->assertSame('ORNEKFIRMA', $account->company_id);
        $this->assertSame('120.TRENDYOL', $account->setting('customer_code'));
        $this->assertSame('20:4,10:3,1:2,0:1', $account->setting('vat_pointers'));
        $raw = (string) DB::table('invoice_accounts')->value('credentials');
        $this->assertStringNotContainsString('mk-sifre', $raw);
        $this->assertStringNotContainsString('mk-api-anahtar', $raw);

        $response = $this->actingAs($user)->get('/settings/invoicing')->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->where('account.provider', 'mikro')->where('account.isErp', true));
        foreach (['mk-sifre', 'mk-api-anahtar'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $response->getContent());
        }
    }

    #[Test]
    public function a_wrong_api_key_or_bad_mapping_is_not_saved_and_mikro_is_hidden_until_enabled(): void
    {
        [$tenant, $user] = $this->trendyolOrder();
        $this->restReply('CariListesiV3', Http::response($this->error('401', 'connection error - Geçersiz ApiKey!'), 401));

        $this->actingAs($user)->post('/settings/invoicing/erp/mikro', $this->form())->assertSessionHasErrors('erp');
        $this->actingAs($user)->post('/settings/invoicing/erp/mikro', [...$this->form(), 'vat_pointers' => 'yirmi'])->assertSessionHasErrors('vat_pointers');
        $this->assertSame(0, $this->asTenant($tenant, fn () => InvoiceAccount::query()->count()));

        config(['services.erp.unverified_enabled' => []]);
        $this->assertSame([], ErpProviders::offered());
        $this->actingAs($user)->post('/settings/invoicing/erp/mikro', $this->form())->assertNotFound();
    }

    #[Test]
    public function the_job_books_the_order_in_mikro(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->mikroAccount($tenant);
        $this->channelResponder = fn () => Http::response(['content' => [$this->trendyolPackage()], 'totalPages' => 1]);
        $this->restReply('SiparisKaydetV2', $this->ok([]));
        $invoice = $this->pending($tenant, $order->id);

        $job = (new IssueInvoice($invoice->id, $tenant->id))->withFakeQueueInteractions();
        $job->handle(app(AdapterRegistry::class), app(InvoiceProviders::class), app(ChannelErrorText::class));
        $job->assertNotReleased();

        $done = $this->asTenant($tenant, fn (): Invoice => $invoice->fresh());
        $this->assertSame(Invoice::STATUS_ISSUED, $done->status);
        $this->assertNull($done->document_type);
        foreach (['invoices', 'invoice_accounts', 'orders'] as $table) {
            $this->assertStringNotContainsString('Ayşe', (string) json_encode(DB::table($table)->get(), JSON_UNESCAPED_UNICODE), $table);
        }
    }

    // ─────────────────────────────────────────────────── yardımcılar

    private function provider(InvoiceAccount $account): MikroInvoiceProvider
    {
        return new MikroInvoiceProvider(app(ErpEndpoint::class), $account);
    }

    private function mikroAccount(Tenant $tenant, array $settings = [], bool $fresh = true): InvoiceAccount
    {
        if (! $fresh) {
            return $this->asTenant($tenant, fn (): InvoiceAccount => InvoiceAccount::query()->sole());
        }

        return $this->asTenant($tenant, fn (): InvoiceAccount => InvoiceAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'mikro',
            'credentials' => ['base_url' => self::BASE, 'api_key' => 'mk-api-anahtar', 'company_code' => 'ORNEKFIRMA', 'work_year' => '2026', 'username' => 'SRV', 'password' => 'mk-sifre'],
            'company_id' => 'ORNEKFIRMA',
            'company_name' => 'Mikro · ORNEKFIRMA',
            'settings' => [
                'customer_code' => '120.TRENDYOL', 'vat_pointers' => '20:4,10:3,1:2,0:1', 'warehouse_no' => '2', 'document_series' => 'WEB',
                'companies' => [['id' => 'ORNEKFIRMA', 'name' => 'Mikro · ORNEKFIRMA']],
                ...$settings,
            ],
            'status' => InvoiceAccount::STATUS_CONNECTED,
        ]));
    }

    /** @return array<string, string> */
    private function form(): array
    {
        return [
            'base_url' => self::BASE, 'api_key' => 'mk-api-anahtar', 'company_code' => 'ORNEKFIRMA', 'work_year' => '2026',
            'username' => 'SRV', 'password' => 'mk-sifre', 'customer_code' => '120.TRENDYOL', 'vat_pointers' => '20:4,10:3,1:2,0:1',
            'warehouse_no' => '1', 'document_series' => '',
        ];
    }

    private function pending(Tenant $tenant, string $orderId): Invoice
    {
        return $this->asTenant($tenant, fn (): Invoice => Invoice::query()->create([
            'order_id' => $orderId,
            'provider' => 'mikro',
            'status' => Invoice::STATUS_PENDING,
        ]));
    }

    private function draft(?string $sku = 'KHV-1'): InvoiceDraft
    {
        return new InvoiceDraft(
            buyer: new InvoiceBuyer('Ayşe Yılmaz', false, '12345678901', null, 'Atatürk Cad. No:1', 'İstanbul', 'Kadıköy'),
            lines: [new InvoiceLine('Kahve 250 g', 2, '120.00', 20, $sku)],
            orderNumber: '10500001',
            orderedAt: new DateTimeImmutable('2026-10-08 10:00:00'),
            platformName: 'Trendyol',
            platformUrl: 'https://www.trendyol.com',
        );
    }

    /** Belgedeki başarılı kayıt modeli. */
    private function ok(array $data): array
    {
        return ['result' => [['StatusCode' => 200, 'Data' => $data, 'ErrorMessage' => '', 'IsError' => false]]];
    }

    /** Belgedeki hatalı giriş / kayıt modelleri. */
    private function error(string $code, string $message): array
    {
        return ['result' => [['StatusCode' => $code, 'Data' => null, 'ErrorMessage' => $message, 'IsError' => true]]];
    }

    private function providerError(\Closure $call): InvoiceProviderException
    {
        try {
            $call();
        } catch (InvoiceProviderException $e) {
            return $e;
        }

        $this->fail('InvoiceProviderException bekleniyordu.');
    }
}
