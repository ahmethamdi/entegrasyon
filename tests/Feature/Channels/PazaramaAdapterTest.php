<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Actions\ConnectChannel;
use App\Domain\Channels\Adapters\Pazarama\PazaramaAdapter;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * PazaramaAdapter — REST (`isortagimapi.pazarama.com`), OAuth2
 * `client_credentials` (`isortagimgiris.pazarama.com/connect/token`).
 *
 * Yanıtlar API Entegrasyon Portali'ndaki örnek gövdelerle aynı biçimde
 * kurulur (§6). Hata biçimleri 8 Eki 2026 canlı ölçümünden.
 *
 * DEĞİŞMEZ KURAL — STOK YÜKÜ FİYAT, FİYAT YÜKÜ STOK TAŞIMAZ; ANAHTAR BARKOD.
 * DEĞİŞMEZ KURAL — SATICI BAŞINA İKİ STOK-FİYAT İSTEĞİ ARASI 10 SN.
 * DEĞİŞMEZ KURAL — SİPARİŞ PENCERESİ ≤1 AY ve `endDate` O GÜNÜ KAPSAMAZ.
 */
final class PazaramaAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URL = 'https://isortagimgiris.pazarama.com/connect/token';

    #[Test]
    public function it_declares_only_the_capabilities_it_implements(): void
    {
        $adapter = $this->adapter();

        $this->assertInstanceOf(SupportsCatalogImport::class, $adapter);
        $this->assertInstanceOf(SupportsInventory::class, $adapter);
        $this->assertInstanceOf(SupportsPricing::class, $adapter);
        $this->assertInstanceOf(SupportsOrders::class, $adapter);
        $this->assertInstanceOf(SupportsTokenRefresh::class, $adapter);
        $this->assertNotInstanceOf(SupportsCatalog::class, $adapter);
        $this->assertSame('TRY', $adapter->channelCurrency());
        $this->assertSame(3000, $adapter->maxInventoryBatchSize());
        $this->assertSame(3000, $adapter->maxPriceBatchSize());
        $this->assertSame('/connect/token', $adapter->tokenEndpointFragment());
        $this->assertLessThanOrEqual(3600, $adapter->refreshLeadSeconds());

        // Kova saniyede 1'in altına inemez; en sıkı hâl, tek eşzamanlı.
        $profile = $adapter->rateLimitProfile();
        $this->assertSame([1, 1, 1], [$profile->requestsPerSecond, $profile->burstCapacity, $profile->maxConcurrent]);
    }

    /**
     * Token: form gövdesi `grant_type` + `scope`, HTTP Basic clientId:secret
     * (kasadaki ölü Bearer DEĞİL). Zarflı `data.accessToken` okunur; süre
     * gelmezse resmi 1 saat. Secret bitişi üretim gününden +365.
     */
    #[Test]
    public function the_token_request_uses_basic_auth_and_reads_the_wrapped_answer(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['data' => ['accessToken' => 'YENI-TOKEN'], 'success' => true])]);

        $fresh = $this->adapter(settings: [PazaramaAdapter::SECRET_CREATED_AT_KEY => '2026-01-10'])->refreshCredentials();

        $this->assertSame('YENI-TOKEN', $fresh->secrets['access_token']);
        $this->assertSame('CID-1', $fresh->secrets['client_id']);
        $this->assertSame('SIR-GIZLI-12345', $fresh->secrets['client_secret']);
        $this->assertEqualsWithDelta(time() + 3600, $fresh->expiresAt?->getTimestamp(), 5);
        $this->assertSame('2027-01-10', $fresh->refreshExpiresAt?->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('Y-m-d'));

        Http::assertSent(static fn (Request $r): bool => $r->url() === self::TOKEN_URL
            && $r->method() === 'POST'
            && $r->isForm()
            && $r->data() === ['grant_type' => 'client_credentials', 'scope' => 'merchantgatewayapi.fullaccess']
            && $r->header('Authorization') === ['Basic '.base64_encode('CID-1:SIR-GIZLI-12345')]);
    }

    /** Standart OAuth biçimi de okunur; tarih yoksa secret bitişi bilinmiyor (null). */
    #[Test]
    public function the_standard_token_answer_is_read_too(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'T-2', 'expires_in' => 1800, 'token_type' => 'Bearer'])]);

        $fresh = $this->adapter()->refreshCredentials();

        $this->assertSame('T-2', $fresh->secrets['access_token']);
        $this->assertEqualsWithDelta(time() + 1800, $fresh->expiresAt?->getTimestamp(), 5);
        $this->assertNull($fresh->refreshExpiresAt);
    }

    /** Token taşımayan 200 (ya da `success: false`) başarı sayılmaz. */
    #[Test]
    public function a_token_answer_without_a_token_raises(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['data' => null, 'success' => false, 'messageCode' => 'x'])]);

        $this->expectException(RuntimeException::class);
        $this->adapter()->refreshCredentials();
    }

    /** Çift yoksa token isteği ATILMAZ; erişim anahtarı yoksa API isteği ATILMAZ. */
    #[Test]
    public function without_credentials_nothing_is_sent(): void
    {
        Http::fake();

        try {
            $this->adapter(secrets: [])->refreshCredentials();
            $this->fail('İstisna bekleniyordu.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('clientId', $e->getMessage());
        }

        $health = $this->adapter(secrets: ['client_id' => 'CID-1', 'client_secret' => 'S'])->healthCheck();

        $this->assertFalse($health->healthy);
        $this->assertStringContainsString('erişim anahtarı', (string) $health->message);
        Http::assertNothingSent();
    }

    /** Sağlık: `approved?Size=1`, açık Bearer — Basic YOK (client_id/client_secret Basic çifti değil). */
    #[Test]
    public function the_health_check_sends_the_bearer_token(): void
    {
        Http::fake(['*' => Http::response(['data' => ['sellerProducts' => [], 'nextCursor' => null], 'success' => true])]);

        $this->assertTrue($this->adapter()->healthCheck()->healthy);

        Http::assertSent(static fn (Request $r): bool => str_starts_with($r->url(), 'https://isortagimapi.pazarama.com/product/products/approved?')
            && ($r->data()['Size'] ?? null) === 1
            && $r->header('Authorization') === ['Bearer TOKEN-1']);
    }

    /** JSON olmayan ya da `success: false` taşıyan 200 sağlıklı sayılmaz. */
    #[Test]
    public function a_non_json_or_unsuccessful_answer_is_not_healthy(): void
    {
        $answer = Http::response('<html>bakım</html>', 200, ['Content-Type' => 'text/html']);

        Http::fake(function () use (&$answer) {
            return $answer;
        });

        $this->assertFalse($this->adapter()->healthCheck()->healthy);

        $answer = Http::response(['data' => null, 'success' => false, 'message' => 'Yetkisiz'], 200);
        $this->assertFalse($this->adapter()->healthCheck()->healthy);
    }

    /**
     * Yanlış kimlik: token 400 + JSON `invalid_token`, API 401 BOŞ gövde —
     * ikisi de kalıcı kimlik hatası; boş gövde çözümleyiciyi patlatmaz.
     */
    #[Test]
    public function both_shapes_of_a_wrong_key_are_authentication_errors(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['data' => null, 'success' => false, 'messageCode' => 'invalid_token', 'message' => 'Token bulunamadı veya geçersiz'], 400),
            'isortagimapi.pazarama.com/*' => Http::response('', 401, ['WWW-Authenticate' => 'Bearer error="invalid_token"']),
        ]);

        $adapter = $this->adapter();

        try {
            $adapter->refreshCredentials();
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
    }

    #[Test]
    public function other_http_errors_are_classified_by_status(): void
    {
        $adapter = $this->adapter();

        Http::fake([
            '*/fivehundred' => Http::response(['success' => false], 500),
            '*/badrequest' => Http::response(['success' => false, 'messageCode' => 'validation', 'message' => 'Geçersiz'], 400),
            '*/toomany' => Http::response('', 429),
            '*/forbidden' => Http::response('', 403),
        ]);

        $classify = static function (string $path) use ($adapter): ErrorClass {
            try {
                Http::get('https://isortagimapi.pazarama.com/'.$path)->throw();
            } catch (RequestException $e) {
                return $adapter->classifyError($e);
            }

            return ErrorClass::NETWORK;
        };

        $this->assertSame(ErrorClass::SERVER_ERROR, $classify('fivehundred'));
        $this->assertSame(ErrorClass::VALIDATION, $classify('badrequest'));
        $this->assertSame(ErrorClass::RATE_LIMITED, $classify('toomany'));
        $this->assertSame(ErrorClass::AUTHENTICATION, $classify('forbidden'));
    }

    /**
     * Her satır bir BARKOD: kimlik `code` (METİN), üst kimlik `groupCode`,
     * SKU `stockCode`, görseller sırayla ve yalnız https; imleç olduğu gibi.
     */
    #[Test]
    public function products_are_imported_by_barcode_with_the_group_as_parent(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            'sellerProducts' => [
                $this->productRow('007868', 'GRUP-1', 'STK-A', 1600, 2000, 4),
                [...$this->productRow('', 'GRUP-2', 'STK-B', 10, 10, 1), 'code' => ''],
            ],
            'nextCursor' => 'MjAyNS0xMi0xOA==',
        ], 'success' => true])]);

        $adapter = $this->adapter();
        $page = $adapter->fetchProductPage();

        $this->assertCount(1, $page->products);
        $this->assertTrue($page->hasMore);
        $this->assertSame('MjAyNS0xMi0xOA==', $page->nextCursor);

        $product = $page->products[0];
        $this->assertSame('007868', $product->externalId);
        $this->assertSame('STK-A', $product->sku);
        $this->assertSame('007868', $product->barcode);
        $this->assertSame('1600.00', $product->price);
        $this->assertSame(4, $product->quantity);
        $this->assertSame('TRY', $product->currency);
        $this->assertSame('Marka X', $product->brand);
        $this->assertSame(['https://img.pzrmcdn.com/1.jpg', 'https://img.pzrmcdn.com/2.jpg'], $product->images);
        $this->assertSame([
            'external_id' => '007868',
            'external_parent_id' => 'GRUP-1',
            'channel_metadata' => ['stock_code' => 'STK-A', 'vat_rate' => 20],
        ], $product->listingIdentity);

        $adapter->fetchProductPage('MjAyNS0xMi0xOA==');

        Http::assertSent(static fn (Request $r): bool => ($r->data()['Size'] ?? null) === 100 && ! isset($r->data()['Cursor']));
        Http::assertSent(static fn (Request $r): bool => ($r->data()['Cursor'] ?? null) === 'MjAyNS0xMi0xOA==');
    }

    /** Son sayfa: `nextCursor` null → devam yok. */
    #[Test]
    public function a_null_cursor_ends_the_import(): void
    {
        Http::fake(['*' => Http::response(['data' => ['sellerProducts' => [$this->productRow('1', 'G', 'S', 1, 1, 1)], 'nextCursor' => null], 'success' => true])]);

        $page = $this->adapter()->fetchProductPage();

        $this->assertFalse($page->hasMore);
        $this->assertNull($page->nextCursor);
    }

    /**
     * Stok: `updateStock-v2`, YALNIZ `code` + `stockCount` (fiyat YOK);
     * işlem kimliği (dataId) sonuçta.
     */
    #[Test]
    public function the_stock_payload_carries_no_price_fields(): void
    {
        Http::fake(['*' => Http::response(['data' => '7e618393-2fe4-459d-acf7-eb38bb1d72ce', 'success' => true, 'message' => 'Fiyat/Stok güncelleme işleminiz sıraya alındı.'])]);

        $result = $this->adapter()->pushInventory(new InventoryPushBatch(
            channelConnectionId: 'c1',
            items: [
                new InventoryPushItem(listingId: 'l1', externalId: '5707055046711', sku: 'A', quantity: 7, version: 1),
                new InventoryPushItem(listingId: 'l2', externalId: '0123', sku: 'B', quantity: 0, version: 1),
            ],
        ));

        $this->assertTrue($result->successful);
        $this->assertSame('7e618393-2fe4-459d-acf7-eb38bb1d72ce', $result->data['batch_id']);
        $this->assertSame(2, $result->data['pushed']);

        Http::assertSent(static fn (Request $r): bool => $r->url() === 'https://isortagimapi.pazarama.com/product/updateStock-v2'
            && $r->method() === 'POST'
            && $r->data() === ['items' => [['code' => '5707055046711', 'stockCount' => 7], ['code' => '0123', 'stockCount' => 0]]]
            && $r->header('Authorization') === ['Bearer TOKEN-1']);
    }

    /**
     * Fiyat: `updatePrice-v2`, `listPrice` + `salePrice` BİRLİKTE, stok YOK.
     * Üstü çizili fiyat satıştan büyükse `listPrice`, değilse iki alan eşit.
     */
    #[Test]
    public function the_price_payload_has_both_prices_and_no_stock(): void
    {
        Http::fake(['*' => Http::response(['data' => 'b14a42a6-9311-4d79-bcf6-346df5f39294', 'success' => true])]);

        $result = $this->adapter()->pushPrices(new PricePushBatch(
            channelConnectionId: 'c1',
            items: [
                ['listing_id' => 'l1', 'external_id' => '007868', 'price' => '1600', 'compare_at_price' => '2000.00', 'version' => 1],
                ['listing_id' => 'l2', 'external_id' => '0123', 'price' => '99.9', 'compare_at_price' => '50', 'version' => 1],
            ],
        ));

        $this->assertTrue($result->successful);
        $this->assertSame('b14a42a6-9311-4d79-bcf6-346df5f39294', $result->data['batch_id']);

        Http::assertSent(static function (Request $r): bool {
            $items = $r->data()['items'] ?? [];

            return $r->url() === 'https://isortagimapi.pazarama.com/product/updatePrice-v2'
                && count($items) === 2
                && $items[0]['code'] === '007868' && (float) $items[0]['listPrice'] === 2000.0 && (float) $items[0]['salePrice'] === 1600.0
                && $items[1]['code'] === '0123' && (float) $items[1]['listPrice'] === 99.9 && (float) $items[1]['salePrice'] === 99.9
                && ! str_contains($r->body(), 'stockCount');
        });
    }

    /**
     * 10 sn kuralı: stok ve fiyat ORTAK sayaç. 10 sn dolmadan gelen yazım
     * İSTEK ATMADAN `RATE_LIMITED` + kalan süre döner; süre dolunca gider.
     */
    #[Test]
    public function stock_and_price_requests_are_ten_seconds_apart(): void
    {
        Http::fake(['*' => Http::response(['data' => 'b14a42a6-9311-4d79-bcf6-346df5f39294', 'success' => true])]);

        $adapter = $this->adapter();
        $stock = new InventoryPushBatch(channelConnectionId: 'c1', items: [new InventoryPushItem(listingId: 'l1', externalId: 'X', sku: 'X', quantity: 1, version: 1)]);
        $prices = new PricePushBatch(channelConnectionId: 'c1', items: [['listing_id' => 'l1', 'external_id' => 'X', 'price' => '10', 'version' => 1]]);

        $this->assertTrue($adapter->pushInventory($stock)->successful);

        $this->travel(4)->seconds();
        $blocked = $adapter->pushPrices($prices);

        $this->assertTrue($blocked->failed());
        $this->assertSame(ErrorClass::RATE_LIMITED, $blocked->errorClass);
        $this->assertGreaterThanOrEqual(5, $blocked->retryAfter);
        $this->assertLessThanOrEqual(6, $blocked->retryAfter);
        Http::assertSentCount(1);

        $this->travel(7)->seconds();

        $after = $adapter->pushPrices($prices);
        $this->assertTrue($after->successful, (string) $after->errorMessage);
        Http::assertSentCount(2);
    }

    /** `success: false` zarfı reddtir (VALIDATION); kimliksiz başarı istisnadır. */
    #[Test]
    public function a_rejected_or_idless_answer_is_not_a_success(): void
    {
        $answer = Http::response(['data' => null, 'success' => false, 'userMessage' => 'Ürün bulunamadı'], 200);

        Http::fake(function () use (&$answer) {
            return $answer;
        });

        $batch = new InventoryPushBatch(channelConnectionId: 'c1', items: [new InventoryPushItem(listingId: 'l1', externalId: 'X', sku: 'X', quantity: 1, version: 1)]);

        $result = $this->adapter()->pushInventory($batch);

        $this->assertTrue($result->failed());
        $this->assertSame(ErrorClass::VALIDATION, $result->errorClass);
        $this->assertStringContainsString('Ürün bulunamadı', (string) $result->errorMessage);

        $answer = Http::response(['data' => null, 'success' => true], 200);

        $this->expectException(RuntimeException::class);
        $this->adapter()->pushInventory($batch);
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

    /** Uzak stok `Code` süzgeciyle; başka barkod dönerse alınmaz; başarısız yanıt boş snapshot DEĞİL. */
    #[Test]
    public function remote_stock_is_read_by_barcode_and_failures_raise(): void
    {
        $failing = false;

        // TEK fake, yanıt değişkenden: ikinci `Http::fake()` çağrısında İLK kayıt kazanırdı.
        Http::fake(function () use (&$failing) {
            return $failing
                ? Http::response(['success' => false], 500)
                : Http::response(['data' => ['sellerProducts' => [$this->productRow('007868', 'G', 'S', 1600, 2000, 4), $this->productRow('0078689', 'G', 'S2', 1, 1, 9)], 'nextCursor' => null], 'success' => true]);
        });

        $adapter = $this->adapter();

        $this->assertSame(['007868' => 4], $adapter->fetchInventory([new Listing(['external_id' => '007868'])])->quantitiesByExternalId);
        $this->assertSame(['007868' => '1600.00'], $adapter->fetchPrices([new Listing(['external_id' => '007868'])])->pricesByExternalId);
        Http::assertSent(static fn (Request $r): bool => ($r->data()['Code'] ?? null) === '007868');

        $failing = true;

        $this->expectException(RequestException::class);
        $adapter->fetchPrices([new Listing(['external_id' => '007868'])]);
    }

    /**
     * 40 günlük birikim: her istek ≤1 ay (`endDate` hariç), dilimler
     * aralıksız ve yeniden eskiye, son gün YARIN (bugün kapsansın), V2.
     */
    #[Test]
    public function a_long_order_window_is_split_into_slices_of_at_most_one_month(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'success' => true])]);

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
            $this->assertSame('https://isortagimapi.pazarama.com/order/getOrdersForApiV2', $r->url());
            $windows[] = [$r->data()['startDate'], $r->data()['endDate']];

            return true;
        });

        $tomorrow = Carbon::now('Europe/Istanbul')->addDay()->format('Y-m-d');
        $this->assertGreaterThanOrEqual(2, count($windows));
        $this->assertSame($tomorrow, $windows[0][1]);
        $this->assertLessThanOrEqual($since->copy()->setTimezone('Europe/Istanbul')->format('Y-m-d'), $windows[array_key_last($windows)][0]);

        foreach ($windows as $i => [$start, $end]) {
            $days = Carbon::parse($start)->diffInDays(Carbon::parse($end));
            $this->assertLessThanOrEqual(28, $days, "Dilim {$i} 1 ayı aşabilir.");
            $this->assertGreaterThan(0, $days);

            if ($i > 0) {
                $this->assertSame($windows[$i - 1][0], $end, "Dilim {$i} öncekine bitişik değil.");
            }
        }
    }

    /**
     * Güncellenme filtresi yok: imleç 5 dk önce olsa da en az 30 gün geriye
     * bakılır (iptal/iade). Tam sayfa (500) bir sonraki sayfayı ister.
     */
    #[Test]
    public function every_poll_looks_back_thirty_days_and_pages_until_a_short_page(): void
    {
        $full = array_fill(0, 500, ['orderNumber' => null]);

        Http::fake(static fn (Request $r) => Http::response(['data' => ($r->data()['pageNumber'] ?? 1) === 1 ? $full : [], 'success' => true]));

        $adapter = $this->adapter();
        $first = $adapter->fetchOrders(Carbon::now()->subMinutes(5));

        $this->assertSame('o:0:2', $first->nextCursor);

        $starts = [];
        $cursor = $first->nextCursor;

        while ($cursor !== null) {
            $cursor = $adapter->fetchOrders(Carbon::now()->subMinutes(5), $cursor)->nextCursor;
        }

        Http::assertSent(function (Request $r) use (&$starts): bool {
            $starts[] = $r->data()['startDate'];

            return true;
        });

        $this->assertLessThanOrEqual(Carbon::now('Europe/Istanbul')->subDays(30)->format('Y-m-d'), min($starts));
    }

    /**
     * Bağlanırken çift ilk erişim anahtarıyla değiştirilir, SONRA sağlık
     * kontrolü Bearer ile gider; süre ve secret bitişi kasaya yazılır.
     */
    #[Test]
    public function connecting_exchanges_the_pair_for_a_token_then_checks_health(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'TOKEN-ILK', 'expires_in' => 3600]),
            'isortagimapi.pazarama.com/*' => Http::response(['data' => ['sellerProducts' => [], 'nextCursor' => null], 'success' => true]),
        ]);

        $tenant = $this->makeTenant();

        $connection = $this->asTenant($tenant, fn (): ChannelConnection => app(ConnectChannel::class)->run(
            channelTypeCode: 'pazarama',
            label: 'Pazarama mağazam',
            storeUrl: null,
            secrets: ['client_id' => 'CID-1', 'client_secret' => 'SIR-1'],
            settings: [PazaramaAdapter::SELLER_NAME_KEY => 'magazam', PazaramaAdapter::SECRET_CREATED_AT_KEY => '2026-10-01'],
            accountId: 'magazam',
        ));

        $this->assertSame('active', $connection->status);

        $credential = DB::table('channel_credentials')->where('channel_connection_id', $connection->id)->whereNull('revoked_at')->first();
        $this->assertNotNull($credential->expires_at);
        $this->assertStringStartsWith('2027-10-0', (string) $credential->refresh_expires_at);

        $recorded = Http::recorded()->map(fn (array $pair): string => strtok($pair[0]->url(), '?'))->all();
        $this->assertSame([self::TOKEN_URL, 'https://isortagimapi.pazarama.com/product/products/approved'], $recorded);
        Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), 'approved') && $r->hasHeader('Authorization', 'Bearer TOKEN-ILK'));

        // Form tanımı: token değişimi, hesap kimliği mağaza adı, tarih isteğe bağlı ve biçimli.
        $this->assertTrue(ChannelConnectForm::exchangesToken('pazarama'));
        $this->assertSame(PazaramaAdapter::SELLER_NAME_KEY, ChannelConnectForm::accountField('pazarama'));
        $this->assertSame(['client_id', 'client_secret'], array_column(ChannelConnectForm::secretFields('pazarama'), 'name'));
        $rules = ChannelConnectForm::validationRules('pazarama');
        $this->assertContains('nullable', $rules[PazaramaAdapter::SECRET_CREATED_AT_KEY]);
        $this->assertContains('date_format:Y-m-d', $rules[PazaramaAdapter::SECRET_CREATED_AT_KEY]);
    }

    /** Reddedilen çift: bağlantı `pending`, kimliksiz sağlık isteği ATILMAZ. */
    #[Test]
    public function a_rejected_pair_leaves_the_connection_pending(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['data' => null, 'success' => false, 'messageCode' => 'invalid_token', 'message' => 'Token bulunamadı veya geçersiz'], 400),
            '*' => Http::response(['data' => [], 'success' => true]),
        ]);

        $tenant = $this->makeTenant();

        $connection = $this->asTenant($tenant, fn (): ChannelConnection => app(ConnectChannel::class)->run(
            channelTypeCode: 'pazarama',
            label: 'Pazarama mağazam',
            storeUrl: null,
            secrets: ['client_id' => 'CID-1', 'client_secret' => 'YANLIS'],
            settings: [PazaramaAdapter::SELLER_NAME_KEY => 'magazam'],
            accountId: 'magazam',
        ));

        $this->assertSame('pending', $connection->status);
        $this->assertNotNull($connection->last_error);
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), 'isortagimapi'));
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @return array<string, mixed> */
    private function productRow(string $code, string $group, string $stockCode, float $sale, float $list, int $stock): array
    {
        return [
            'name' => 'Ürün '.$code, 'displayName' => 'Ürün '.$code, 'description' => 'Açıklama', 'brandName' => 'Marka X',
            'code' => $code, 'groupCode' => $group, 'stockCount' => $stock, 'stockCode' => $stockCode, 'priorityRank' => 0,
            'listPrice' => $list, 'salePrice' => $sale, 'vatRate' => 20, 'categoryName' => 'Kupa', 'categoryId' => '3fa85f64-5717-4562-b3fc-2c963f66afa6',
            'state' => 3, 'status' => null, 'waitingApproveExp' => null, 'productSaleLimitDetail' => null,
            'attributes' => [['attributeName' => 'Renk', 'attributeValue' => 'Siyah']],
            'images' => [
                ['imageUrl' => 'https://img.pzrmcdn.com/2.jpg', 'sortOrder' => 2],
                ['imageUrl' => 'http://guvensiz/x.jpg', 'sortOrder' => 0],
                ['imageUrl' => 'https://img.pzrmcdn.com/1.jpg', 'sortOrder' => 1],
            ],
            'deliveryTypes' => null, 'productStatus' => 0, 'productGroups' => null,
        ];
    }

    /**
     * @param  array<string, string>  $secrets
     * @param  array<string, string>  $settings
     */
    private function adapter(
        array $secrets = ['client_id' => 'CID-1', 'client_secret' => 'SIR-GIZLI-12345', 'access_token' => 'TOKEN-1'],
        array $settings = [],
    ): PazaramaAdapter {
        $tenant = $this->makeTenant();

        $connection = $this->asTenant($tenant, function () use ($secrets, $settings): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'pazarama',
                // Aynı testte birden çok bağlantı: (tür, hesap) tekil.
                'external_account_id' => 'magazam-'.uniqid(),
                'settings' => [PazaramaAdapter::SELLER_NAME_KEY => 'magazam', ...$settings],
            ]);

            if ($secrets !== []) {
                app(CredentialVault::class)->store($connection, $secrets);
            }

            return $connection;
        });

        return $this->asTenant($tenant, fn () => new PazaramaAdapter(
            $connection,
            new ChannelHttpClient($connection, app(CredentialVault::class), app(PayloadRedactor::class)),
        ));
    }

    private function makeTenant(): Tenant
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'pazarama'],
            [
                'name' => 'Pazarama', 'kind' => 'marketplace', 'adapter_class' => PazaramaAdapter::class,
                'capabilities' => [], 'rate_limit_profile' => [], 'supports_webhooks' => false, 'is_active' => true,
            ],
        ));

        return (new CreateTenant)->run(name: 'Pazarama '.uniqid(), owner: User::factory()->create());
    }
}
