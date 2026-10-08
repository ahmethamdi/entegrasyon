<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Actions\ConnectChannel;
use App\Domain\Channels\Adapters\Ciceksepeti\CiceksepetiAdapter;
use App\Domain\Channels\Adapters\Ciceksepeti\CiceksepetiFault;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Contracts\SupportsTokenRefresh;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelConnectForm;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * CiceksepetiAdapter — REST (`apis.ciceksepeti.com/api/v1`), yalnız
 * `x-api-key` + `user-agent: <SatıcıId>-34Pazar`.
 *
 * Yanıtlar ciceksepeti.dev'deki örnek gövdelerle aynı biçimde kurulur (§5).
 * HTML 403 biçimi 8 Eki 2026 Almanya ölçümünden.
 *
 * DEĞİŞMEZ KURAL — STOK YÜKÜ FİYAT, FİYAT YÜKÜ STOK TAŞIMAZ; ANAHTAR stockCode.
 * DEĞİŞMEZ KURAL — AYNI STOK-FİYAT GÖVDESİ 30 DK İÇİNDE İKİNCİ KEZ GİTMEZ.
 * DEĞİŞMEZ KURAL — HTML 403 ERİŞİM ENGELİDİR, AÇIKLAYICI MESAJLA KALICI.
 * DEĞİŞMEZ KURAL — SİPARİŞ PENCERESİ ≤2 HAFTA, İADE PENCERESİ ≤1 AY.
 *
 * Bekleme (`Sleep`) sahtedir ve saati ilerletir: 5 sn aralıkları gerçekten
 * beklenmez ama "ne kadar beklendi" ölçülür.
 */
