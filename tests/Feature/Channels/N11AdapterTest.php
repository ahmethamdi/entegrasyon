<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\N11\N11Adapter;
use App\Domain\Channels\Adapters\N11\N11SoapFault;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Contracts\SupportsTokenRefresh;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\InventoryPushItem;
use App\Domain\Sync\Support\PricePushBatch;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * N11Adapter — REST (`api.n11.com`), iade için SOAP `ClaimReturnList`.
 *
 * Yanıtlar developer.n11.com'daki örnek gövdelerle aynı biçimde kurulur.
 *
 * DEĞİŞMEZ KURAL — STOK YÜKÜ FİYAT, FİYAT YÜKÜ STOK TAŞIMAZ.
 * DEĞİŞMEZ KURAL — SİPARİŞ PENCERESİ ≤15 GÜN: aşan pencere sessizce
 * kırpılır, bu yüzden dilimlenir.
 */
final class N11AdapterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_declares_only_the_capabilities_it_implements(): void
    {
        $adapter = $this->adapter();

        $this->assertInstanceOf(SupportsCatalogImport::class, $adapter);
        $this->assertInstanceOf(SupportsInventory::class, $adapter);
        $this->assertInstanceOf(SupportsPricing::class, $adapter);
        $this->assertInstanceOf(SupportsOrders::class, $adapter);
        $this->assertNotInstanceOf(SupportsCatalog::class, $adapter);
        $this->assertNotInstanceOf(SupportsTokenRefresh::class, $adapter);
        $this->assertSame('TRY', $adapter->channelCurrency());
    }

    /** Sağlık: `product-query?page=0&size=1`, başlıkta appKey/appSecret, Authorization YOK, sender açık. */
    #[Test]
    public function the_health_check_sends_the_key_pair_as_headers(): void
    {
        Http::fake(['*' => Http::response(['content' => [], 'totalElements' => 0, 'totalPages' => 0])]);

        $this->assertTrue($this->adapter()->healthCheck()->healthy);

        Http::assertSent(static fn (Request $r): bool => str_starts_with($r->url(), 'https://api.n11.com/ms/product-query?')
            && ($r->data()['page'] ?? null) === 0 && ($r->data()['size'] ?? null) === 1
            && ($r->data()['sender'] ?? null) === 'SELLER'
            && $r->hasHeader('appKey', 'ANAHTAR-1') && $r->hasHeader('appSecret', 'SIR-GIZLI-12345')
            && ! $r->hasHeader('Authorization'));
    }

    /** Anahtar çifti yoksa istek ATILMAZ. */
    #[Test]
    public function without_the_key_pair_nothing_is_sent(): void
    {
        Http::fake();

        $health = $this->adapter(secrets: [])->healthCheck();

        $this->assertFalse($health->healthy);
        $this->assertStringContainsString('appKey', (string) $health->message);
        Http::assertNothingSent();
    }

    /** JSON olmayan 200 sağlıklı sayılmaz (boş sonuç "kayıt yok" sanılırdı). */
    #[Test]
    public function a_non_json_success_is_not_healthy(): void
    {
        Http::fake(['*' => Http::response('<html>bakım</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->assertFalse($this->adapter()->healthCheck()->healthy);
    }

    /**
     * Yanlış anahtar: `/rest/` 403 düz metin "Authentication failed" (JSON
     * değil, çözümleyici patlamaz), `/ms/` 401 JSON, SOAP 200 + failure.
     */
    #[Test]
    public function every_shape_of_a_wrong_key_is_an_authentication_error(): void
    {
        Http::fake([
            'api.n11.com/rest/*' => Http::response('Authentication failed', 403, ['Content-Type' => 'text/plain']),
            'api.n11.com/ms/*' => Http::response(['@type' => 'SellerApiUserUnauthorizedException', 'message' => 'Apide doğrulama işlemi başarısız oldu.'], 401),
            'api.n11.com/ws/*' => Http::response($this->claimResponse('<result><status>failure</status><errorCode>SELLER_API.authenticationFailed</errorCode><errorMessage>Doğrulama başarısız</errorMessage></result>')),
        ]);

        $adapter = $this->adapter();

        try {
            $adapter->fetchOrders(Carbon::now()->subHour());
            $this->fail('İstisna bekleniyordu.');
        } catch (RequestException $e) {
            $this->assertSame(ErrorClass::AUTHENTICATION, $adapter->classifyError($e));
        }

        try {
            $adapter->fetchProductPage();
            $this->fail('İstisna bekleniyordu.');
        } catch (RequestException $e) {
            $this->assertSame(ErrorClass::AUTHENTICATION, $adapter->classifyError($e));
        }

        try {
            $adapter->fetchOrders(Carbon::now()->subHour(), 'c:0');
            $this->fail('İstisna bekleniyordu.');
        } catch (N11SoapFault $e) {
            $this->assertSame(ErrorClass::AUTHENTICATION, $adapter->classifyError($e));
        }

        $this->assertSame(ErrorClass::SERVER_ERROR, $adapter->classifyError(new N11SoapFault('x', 'NoXml')));
    }

    #[Test]
    public function other_http_errors_are_classified_by_status(): void
    {
        $adapter = $this->adapter();

        Http::fake([
            '*/fivehundred' => Http::response(['message' => 'iç hata'], 500),
            '*/badrequest' => Http::response(['message' => 'geçersiz'], 400),
            '*/toomany' => Http::response('', 429),
            // Düz metin kimlik reddi hangi durumla gelirse gelsin kalıcı kimlik hatası.
            '*/plainauth' => Http::response('Authentication failed', 400, ['Content-Type' => 'text/plain']),
        ]);

        $classify = static function (string $path) use ($adapter): ErrorClass {
            try {
                Http::get('https://api.n11.com/'.$path)->throw();
            } catch (RequestException $e) {
                return $adapter->classifyError($e);
            }

            return ErrorClass::NETWORK;
        };

        $this->assertSame(ErrorClass::SERVER_ERROR, $classify('fivehundred'));
        $this->assertSame(ErrorClass::VALIDATION, $classify('badrequest'));
        $this->assertSame(ErrorClass::RATE_LIMITED, $classify('toomany'));
        $this->assertSame(ErrorClass::AUTHENTICATION, $classify('plainauth'));
    }

    /**
     * Her satır bir SKU: kimlik `stockCode` (METİN), üst kimlik
     * `productMainId`, fiyat 2 haneli metin, `TL` → TRY, marka özellik 1.
     */
    #[Test]
    public function products_are_imported_by_stock_code_with_the_model_code_as_parent(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [
                $this->productRow('007-A', 'MODEL-1', 1600, 2000, 4),
                $this->productRow('0123', 'MODEL-1', 99.9, 99.9, 0),
                [...$this->productRow('', 'MODEL-2', 10, 10, 1), 'stockCode' => ''],
            ],
            'totalPages' => 3, 'last' => false, 'number' => 0,
        ])]);

        $page = $this->adapter()->fetchProductPage();

        $this->assertCount(2, $page->products);
        $this->assertTrue($page->hasMore);
        $this->assertSame('1', $page->nextCursor);

        [$first, $second] = $page->products;

        $this->assertSame('007-A', $first->externalId);
        $this->assertSame('007-A', $first->sku);
        $this->assertSame('1600.00', $first->price);
        $this->assertSame(4, $first->quantity);
        $this->assertSame('TRY', $first->currency);
        $this->assertSame('Marka X', $first->brand);
        $this->assertSame(['https://n11scdn.akamaized.net/007-A.jpg'], $first->images);
        $this->assertSame([
            'external_id' => '007-A',
            'external_parent_id' => 'MODEL-1',
            'channel_metadata' => ['n11_product_id' => '1234567890', 'currency_type' => 'TL', 'vat_rate' => 20],
        ], $first->listingIdentity);

        // Baştaki sıfır korunur — `(int)` yok.
        $this->assertSame('0123', $second->externalId);

        Http::assertSent(static fn (Request $r): bool => ($r->data()['size'] ?? null) === 250 && ($r->data()['sender'] ?? null) === 'SELLER');
    }

    /**
     * Stok görevi: YALNIZ `stockCode` + `quantity`; fiyat alanı YOK,
     * `integrator` var, görev kimliği sonuçta taşınır.
     */
    #[Test]
    public function the_stock_payload_carries_no_price_fields(): void
    {
        Http::fake(['*' => Http::response(['id' => 1092, 'type' => 'SKU_UPDATE', 'status' => 'IN_QUEUE', 'reasons' => ['2 sku işlenmeye alındı.']])]);

        $result = $this->adapter()->pushInventory(new InventoryPushBatch(
            channelConnectionId: 'c1',
            items: [
                new InventoryPushItem(listingId: 'l1', externalId: '007-A', sku: 'A', quantity: 7, version: 1),
                new InventoryPushItem(listingId: 'l2', externalId: '0123', sku: 'B', quantity: 0, version: 1),
            ],
        ));

        $this->assertTrue($result->successful);
        $this->assertSame('1092', $result->data['task_id']);

        Http::assertSent(static function (Request $r): bool {
            $skus = $r->data()['payload']['skus'] ?? [];

            return $r->url() === 'https://api.n11.com/ms/product/tasks/price-stock-update'
                && ($r->data()['payload']['integrator'] ?? null) === '34Pazar'
                && $skus === [['stockCode' => '007-A', 'quantity' => 7], ['stockCode' => '0123', 'quantity' => 0]];
        });
    }

    /**
     * Fiyat görevi: `listPrice` + `salePrice` BİRLİKTE, TAM 2 hane, sayı;
     * `quantity` ve `currencyType` YOK. Üstü çizili fiyat yoksa iki alan eşit.
     */
    #[Test]
    public function the_price_payload_has_two_decimals_and_no_stock(): void
    {
        Http::fake(['*' => Http::response(['id' => 1093, 'type' => 'SKU_UPDATE', 'status' => 'IN_QUEUE', 'reasons' => []])]);

        [$tenant, $connection] = $this->connection();
        $a = $this->listing($tenant, $connection, '007-A', ['currency_type' => 'TL']);
        $b = $this->listing($tenant, $connection, '0123', ['currency_type' => 'TL']);

        $result = $this->asTenant($tenant, fn () => $this->adapterFor($connection)->pushPrices(new PricePushBatch(
            channelConnectionId: $connection->id,
            items: [
                ['listing_id' => $a, 'external_id' => '007-A', 'price' => '1600', 'compare_at_price' => '2000.00', 'version' => 1],
                ['listing_id' => $b, 'external_id' => '0123', 'price' => '99.9', 'version' => 1],
            ],
        )));

        $this->assertTrue($result->successful);
        $this->assertSame('1093', $result->data['task_id']);

        Http::assertSent(static fn (Request $r): bool => str_contains($r->body(),
            '"skus":[{"stockCode":"007-A","listPrice":2000.00,"salePrice":1600.00},{"stockCode":"0123","listPrice":99.90,"salePrice":99.90}]')
            && ! str_contains($r->body(), 'quantity')
            && ! str_contains($r->body(), 'currencyType'));
    }

    /** TL dışı ürüne fiyat YAZILMAZ. */
    #[Test]
    public function a_foreign_currency_product_is_not_priced(): void
    {
        Http::fake();

        [$tenant, $connection] = $this->connection();
        $listing = $this->listing($tenant, $connection, 'USD-1', ['currency_type' => 'USD']);

        $result = $this->asTenant($tenant, fn () => $this->adapterFor($connection)->pushPrices(new PricePushBatch(
            channelConnectionId: $connection->id,
            items: [['listing_id' => $listing, 'external_id' => 'USD-1', 'price' => '10.00', 'version' => 1]],
        )));

        $this->assertTrue($result->failed());
        $this->assertSame(ErrorClass::VALIDATION, $result->errorClass);
        Http::assertNothingSent();
    }

    /** Gövdeyi bütünüyle reddeden görev başarı sayılmaz. */
    #[Test]
    public function a_rejected_task_is_a_validation_failure(): void
    {
        Http::fake(['*' => Http::response(['id' => 1094, 'type' => 'SKU_UPDATE', 'status' => 'REJECT', 'reasons' => ['Geçersiz istek']])]);

        $result = $this->adapter()->pushInventory(new InventoryPushBatch(
            channelConnectionId: 'c1',
            items: [new InventoryPushItem(listingId: 'l1', externalId: 'X', sku: 'X', quantity: 1, version: 1)],
        ));

        $this->assertTrue($result->failed());
        $this->assertSame(ErrorClass::VALIDATION, $result->errorClass);
        $this->assertStringContainsString('Geçersiz istek', (string) $result->errorMessage);
    }

    /** Kimliksiz listing sorulmaz; hiç kimlik yoksa çağrı YOK (süzgeçsiz sorgu bütün kataloğu getirirdi). */
    #[Test]
    public function listings_without_an_identity_cause_no_call(): void
    {
        Http::fake();

        $adapter = $this->adapter();
        $listing = new Listing(['external_id' => null]);

        $this->assertSame([], $adapter->fetchInventory([$listing])->quantitiesByExternalId);
        $this->assertSame([], $adapter->fetchPrices([$listing])->pricesByExternalId);
        Http::assertNothingSent();
    }

    /** Uzak stok `stockCode` ile tek tek; başka SKU dönerse alınmaz; başarısız yanıt boş snapshot DEĞİL. */
    #[Test]
    public function remote_stock_is_read_by_stock_code_and_failures_raise(): void
    {
        $failing = false;

        // TEK fake, yanıt değişkenden: ikinci `Http::fake()` çağrısında İLK kayıt kazanırdı.
        Http::fake(function () use (&$failing) {
            return $failing
                ? Http::response(['message' => 'iç hata'], 500)
                : Http::response(['content' => [$this->productRow('007-A', 'M', 1600, 2000, 4), $this->productRow('007-AB', 'M', 1, 1, 9)], 'totalPages' => 1, 'last' => true]);
        });

        $adapter = $this->adapter();
        $snapshot = $adapter->fetchInventory([new Listing(['external_id' => '007-A'])]);

        $this->assertSame(['007-A' => 4], $snapshot->quantitiesByExternalId);
        Http::assertSent(static fn (Request $r): bool => ($r->data()['stockCode'] ?? null) === '007-A');

        $failing = true;

        $this->expectException(RequestException::class);
        $adapter->fetchPrices([new Listing(['external_id' => '007-A'])]);
    }

    /**
     * 40 günlük birikim: her istek ≤15 günlük pencere, pencereler aralıksız
     * ve bütün aralığı kapsıyor; her dilimde her statü ayrı sorgulanır.
     */
    #[Test]
    public function a_long_order_window_is_split_into_slices_of_at_most_fifteen_days(): void
    {
        Http::fake([
            'api.n11.com/rest/*' => Http::response(['content' => [], 'totalPages' => 0]),
            'api.n11.com/ws/*' => Http::response($this->claimResponse('<result><status>success</status></result><pagingData><currentPage>0</currentPage><pageSize>20</pageSize><totalCount>0</totalCount><pageCount>0</pageCount></pagingData>')),
        ]);

        $since = Carbon::now()->subDays(40);
        $adapter = $this->adapter();

        $cursor = null;
        $pages = 0;

        do {
            $page = $adapter->fetchOrders($since, $cursor);
            $cursor = $page->nextCursor;
            $pages++;
        } while ($page->hasMore && $pages < 100);

        $windows = [];

        Http::assertSent(function (Request $r) use (&$windows): bool {
            if (str_contains($r->url(), 'shipmentPackages')) {
                $windows[$r->data()['status']][] = [(int) $r->data()['startDate'], (int) $r->data()['endDate']];
            }

            return true;
        });

        $this->assertSame(['Created', 'Picking', 'Shipped', 'Delivered', 'Cancelled', 'UnSupplied'], array_keys($windows));

        $created = $windows['Created'];
        $this->assertGreaterThanOrEqual(3, count($created));
        $this->assertLessThanOrEqual($since->getTimestampMs(), $created[0][0]);
        $this->assertGreaterThanOrEqual(Carbon::now()->getTimestampMs(), $created[array_key_last($created)][1]);

        foreach ($created as $i => [$start, $end]) {
            $this->assertLessThanOrEqual(15 * 86_400_000, $end - $start, "Dilim {$i} 15 günü aşıyor.");

            if ($i > 0) {
                $this->assertSame($created[$i - 1][1], $start, "Dilim {$i} öncekine bitişik değil.");
            }
        }

        Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), 'shipmentPackages')
            && ($r->data()['orderByField'] ?? null) === 'true' && ($r->data()['sender'] ?? null) === 'SELLER');
    }

    /** SOAP zarfındaki appSecret `api_calls` günlüğüne düz yazılmaz; zarf şemaya uygun. */
    #[Test]
    public function the_soap_envelope_follows_the_schema_and_the_secret_is_masked(): void
    {
        Http::fake(['*' => Http::response($this->claimResponse('<result><status>success</status></result><pagingData><pageCount>0</pageCount></pagingData>'))]);

        $this->adapter()->fetchOrders(Carbon::now()->subHour(), 'c:0');

        Http::assertSent(static fn (Request $r): bool => $r->url() === 'https://api.n11.com/ws/returnService/'
            && str_contains($r->body(), '<sch:ClaimReturnListRequest><auth><appKey>ANAHTAR-1</appKey><appSecret>SIR-GIZLI-12345</appSecret></auth>')
            && str_contains($r->body(), '<status>APPROVED</status>')
            && str_contains($r->body(), '<sender>SELLER</sender>')
            && preg_match('#<startDate>\d{2}/\d{2}/\d{4}</startDate>#', $r->body()) === 1
            && str_contains($r->body(), '<currentPage>0</currentPage>'));

        $logged = (string) DB::table('api_calls')->latest('id')->value('request_body');

        $this->assertNotSame('', $logged);
        $this->assertStringNotContainsString('SIR-GIZLI-12345', $logged);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @return array<string, mixed> */
    private function productRow(string $stockCode, string $mainId, float $sale, float $list, int $quantity): array
    {
        return [
            'n11ProductId' => 1234567890, 'sellerId' => 9876543, 'sellerNickname' => 'testMagaza',
            'stockCode' => $stockCode, 'title' => 'Ürün '.$stockCode, 'description' => 'Açıklama',
            'categoryId' => 1000, 'productMainId' => $mainId, 'status' => 'Active', 'saleStatus' => 'On_Sale',
            'barcode' => null, 'groupId' => 1, 'currencyType' => 'TL', 'salePrice' => $sale, 'listPrice' => $list,
            'quantity' => $quantity,
            'attributes' => [['attributeId' => 1, 'attributeName' => 'Marka', 'attributeValue' => 'Marka X']],
            'imageUrls' => ['https://n11scdn.akamaized.net/'.$stockCode.'.jpg', 'http://guvensiz/x.jpg'],
            'vatRate' => 20, 'commissionRate' => 8, 'sender' => 'SELLER',
        ];
    }

    private function claimResponse(string $inner): string
    {
        return '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"><SOAP-ENV:Header/><SOAP-ENV:Body>'
            .'<ns3:ClaimReturnListResponse xmlns:ns3="http://www.n11.com/ws/schemas">'.$inner.'</ns3:ClaimReturnListResponse>'
            .'</SOAP-ENV:Body></SOAP-ENV:Envelope>';
    }

    /** @param array<string, mixed> $metadata */
    private function listing(Tenant $tenant, ChannelConnection $connection, string $externalId, array $metadata): string
    {
        return $this->asTenant($tenant, fn (): string => Listing::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_connection_id' => $connection->id,
            'external_id' => $externalId,
            'external_parent_id' => 'MODEL-1',
            'channel_metadata' => $metadata,
        ])->id);
    }

    /** @param array<string, string> $secrets */
    private function adapter(array $secrets = ['app_key' => 'ANAHTAR-1', 'app_secret' => 'SIR-GIZLI-12345']): N11Adapter
    {
        [$tenant, $connection] = $this->connection($secrets);

        return $this->asTenant($tenant, fn () => $this->adapterFor($connection));
    }

    /**
     * @param  array<string, string>  $secrets
     * @return array{0: Tenant, 1: ChannelConnection}
     */
    private function connection(array $secrets = ['app_key' => 'ANAHTAR-1', 'app_secret' => 'SIR-GIZLI-12345']): array
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'n11'],
            [
                'name' => 'N11', 'kind' => 'marketplace', 'adapter_class' => N11Adapter::class,
                'capabilities' => [], 'rate_limit_profile' => [], 'supports_webhooks' => false, 'is_active' => false,
            ],
        ));

        $tenant = (new CreateTenant)->run(name: 'N11 '.uniqid(), owner: User::factory()->create());

        $connection = $this->asTenant($tenant, function () use ($secrets): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'n11',
                'external_account_id' => 'testMagaza',
                'settings' => [N11Adapter::SELLER_NAME_KEY => 'testMagaza'],
            ]);

            if ($secrets !== []) {
                app(CredentialVault::class)->store($connection, $secrets);
            }

            return $connection;
        });

        return [$tenant, $connection];
    }

    private function adapterFor(ChannelConnection $connection): N11Adapter
    {
        return new N11Adapter(
            $connection,
            new ChannelHttpClient($connection, app(CredentialVault::class), app(PayloadRedactor::class)),
        );
    }
}
