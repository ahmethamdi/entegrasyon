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
use App\Domain\Invoicing\Providers\Logo\LogoInvoiceProvider;
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
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Shipping\FakesRestCarriers;
use Tests\TestCase;

/**
 * Logo ERP (Logo Objects REST) — sipariş satış siparişi olarak aktarılır.
 *
 * ⚠️ MOCK_TESTED: token ve salesOrders cevapları Logo'nun resmî belgesindeki
 * örneklerden (docs.logo.com.tr arşivi); gerçek bir Logo kurulumuna istek
 * atılmadı. Hata gövdesi biçimi belgede YOK — testteki 400 gövdesi varsayımdır.
 */
final class LogoErpTest extends TestCase
{
    use FakesRestCarriers;
    use InvoicingFixtures;
    use RefreshDatabase;

    private const BASE = 'https://logo.ornekfirma.com.tr';

    protected function setUp(): void
    {
        parent::setUp();
        $this->carrierHosts = ['logo.ornekfirma.com.tr'];
        // DNS'e çıkılmaz: ad genel bir IP'ye çözülür (SSRF süzgeci yine çalışır).
        $this->app->instance(OutboundUrlGuard::class, new OutboundUrlGuard(fn (string $host): array => ['93.184.216.34']));
        config([
            'services.logo.client_id' => 'pazar-logo-id',
            'services.logo.client_secret' => 'pazar-logo-sir',
            'services.erp.unverified_enabled' => ['logo'],
        ]);
    }

    // ─────────────────────────────────────────────────── sağlayıcı

    #[Test]
    public function the_order_is_sent_as_the_documented_sales_order_and_its_reference_is_kept(): void
    {
        $this->tokenOk();
        $this->restReply('/api/v1/salesOrders', fn (HttpRequest $r) => Http::response([...$r->data(), 'INTERNAL_REFERENCE' => 41051]));
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->logoAccount($tenant);
        $invoice = $this->pending($tenant, $order->id);

        $this->asTenant($tenant, fn () => $this->provider($account)->submit($invoice, $this->draft()));

        [$token, $post] = $this->restCalls;
        $this->assertSame(self::BASE.'/api/v1/token', $token['url']);
        $this->assertSame('Basic '.base64_encode('pazar-logo-id:pazar-logo-sir'), $token['headers']['Authorization'][0], 'Belge: ClientID:ClientSecret base64.');
        $this->assertStringContainsString('application/x-www-form-urlencoded', $token['headers']['Content-Type'][0]);

        $this->assertSame('POST', $post['method']);
        $this->assertSame('Bearer AQAANCMnd8BFdER-test', $post['headers']['Authorization'][0]);
        $body = $post['json'];
        $this->assertSame(0, $body['INTERNAL_REFERENCE']);
        $this->assertSame('~', $body['NUMBER'], 'Numarayı Logo verir (belge örneği).');
        $this->assertSame('2026-10-08T00:00:00', $body['DATE']);
        $this->assertSame('120.TRENDYOL', $body['ARP_CODE']);
        $this->assertSame(1, $body['CURRSEL_TOTAL']);
        $this->assertSame([[
            'TYPE' => 0, 'MASTER_CODE' => 'KHV-1', 'QUANTITY' => 2, 'PRICE' => 100, 'VAT_RATE' => 20,
            'UNIT_CODE' => 'ADET', 'UNIT_CONV1' => 1, 'UNIT_CONV2' => 1, 'EDT_CURR' => 1,
        ]], $body['TRANSACTIONS']['items'], 'KDV hariç birim fiyat: 120 / 1,20 = 100.');
        $this->assertStringNotContainsString('Ayşe', (string) json_encode($body, JSON_UNESCAPED_UNICODE), 'Alıcı ERP siparişine yazılmaz (cari = pazar yeri carisi).');

        $saved = $this->reload($tenant, $invoice);
        $this->assertSame('41051', $saved->provider_invoice_id);
        $this->assertSame('120.TRENDYOL', $saved->provider_contact_id);

        // Yeniden deneme ikinci sipariş AÇMAZ.
        $this->asTenant($tenant, fn () => $this->provider($account)->submit($saved, $this->draft()));
        $this->assertCount(1, $this->restCallsTo('/api/v1/salesOrders'));
    }