final class CiceksepetiAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const LIVE = 'https://apis.ciceksepeti.com/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake(syncWithCarbon: true);
    }

    #[Test]
    public function it_declares_only_the_capabilities_it_implements(): void
    {
        $adapter = $this->adapter();

        $this->assertInstanceOf(SupportsCatalogImport::class, $adapter);
        $this->assertInstanceOf(SupportsInventory::class, $adapter);
        $this->assertInstanceOf(SupportsPricing::class, $adapter);
        $this->assertInstanceOf(SupportsOrders::class, $adapter);
        $this->assertNotInstanceOf(SupportsTokenRefresh::class, $adapter);
        $this->assertNotInstanceOf(SupportsCatalog::class, $adapter);
        $this->assertSame('TRY', $adapter->channelCurrency());
        $this->assertSame(200, $adapter->maxInventoryBatchSize());
        $this->assertSame(200, $adapter->maxPriceBatchSize());

        $profile = $adapter->rateLimitProfile();
        $this->assertSame([1, 1, 1], [$profile->requestsPerSecond, $profile->burstCapacity, $profile->maxConcurrent]);
    }

    /**
     * Her istekte `x-api-key` ve `User-Agent: <SatıcıId>-34Pazar`;
     * Authorization YOK (anahtar adı Basic auth çifti değil). Sağlık: sayfa
     * 1, tek satır.
     */
    #[Test]
    public function every_request_carries_the_api_key_and_the_integrator_user_agent(): void
    {
        Http::fake(['*' => Http::response(['totalCount' => 0, 'products' => []])]);

        $this->assertTrue($this->adapter()->healthCheck()->healthy);

        Http::assertSent(static fn (Request $r): bool => str_starts_with($r->url(), self::LIVE.'/Products?')
            && $r->method() === 'GET'
            && ($r->data()['PageSize'] ?? null) === 1
            && ($r->data()['Page'] ?? null) === 1
            && $r->header('x-api-key') === ['CS-ANAHTAR-123']
            && $r->header('User-Agent') === ['998877-34Pazar']
            && ! $r->hasHeader('Authorization'));
    }

    /** Ortam `test` → sandbox hostu; başka değer canlı. */
    #[Test]
    public function the_sandbox_environment_uses_the_sandbox_host(): void
    {
        Http::fake(['*' => Http::response(['totalCount' => 0, 'products' => []])]);

        $this->adapter(settings: [CiceksepetiAdapter::ENVIRONMENT_KEY => 'test'])->healthCheck();

        Http::assertSent(static fn (Request $r): bool => str_starts_with($r->url(), 'https://sandbox-apis.ciceksepeti.com/api/v1/Products?'));
        Http::assertNotSent(static fn (Request $r): bool => str_starts_with($r->url(), self::LIVE));
    }

    /** Aynı sorgu 10 dk'da bir: art arda sağlık kontrolleri `SortMethod`'u döndürür. */
    #[Test]
    public function consecutive_health_checks_never_repeat_the_same_query(): void
    {
        Http::fake(['*' => Http::response(['totalCount' => 0, 'products' => []])]);

        $adapter = $this->adapter();

        foreach (range(1, 8) as $_) {
            $adapter->healthCheck();
        }

        $sorts = Http::recorded()->map(static fn (array $pair): mixed => $pair[0]->data()['SortMethod'] ?? null)->all();

        $this->assertCount(8, array_unique($sorts));
        $this->assertSame(range(1, 8), array_map('intval', $sorts));
    }

    /** Anahtar yoksa istek ATILMAZ; sağlık nedeni söyler. */
    #[Test]
    public function without_a_key_nothing_is_sent(): void
    {
        Http::fake();

        $health = $this->adapter(secrets: [])->healthCheck();

        $this->assertFalse($health->healthy);
        $this->assertStringContainsString('API anahtarı', (string) $health->message);
        Http::assertNothingSent();
    }

    /** JSON olmayan ya da `products` taşımayan 200 sağlıklı sayılmaz. */
    #[Test]
    public function a_non_json_or_shapeless_answer_is_not_healthy(): void
    {
        $answer = Http::response('<html>bakım</html>', 200, ['Content-Type' => 'text/html']);

        Http::fake(function () use (&$answer) {
            return $answer;
        });

        $this->assertFalse($this->adapter()->healthCheck()->healthy);

        $answer = Http::response(['Message' => 'Bir hata oluştu'], 200);
        $this->assertFalse($this->adapter()->healthCheck()->healthy);
    }

    /**
     * Almanya ölçümü: HTML 403 ("Page Not Available"). Sağlık "anahtarın
     * yanlış" DEMEZ, IP engelini söyler; yazımda kalıcı AUTHENTICATION.
     */
    #[Test]
    public function an_html_403_is_an_access_block_with_an_explanatory_message(): void
    {
        Http::fake(['*' => Http::response('<html><head><title>404 Not Found</title></head><body>Page Not Available</body></html>', 403, ['Content-Type' => 'text/html'])]);

        $adapter = $this->adapter();
        $health = $adapter->healthCheck();

        $this->assertFalse($health->healthy);
        $this->assertSame(CiceksepetiAdapter::ACCESS_BLOCKED_MESSAGE, $health->message);
        $this->assertStringContainsString('IP', CiceksepetiAdapter::ACCESS_BLOCKED_MESSAGE);

        try {
            $adapter->pushInventory($this->stockBatch(['X' => 1]));
            $this->fail('İstisna bekleniyordu.');
        } catch (CiceksepetiFault $e) {
            $this->assertSame(ErrorClass::AUTHENTICATION, $adapter->classifyError($e));
            $this->assertTrue($e->errorClass->isPermanent());
            $this->assertSame(CiceksepetiAdapter::ACCESS_BLOCKED_MESSAGE, $e->getMessage());
        }
    }

    /** Ham `RequestException` olarak gelen HTML 403 de kimlik sınıfıdır; öteki kodlar duruma göre. */
    #[Test]
    public function http_errors_are_classified_by_shape_and_status(): void
    {
        $adapter = $this->adapter();

        Http::fake([
            '*/html403' => Http::response('<!DOCTYPE html><html>Page Not Available</html>', 403),
            '*/json403' => Http::response(['Message' => 'Forbidden'], 403),
            '*/badkey' => Http::response(['message' => 'Geçersiz API Key'], 400),
            '*/badrequest' => Http::response(['message' => 'stockCode zorunlu'], 400),
            '*/toomany' => Http::response('', 429),
            '*/fivehundred' => Http::response('', 500),
            '*/missing' => Http::response('', 404),
        ]);

        $classify = static function (string $path) use ($adapter): ErrorClass {
            try {
                Http::get(self::LIVE.'/'.$path)->throw();
            } catch (RequestException $e) {
                return $adapter->classifyError($e);
            }

            return ErrorClass::NETWORK;
        };

        $this->assertSame(ErrorClass::AUTHENTICATION, $classify('html403'));
        $this->assertSame(ErrorClass::AUTHENTICATION, $classify('json403'));
        $this->assertSame(ErrorClass::AUTHENTICATION, $classify('badkey'));
        $this->assertSame(ErrorClass::VALIDATION, $classify('badrequest'));
        $this->assertSame(ErrorClass::RATE_LIMITED, $classify('toomany'));
        $this->assertSame(ErrorClass::SERVER_ERROR, $classify('fivehundred'));
        $this->assertSame(ErrorClass::NOT_FOUND, $classify('missing'));
        $this->assertSame(ErrorClass::NETWORK, $adapter->classifyError(new RuntimeException('x')));
    }

    /**
     * Her satır bir VARYANT: kimlik `stockCode` (METİN), üst `mainProductCode`;
     * alan adları tutarsız (`StockQuantity` / `stock`) — ikisi de okunur.
     * Sayfa 1'den, 60'lık; `totalCount` sonu belirler. Kodsuz satır alınmaz.
     */
    #[Test]
    public function products_are_imported_by_stock_code_with_both_field_spellings(): void
    {
        Http::fake(static fn (Request $r) => Http::response([
            'totalCount' => 61,
            'products' => ($r->data()['Page'] ?? 1) === 1
                ? [
                    ['productName' => 'Çanta', 'productCode' => 'kcx37758937', 'categoryId' => 179, 'stockCode' => '007', 'mainProductCode' => 'ANA-1',
                        'productStatusType' => 'YAYINDA', 'description' => 'Açıklama', 'isUseStockQuantity' => true, 'StockQuantity' => 4, 'salesPrice' => 139,
                        'images' => ['https://cdn03.ciceksepeti.com/a.jpeg', 'http://guvensiz/b.jpeg'], 'attributes' => []],
                    ['productName' => 'Bot', 'productCode' => 'kcx84644975', 'stockCode' => 'test4', 'mainProductCode' => null,
                        'productStatusType' => 8, 'stock' => 1, 'salesPrice' => 129, 'barcode' => 'ASD-123', 'images' => []],
                    ['productName' => 'Kodsuz', 'stockCode' => '', 'stock' => 3],
                ]
                : [['productName' => 'Son', 'stockCode' => 'SON-1', 'stock' => 0, 'TotalPrice' => 10]],
        ]));

        $adapter = $this->adapter();
        $page = $adapter->fetchProductPage();

        $this->assertCount(2, $page->products);
        $this->assertTrue($page->hasMore);
        $this->assertSame('2', $page->nextCursor);

        [$canta, $bot] = $page->products;
        $this->assertSame('007', $canta->externalId);
        $this->assertSame('007', $canta->sku);
        $this->assertSame(4, $canta->quantity);
        $this->assertSame('139.00', $canta->price);
        $this->assertSame('TRY', $canta->currency);
        $this->assertSame(['https://cdn03.ciceksepeti.com/a.jpeg'], $canta->images);
        $this->assertSame([
            'external_id' => '007',
            'external_parent_id' => 'ANA-1',
            'channel_metadata' => ['product_code' => 'kcx37758937', 'category_id' => 179, 'stock_tracked' => true],
        ], $canta->listingIdentity);
        $this->assertSame(1, $bot->quantity);
        $this->assertSame('out_of_stock', $bot->status);
        $this->assertSame('ASD-123', $bot->barcode);

        $last = $adapter->fetchProductPage('2');
        $this->assertFalse($last->hasMore);
        $this->assertNull($last->nextCursor);
        $this->assertSame('10.00', $last->products[0]->price);

        Http::assertSent(static fn (Request $r): bool => ($r->data()['Page'] ?? null) === 1 && ($r->data()['PageSize'] ?? null) === 60 && ! isset($r->data()['ProductStatus']));
        Http::assertSent(static fn (Request $r): bool => ($r->data()['Page'] ?? null) === 2);
    }

    /** Ürün listesine farklı istekler 5 sn arayla — erken gelen BEKLER. */
    #[Test]
    public function product_list_requests_are_five_seconds_apart(): void
    {
        Http::fake(['*' => Http::response(['totalCount' => 200, 'products' => [['stockCode' => 'A', 'stock' => 1]]])]);

        $adapter = $this->adapter();
        $startedAt = Carbon::now()->getTimestampMs();

        $adapter->fetchProductPage();
        $adapter->fetchProductPage('2');
        $adapter->fetchProductPage('3');

        Http::assertSentCount(3);
        $this->assertGreaterThanOrEqual(10_000, Carbon::now()->getTimestampMs() - $startedAt);
        Sleep::assertSleptTimes(2);
    }

    /**
     * Stok: `PUT /Products/price-and-stock`, `{"items": [...]}`, YALNIZ
     * `stockCode` + `stockQuantity` (fiyat YOK); `batchId` sonuçta.
     */
    #[Test]
    public function the_stock_payload_carries_no_price_fields(): void
    {
        Http::fake(['*' => Http::response(['batchId' => 'cef33e24-2f49-4c7f-a745-a59f7d5ce90d'])]);

        $result = $this->adapter()->pushInventory($this->stockBatch(['007' => 7, 'B-2' => 0]));

        $this->assertTrue($result->successful);
        $this->assertSame('cef33e24-2f49-4c7f-a745-a59f7d5ce90d', $result->data['batch_id']);
        $this->assertSame(2, $result->data['pushed']);

        Http::assertSent(static fn (Request $r): bool => $r->url() === self::LIVE.'/Products/price-and-stock'
            && $r->method() === 'PUT'
            && $r->data() === ['items' => [['stockCode' => '007', 'stockQuantity' => 7], ['stockCode' => 'B-2', 'stockQuantity' => 0]]]);
    }

    /**
     * Fiyat: `salesPrice`, stok YOK; `listPrice` yalnız gerçek indirimde ve
     * fark %1–%80 iken. Sıfır fiyat gönderilmez, kalem bazında reddedilir.
     */
    #[Test]
    public function the_price_payload_has_no_stock_and_a_list_price_only_for_a_valid_discount(): void
    {
        Http::fake(['*' => Http::response(['batchId' => 'b-1'])]);

        $result = $this->adapter()->pushPrices(new PricePushBatch(
            channelConnectionId: 'c1',
            items: [
                ['listing_id' => 'l1', 'external_id' => 'A', 'price' => '1600', 'compare_at_price' => '2000.00', 'version' => 1],
                ['listing_id' => 'l2', 'external_id' => 'B', 'price' => '99.9', 'compare_at_price' => '50', 'version' => 1],
                ['listing_id' => 'l3', 'external_id' => 'C', 'price' => '100', 'compare_at_price' => '100.5', 'version' => 1],
                ['listing_id' => 'l4', 'external_id' => 'D', 'price' => '10', 'compare_at_price' => '100', 'version' => 1],
                ['listing_id' => 'l5', 'external_id' => 'E', 'price' => '49.99', 'version' => 1],
            ],
        ));

        $this->assertTrue($result->successful);
        $this->assertSame('b-1', $result->data['batch_id']);

        Http::assertSent(static function (Request $r): bool {
            $items = $r->data()['items'] ?? [];

            return $r->url() === self::LIVE.'/Products/price-and-stock'
                && count($items) === 5
                && $items[0] == ['stockCode' => 'A', 'salesPrice' => 1600, 'listPrice' => 2000]
                && $items[1] == ['stockCode' => 'B', 'salesPrice' => 99.9]
                // %0,5 indirim (<%1) ve %90 indirim (>%80): üstü çizili fiyat GİTMEZ.
                && $items[2] == ['stockCode' => 'C', 'salesPrice' => 100]
                && $items[3] == ['stockCode' => 'D', 'salesPrice' => 10]
                && $items[4] == ['stockCode' => 'E', 'salesPrice' => 49.99]
                && ! str_contains($r->body(), 'stockQuantity');
        });
    }

    #[Test]
    public function a_zero_price_is_never_sent(): void
    {
        Http::fake();

        $result = $this->adapter()->pushPrices(new PricePushBatch(
            channelConnectionId: 'c1',
            items: [['listing_id' => 'l1', 'external_id' => 'A', 'price' => '0', 'version' => 1]],
        ));

        $this->assertTrue($result->failed());
        $this->assertSame(ErrorClass::VALIDATION, $result->errorClass);
        Http::assertNothingSent();
    }

    /**
     * 5 → 4 → 5: üçüncü yük birincinin AYNISI. Başarı denmez (kanal 4'te
     * kalırdı), GÖNDERİLMEZ (kanal sessizce yok sayabilir): RATE_LIMITED +
     * kalan süre. Farklı gövde hemen gider; 30 dk sonra aynı gövde de gider.
     */
    #[Test]
    public function the_same_stock_body_is_not_sent_twice_within_thirty_minutes(): void
    {
        Http::fake(['*' => Http::response(['batchId' => 'b-x'])]);

        $adapter = $this->adapter();

        $this->assertTrue($adapter->pushInventory($this->stockBatch(['X' => 5]))->successful);
        $this->assertTrue($adapter->pushInventory($this->stockBatch(['X' => 4]))->successful);

        $this->travel(2)->minutes();
        $blocked = $adapter->pushInventory($this->stockBatch(['X' => 5]));

        $this->assertTrue($blocked->failed());
        $this->assertSame(ErrorClass::RATE_LIMITED, $blocked->errorClass);
        $this->assertGreaterThanOrEqual(1670, $blocked->retryAfter);
        $this->assertLessThanOrEqual(1680, $blocked->retryAfter);
        Http::assertSentCount(2);

        $this->travel(28)->minutes();
        $this->assertTrue($adapter->pushInventory($this->stockBatch(['X' => 5]))->successful);
        Http::assertSentCount(3);
    }

    /** Kapı bağlantı başınadır: başka satıcının aynı gövdesi engellenmez. */
    #[Test]
    public function the_same_body_gate_is_per_connection(): void
    {
        Http::fake(['*' => Http::response(['batchId' => 'b-x'])]);

        $this->assertTrue($this->adapter()->pushInventory($this->stockBatch(['X' => 5]))->successful);
        $this->assertTrue($this->adapter()->pushInventory($this->stockBatch(['X' => 5]))->successful);

        Http::assertSentCount(2);
    }

    /** 429: kanal kabul etmedi → kapı bırakılır, yeniden deneme 30 dk beklemez. */
    #[Test]
    public function a_429_releases_the_same_body_gate(): void
    {
        $answer = Http::response(['message' => 'Too many requests'], 429);

        Http::fake(function () use (&$answer) {
            return $answer;
        });

        $adapter = $this->adapter();

        try {
            $adapter->pushInventory($this->stockBatch(['X' => 5]));
            $this->fail('İstisna bekleniyordu.');
        } catch (RequestException $e) {
            $this->assertSame(ErrorClass::RATE_LIMITED, $adapter->classifyError($e));
        }

        $answer = Http::response(['batchId' => 'b-2']);
        $this->assertTrue($adapter->pushInventory($this->stockBatch(['X' => 5]))->successful);
        Http::assertSentCount(2);
    }

    /** Stok-fiyat istekleri arası en az 1 sn (farklı gövde). */
    #[Test]
    public function stock_and_price_requests_are_a_second_apart(): void
    {
        Http::fake(['*' => Http::response(['batchId' => 'b-x'])]);

        $adapter = $this->adapter();

        $adapter->pushInventory($this->stockBatch(['X' => 1]));
        $adapter->pushPrices(new PricePushBatch(channelConnectionId: 'c1', items: [['listing_id' => 'l1', 'external_id' => 'X', 'price' => '10', 'version' => 1]]));

        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    /** `batchId` taşımayan 200 başarı sayılmaz. */
    #[Test]
    public function an_answer_without_a_batch_id_raises(): void
    {
        Http::fake(['*' => Http::response(['Message' => 'İşlem alınamadı'])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('batchId');
        $this->adapter()->pushInventory($this->stockBatch(['X' => 1]));
    }

    /** Kimliksiz listing sorulmaz; hiç kimlik yoksa çağrı YOK. */
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

    /** Uzak stok `StockCode` süzgeciyle; başka kod dönerse alınmaz; başarısız yanıt boş snapshot DEĞİL. */
    #[Test]
    public function remote_stock_is_read_by_stock_code_and_failures_raise(): void
    {
        $failing = false;

        Http::fake(function () use (&$failing) {
            return $failing
                ? Http::response('', 500)
                : Http::response(['totalCount' => 2, 'products' => [
                    ['stockCode' => '007', 'StockQuantity' => 4, 'salesPrice' => 139],
                    ['stockCode' => '0078', 'StockQuantity' => 9, 'salesPrice' => 1],
                ]]);
        });

        $adapter = $this->adapter();

        $this->assertSame(['007' => 4], $adapter->fetchInventory([new Listing(['external_id' => '007'])])->quantitiesByExternalId);
        $this->assertSame(['007' => '139.00'], $adapter->fetchPrices([new Listing(['external_id' => '007'])])->pricesByExternalId);
        Http::assertSent(static fn (Request $r): bool => ($r->data()['StockCode'] ?? null) === '007');

        $failing = true;

        $this->expectException(RequestException::class);
        $adapter->fetchPrices([new Listing(['external_id' => '007'])]);
    }

    /**
     * 40 günlük birikim: aktif ve pasif `GetOrders` dilimleri ≤2 hafta,
     * aralıksız; pasif en az 12 gün geriye; iade listesi İPTAL TARİHİYLE
     * (`cancellationStartDate`, `startDate` YOK), dilim ≤1 ay; sayfalar 0'dan.
     */
    #[Test]
    public function order_windows_respect_two_weeks_and_returns_one_month(): void
    {
        Http::fake(static fn (Request $r) => str_contains($r->url(), 'getcanceledorders')
            ? Http::response(['orderItemList' => []])
            : Http::response(['orderListCount' => 0, 'supplierOrderListWithBranch' => []]));

        $since = Carbon::now()->subDays(40);
        $adapter = $this->adapter();

        $cursor = null;
        $pages = 0;

        do {
            $page = $adapter->fetchOrders($since, $cursor);
            $cursor = $page->nextCursor;
            $pages++;
        } while ($page->hasMore && $pages < 100);

        $active = [];
        $passive = [];
        $returns = [];

        foreach (Http::recorded() as [$request]) {
            $data = $request->data();

            if (str_contains($request->url(), 'getcanceledorders')) {
                $this->assertArrayNotHasKey('startDate', $data);
                $this->assertSame(0, $data['page']);
                $returns[] = [$data['cancellationStartDate'], $data['cancellationEndDate']];

                continue;
            }

            $this->assertSame(self::LIVE.'/Order/GetOrders', $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertSame(0, $data['page']);
            $this->assertSame(100, $data['pageSize']);
            $data['isOrderStatusActive'] ? $active[] = [$data['startDate'], $data['endDate']] : $passive[] = [$data['startDate'], $data['endDate']];
        }

        $this->assertGreaterThanOrEqual(3, count($active));
        $this->assertNotEmpty($passive);
        $this->assertNotEmpty($returns);

        foreach (['aktif' => $active, 'pasif' => $passive] as $name => $windows) {
            foreach ($windows as $i => [$start, $end]) {
                $this->assertLessThanOrEqual(14 * 86400, Carbon::parse($end)->getTimestamp() - Carbon::parse($start)->getTimestamp(), "{$name} dilim {$i} 2 haftayı aşıyor.");

                if ($i > 0) {
                    $this->assertSame($windows[$i - 1][1], $start, "{$name} dilim {$i} öncekine bitişik değil.");
                }
            }
        }

        $this->assertLessThanOrEqual($since->getTimestamp(), Carbon::parse($active[0][0])->getTimestamp());
        $this->assertGreaterThanOrEqual(Carbon::now()->getTimestamp(), Carbon::parse(end($active)[1])->getTimestamp());

        foreach ($returns as [$start, $end]) {
            $this->assertLessThanOrEqual(28 * 86400, Carbon::parse($end)->getTimestamp() - Carbon::parse($start)->getTimestamp());
        }
    }

    /** Pasif aşama bugünden en az 12 gün geriye bakar (imleç 5 dk önce olsa da), tek dilimde. */
    #[Test]
    public function the_cancellation_stage_looks_back_twelve_days(): void
    {
        Http::fake(static fn (Request $r) => Http::response(['orderListCount' => 0, 'supplierOrderListWithBranch' => []]));

        $page = $this->adapter()->fetchOrders(Carbon::now()->subMinutes(5), 'p:0:0');

        $this->assertSame('r:0:0', $page->nextCursor);
        Http::assertSent(fn (Request $r): bool => ($r->data()['isOrderStatusActive'] ?? null) === false
            && Carbon::parse($r->data()['startDate'])->lessThanOrEqualTo(Carbon::now()->subDays(12)));
    }

    /** İade onayı günler sonra gelir: imleç yeni olsa da iade listesi ~27 gün geriye (iptal/iade tarihiyle) sorulur. */
    #[Test]
    public function the_return_stage_looks_back_almost_a_month(): void
    {
        Http::fake(['*' => Http::response(['orderItemList' => []])]);

        $page = $this->adapter()->fetchOrders(Carbon::now()->subMinutes(5), 'r:0:0');

        Http::assertSent(static fn (Request $r): bool => str_ends_with($r->url(), '/Order/getcanceledorders')
            && Carbon::parse($r->data()['cancellationStartDate'])->lessThanOrEqualTo(Carbon::now()->subDays(27))
            && Carbon::parse($r->data()['cancellationEndDate'])->greaterThanOrEqualTo(Carbon::now()));
        // Tek dilim: devam yok.
        $this->assertNull($page->nextCursor);
    }

    /** Boş/boşluk kimlikli listing sorulmaz; kimlikli olan tek tek sorulur. */
    #[Test]
    public function a_blank_identity_is_never_queried(): void
    {
        Http::fake(['*' => Http::response(['totalCount' => 0, 'products' => []])]);

        $this->adapter()->fetchInventory([new Listing(['external_id' => '  ']), new Listing(['external_id' => 'A'])]);

        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $r): bool => ($r->data()['StockCode'] ?? null) === 'A');
    }

    /**
     * Bağlanırken anahtar kasaya, satıcı ID ve ortam `settings`'e gider;
     * sağlık kontrolü başlıklarla gider ve bağlantı etkinleşir.
     */
    #[Test]
    public function connecting_stores_the_key_and_checks_health(): void
    {
        Http::fake(['*' => Http::response(['totalCount' => 3, 'products' => []])]);

        $tenant = $this->makeTenant();

        $connection = $this->asTenant($tenant, fn (): ChannelConnection => app(ConnectChannel::class)->run(
            channelTypeCode: 'ciceksepeti',
            label: 'Çiçeksepeti mağazam',
            storeUrl: null,
            secrets: [CiceksepetiAdapter::API_KEY_SECRET => 'CS-ANAHTAR-123'],
            settings: [CiceksepetiAdapter::SELLER_ID_KEY => '998877'],
            accountId: '998877',
        ));

        $this->assertSame('active', $connection->status);
        Http::assertSent(static fn (Request $r): bool => $r->header('x-api-key') === ['CS-ANAHTAR-123'] && $r->header('User-Agent') === ['998877-34Pazar']);

        $this->assertFalse(ChannelConnectForm::exchangesToken('ciceksepeti'));
        $this->assertSame(CiceksepetiAdapter::SELLER_ID_KEY, ChannelConnectForm::accountField('ciceksepeti'));
        $this->assertSame(['ciceksepeti_api_key'], array_column(ChannelConnectForm::secretFields('ciceksepeti'), 'name'));
        $this->assertNotContains('api_key', array_column(ChannelConnectForm::secretFields('ciceksepeti'), 'name'));

        $rules = ChannelConnectForm::validationRules('ciceksepeti');
        $this->assertContains('required', $rules[CiceksepetiAdapter::SELLER_ID_KEY]);
        $this->assertContains('nullable', $rules[CiceksepetiAdapter::ENVIRONMENT_KEY]);
        $this->assertContains('in:canli,test', $rules[CiceksepetiAdapter::ENVIRONMENT_KEY]);

        $validator = validator([CiceksepetiAdapter::API_KEY_SECRET => 'k', CiceksepetiAdapter::SELLER_ID_KEY => "12\r\nX-Kotu: 1"], $rules);
        $this->assertTrue($validator->fails());
    }

    /** IP engeli: bağlantı `pending` kalır ve son hata IP'yi söyler. */
    #[Test]
    public function an_access_block_leaves_the_connection_pending_with_the_reason(): void
    {
        Http::fake(['*' => Http::response('<html>Page Not Available</html>', 403, ['Content-Type' => 'text/html'])]);

        $tenant = $this->makeTenant();

        $connection = $this->asTenant($tenant, fn (): ChannelConnection => app(ConnectChannel::class)->run(
            channelTypeCode: 'ciceksepeti',
            label: 'Çiçeksepeti mağazam',
            storeUrl: null,
            secrets: [CiceksepetiAdapter::API_KEY_SECRET => 'CS-ANAHTAR-123'],
            settings: [CiceksepetiAdapter::SELLER_ID_KEY => '998877'],
            accountId: '998877',
        ));

        $this->assertSame('pending', $connection->status);
        $this->assertStringContainsString('IP', (string) $connection->last_error);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @param array<string, int> $quantities */
    private function stockBatch(array $quantities): InventoryPushBatch
    {
        $items = [];
        $i = 0;

        foreach ($quantities as $code => $quantity) {
            $items[] = new InventoryPushItem(listingId: 'l'.++$i, externalId: (string) $code, sku: (string) $code, quantity: $quantity, version: 1);
        }

        return new InventoryPushBatch(channelConnectionId: 'c1', items: $items);
    }

    /**
     * @param  array<string, string>  $secrets
     * @param  array<string, string>  $settings
     */
    private function adapter(
        array $secrets = [CiceksepetiAdapter::API_KEY_SECRET => 'CS-ANAHTAR-123'],
        array $settings = [],
    ): CiceksepetiAdapter {
        $tenant = $this->makeTenant();

        $connection = $this->asTenant($tenant, function () use ($secrets, $settings): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'ciceksepeti',
                'external_account_id' => 'cs-'.uniqid(),
                'settings' => [CiceksepetiAdapter::SELLER_ID_KEY => '998877', ...$settings],
            ]);

            if ($secrets !== []) {
                app(CredentialVault::class)->store($connection, $secrets);
            }

            return $connection;
        });

        return $this->asTenant($tenant, fn () => new CiceksepetiAdapter(
            $connection,
            new ChannelHttpClient($connection, app(CredentialVault::class), app(PayloadRedactor::class)),
        ));
    }

    private function makeTenant(): Tenant
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'ciceksepeti'],
            [
                'name' => 'Çiçeksepeti', 'kind' => 'marketplace', 'adapter_class' => CiceksepetiAdapter::class,
                'capabilities' => [], 'rate_limit_profile' => [], 'supports_webhooks' => false, 'is_active' => true,
            ],
        ));

        return (new CreateTenant)->run(name: 'Çiçeksepeti '.uniqid(), owner: User::factory()->create());
    }
}
