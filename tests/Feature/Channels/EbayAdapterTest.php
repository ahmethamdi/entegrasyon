<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Ebay\EbayAdapter;
use App\Domain\Channels\Adapters\Ebay\EbayEndpoints;
use App\Domain\Channels\Contracts\SupportsApprovalWorkflow;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Contracts\SupportsFulfillment;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOfferLifecycle;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Contracts\SupportsTaxonomy;
use App\Domain\Channels\Contracts\SupportsTokenRefresh;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Enums\ErrorClass;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * eBay adapter — slice 4.1 (bağlantı + kimlik + sağlık + token yenileme).
 *
 * V3.0 · §13.3 · §17 · §20 · §21 · P0-5.
 */
final class EbayAdapterTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────── §17 · sağlık ve yapılandırma

    /**
     * ⚠️ POLİTİKA ÜÇLÜSÜ EKSİKSE BAĞLANTI SAĞLIKSIZDIR — kanal
     * çalışıyor OLSA BİLE.
     *
     * Eksik politika offer yaratmada `VALIDATION` üretir ve o hata
     * KALICIDIR; listing "düzeltilemez" damgasıyla ölür. Sağlıklı
     * sayılsaydı bağlantı `active` olur, satıcı ürün göndermeye başlar
     * ve HER ürün kalıcı hatayla ölürdü — "aktif ama çalışmayan bağlantı
     * en pahalı hata biçimidir" kuralının tam vakası.
     */
    #[Test]
    public function a_missing_policy_triple_makes_the_connection_unhealthy(): void
    {
        Http::fake(['*' => Http::response(['sellingLimit' => ['amount' => 1]], 200)]);

        $result = $this->adapter(settings: [
            'merchant_location_key' => 'WAREHOUSE-1',
            'marketplace_id' => 'EBAY_DE',
            // politika üçlüsü YOK
        ])->healthCheck();

        $this->assertFalse($result->healthy);
    }

    /**
     * ⚠️ EKSİK ALAN ADIYLA SÖYLENİR, SAYIYLA DEĞİL.
     *
     * "Üç alan eksik" demek satıcıya ne yapacağını söylemez
     * (eşleştirme ekranındaki "eksik zorunlu öznitelik ADIYLA
     * gösterilir" kuralının aynısı).
     */
    #[Test]
    public function the_health_message_names_every_missing_setting(): void
    {
        Http::fake(['*' => Http::response(['sellingLimit' => []], 200)]);

        $result = $this->adapter(settings: [])->healthCheck();

        foreach ([
            'merchant_location_key',
            'marketplace_id',
            'fulfillment_policy_id',
            'payment_policy_id',
            'return_policy_id',
        ] as $key) {
            $this->assertStringContainsString(
                $key,
                (string) $result->message,
                "Eksik alan `{$key}` mesajda ADIYLA geçmiyor.",
            );
        }
    }

    /**
     * ⚠️ YAPILANDIRMA EKSİKKEN KANALA İSTEK HİÇ ATILMAZ.
     *
     * Atılsaydı hem boşuna kota harcanır hem de kanalın 200'ü
     * "sağlıklı" izlenimi verirdi; oysa eksiklik BİZDEDİR ve kanalın
     * cevabı onu düzeltmez.
     */
    #[Test]
    public function no_request_is_sent_while_configuration_is_missing(): void
    {
        Http::fake(['*' => Http::response(['sellingLimit' => []], 200)]);

        $this->adapter(settings: [])->healthCheck();

        Http::assertNothingSent();
    }

    /** Yapılandırma tamsa ve kanal cevap veriyorsa bağlantı sağlıklıdır. */
    #[Test]
    public function a_fully_configured_connection_is_healthy(): void
    {
        Http::fake(['*' => Http::response(['sellingLimit' => ['amount' => 5]], 200)]);

        $this->assertTrue($this->adapter()->healthCheck()->healthy);
    }

    /**
     * ⚠️ 200 TEK BAŞINA YETMEZ — gövde beklenen alanı taşımalıdır.
     *
     * Vekil sunucu veya bakım sayfası 200 döndürebilir; alan kontrolü
     * olmasaydı bağlantı "sağlıklı" sayılır ve ilk gerçek çağrıda
     * ölürdü.
     */
    #[Test]
    public function a_two_hundred_without_the_expected_field_is_unhealthy(): void
    {
        Http::fake(['*' => Http::response(['bakim' => true], 200)]);

        $this->assertFalse($this->adapter()->healthCheck()->healthy);
    }

    /**
     * ⚠️ SANDBOX AYRI ANA BİLGİSAYARDIR ve VARSAYILAN ÜRETİMDİR.
     *
     * Varsayılan sandbox olsaydı satıcının gerçek mağazası yerine boş
     * bir test hesabına yazılır ve "senkron başarılı" görünürken hiçbir
     * şey değişmezdi.
     */
    #[Test]
    public function requests_go_to_production_unless_the_sandbox_flag_is_set(): void
    {
        Http::fake(['*' => Http::response(['sellingLimit' => []], 200)]);

        $this->adapter()->healthCheck();

        Http::assertSent(static fn ($request): bool => str_starts_with(
            $request->url(),
            'https://api.ebay.com/',
        ));
    }

    #[Test]
    public function the_sandbox_flag_switches_the_host(): void
    {
        Http::fake(['*' => Http::response(['sellingLimit' => []], 200)]);

        $this->adapter(settings: [...$this->fullSettings(), 'use_sandbox' => true])->healthCheck();

        Http::assertSent(static fn ($request): bool => str_starts_with(
            $request->url(),
            'https://api.sandbox.ebay.com/',
        ));
    }

    // ────────────────────────────────────────────── §21 · sınıflandırma

    /**
     * ⚠️ `25xxx` İŞ KURALI HATALARI KALICIDIR — HTTP DURUMU NE OLURSA
     * OLSUN.
     *
     * eBay bu aileyi 500 ile de döndürebilir. Yalnızca duruma bakılsaydı
     * `25002` (duplicate offer) GEÇİCİ sayılır, iş sonsuza kadar yeniden
     * denenir ve her denemede aynı duplicate hatası alınırdı.
     *
     * `25002` özellikle kritiktir: "bu SKU için offer ZATEN VAR"
     * demektir ve tekrar denemek DÜZELTMEZ — düzelten şey
     * `channel_metadata`'daki `offer_id`'yi okuyup kaldığı yerden devam
     * etmektir (§13.2 · Delta 1'in varlık sebebi).
     */
    #[Test]
    public function a_business_rule_error_is_permanent_even_inside_a_server_error(): void
    {
        $this->assertSame(
            ErrorClass::VALIDATION,
            $this->classify(500, ['errors' => [['errorId' => 25002]]]),
            '`25002` bir 500 gövdesinde GEÇİCİ sayıldı — iş sonsuza kadar '
            .'yeniden denenir ve her tur aynı duplicate hatasını alırdı.',
        );
    }

    /** Aynı aile 400 içinde de kalıcıdır. */
    #[Test]
    public function a_business_rule_error_is_permanent_inside_a_client_error(): void
    {
        $this->assertSame(
            ErrorClass::VALIDATION,
            $this->classify(400, ['errors' => [['errorId' => 25001]]]),
        );
    }

    /**
     * ⚠️ ARALIK DIŞI HATA KİMLİĞİ İŞ KURALI SAYILMAZ.
     *
     * Sayılsaydı geçici bir sunucu hatası (`errorId` 2000 gibi) kalıcı
     * damgalanır ve listing hiç yeniden denenmeden ölürdü.
     */
    #[Test]
    public function an_error_id_below_the_business_range_keeps_the_status_classification(): void
    {
        $this->assertSame(
            ErrorClass::SERVER_ERROR,
            $this->classify(500, ['errors' => [['errorId' => 2000]]]),
        );
    }

    /**
     * ⚠️ ÜST SINIR DA SÜRÜLÜR — ve bu MUTASYONLA bulundu.
     *
     * İlk yazımda yalnızca ALT sınırın dışı (`2000`) sınanıyordu ve üst
     * sınırı `25999` yerine `99999` yapan mutasyon HAYATTA KALDI: sınır
     * testi tek yönden sürülürse öteki yön ÖLÇÜLMEMİŞ olur.
     *
     * `26000` gerçek bir aileye aittir (eBay'in hata kimlikleri 25xxx'te
     * bitmez); iş kuralı sayılsaydı geçici bir hata kalıcı damgalanır ve
     * listing hiç yeniden denenmeden ölürdü.
     */
    #[Test]
    public function an_error_id_above_the_business_range_keeps_the_status_classification(): void
    {
        $this->assertSame(
            ErrorClass::SERVER_ERROR,
            $this->classify(500, ['errors' => [['errorId' => 26000]]]),
        );
    }

    /** Aralığın İKİ UCU da iş kuralıdır — sınırlar dahildir. */
    #[Test]
    public function both_edges_of_the_business_range_are_permanent(): void
    {
        $this->assertSame(
            ErrorClass::VALIDATION,
            $this->classify(500, ['errors' => [['errorId' => 25000]]]),
        );
    }

    #[Test]
    public function the_upper_edge_of_the_business_range_is_permanent(): void
    {
        $this->assertSame(
            ErrorClass::VALIDATION,
            $this->classify(500, ['errors' => [['errorId' => 25999]]]),
        );
    }

    /**
     * ⚠️ 401 `AUTHENTICATION` DÖNER ve KALICIDIR — ama bu "anahtar
     * yanlış" demek DEĞİLDİR: token 2 SAATLİKTİR ve büyük olasılıkla
     * yalnızca süresi dolmuştur. Düzelten şey `credentials:refresh`
     * taramasıdır (§20).
     *
     * ⚠️ HER DURUM AYRI TESTTİR ve bu ZORUNLUDUR. `Http::fake()` AYNI
     * TESTTE İKİ KEZ ÇAĞRILAMAZ: ikinci çağrı birincinin YERİNE GEÇMEZ
     * ve ilk sahte yanıt kullanılmaya devam eder. Tek testte
     * toplansaydı ilk iddiadan sonrakiler HEP 429 ölçer ve eşleme
     * SAHTE YEŞİL olurdu (bu turda gerçekten yaşandı).
     */
    #[Test]
    public function a_429_is_rate_limited(): void
    {
        $this->assertSame(ErrorClass::RATE_LIMITED, $this->classify(429));
    }

    #[Test]
    public function a_401_is_authentication(): void
    {
        $this->assertSame(ErrorClass::AUTHENTICATION, $this->classify(401));
    }

    #[Test]
    public function a_403_is_authentication(): void
    {
        $this->assertSame(ErrorClass::AUTHENTICATION, $this->classify(403));
    }

    #[Test]
    public function a_404_is_not_found(): void
    {
        $this->assertSame(ErrorClass::NOT_FOUND, $this->classify(404));
    }

    #[Test]
    public function a_409_is_conflict(): void
    {
        $this->assertSame(ErrorClass::CONFLICT, $this->classify(409));
    }

    #[Test]
    public function a_408_is_timeout(): void
    {
        $this->assertSame(ErrorClass::TIMEOUT, $this->classify(408));
    }

    #[Test]
    public function a_503_is_a_server_error(): void
    {
        $this->assertSame(ErrorClass::SERVER_ERROR, $this->classify(503));
    }

    #[Test]
    public function a_422_is_validation(): void
    {
        $this->assertSame(ErrorClass::VALIDATION, $this->classify(422));
    }

    // ──────────────────────────────────────────── §13.3 · token yenileme

    /**
     * ⚠️ YENİLEME İSTEĞİ `Basic` KİMLİKLE GİDER, `Bearer` İLE DEĞİL.
     *
     * Kasada `access_token` bulunduğu için istemci normalde `Bearer`
     * yazardı — ve o token tam da ÖLDÜĞÜ için yenileniyor. Kasa
     * adapter'ın başlığını ezseydi istek ölü token'la gider, 401 alır ve
     * bağlantı "anahtarın yanlış" damgasıyla ölürdü.
     *
     * İddia DEĞER SAYISINA bakar: kasa kendi değerini aynı anahtarın
     * yanına EKLİYOR ve tek bir değerin varlığını sınamak bunu
     * göremezdi (`ChannelHttpClientTest`'te mutasyonla bulundu).
     */
    #[Test]
    public function the_refresh_request_authenticates_with_basic_not_bearer(): void
    {
        Http::fake(['*' => Http::response([
            'access_token' => 'yeni-access',
            'expires_in' => 7200,
        ], 200)]);

        $this->adapter()->refreshCredentials();

        Http::assertSent(static function ($request): bool {
            $values = [];

            foreach ($request->headers() as $name => $sent) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $values = [...$values, ...$sent];
                }
            }

            return $values === ['Basic '.base64_encode('app-id:cert-id')];
        });
    }

    /**
     * ⚠️ GÖVDE FORM-ENCODED GİDER (RFC 6749 · §4.1.3).
     *
     * JSON gönderilseydi eBay alanları HİÇ okumaz, `invalid_request`
     * döner ve sebebi gövdede görünmezdi. Etsy'nin uç noktası JSON'u da
     * kabul ettiği için bu fark beşinci kanalda görünmemişti.
     */
    #[Test]
    public function the_refresh_request_body_is_form_encoded(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 'yeni', 'expires_in' => 7200], 200)]);

        $this->adapter()->refreshCredentials();

        Http::assertSent(static fn ($request): bool => str_contains(
            (string) $request->header('Content-Type')[0],
            'application/x-www-form-urlencoded',
        ) && str_contains($request->body(), 'grant_type=refresh_token'));
    }

    /**
     * ⚠️ REFRESH TOKEN YANITTA GELMEZSE ESKİSİ KORUNUR.
     *
     * eBay'de refresh token 18 ay SABİT ömürlüdür ve yenileme onu
     * TAZELEMEZ; o alan yanıtta genellikle HİÇ GELMEZ. Körlemesine
     * üzerine yazılsaydı refresh token NULL olur ve bağlantı BİR
     * SONRAKİ turda ölürdü.
     */
    #[Test]
    public function the_existing_refresh_token_survives_a_response_without_one(): void
    {
        Http::fake(['*' => Http::response([
            'access_token' => 'yeni-access',
            'expires_in' => 7200,
        ], 200)]);

        $refreshed = $this->adapter()->refreshCredentials();

        $this->assertSame('eski-refresh', $refreshed->secrets['refresh_token']);
        $this->assertSame('yeni-access', $refreshed->secrets['access_token']);
    }

    /** Yanıt yeni bir refresh token taşıyorsa O yazılır. */
    #[Test]
    public function a_returned_refresh_token_replaces_the_old_one(): void
    {
        Http::fake(['*' => Http::response([
            'access_token' => 'yeni-access',
            'refresh_token' => 'yeni-refresh',
            'expires_in' => 7200,
        ], 200)]);

        $this->assertSame(
            'yeni-refresh',
            $this->adapter()->refreshCredentials()->secrets['refresh_token'],
        );
    }

    /**
     * ⚠️ İSTEMCİ SIRRI DA KORUNUR.
     *
     * `client_id`/`client_secret` yenileme yanıtında GELMEZ ve sır
     * kümesi TAM yazıldığı için düşerlerse bir sonraki yenileme
     * kimliksiz kalırdı.
     */
    #[Test]
    public function the_client_credentials_survive_the_refresh(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 'yeni', 'expires_in' => 7200], 200)]);

        $secrets = $this->adapter()->refreshCredentials()->secrets;

        $this->assertSame('app-id', $secrets['client_id']);
        $this->assertSame('cert-id', $secrets['client_secret']);
    }

    /**
     * ⚠️ `expires_in` YOKSA NULL DÖNER, UYDURULMAZ.
     *
     * Varsayılan bir süre yazılsaydı ve eBay onu değiştirseydi tarama
     * token'ı ya çok geç ya hiç yenilerdi; ikisi de bağlantıyı öldürür.
     */
    #[Test]
    public function a_missing_expiry_is_null_not_invented(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 'yeni'], 200)]);

        $this->assertNull($this->adapter()->refreshCredentials()->expiresAt);
    }

    /** Süre verilmişse mutlak ana çevrilir. */
    #[Test]
    public function the_expiry_is_converted_to_an_absolute_moment(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 'y', 'expires_in' => 7200], 200)]);

        $expiresAt = $this->adapter()->refreshCredentials()->expiresAt;

        $this->assertNotNull($expiresAt);
        $this->assertEqualsWithDelta(time() + 7200, $expiresAt->getTimestamp(), 5.0);
    }

    /**
     * ⚠️ REFRESH TOKEN YOKSA İSTEK HİÇ ATILMAZ.
     *
     * Atılsaydı kanal 400 döner ve sebep "geçersiz istek" görünürdü;
     * oysa gerçek sebep bağlantının YENİDEN YETKİLENDİRİLMESİ
     * gerektiğidir ve satıcının yapması gereken iş tamamen farklıdır.
     */
    #[Test]
    public function a_missing_refresh_token_throws_instead_of_calling_the_channel(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->expectException(RuntimeException::class);

        $this->adapter(refreshToken: null)->refreshCredentials();
    }

    /**
     * ⚠️ İSTEMCİ KİMLİĞİ YOKSA İSTEK HİÇ ATILMAZ (`97a7eb7` biçimi).
     *
     * Boş kimlikle giden istek 401 alır, `AUTHENTICATION` KALICI sayılır
     * ve bağlantı "anahtarın yanlış" diyerek ölür — oysa anahtar YOKTUR,
     * yanlış değildir.
     */
    #[Test]
    public function a_missing_client_id_throws_instead_of_sending_an_anonymous_request(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->expectException(RuntimeException::class);

        $this->adapter(clientId: null)->refreshCredentials();
    }

    /**
     * ⚠️ YANIT ACCESS TOKEN TAŞIMIYORSA İSTİSNA — sessiz başarı YOKTUR.
     *
     * `RefreshedCredentials` boş access token'la dönseydi kasaya boş bir
     * token yazılır ve bağlantı SESSİZCE ölürdü.
     */
    #[Test]
    public function a_response_without_an_access_token_throws(): void
    {
        Http::fake(['*' => Http::response(['expires_in' => 7200], 200)]);

        $this->expectException(RuntimeException::class);

        $this->adapter()->refreshCredentials();
    }

    /**
     * ⚠️ PAY TARAMA SIKLIĞINDAN KÜÇÜK OLAMAZ.
     *
     * Tarama 15 dakikada bir koşar (§20); pay daha KISA olsaydı token
     * iki tur arasında hem "henüz aday değil" hem "artık ölmüş"
     * olabilirdi ve o aralıktaki her çağrı 401 alırdı.
     */
    #[Test]
    public function the_refresh_lead_is_not_shorter_than_the_scan_interval(): void
    {
        $this->assertGreaterThanOrEqual(900, $this->adapter()->refreshLeadSeconds());
    }

    // ────────────────────────────────────────────────────── §25 · ölçüm

    /**
     * ⚠️ TOKEN UÇ NOKTASI SÜZGECİ OLMADAN HER 4xx "TOKEN HATASI" SAYILIR.
     *
     * `api_calls` kanala giden HER çağrıyı taşır; süzülmezse başarısız
     * bir stok itmesi "token yenilenemedi" sayılır, satıcıya yeniden
     * yetkilendirme yaptırılır ve gerçek sorun HİÇ görünmez.
     */
    #[Test]
    public function the_token_endpoint_fragment_matches_the_real_path(): void
    {
        $this->assertSame(EbayEndpoints::TOKEN, $this->adapter()->tokenEndpointFragment());
    }

    /** Günlük tavan bildirilir — §25 kota metriği bunu okur. */
    #[Test]
    public function a_daily_request_quota_is_declared(): void
    {
        $this->assertSame(5_000, $this->adapter()->dailyRequestQuota());
    }

    // ──────────────────────────────────────── §13.6 · webhook YOKTUR

    /**
     * ⚠️ SİPARİŞ WEBHOOK'U YOKTUR — DAİMA `false`.
     *
     * `true` dönmek eBay adına İMZASIZ SİPARİŞ ENJEKTE etmenin kapısını
     * açardı (Trendyol ve Etsy'deki kararın aynısı).
     */
    #[Test]
    public function webhook_signature_verification_always_fails(): void
    {
        $this->assertFalse($this->adapter()->verifyWebhookSignature('{}', []));
        $this->assertNull($this->adapter()->extractEventId([]));
        $this->assertSame('unknown', $this->adapter()->extractEventType([]));
    }

    // ──────────────────────────────────── §05 · yazılmamış yetenekler

    /**
     * ⚠️ YAZILMAMIŞ YETENEK İLAN EDİLMEZ (§05).
     *
     * Uygulanmamış bir arayüz panelde ÇALIŞMAYAN bir sekme açar. Bu
     * liste slice kapandıkça KÜÇÜLÜR; yazılan bir yetenek listeden
     * çıkarılmazsa test YANLIŞ SEBEPLE kırmızıya döner (Trendyol'daki
     * kuralın aynısı).
     *
     * `SupportsOfferLifecycle` burada YOKTUR çünkü arayüz henüz HİÇ
     * yazılmadı (slice 4.3); yazıldığında bu listeye eklenecek ve 4.4'te
     * çıkarılacak.
     */
    #[Test]
    public function unwritten_capabilities_are_not_declared(): void
    {
        $adapter = $this->adapter();

        foreach ([
            // ⚠️ `SupportsCatalog` BU LİSTEDEN HİÇ ÇIKMAYACAK ve bu bir
            // eksiklik DEĞİLDİR. O arayüz yayını TEK ÇAĞRI varsayar;
            // eBay'de yayın ÜÇ ADIMDIR ve ara kimlik saklanmazsa
            // idempotency kaybolur (§03 · Delta 1). Yerini
            // `SupportsOfferLifecycle` aldı.
            SupportsCatalog::class,
            SupportsCatalogImport::class,
            SupportsInventory::class,
            SupportsPricing::class,
            SupportsOrders::class,
            SupportsFulfillment::class,
            SupportsApprovalWorkflow::class,
        ] as $capability) {
            $this->assertNotInstanceOf(
                $capability,
                $adapter,
                "`{$capability}` ilan edilmiş ama gövdesi YOK — "
                .'panelde çalışmayan bir sekme açardı (§05).',
            );
        }
    }

    /** Slice 4.1'de yazılan TEK yetenek token yenilemedir. */
    #[Test]
    public function token_refresh_is_declared(): void
    {
        $this->assertInstanceOf(SupportsTokenRefresh::class, $this->adapter());
    }

    /** Slice 4.4 — üç adımlı yayın zinciri artık UYGULANMIŞTIR. */
    #[Test]
    public function the_offer_lifecycle_capability_is_declared(): void
    {
        $this->assertInstanceOf(SupportsOfferLifecycle::class, $this->adapter());
    }

    /** Slice 4.5 — taksonomi artık UYGULANMIŞTIR. */
    #[Test]
    public function the_taxonomy_capability_is_declared(): void
    {
        $this->assertInstanceOf(SupportsTaxonomy::class, $this->adapter());
    }

    // ──────────────────────────────────────────────────────── yardımcılar

    /** @param array<string, mixed>|null $body */
    private function classify(int $status, ?array $body = null): ErrorClass
    {
        Http::fake(['*' => Http::response($body ?? ['hata' => true], $status)]);

        $adapter = $this->adapter();

        try {
            $adapter->connection()->refresh();
            Http::get('https://api.ebay.com/probe')->throw();
        } catch (RequestException $e) {
            return $adapter->classifyError($e);
        }

        $this->fail('Beklenen istisna fırlatılmadı.');
    }

    /** @return array<string, string> */
    private function fullSettings(): array
    {
        return [
            'merchant_location_key' => 'WAREHOUSE-1',
            'marketplace_id' => 'EBAY_DE',
            'fulfillment_policy_id' => 'FP-1',
            'payment_policy_id' => 'PP-1',
            'return_policy_id' => 'RP-1',
        ];
    }

    /** @param array<string, mixed>|null $settings */
    private function adapter(
        ?array $settings = null,
        ?string $refreshToken = 'eski-refresh',
        ?string $clientId = 'app-id',
    ): EbayAdapter {
        $tenant = $this->makeTenant();

        $connection = $this->asTenant($tenant, function () use ($settings, $refreshToken, $clientId): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'ebay',
                'external_account_id' => 'ebay-seller-'.uniqid(),
                'status' => 'active',
                // ⚠️ `settings` ŞİFRESİZDİR — buraya YALNIZCA YAPILANDIRMA
                // yazılır (§19 · madde 4). Token'lar ve istemci sırrı
                // kasadadır.
                'settings' => $settings ?? $this->fullSettings(),
            ]);

            app(CredentialVault::class)->store($connection, array_filter([
                'client_id' => $clientId,
                'client_secret' => 'cert-id',
                'access_token' => 'olu-access',
                'refresh_token' => $refreshToken,
            ], static fn (mixed $v): bool => $v !== null));

            return $connection;
        });

        return $this->asTenant($tenant, fn (): EbayAdapter => new EbayAdapter(
            $connection,
            new ChannelHttpClient(
                $connection,
                app(CredentialVault::class),
                app(PayloadRedactor::class),
            ),
        ));
    }

    private function makeTenant(): Tenant
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'ebay'],
            [
                'name' => 'eBay',
                'kind' => 'marketplace',
                'adapter_class' => EbayAdapter::class,
                // ⚠️ SİPARİŞ WEBHOOK'U YOKTUR (§13.6) — yoklamayla gelir.
                // `true` olsaydı yoklama turu bu kanalı ATLAR ve
                // siparişler HİÇ GELMEZDİ.
                'supports_webhooks' => false,
                // ⚠️ KANAL KAPALI BAŞLAR — açılma kararı slice 4.9'da.
                'is_active' => false,
            ],
        ));

        return (new CreateTenant)->run(
            name: 'eBay '.uniqid(),
            owner: User::factory()->create(),
        );
    }
}
