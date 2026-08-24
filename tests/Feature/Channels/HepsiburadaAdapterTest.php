<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Hepsiburada\HepsiburadaAdapter;
use App\Domain\Channels\Adapters\Hepsiburada\HepsiburadaEndpoints;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\SupportsCatalog;
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
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\PricePushBatch;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * HepsiburadaAdapter — üçüncü kanal.
 *
 * Mimari Karar Dokümanı v2.2 · §7 (Adapter Architecture).
 *
 * ⚠️ **DOKÜMAN BU KANALI KAPSAM DIŞI BIRAKIYOR** (§16: "Ay 7"). Faz 4
 * bittiği için kullanıcının açık kararıyla açıldı.
 *
 * ⚠️ **UÇ NOKTALAR DOĞRULANMADI** — `developers.hepsiburada.com` bot
 * isteklerini 403 ile reddediyor. Bu testler adapter'ın DAVRANIŞINI
 * korur (kimlik, başlık, hata sınıflandırma, imza); uç nokta
 * YOLLARININ doğruluğunu KANITLAMAZ. O ancak gerçek satıcı hesabıyla
 * doğrulanabilir ve kanal o yüzden `is_active = false` ile seed edilir.
 *
 * DEĞİŞMEZ KURAL — `User-Agent` KİMLİK DOĞRULAMANIN PARÇASIDIR:
 *   Hepsiburada `{merchantId} - {AppName}` bekler ve eksikse kimlik
 *   bilgisi DOĞRU olsa bile 401 döner. Bu, `97a7eb7`'de yaşanan
 *   "istek sessizce kimliksiz gitti" hatasının bir başka biçimidir.
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

        // Bu ikisi HENÜZ yazılmadı ve ilan EDİLMEMELİ.
        $this->assertNotInstanceOf(SupportsCatalog::class, $adapter);
        $this->assertNotInstanceOf(SupportsTaxonomy::class, $adapter);
    }

    // ─────────────────────────────────────────────────── User-Agent

    /**
     * HER İSTEK `User-Agent: {merchantId} - {AppName}` TAŞIR.
     *
     * Bu testin kırılması kanalın **401 döndürmesi** demektir — üstelik
     * anahtar doğruyken. Hata "anahtarın yanlış" diye görünür ve
     * kullanıcı anahtarı defalarca yeniden girer.
     */
    #[Test]
    public function every_request_carries_the_merchant_user_agent(): void
    {
        Http::fake(['*' => Http::response(['listings' => []], 200)]);

        $this->adapter(merchantId: 'MERCHANT-42')->healthCheck();

        Http::assertSent(static fn ($request): bool => $request->hasHeader(
            'User-Agent',
            'MERCHANT-42 - Entegrasyon',
        ));
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

    /** Geçerli HMAC imzası KABUL edilir. */
    #[Test]
    public function a_valid_signature_is_accepted(): void
    {
        $raw = '{"orderNumber":"HB-1"}';
        $secret = 'hb_webhook_secret_value';

        $adapter = $this->adapter(secrets: [
            'api_key' => 'k', 'api_secret' => 's', 'webhook_secret' => $secret,
        ]);

        $signature = base64_encode(hash_hmac('sha256', $raw, $secret, true));

        $this->assertTrue($adapter->verifyWebhookSignature(
            $raw,
            ['X-HB-Signature' => [$signature]],
        ));
    }

    /**
     * BAŞLIK ADI BÜYÜK/KÜÇÜK HARFTEN BAĞIMSIZ OKUNUR.
     *
     * HTTP başlık adları duyarsızdır ve vekil sunucular onları yeniden
     * yazar. Tam eşleşme aransaydı MEŞRU webhook reddedilir ve kanal
     * sonsuza kadar yeniden gönderirdi.
     */
    #[Test]
    public function the_signature_header_is_matched_case_insensitively(): void
    {
        $raw = '{"orderNumber":"HB-2"}';
        $secret = 'hb_webhook_secret_value';

        $adapter = $this->adapter(secrets: [
            'api_key' => 'k', 'api_secret' => 's', 'webhook_secret' => $secret,
        ]);

        $signature = base64_encode(hash_hmac('sha256', $raw, $secret, true));

        $this->assertTrue($adapter->verifyWebhookSignature(
            $raw,
            ['x-hb-signature' => [$signature]],
        ));
    }

    /** Yanlış imza REDDEDİLİR — sahte sipariş enjeksiyonu engellenir. */
    #[Test]
    public function a_forged_signature_is_rejected(): void
    {
        $adapter = $this->adapter(secrets: [
            'api_key' => 'k', 'api_secret' => 's', 'webhook_secret' => 'dogru_sir',
        ]);

        $this->assertFalse($adapter->verifyWebhookSignature(
            '{"orderNumber":"HB-3"}',
            ['X-HB-Signature' => ['sahte-imza']],
        ));
    }

    /**
     * GÖVDE DEĞİŞİRSE İMZA TUTMAZ.
     *
     * İmzanın HAM GÖVDE üzerinden doğrulanmasının sebebi budur: tek bir
     * baytın değişmesi doğrulamayı düşürmeli.
     */
    #[Test]
    public function tampering_with_the_body_breaks_the_signature(): void
    {
        $secret = 'hb_webhook_secret_value';

        $adapter = $this->adapter(secrets: [
            'api_key' => 'k', 'api_secret' => 's', 'webhook_secret' => $secret,
        ]);

        $signature = base64_encode(hash_hmac('sha256', '{"qty":1}', $secret, true));

        $this->assertFalse($adapter->verifyWebhookSignature(
            '{"qty":999}',
            ['X-HB-Signature' => [$signature]],
        ));
    }

    /**
     * SIR TANIMSIZSA DOĞRULAMA "GEÇTİ" DEMEZ.
     *
     * Güvenli taraf REDDETMEKTİR: kabul etmek, imzasız sipariş
     * enjeksiyonuna kapı açardı.
     */
    #[Test]
    public function a_missing_webhook_secret_rejects_instead_of_accepting(): void
    {
        $adapter = $this->adapter(secrets: ['api_key' => 'k', 'api_secret' => 's']);

        $this->assertFalse($adapter->verifyWebhookSignature(
            '{"orderNumber":"HB-4"}',
            ['X-HB-Signature' => ['herhangi']],
        ));
    }

    /** İmza başlığı hiç yoksa REDDEDİLİR. */
    #[Test]
    public function a_missing_signature_header_is_rejected(): void
    {
        $adapter = $this->adapter(secrets: [
            'api_key' => 'k', 'api_secret' => 's', 'webhook_secret' => 'sir',
        ]);

        $this->assertFalse($adapter->verifyWebhookSignature('{}', []));
    }

    // ─────────────────────────────────────────── yazılmamış yetenekler

    /**
     * YAZILMAMIŞ YETENEK SESSİZCE BAŞARILI DÖNMEZ (§7).
     *
     * `AdapterResult::success()` dönseydi operasyon tamamlandı sanılır,
     * `synced_version` ilerler ve satır kanalda hiçbir şey değişmemişken
     * "senkron" görünürdü — teşhisi en zor hata sınıfı.
     *
     * **BU LİSTE MADDE KAPANDIKÇA KÜÇÜLÜR.** Yazılan bir gövde listeden
     * çıkarılmazsa test YANLIŞ SEBEPLE kırmızıya döner ve o, kuralın
     * kendisini korur (Trendyol'da aynı kalıp kullanılıyor).
     */
    #[Test]
    public function unimplemented_capabilities_throw_instead_of_reporting_success(): void
    {
        $adapter = $this->adapter();

        $unwritten = [
            // GERÇEK nesne kurulur: bu değer nesneleri `final` ve
            // stub'lanamaz — projede bilinçli bir tasarım kararı.
            'pushInventory' => fn () => $adapter->pushInventory(
                new InventoryPushBatch(channelConnectionId: 'c1', items: [])
            ),
            'pushPrices' => fn () => $adapter->pushPrices(
                new PricePushBatch(channelConnectionId: 'c1', items: [])
            ),
            'fetchInventory' => fn () => $adapter->fetchInventory([]),
            'fetchPrices' => fn () => $adapter->fetchPrices([]),
            'fetchOrders' => fn () => $adapter->fetchOrders(now()),
        ];

        foreach ($unwritten as $name => $call) {
            try {
                $call();
                $this->fail("{$name} sessizce başarılı döndü — §7 ihlali.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('yazılmadı', $e->getMessage());
            }
        }
    }

    /**
     * STOK/FİYAT GÖVDELERİ AYNI-YÜK TUZAĞINI AÇIKÇA SÖYLER.
     *
     * Bu kural Trendyol'un TERSİ ve yazacak kişi onu bilmezse satışı
     * kapatan bir yük gönderir. Mesaj bir belge değil, KORUMA.
     */
    #[Test]
    public function the_unwritten_push_methods_warn_about_the_shared_payload(): void
    {
        $adapter = $this->adapter();

        try {
            $adapter->pushInventory(new InventoryPushBatch(channelConnectionId: 'c1', items: []));
            $this->fail('istisna bekleniyordu');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('AYNI', $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────── uç noktalar

    /**
     * YER TUTUCU ADIYLA DOLDURULUR, KONUMLA DEĞİL.
     *
     * Konumla eşleştirme `{merchantId}` ve `{merchantSku}`'nun sırası
     * değiştiğinde sessizce yanlış değeri yazar ve istek BAŞKA bir
     * satıcının SKU'suna giderdi (toplu içe aktarmadaki "kolonlar ADIYLA
     * eşlenir" kuralının aynısı).
     */
    #[Test]
    public function endpoint_placeholders_are_filled_by_name(): void
    {
        $path = HepsiburadaEndpoints::path(
            HepsiburadaEndpoints::LISTING_UPDATE,
            ['merchantId' => 'M1', 'merchantSku' => 'TSH-001'],
        );

        $this->assertSame('/listings/merchantid/M1/sku/TSH-001', $path);
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
            HepsiburadaEndpoints::LISTING_UPDATE,
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
            HepsiburadaEndpoints::LISTING_UPDATE,
            ['merchantId' => 'M1', 'merchantSku' => 'A/B C'],
        );

        $this->assertSame('/listings/merchantid/M1/sku/A%2FB%20C', $path);
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

    private function adapter(
        string $merchantId = 'MERCHANT-1',
        array $secrets = ['api_key' => 'k', 'api_secret' => 's'],
    ): HepsiburadaAdapter {
        [$tenant] = $this->makeTenant();

        return $this->asTenant($tenant, function () use ($merchantId, $secrets): HepsiburadaAdapter {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'hepsiburada',
                'external_account_id' => $merchantId,
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
                    'catalog' => false, 'inventory' => true, 'pricing' => true,
                    'orders' => true, 'taxonomy' => false, 'approval' => false,
                    'fulfillment' => false,
                ],
                'rate_limit_profile' => [],
                'supports_webhooks' => true,
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