    #[Test]
    public function the_token_is_form_encoded_with_user_password_and_firm_number(): void
    {
        $this->tokenOk();

        ErpProviders::probe('logo', ['base_url' => self::BASE.'/', 'username' => 'LOGO', 'password' => '', 'firm_no' => '214'], app(ErpEndpoint::class));

        $request = $this->restCalls[0];
        parse_str($request['raw'], $form);
        $this->assertSame(['grant_type' => 'password', 'username' => 'LOGO', 'password' => '', 'firmno' => '214'], $form, 'Belgedeki gövde: grant_type=password&username=LOGO&password=&firmno=214.');
        $this->assertSame('POST', $request['method']);
        $this->assertSame(self::BASE.'/api/v1/token', $request['url'], 'Sondaki / atılır.');
    }

    #[Test]
    public function wrong_credentials_and_an_unknown_platform_id_are_authentication_and_validation(): void
    {
        $this->restReply('/api/v1/token', Http::response(['error' => 'invalid_grant', 'error_description' => 'Kullanıcı adı veya şifre hatalı'], 400));

        $e = $this->providerError(fn () => ErpProviders::probe('logo', $this->credentials(), app(ErpEndpoint::class)));
        $this->assertSame(ErrorClass::AUTHENTICATION, $e->class);
        $this->assertSame('Kullanıcı adı veya şifre hatalı', $e->getMessage());

        config(['services.logo.client_id' => '']);
        $before = count($this->restCalls);
        $e = $this->providerError(fn () => ErpProviders::probe('logo', $this->credentials(), app(ErpEndpoint::class)));
        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertCount($before, $this->restCalls, 'Çözüm ortağı kimliği yokken istek gitmez.');
    }

    #[Test]
    public function an_internal_or_plain_http_address_is_refused_before_any_request(): void
    {
        foreach (['http://logo.ornekfirma.com.tr', 'https://10.0.0.5', 'https://postgres', 'https://logo.ornekfirma.com.tr:9010', 'https://erp.firma.local'] as $url) {
            $e = $this->providerError(fn () => ErpProviders::probe('logo', ['base_url' => $url] + $this->credentials(), app(ErpEndpoint::class)));
            $this->assertSame(ErrorClass::VALIDATION, $e->class, $url);
        }

        // İstek anında DNS iç ağa dönerse yine reddedilir (rebinding).
        $this->app->instance(OutboundUrlGuard::class, new OutboundUrlGuard(fn (): array => ['169.254.169.254']));
        $e = $this->providerError(fn () => ErpProviders::probe('logo', $this->credentials(), app(ErpEndpoint::class)));
        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertSame([], $this->restCalls);
    }

    #[Test]
    public function an_expired_token_is_renewed_once_and_errors_keep_their_class(): void
    {
        $this->restReply('/api/v1/token', $this->token('AQAA-eski'), $this->token('AQAA-yeni'));
        $this->restReply('/api/v1/salesOrders',
            Http::response('', 401),
            fn (HttpRequest $r) => Http::response([...$r->data(), 'INTERNAL_REFERENCE' => 7]),
            Http::response(['Message' => 'MASTER_CODE KHV-1 bulunamadı'], 400),
            Http::response('<html>IIS</html>', 503),
            fn () => Http::failedConnection(),
        );
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->logoAccount($tenant);

        $invoice = $this->pending($tenant, $order->id);
        $this->asTenant($tenant, fn () => $this->provider($account)->submit($invoice, $this->draft()));
        $posts = $this->restCallsTo('/api/v1/salesOrders');
        $this->assertSame('Bearer AQAA-eski', $posts[0]['headers']['Authorization'][0]);
        $this->assertSame('Bearer AQAA-yeni', $posts[1]['headers']['Authorization'][0]);
        $this->assertSame('7', $this->reload($tenant, $invoice)->provider_invoice_id);

        $this->asTenant($tenant, fn () => $invoice->forceFill(['provider_invoice_id' => null])->save());
        $submit = fn () => $this->asTenant($tenant, fn () => $this->provider($account)->submit($invoice, $this->draft()));

        $e = $this->providerError($submit);
        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertSame('MASTER_CODE KHV-1 bulunamadı', $e->getMessage());
        $this->assertSame(ErrorClass::SERVER_ERROR, $this->providerError($submit)->class);
        $this->assertSame(ErrorClass::NETWORK, $this->providerError($submit)->class);
    }

