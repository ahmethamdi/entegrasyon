<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Hepsiburada\HepsiburadaAdapter;
use App\Domain\Channels\Adapters\Hepsiburada\HepsiburadaEndpoints;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Contracts\SupportsTaxonomy;
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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HepsiburadaAdapter — üçüncü kanal.
 *
 * Mimari Karar Dokümanı v2.2 · §7 (Adapter Architecture).
 *
 * ⚠️ **DOKÜMAN BU KANALI KAPSAM DIŞI BIRAKIYOR** (§16: "Ay 7"). Faz 4
 * bittiği için kullanıcının açık kararıyla açıldı.
 *
 * Uç noktalar ve kimlik resmî dokümana göre (`docs/HEPSIBURADA-API-NOTLARI.md`,
 * 6 Eki 2026); gerçek hesapla henüz sınanmadı, kanal `is_active = false`.
 *
 * DEĞİŞMEZ KURAL — KİMLİK: Basic auth `merchantId:ServisAnahtarı`,
 *   `User-Agent` = entegratör kullanıcı adı. Biri eksikse istek HİÇ
 *   atılmaz (yoksa "anahtar yanlış" diye görünen kimliksiz istek, `97a7eb7`).
 *
 * DEĞİŞMEZ KURAL — STOK VE FİYAT AYNI YÜKTE (Trendyol'un TERSİ):
 *   Kanal eksik alanı sıfır sayabiliyor ve "stok 0 = satışa kapat" diye
 *   yorumluyor. Yazılmamış gövdeler bunu AÇIKÇA söyler.
 *
 * DEĞİŞMEZ KURAL — YAZILMAMIŞ YETENEK SESSİZCE BAŞARILI DÖNMEZ (§7).
 */
final class HepsiburadaAdapterTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────── yetenekler

    /**
     * İLAN EDİLEN YETENEKLER GERÇEKTEN UYGULANANLARDIR.
     *
     * `SupportsCatalog` ve `SupportsTaxonomy` bu turda UYGULANMADI:
     * yetenek `instanceof` ile okunur ve ilan edilen ama çalışmayan bir
     * yetenek, panelde çalışmayan bir sekme demektir.
     */
    #[Test]
    public function it_declares_only_the_capabilities_it_implements(): void
    {
        $adapter = $this->adapter();

        $this->assertInstanceOf(ChannelAdapter::class, $adapter);
        $this->assertInstanceOf(SupportsInventory::class, $adapter);
        $this->assertInstanceOf(SupportsPricing::class, $adapter);
        $this->assertInstanceOf(SupportsOrders::class, $adapter);
        $this->assertInstanceOf(SupportsCatalogImport::class, $adapter);

        // Bu ikisi HENÜZ yazılmadı ve ilan EDİLMEMELİ.
        $this->assertNotInstanceOf(SupportsCatalog::class, $adapter);
        $this->assertNotInstanceOf(SupportsTaxonomy::class, $adapter);
    }

    // ─────────────────────────────────────────────────── User-Agent

    /**
     * HER İSTEK `merchantId:ServisAnahtarı` Basic auth'u ve entegratör
     * kullanıcı adını (`User-Agent`) TAŞIR — resmî dokümandaki biçim.
     *
     * Kırılması kanalın anahtar DOĞRUYKEN reddetmesi demektir; hata
     * "anahtarın yanlış" diye görünür.
     */
    #[Test]
    public function every_request_carries_basic_auth_and_the_integrator_user_agent(): void
    {
        Http::fake(['*' => Http::response(['listings' => []], 200)]);

        $this->adapter(merchantId: 'MERCHANT-42')->healthCheck();

        Http::assertSent(static fn ($request): bool => $request->hasHeader('User-Agent', 'firma_dev')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('MERCHANT-42:ANAHTAR12345')));
    }

    /**
     * Servis Anahtarı ya da entegratör adı yoksa istek HİÇ atılmaz.
     */
    #[Test]
    public function a_missing_service_key_or_integrator_sends_nothing(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $noKey = $this->adapter(merchantId: 'M-NOKEY', secrets: [])->healthCheck();
        $noIntegrator = $this->adapter(merchantId: 'M-NOUA', settings: [])->healthCheck();

        $this->assertFalse($noKey->healthy);
        $this->assertStringContainsString('Servis Anahtarı', (string) $noKey->message);
        $this->assertFalse($noIntegrator->healthy);
        $this->assertStringContainsString('entegratör', (string) $noIntegrator->message);

        Http::assertNothingSent();
    }

    /**
     * TEST ORTAMI `-sit` ADRESİNE GİDER, canlı ortam eksiz adrese.
     *
     * Yetki önce testte verilir; test bilgisiyle canlıya gitmek (ya da
     * tersi) 401 verir ve sebep görünmezdi. Yol büyük L ile `/Listings/`.
     */
    #[Test]
    public function the_environment_selects_the_host(): void
    {
        Http::fake(['*' => Http::response(['listings' => []], 200)]);

        $this->adapter(merchantId: 'M-TEST', settings: [
            HepsiburadaAdapter::INTEGRATOR_KEY => 'firma_dev',
            HepsiburadaAdapter::ENVIRONMENT_KEY => HepsiburadaAdapter::ENVIRONMENT_TEST,
        ])->healthCheck();
        $this->adapter(merchantId: 'M-LIVE')->healthCheck();

        Http::assertSent(static fn ($r): bool => str_starts_with($r->url(), 'https://listing-external-sit.hepsiburada.com/Listings/merchantid/M-TEST'));
        Http::assertSent(static fn ($r): bool => str_starts_with($r->url(), 'https://listing-external.hepsiburada.com/Listings/merchantid/M-LIVE'));
    }

    /**
     * SATICI KİMLİĞİ YOKSA İSTEK HİÇ ATILMAZ.
     *
     * Boş bir kimlikle `User-Agent: " - Entegrasyon"` gönderilirdi;
     * kanal 401 döner, `AUTHENTICATION` KALICI sayılır ve listing
     * "anahtarın yanlış" diyerek ölür — oysa sorun kimliğin
     * tanımsızlığıdır ve o hiçbir yerde görünmez.
     */
    #[Test]
    public function a_missing_merchant_id_fails_loudly_instead_of_sending_a_broken_header(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        [$tenant] = $this->makeTenant();

        $adapter = $this->asTenant($tenant, function (): HepsiburadaAdapter {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'hepsiburada',
                'external_account_id' => '',
                'settings' => [],
            ]);

            return $this->adapterFor($connection);
        });

        // Sağlık kontrolü istisnayı YUTAR ve sağlıksız döner — doğru
        // davranış: bağlantı `pending` kalır ve sebep `last_error`'da.
        $result = $adapter->healthCheck();

        $this->assertFalse($result->healthy);
        $this->assertStringContainsString('merchantId', (string) $result->message);

        Http::assertNothingSent();
    }

    // ─────────────────────────────────────────────────── sağlık

    /** Sağlıklı yanıt gecikmeyle birlikte döner. */
    #[Test]
    public function a_successful_call_reports_healthy(): void
    {
        Http::fake(['*' => Http::response(['listings' => []], 200)]);

        $result = $this->adapter()->healthCheck();

        $this->assertTrue($result->healthy);
        $this->assertNotNull($result->latencyMs);
    }

    /** 2xx olmayan yanıt SAĞLIKSIZDIR — bağlantı `active` olmaz. */
    #[Test]
    public function a_non_2xx_response_reports_unhealthy(): void
    {
        Http::fake(['*' => Http::response(['message' => 'yetkisiz'], 401)]);

        $result = $this->adapter()->healthCheck();

        $this->assertFalse($result->healthy);
        $this->assertStringContainsString('401', (string) $result->message);
    }

    // ─────────────────────────────────────────────────── sınıflandırma

    /**
     * Hata sınıflandırması — SINIFLANDIRMA ADAPTER'DA, KARAR ÇEKİRDEKTE.
     *
     * `VALIDATION` ve `AUTHENTICATION` KALICIDIR: yeniden denemek bütçe
     * israfıdır ve kullanıcı müdahalesi gerekir.
     */
    #[Test]
    public function http_statuses_map_to_error_classes(): void
    {
        $adapter = $this->adapter();

        $cases = [
            429 => ErrorClass::RATE_LIMITED,
            401 => ErrorClass::AUTHENTICATION,
            403 => ErrorClass::AUTHENTICATION,
            404 => ErrorClass::NOT_FOUND,
            409 => ErrorClass::CONFLICT,
            408 => ErrorClass::TIMEOUT,
            422 => ErrorClass::VALIDATION,
            400 => ErrorClass::VALIDATION,
            500 => ErrorClass::SERVER_ERROR,
            503 => ErrorClass::SERVER_ERROR,
        ];

        foreach ($cases as $status => $expected) {
            $this->assertSame(
                $expected,
                $adapter->classifyError($this->httpError($status)),
                "HTTP {$status} yanlış sınıflandırıldı.",
            );
        }
    }

    /** Ağ hatası NETWORK'tür — sonuç BELİRSİZ, kalıcı değil. */
    #[Test]
    public function a_connection_failure_is_a_network_error(): void
    {
        $this->assertSame(
            ErrorClass::NETWORK,
            $this->adapter()->classifyError(new ConnectionException('koptu')),
        );
    }

    // ─────────────────────────────────────────────────── webhook

    /**
     * HB bildirimi Basic auth taşır (imza YOK): `merchantId` +
     * kasadaki `webhook_secret`. Doğrusu kabul edilir.
     */
    #[Test]
    public function a_webhook_with_the_right_basic_auth_is_accepted(): void
    {
        $adapter = $this->adapter(merchantId: 'M-1', secrets: [
            'service_key' => 'ANAHTAR12345', 'webhook_secret' => 'hb_webhook_sifresi',
        ]);

        $this->assertTrue($adapter->verifyWebhookSignature('{}', [
            'Authorization' => ['Basic '.base64_encode('M-1:hb_webhook_sifresi')],
        ]));
        // Başlık adı ve şema adı harf duyarsız (vekiller yeniden yazar).
        $this->assertTrue($adapter->verifyWebhookSignature('{}', [
            'authorization' => ['basic '.base64_encode('M-1:hb_webhook_sifresi')],
        ]));
    }

    /** Yanlış şifre, başka satıcı, başlıksız ya da şifre tanımsız → RED. */
    #[Test]
    public function a_webhook_without_the_right_basic_auth_is_rejected(): void
    {
        $adapter = $this->adapter(merchantId: 'M-1', secrets: [
            'service_key' => 'ANAHTAR12345', 'webhook_secret' => 'dogru',
        ]);

        $this->assertFalse($adapter->verifyWebhookSignature('{}', [
            'Authorization' => ['Basic '.base64_encode('M-1:yanlis')],
        ]));
        $this->assertFalse($adapter->verifyWebhookSignature('{}', [
            'Authorization' => ['Basic '.base64_encode('M-2:dogru')],
        ]));
        $this->assertFalse($adapter->verifyWebhookSignature('{}', []));
        $this->assertFalse($adapter->verifyWebhookSignature('{}', [
            'Authorization' => ['Bearer dogru'],
        ]));

        // Şifre kasada yoksa "geçti" denmez.
        $noSecret = $this->adapter(merchantId: 'M-3', secrets: ['service_key' => 'ANAHTAR12345']);
        $this->assertFalse($noSecret->verifyWebhookSignature('{}', [
            'Authorization' => ['Basic '.base64_encode('M-3:')],
        ]));
    }

    // ─────────────────────────────────────────── içe aktarma

    /**
     * İlan listesi + katalog: ad, marka, barkod, HTTPS görsel kataloğdan;
     * fiyat, stok, SKU'lar ilandan. Kimlik `hepsiburadaSku`, `merchantSku`
     * stok/fiyat gönderimi için `channel_metadata`'da. İmleç offset'tir.
     */
    #[Test]
    public function a_listing_page_is_imported_with_catalog_details(): void
    {
        Http::fake([
            'listing-external.hepsiburada.com/*' => Http::response([
                'totalCount' => 120,
                'listings' => [[
                    'listingId' => 'L-1', 'hepsiburadaSku' => 'HBV00000ABC', 'merchantSku' => 'KUPA-01',
                    'price' => 149.9, 'availableStock' => 7, 'isSalable' => true, 'isLocked' => false,
                ]],
            ], 200),
            'mpop.hepsiburada.com/*' => Http::response(['data' => [[
                'hbSku' => 'HBV00000ABC', 'productName' => 'Seramik Kupa', 'brand' => 'Kupacı',
                'barcode' => '8690000000011', 'description' => 'El yapımı',
                'images' => ['https://cdn.example/1.jpg', 'http://guvensiz/2.jpg'],
            ]]], 200),
        ]);

        $page = $this->adapter(merchantId: 'M-IMP')->fetchProductPage('50');
        $product = $page->products[0];

        $this->assertSame('HBV00000ABC', $product->externalId);
        $this->assertSame('KUPA-01', $product->sku);
        $this->assertSame('Seramik Kupa', $product->title);
        $this->assertSame('Kupacı', $product->brand);
        $this->assertSame('8690000000011', $product->barcode);
        $this->assertSame('149.9', $product->price);
        $this->assertSame(7, $product->quantity);
        $this->assertSame('on_sale', $product->status);
        $this->assertSame(['https://cdn.example/1.jpg'], $product->images);
        $this->assertSame('HBV00000ABC', $product->listingIdentity['external_id']);
        $this->assertSame('KUPA-01', $product->listingIdentity['channel_metadata']['merchant_sku']);
        $this->assertSame('100', $page->nextCursor);
        $this->assertTrue($page->hasMore);

        Http::assertSent(static fn ($r): bool => str_contains($r->url(), '/Listings/merchantid/M-IMP')
            && str_contains($r->url(), 'offset=50') && str_contains($r->url(), 'limit=50'));
        Http::assertSent(static fn ($r): bool => str_starts_with($r->url(), 'https://mpop.hepsiburada.com/product/api/products/all-products-of-merchant/M-IMP')
            && str_contains($r->url(), 'hbSku=HBV00000ABC'));
    }

    /**
     * KATALOG YANIT VERMEZSE İLAN DÜŞMEZ — ad yerine SKU yazılır.
     *
     * Düşseydi satıcının satıştaki ürünü 34Pazar'da görünmez olurdu.
     */
    #[Test]
    public function a_listing_is_kept_when_the_catalog_lookup_fails(): void
    {
        Http::fake([
            'listing-external.hepsiburada.com/*' => Http::response([
                'totalCount' => 1,
                'listings' => [['hepsiburadaSku' => 'HBV1', 'merchantSku' => 'SKU-1', 'price' => 10, 'availableStock' => 0, 'isSalable' => false, 'isLocked' => true]],
            ], 200),
            'mpop.hepsiburada.com/*' => Http::response(['message' => 'hata'], 500),
        ]);

        $page = $this->adapter(merchantId: 'M-FAIL')->fetchProductPage();

        $this->assertCount(1, $page->products);
        $this->assertSame('SKU-1', $page->products[0]->title);
        $this->assertSame('locked', $page->products[0]->status);
        $this->assertFalse($page->hasMore);
        $this->assertNull($page->nextCursor);
    }

    // ─────────────────────────────────────────────────── stok / fiyat

    /**
     * STOK `stock-uploads`'a gider ve FİYAT TAŞIMAZ.
     *
     * Toplu `inventory-uploads`'ta tek alan gönderilince ötekinin sıfırlanıp
     * sıfırlanmadığı belgelenmemiş; sıfırlanırsa satış kapanır.
     * `merchantSku` büyük harfe çevrilir (HB öyle saklar).
     */
    #[Test]
    public function stock_goes_to_stock_uploads_without_a_price(): void
    {
        Http::fake(['*' => Http::response(['id' => 'UP-1'], 200)]);

        $result = $this->adapter(merchantId: 'M-STK')->pushInventory(new InventoryPushBatch(
            channelConnectionId: 'c1',
            items: [new InventoryPushItem(listingId: 'l1', externalId: 'HBV1', sku: 'kupa-01', quantity: 4, version: 1)],
        ));

        $this->assertTrue($result->successful);
        $this->assertSame('UP-1', $result->data['upload_id']);

        Http::assertSent(static fn ($r): bool => $r->method() === 'POST'
            && str_ends_with($r->url(), '/Listings/merchantid/M-STK/stock-uploads')
            && $r->data() === [['hepsiburadaSku' => 'HBV1', 'merchantSku' => 'KUPA-01', 'availableStock' => 4]]);
    }

    /**
     * FİYAT `price-uploads`'a gider ve STOK TAŞIMAZ; `merchantSku` içe
     * aktarmanın `channel_metadata`'ya yazdığı değerden gelir.
     */
    #[Test]
    public function price_goes_to_price_uploads_without_stock(): void
    {
        Http::fake(['*' => Http::response(['id' => 'UP-2'], 200)]);

        [$tenant] = $this->makeTenant();

        [$adapter, $listingId] = $this->asTenant($tenant, function () use ($tenant): array {
            $connection = ChannelConnection::factory()->create([
                'tenant_id' => $tenant->id,
                'channel_type_code' => 'hepsiburada',
                'external_account_id' => 'M-PRC',
                'settings' => [HepsiburadaAdapter::INTEGRATOR_KEY => 'firma_dev'],
            ]);
            app(CredentialVault::class)->store($connection, ['service_key' => 'ANAHTAR12345']);
            $listing = Listing::factory()->create([
                'tenant_id' => $tenant->id,
                'channel_connection_id' => $connection->id,
                'external_id' => 'HBV2',
                'channel_metadata' => ['merchant_sku' => 'KUPA-02'],
            ]);

            return [$this->adapterFor($connection), $listing->id];
        });

        $this->asTenant($tenant, fn () => $adapter->pushPrices(new PricePushBatch(
            channelConnectionId: 'c1',
            items: [['listing_id' => $listingId, 'external_id' => 'HBV2', 'price' => '129.90', 'version' => 1]],
        )));

        Http::assertSent(static fn ($r): bool => str_ends_with($r->url(), '/Listings/merchantid/M-PRC/price-uploads')
            && $r->data() === [['hepsiburadaSku' => 'HBV2', 'merchantSku' => 'KUPA-02', 'price' => 129.9]]);
    }

    /** Başarısız yükleme İSTİSNA olarak yükselir — sessizce başarılı dönmez. */
    #[Test]
    public function a_failed_upload_throws(): void
    {
        Http::fake(['*' => Http::response(['message' => 'hata'], 400)]);

        $this->expectException(RequestException::class);

        $this->adapter()->pushInventory(new InventoryPushBatch(
            channelConnectionId: 'c1',
            items: [new InventoryPushItem(listingId: 'l1', externalId: 'HBV1', sku: 'A', quantity: 1, version: 1)],
        ));
    }

    /**
     * Uzak stok/fiyat `hbSkuList` ile okunur; kimliksiz listing sorulmaz,
     * hiç kimlik yoksa çağrı YAPILMAZ (filtresiz istek bütün kataloğu getirirdi).
     */
    #[Test]
    public function remote_stock_and_price_are_read_by_hb_sku(): void
    {
        Http::fake(['*' => Http::response(['listings' => [
            ['hepsiburadaSku' => 'HBV1', 'availableStock' => 3, 'price' => 99.5],
        ]], 200)]);

        $adapter = $this->adapter(merchantId: 'M-READ');

        $this->assertSame([], $adapter->fetchInventory([])->quantitiesByExternalId);
        Http::assertNothingSent();

        $listing = new Listing(['external_id' => 'HBV1']);

        $this->assertSame(['HBV1' => 3], $adapter->fetchInventory([$listing])->quantitiesByExternalId);
        $this->assertSame(['HBV1' => '99.5'], $adapter->fetchPrices([$listing])->pricesByExternalId);

        Http::assertSent(static fn ($r): bool => str_contains($r->url(), 'hbSkuList=HBV1'));
    }

    // ─────────────────────────────────────────────────── uç noktalar

    /**
     * YER TUTUCU ADIYLA DOLDURULUR, KONUMLA DEĞİL.
     *
     * Konumla eşleştirme `{merchantId}` ve `{id}`'nin sırası
     * değiştiğinde sessizce yanlış değeri yazar ve istek BAŞKA bir
     * satıcının SKU'suna giderdi (toplu içe aktarmadaki "kolonlar ADIYLA
     * eşlenir" kuralının aynısı).
     */
    #[Test]
    public function endpoint_placeholders_are_filled_by_name(): void
    {
        $path = HepsiburadaEndpoints::path(
            HepsiburadaEndpoints::STOCK_UPLOAD_STATUS,
            ['id' => 'U-9', 'merchantId' => 'M1'],
        );

        $this->assertSame('/Listings/merchantid/M1/stock-uploads/id/U-9', $path);
    }

    /**
     * DOLDURULMAMIŞ YER TUTUCU SESSİZCE GEÇMEZ.
     *
     * Geçseydi istek literal `{merchantSku}` içeren bir adrese gider,
     * kanal 404 döner ve sebep hiçbir yerde görünmezdi.
     */
    #[Test]
    public function an_unfilled_placeholder_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HepsiburadaEndpoints::path(
            HepsiburadaEndpoints::STOCK_UPLOAD_STATUS,
            ['merchantId' => 'M1'],
        );
    }

    /** Bilinmeyen yer tutucu da sessizce yutulmaz. */
    #[Test]
    public function an_unknown_placeholder_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HepsiburadaEndpoints::path(
            HepsiburadaEndpoints::LISTING_LIST,
            ['merchantId' => 'M1', 'bilinmeyen' => 'x'],
        );
    }

    /**
     * SKU URL'DE KAÇIRILIR.
     *
     * Boşluk veya eğik çizgi taşıyan bir SKU kaçırılmazsa yol yapısını
     * bozar ve istek BAŞKA bir uç noktaya gider.
     */
    #[Test]
    public function path_values_are_url_encoded(): void
    {
        $path = HepsiburadaEndpoints::path(
            HepsiburadaEndpoints::STOCK_UPLOAD_STATUS,
            ['merchantId' => 'M1', 'id' => 'A/B C'],
        );

        $this->assertSame('/Listings/merchantid/M1/stock-uploads/id/A%2FB%20C', $path);
    }

    // ─────────────────────────────────────────────────── hız sınırı

    /**
     * EN DÜŞÜK SINIR SEÇİLİR — kova BAĞLANTI başınadır.
     *
     * Tek kova iki farklı uç nokta sınırını ayrı ayrı temsil edemez.
     * Yüksek sınırı seçmek sipariş çağrılarını sürekli 429'a sokardı;
     * düşük sınırın bedeli yalnızca yavaşlıktır.
     */
    #[Test]
    public function the_rate_limit_falls_back_to_the_conservative_profile(): void
    {
        $profile = $this->adapter()->rateLimitProfile();

        $this->assertSame(10, $profile->requestsPerSecond);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /**
     * @param  array<string, string>  $secrets
     * @param  array<string, string>  $settings
     */
    private function adapter(
        string $merchantId = 'MERCHANT-1',
        array $secrets = ['service_key' => 'ANAHTAR12345'],
        array $settings = [HepsiburadaAdapter::INTEGRATOR_KEY => 'firma_dev'],
    ): HepsiburadaAdapter {
        [$tenant] = $this->makeTenant();

        return $this->asTenant($tenant, function () use ($merchantId, $secrets, $settings): HepsiburadaAdapter {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'hepsiburada',
                'external_account_id' => $merchantId,
                'settings' => $settings,
            ]);

            app(CredentialVault::class)->store($connection, $secrets);

            return $this->adapterFor($connection);
        });
    }

    private function adapterFor(ChannelConnection $connection): HepsiburadaAdapter
    {
        return new HepsiburadaAdapter(
            $connection,
            new ChannelHttpClient(
                $connection,
                app(CredentialVault::class),
                app(PayloadRedactor::class),
            ),
        );
    }

    /** @return array{0: Tenant, 1: User} */
    private function makeTenant(string $name = 'HB'): array
    {
        $this->channelType();

        $user = User::factory()->create();

        $tenant = (new CreateTenant)->run(name: $name.' '.uniqid(), owner: $user);

        return [$tenant, $user];
    }

    private function channelType(): ChannelType
    {
        return $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'hepsiburada'],
            [
                'name' => 'Hepsiburada',
                'kind' => 'marketplace',
                'adapter_class' => HepsiburadaAdapter::class,
                'capabilities' => [
                    'catalog' => false, 'catalog_import' => true, 'inventory' => true, 'pricing' => true,
                    'orders' => true, 'taxonomy' => false, 'approval' => false,
                    'fulfillment' => false,
                ],
                'rate_limit_profile' => [],
                'supports_webhooks' => false,
                // UÇ NOKTALAR DOĞRULANMADAN CANLI BAĞLANTI AÇILMAZ.
                'is_active' => false,
            ],
        ));
    }

    private function httpError(int $status): RequestException
    {
        return new RequestException(new Response(
            new \GuzzleHttp\Psr7\Response($status, [], '{"message":"hata"}')
        ));
    }
}
