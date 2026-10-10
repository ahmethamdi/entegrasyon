<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Orders\Actions\IngestChannelOrder;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Support\IncomingOrder;
use App\Domain\Orders\Support\IncomingOrderLine;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * e-fatura testlerinin ortak kurgusu: Trendyol siparişi, Paraşüt hesabı ve
 * iki tarafın sahte API'si.
 *
 * Paraşüt yanıtları açık kaynak v4 istemcilerinden derlenen biçimdedir;
 * gerçek hesapta doğrulanınca burası da güncellenir.
 */
trait InvoicingFixtures
{
    protected const COMPANY = '555';

    protected const PDF_URL = 'https://parasut-pdf.example.com/ea-1.pdf';

    /** Gerçek PDF başlığıyla başlayan küçük gövde — indirici `%PDF-` arar. */
    protected const PDF_BYTES = "%PDF-1.4\n% sahte fatura\n%%EOF";

    /** @var list<HttpRequest> */
    protected array $sent = [];

    /** Sahte API seçenekleri — istek ANINDA okunur. */
    protected array $apiOptions = [];

    protected bool $apisFaked = false;

    /** @return array{0: Tenant, 1: User, 2: Order} */
    protected function trendyolOrder(string $channel = 'trendyol'): array
    {
        $adapters = ['trendyol' => TrendyolAdapter::class, 'woocommerce' => WooCommerceAdapter::class];

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => $channel],
            [
                'name' => ucfirst($channel),
                'kind' => 'marketplace',
                'adapter_class' => $adapters[$channel],
                'is_active' => true,
                'rate_limit_profile' => ['requests_per_second' => 50, 'burst_capacity' => 50],
            ],
        ));

        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Fatura '.uniqid(), owner: $user);

        $order = $this->asTenant($tenant, function () use ($tenant, $channel): Order {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => $channel,
                'external_account_id' => (string) random_int(100000, 999999),
                'settings' => $channel === 'trendyol'
                    ? [TrendyolAdapter::SELLER_ID_KEY => '777']
                    : ['base_url' => 'https://fatura.example.com/wp-json/wc/v3/'],
            ]);

            app(CredentialVault::class)->store($connection, $channel === 'trendyol'
                ? ['api_key' => 'k_fatura_123456', 'api_secret' => 's_fatura_123456']
                : ['consumer_key' => 'ck_fatura_test_1234567890', 'consumer_secret' => 'cs_fatura_test_1234567890']);

            $variant = Variant::factory()->create(['sku' => 'KHV-1']);

            (new IngestChannelOrder)->run(
                new IncomingOrder(
                    channelConnectionId: $connection->id,
                    externalId: '9001',
                    lines: [new IncomingOrderLine(
                        externalLineId: '1',
                        sku: 'KHV-1',
                        title: 'Kahve',
                        quantity: 2,
                        variantId: $variant->id,
                        unitPrice: '120.00',
                        lineTotal: '240.00',
                    )],
                    externalNumber: '10500001',
                    grandTotal: '240.00',
                    placedAt: new \DateTimeImmutable('2026-10-08 10:00:00'),
                ),
                $tenant->defaultWarehouse()->id,
            );

            return Order::query()->where('external_id', '9001')->sole();
        });

        return [$tenant, $user, $order];
    }

    protected function parasutAccount(Tenant $tenant, array $overrides = []): InvoiceAccount
    {
        $this->configureParasutApp();

        return $this->asTenant($tenant, fn (): InvoiceAccount => InvoiceAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => InvoiceAccount::PROVIDER_PARASUT,
            'credentials' => ['access_token' => 'erisim-eski', 'refresh_token' => 'yenile-eski'],
            'token_expires_at' => now()->addHour(),
            'company_id' => self::COMPANY,
            'company_name' => 'Örnek Ticaret',
            'settings' => ['companies' => [['id' => self::COMPANY, 'name' => 'Örnek Ticaret']]],
            'status' => InvoiceAccount::STATUS_CONNECTED,
            ...$overrides,
        ]));
    }

    protected function configureParasutApp(): void
    {
        config([
            'services.parasut.client_id' => 'pazar-istemci',
            'services.parasut.client_secret' => 'pazar-sir',
            'services.parasut.base_url' => null,
        ]);
    }

    /** @return array<string, mixed> */
    protected function trendyolPackage(array $overrides = []): array
    {
        return array_replace_recursive([
            'shipmentPackageId' => 9001,
            'orderNumber' => '10500001',
            'shipmentPackageStatus' => 'Created',
            'status' => 'Created',
            'orderDate' => 1759928400000,
            'currencyCode' => 'TRY',
            'customerEmail' => 'pf+abc@trendyolmail.com',
            'identityNumber' => '12345678901',
            'commercial' => false,
            'taxNumber' => null,
            'invoiceAddress' => [
                'firstName' => 'Ayşe',
                'lastName' => 'Yılmaz',
                'fullName' => 'Ayşe Yılmaz',
                'fullAddress' => 'Atatürk Cad. No:1 Kadıköy İstanbul',
                'city' => 'İstanbul',
                'district' => 'Kadıköy',
                'countryCode' => 'TR',
            ],
            'lines' => [[
                'lineId' => 1,
                'productName' => 'Kahve 250 g',
                'stockCode' => 'KHV-1',
                'quantity' => 2,
                'lineUnitPrice' => 120.00,
                'lineGrossAmount' => 240.00,
                'vatRate' => 20,
                'orderLineItemStatusName' => 'Created',
            ]],
        ], $overrides);
    }

    /**
     * İki tarafın sahte API'si — gönderilen her istek `$this->sent`'e düşer.
     *
     * @param  array{package?: array<string, mixed>|null, mailbox?: string|null, contact?: string|null, job?: string, token?: array<int, mixed>, pdfUrl?: string|null, pdf?: array<int, mixed>, upload?: array<int, mixed>}  $opts
     */
    protected function fakeApis(array $opts = []): void
    {
        $this->sent = [];
        $this->apiOptions = $opts;

        // `Http::fake` üst üste eklenir ve İLK eşleşen kazanır; ikinci
        // çağrı yeni seçenekleri hiç göremezdi. Tek kez kaydedilir,
        // seçenekler istek anında okunur.
        if ($this->apisFaked) {
            return;
        }

        $this->apisFaked = true;

        Http::fake(function (HttpRequest $request) {
            $opts = $this->apiOptions;
            $package = array_key_exists('package', $opts) ? $opts['package'] : $this->trendyolPackage();
            $this->sent[] = $request;
            $url = $request->url();
            $method = $request->method();
            $base = 'https://api.parasut.com/v4/'.self::COMPANY.'/';

            return match (true) {
                // Fatura dosyası yükleme — `upload`: [gövde, durum].
                str_contains($url, 'apigw.trendyol.com') && str_ends_with($url, '/seller-invoice-file') => Http::response(...($opts['upload'] ?? [[], 200])),
                // Paraşüt'ün süreli PDF bağlantısının hedefi (depolama).
                str_starts_with($url, self::PDF_URL) => Http::response(...($opts['pdf'] ?? [self::PDF_BYTES, 200, ['Content-Type' => 'application/pdf']])),
                str_contains($url, 'apigw.trendyol.com') => Http::response(['content' => $package === null ? [] : [$package], 'totalPages' => 1]),
                str_ends_with($url, '/oauth/token') => Http::response(...($opts['token'] ?? [['access_token' => 'erisim-yeni', 'refresh_token' => 'yenile-yeni', 'expires_in' => 7200]])),
                str_starts_with($url, $base.'contacts') && $method === 'GET' => Http::response(['data' => ($opts['contact'] ?? null) === null ? [] : [['id' => $opts['contact'], 'type' => 'contacts']]]),
                str_starts_with($url, $base.'contacts') => Http::response(['data' => ['id' => 'c-1', 'type' => 'contacts']], 201),
                str_starts_with($url, $base.'sales_invoices/') => Http::response([
                    'data' => ['id' => 'si-1', 'type' => 'sales_invoices', 'relationships' => ['active_e_document' => ['data' => ['id' => 'ea-1', 'type' => 'e_archives']]]],
                    'included' => [['id' => 'ea-1', 'type' => 'e_archives', 'attributes' => ['invoice_number' => 'GIB2026000000123']]],
                ]),
                str_starts_with($url, $base.'sales_invoices') => Http::response(['data' => ['id' => 'si-1', 'type' => 'sales_invoices']], 201),
                str_starts_with($url, $base.'e_invoice_inboxes') => Http::response(['data' => ($opts['mailbox'] ?? null) === null ? [] : [['id' => 'ib-1', 'attributes' => ['vkn' => '1234567890', 'e_invoice_address' => $opts['mailbox']]]]]),
                // `pdfUrl` => null: belge henüz imzalanıyor, bağlantı yok.
                str_contains($url, '/pdf') => Http::response(['data' => ['attributes' => ['url' => array_key_exists('pdfUrl', $opts) ? $opts['pdfUrl'] : self::PDF_URL]]]),
                str_starts_with($url, $base.'e_archives'), str_starts_with($url, $base.'e_invoices') => Http::response(['data' => ['id' => 'job-1', 'type' => 'trackable_jobs']], 202),
                str_starts_with($url, $base.'trackable_jobs') => Http::response(['data' => ['id' => 'job-1', 'attributes' => [
                    'status' => $opts['job'] ?? 'done',
                    'errors' => ($opts['job'] ?? 'done') === 'error' ? ['Alıcı VKN geçersiz'] : [],
                ]]]),
                default => Http::response(['errors' => [['detail' => 'beklenmeyen istek '.$url]]], 500),
            };
        });
    }

    /** @return list<HttpRequest> */
    protected function sentTo(string $needle, ?string $method = null): array
    {
        return array_values(array_filter(
            $this->sent,
            static fn (HttpRequest $r): bool => str_contains($r->url(), $needle) && ($method === null || $r->method() === $method),
        ));
    }
}