    #[Test]
    public function a_line_without_sku_or_a_missing_arp_code_is_refused_without_a_request(): void
    {
        $this->tokenOk();
        [$tenant, , $order] = $this->trendyolOrder();
        $account = $this->logoAccount($tenant);
        $invoice = $this->pending($tenant, $order->id);

        $e = $this->providerError(fn () => $this->asTenant($tenant, fn () => $this->provider($account)->submit($invoice, $this->draft(sku: null))));
        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertStringContainsString('SKU', $e->getMessage());

        $account->forceFill(['settings' => ['companies' => []]]);
        $e = $this->providerError(fn () => $this->asTenant($tenant, fn () => $this->provider($account)->submit($invoice, $this->draft())));
        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertSame([], $this->restCallsTo('/api/v1/salesOrders'));
    }

    // ─────────────────────────────────────────────────── ayar ekranı + iş

    #[Test]
    public function connecting_tries_the_token_first_and_never_shows_the_password(): void
    {
        [$tenant, $user] = $this->trendyolOrder();
        $this->tokenOk();

        $this->actingAs($user)->post('/settings/invoicing/erp/logo', $this->form())->assertRedirect()->assertSessionHasNoErrors();

        $account = $this->asTenant($tenant, fn (): InvoiceAccount => InvoiceAccount::query()->sole());
        $this->assertSame('logo', $account->provider);
        $this->assertTrue($account->isErp());
        $this->assertTrue($account->isUsable());
        $this->assertSame(InvoiceAccount::MODE_BOOKS_ONLY, $account->mode(), 'E-belgeyi Logo keser.');
        $this->assertSame('214', $account->company_id);
        $this->assertSame('120.TRENDYOL', $account->setting('arp_code'));
        $this->assertSame('logo-sifre', $account->credentials['password']);
        $this->assertStringNotContainsString('logo-sifre', (string) DB::table('invoice_accounts')->value('credentials'), 'Diskte şifreli.');
        $this->assertStringNotContainsString('logo-sifre', (string) json_encode(DB::table('invoice_accounts')->value('settings')), 'Şifre ayarlara düşmez.');

        $response = $this->actingAs($user)->get('/settings/invoicing')->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('account.provider', 'logo')
            ->where('account.isErp', true)
            ->where('account.unverified', true)
            ->where('account.mode', 'books_only')
            ->where('account.erpSettings.0.value', '120.TRENDYOL'));
        foreach (['logo-sifre', 'pazar-logo-sir', 'AQAANCMnd8BFdER-test'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $response->getContent(), $secret);
        }
    }

    #[Test]
    public function rejected_credentials_are_not_saved_and_logo_is_hidden_until_enabled(): void
    {
        [$tenant, $user] = $this->trendyolOrder();
        $this->restReply('/api/v1/token', Http::response(['error_description' => 'Geçersiz şifre'], 400));

        $this->actingAs($user)->post('/settings/invoicing/erp/logo', $this->form())->assertSessionHasErrors('erp');
        $this->assertSame(0, $this->asTenant($tenant, fn () => InvoiceAccount::query()->count()));

        config(['services.erp.unverified_enabled' => []]);
        $this->assertSame([], ErpProviders::offered(), 'Doğrulanmamış: varsayılan gizli.');
        $this->actingAs($user)->get('/settings/invoicing')->assertInertia(fn (AssertableInertia $page) => $page->has('erpProviders', 0));
        $this->actingAs($user)->post('/settings/invoicing/erp/logo', $this->form())->assertNotFound();

        config(['services.erp.unverified_enabled' => ['logo'], 'services.logo.client_id' => null]);
        $this->assertSame([], ErpProviders::offered(), 'Çözüm ortağı kimliği yoksa sunulmaz.');
    }

    #[Test]
    public function the_job_books_the_order_in_logo_without_storing_the_buyer(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->logoAccount($tenant);
        $this->channelResponder = fn () => Http::response(['content' => [$this->trendyolPackage()], 'totalPages' => 1]);
        $this->tokenOk();
        $this->restReply('/api/v1/salesOrders', fn (HttpRequest $r) => Http::response([...$r->data(), 'INTERNAL_REFERENCE' => 41051]));
        $invoice = $this->pending($tenant, $order->id);

        $job = (new IssueInvoice($invoice->id, $tenant->id))->withFakeQueueInteractions();
        $job->handle(app(AdapterRegistry::class), app(InvoiceProviders::class), app(ChannelErrorText::class));
        $job->assertNotReleased();

        $done = $this->reload($tenant, $invoice);
        $this->assertSame(Invoice::STATUS_ISSUED, $done->status);
        $this->assertNull($done->document_type, 'Yalnız aktarıldı: e-belge yok.');
        $this->assertNull($done->upload_status, 'Kanala dosya yüklenmez.');
        $this->assertSame('41051', $done->provider_invoice_id);
        $this->assertSame('KHV-1', $this->restCallsTo('/api/v1/salesOrders')[0]['json']['TRANSACTIONS']['items'][0]['MASTER_CODE']);

        foreach (['invoices', 'invoice_accounts', 'orders', 'order_events', 'audit_logs'] as $table) {
            $dump = json_encode(DB::table($table)->get(), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('Ayşe', (string) $dump, "{$table} alıcı adını taşıyor.");
            $this->assertStringNotContainsString('12345678901', (string) $dump, "{$table} TCKN taşıyor.");
        }
    }

    #[Test]
    public function cancel_and_return_are_refused_with_an_erp_hint(): void
    {
        [$tenant, $user, $order] = $this->trendyolOrder();
        $account = $this->logoAccount($tenant);
        $sale = $this->asTenant($tenant, fn (): Invoice => Invoice::query()->create([
            'order_id' => $order->id, 'provider' => 'logo', 'status' => Invoice::STATUS_ISSUED, 'provider_invoice_id' => '41051', 'issued_at' => now(),
        ]));

        $this->actingAs($user)->post("/orders/{$order->id}/invoice/cancel")->assertSessionHasErrors();
        $this->assertSame(1, $this->asTenant($tenant, fn () => Invoice::query()->count()), 'Düzeltme kaydı açılmaz.');

        $e = $this->providerError(fn () => $this->provider($account)->cancel($sale, $sale));
        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertNull($this->provider($account)->pdfUrl($sale));
        $this->assertSame([], $this->restCalls, 'ERP kaydı uzaktan silinmez.');
    }

    // ─────────────────────────────────────────────────── yardımcılar

    private function provider(InvoiceAccount $account): LogoInvoiceProvider
    {
        return new LogoInvoiceProvider(app(ErpEndpoint::class), $account);
    }

    private function logoAccount(Tenant $tenant): InvoiceAccount
    {
        return $this->asTenant($tenant, fn (): InvoiceAccount => InvoiceAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => InvoiceAccount::PROVIDER_LOGO,
            'credentials' => $this->credentials(),
            'company_id' => '214',
            'company_name' => 'Logo · firma 214',
            'settings' => ['arp_code' => '120.TRENDYOL', 'companies' => [['id' => '214', 'name' => 'Logo · firma 214']]],
            'status' => InvoiceAccount::STATUS_CONNECTED,
        ]));
    }

    /** @return array<string, string> */
    private function credentials(): array
    {
        return ['base_url' => self::BASE, 'username' => 'LOGO', 'password' => 'logo-sifre', 'firm_no' => '214'];
    }

    /** @return array<string, string> */
    private function form(): array
    {
        return [...$this->credentials(), 'base_url' => self::BASE.'/', 'arp_code' => '120.TRENDYOL', 'unit_code' => ''];
    }

    private function pending(Tenant $tenant, string $orderId): Invoice
    {
        return $this->asTenant($tenant, fn (): Invoice => Invoice::query()->create([
            'order_id' => $orderId,
            'provider' => InvoiceAccount::PROVIDER_LOGO,
            'status' => Invoice::STATUS_PENDING,
        ]));
    }

    private function reload(Tenant $tenant, Invoice $invoice): Invoice
    {
        return $this->asTenant($tenant, fn (): Invoice => Invoice::query()->findOrFail($invoice->id));
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

    private function tokenOk(): void
    {
        $this->restReply('/api/v1/token', $this->token('AQAANCMnd8BFdER-test'));
    }

    /** Belgedeki token yanıtı (expires_in 119 sn → önbelleğe alınmaz, hep taze). */
    private function token(string $token): array
    {
        return ['access_token' => $token, 'token_type' => 'bearer', 'expires_in' => 3600, 'refresh_token' => '0004c8cd8bef45bf8e2b53583cf07545', 'userName' => 'LOGO', 'firmNo' => '214'];
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
