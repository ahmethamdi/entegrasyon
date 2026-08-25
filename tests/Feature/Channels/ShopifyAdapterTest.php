<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Adapters\Shopify\ShopifyEndpoints;
use App\Domain\Channels\Adapters\Shopify\ShopifyGraphqlException;
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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * ShopifyAdapter — dördüncü kanal, projede ilk GraphQL kanalı.
 *
 * V3.0 · §06 · Faz 1 · slice 1.1–1.2.
 *
 * ⚠️ EN KRİTİK İDDİA (P0-1 · T-V3-11): GRAPHQL 200 DÖNER AMA BAŞARISIZ
 * OLABİLİR. REST'te hata HTTP kodudur; GraphQL'de her şey 200'dür ve hata
 * gövdede `errors` / `userErrors` altında yaşar. `$response->throw()` bunu
 * GÖRMEZ.
 *
 * Kontrol edilmezse `SyncResultRecorder` BAŞARI yazar, `synced_version`
 * ilerler ve kanalda hiçbir şey değişmemişken satır "senkron" görünür.
 * Mutabakat farkı bulur, onarım açar, o da aynı sessiz başarıyla döner —
 * SONSUZ ONARIM DÖNGÜSÜ. Woo'nun `manage_stock` tuzağının aynısı.
 *
 * MİMARİ NOT: bu adapter v2.2'den BİLİNÇLİ bir sapmadır (§06.1). Doküman
 * Shopify'ı ayrı Remix/Node servisi olarak öngörüyor; V3.0 onaylanmış proje
 * kararıyla Laravel adapter yazıyor. §11'in servis token'ı değişmezi İPTAL
 * EDİLMEDİ, ERTELENDİ.
 */
final class ShopifyAdapterTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────── P0-1 · GraphQL hata kanalı

    /**
     * ⚠️ P0-1 · T-V3-11 — `userErrors` DOLU İSE OPERASYON BAŞARISIZDIR.
     *
     * Bu testin varlık sebebi: yanıt HTTP 200'dür ve `errors` boştur.
     * Yalnızca `throw()` çağıran bir kod bunu BAŞARI sayardı.
     */
    #[Test]
    public function a_200_response_carrying_user_errors_is_a_failure(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'productUpdate' => [
                        'product' => null,
                        'userErrors' => [
                            ['field' => ['input', 'title'], 'message' => 'Title can\'t be blank'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->expectException(ShopifyGraphqlException::class);

        $this->adapter()->gql(
            'mutation { productUpdate { userErrors { field message } } }',
            operation: 'productUpdate',
            userErrorPath: 'productUpdate',
        );
    }

    /**
     * Hata mesajı ALAN YOLUNU taşır.
     *
     * "Geçersiz değer" tek başına satıcıya ne yapacağını söylemez; mesaj
     * `sync_attempts.error_message`'a kalıcı yazılır ve ölü mektup ekranında
     * görünür (§12 · "ne yapmalı" kuralı).
     */
    #[Test]
    public function the_exception_message_names_the_offending_field(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'productUpdate' => [
                        'userErrors' => [
                            ['field' => ['input', 'title'], 'message' => 'Title can\'t be blank'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        try {
            $this->adapter()->gql(
                'mutation { productUpdate { userErrors { field message } } }',
                operation: 'productUpdate',
                userErrorPath: 'productUpdate',
            );
            $this->fail('İstisna fırlatılmadı.');
        } catch (ShopifyGraphqlException $e) {
            $this->assertStringContainsString('input.title', $e->getMessage());
            $this->assertStringContainsString('Title can\'t be blank', $e->getMessage());
            $this->assertTrue($e->isUserError);
        }
    }

    /**
     * TAŞIMA hatası (`errors`) da yakalanır — ayrı kanaldır.
     *
     * Sorgu bozuksa veya alan yoksa Shopify `errors` döner ve `data` null
     * olur. Yalnızca `userErrors` kontrol edilseydi bu sessizce boş `data`
     * olarak geçer ve adapter "başarılı ama veri yok" sanırdı.
     */
    #[Test]
    public function a_transport_level_graphql_error_is_also_caught(): void
    {
        Http::fake([
            '*' => Http::response([
                'errors' => [['message' => "Field 'bogus' doesn't exist on type 'Shop'"]],
            ], 200),
        ]);

        $this->expectException(ShopifyGraphqlException::class);
        $this->expectExceptionMessageMatches('/bogus/');

        $this->adapter()->gql('query { shop { bogus } }', operation: 'ShopHealth');
    }

    /**
     * TEMİZ yanıt `data` bloğunu döner — hata kanalları BOŞ olduğunda.
     *
     * Kontroller o kadar geniş yazılmamalıdır ki meşru yanıtı da reddetsin;
     * `errors: []` ve `userErrors: []` BAŞARIDIR.
     */
    #[Test]
    public function a_clean_response_returns_the_data_block(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'productUpdate' => [
                        'product' => ['id' => 'gid://shopify/Product/1'],
                        'userErrors' => [],
                    ],
                ],
            ], 200),
        ]);

        $data = $this->adapter()->gql(
            'mutation { productUpdate { userErrors { message } } }',
            operation: 'productUpdate',
            userErrorPath: 'productUpdate',
        );

        $this->assertSame('gid://shopify/Product/1', $data['productUpdate']['product']['id']);
    }

    /**
     * `userErrors` YOLU VERİLMEZSE o kontrol atlanır — query'ler için.
     *
     * Query'ler `userErrors` taşımaz; uydurma bir yol aramak her query'yi
     * kırardı. Yol adapter tarafından mutation başına verilir.
     */
    #[Test]
    public function queries_without_a_user_error_path_are_not_broken(): void
    {
        Http::fake([
            '*' => Http::response(['data' => ['shop' => ['id' => 'gid://shopify/Shop/1']]], 200),
        ]);

        $data = $this->adapter()->gql('query { shop { id } }', operation: 'ShopHealth');

        $this->assertSame('gid://shopify/Shop/1', $data['shop']['id']);
    }

    /**
     * `userErrors` KALICI hata sınıfına düşer (`VALIDATION`).
     *
     * İş kuralı ihlalidir; yeniden denemek AYNI sonucu verir ve yalnızca
     * kotayı harcar. Geçici sayılsaydı operasyon beş kez denenir, beş kez
     * aynı hatayı alır ve ancak sonunda ölürdü.
     */
    #[Test]
    public function user_errors_are_classified_as_permanent(): void
    {
        $adapter = $this->adapter();

        $this->assertSame(
            ErrorClass::VALIDATION,
            $adapter->classifyError(new ShopifyGraphqlException('productUpdate', [], true)),
        );
    }

    // ─────────────────────────────────────────────────────── kimlik · başlık

    /**
     * ⚠️ KİMLİK `X-Shopify-Access-Token` BAŞLIĞIYLA GİDER — BEARER DEĞİL.
     *
     * `ChannelHttpClient`'ın `access_token` → `withToken()` yolu Bearer
     * üretir ve Shopify onu KABUL ETMEZ. Bearer gönderilseydi kanal 401
     * döner, `AUTHENTICATION` KALICI sayılır ve listing "anahtarın yanlış"
     * diyerek ölürdü — oysa anahtar doğrudur (`97a7eb7` hata biçimi).
     */
    #[Test]
    public function requests_carry_the_shopify_access_token_header(): void
    {
        Http::fake(['*' => Http::response(['data' => ['shop' => ['id' => 'gid://x/1']]], 200)]);

        $this->adapter(secrets: ['access_token' => 'shpat_GIZLI'])
            ->gql('query { shop { id } }', operation: 'ShopHealth');

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('X-Shopify-Access-Token', 'shpat_GIZLI');
        });
    }

    /**
     * ⚠️ TOKEN YOKSA İSTEK HİÇ ATILMAZ.
     *
     * Boş başlıkla giden istek 401 alır, `AUTHENTICATION` KALICI sayılır ve
     * listing "anahtarın yanlış" damgasıyla ölür — oysa anahtar YOKTUR,
     * yanlış değildir. Hepsiburada'daki "satıcı kimliği yoksa istek
     * atılmaz" kuralının aynısı.
     */
    #[Test]
    public function no_request_is_sent_when_the_access_token_is_missing(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/token tanımsız/');

        $this->adapter(secrets: [])->gql('query { shop { id } }', operation: 'ShopHealth');

        Http::assertNothingSent();
    }

    /**
     * İstek mağazanın KENDİ alan adına ve SÜRÜMLÜ yola gider.
     *
     * Shopify'da tek API ana bilgisayarı YOKTUR; her mağaza kendi alt alan
     * adına sahiptir (§06.2).
     */
    #[Test]
    public function the_request_targets_the_shop_domain_and_a_versioned_path(): void
    {
        Http::fake(['*' => Http::response(['data' => ['shop' => ['id' => 'gid://x/1']]], 200)]);

        $this->adapter(shopDomain: 'benim-magazam.myshopify.com')
            ->gql('query { shop { id } }', operation: 'ShopHealth');

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'https://benim-magazam.myshopify.com/')
                && str_contains($request->url(), ShopifyEndpoints::API_VERSION)
                && str_ends_with($request->url(), '/graphql.json');
        });
    }

    // ─────────────────────────────────────────────────────────── sağlık

    /**
     * Sağlıklı mağaza + seçili konum → `healthy`.
     */
    #[Test]
    public function health_check_passes_when_the_shop_answers_and_a_location_is_set(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => ['shop' => ['id' => 'gid://shopify/Shop/1', 'name' => 'Test']],
            ], 200),
        ]);

        $health = $this->adapter(settings: [
            ShopifyAdapter::LOCATION_KEY => 'gid://shopify/Location/12',
        ])->healthCheck();

        $this->assertSame('healthy', $health->status());
    }

    /**
     * ⚠️ P1-5 — KONUM SEÇİLMEMİŞSE BAĞLANTI SAĞLIKSIZDIR.
     *
     * Shopify bir mağazada birden çok konum destekler ve stok yazma
     * `location_gid` ister. Varsayılanı SESSİZCE seçmek, iki depolu bir
     * satıcının stoğunu YANLIŞ DEPOYA yazardı — geri alınamaz ve satıcı
     * bunu ancak siparişler yanlış depodan çıkınca fark eder.
     *
     * Sağlık kontrolü geçmeden bağlantı `active` OLMAZ (v2.2 · §13).
     */
    #[Test]
    public function health_check_fails_when_no_location_is_selected(): void
    {
        Http::fake([
            '*' => Http::response(['data' => ['shop' => ['id' => 'gid://shopify/Shop/1']]], 200),
        ]);

        $health = $this->adapter(settings: [])->healthCheck();

        $this->assertSame('unhealthy', $health->status());
    }

    // ────────────────────────────────────────────────────────── webhook

    /**
     * ⚠️ P0-6 — HMAC HAM GÖVDE ÜZERİNDEN doğrulanır.
     *
     * Ayrıştırıp yeniden serileştirmek baytları değiştirir (anahtar sırası,
     * boşluk, sayı biçimi) ve imza tutmaz.
     */
    #[Test]
    public function a_valid_hmac_over_the_raw_body_is_accepted(): void
    {
        $raw = '{"id":12345,"total_price":"99.90"}';
        $secret = 'shpss_WEBHOOK_SIRRI';

        $adapter = $this->adapter(secrets: [
            'access_token' => 'shpat_x',
            'webhook_secret' => $secret,
        ]);

        $signature = base64_encode(hash_hmac('sha256', $raw, $secret, true));

        $this->assertTrue($adapter->verifyWebhookSignature($raw, [
            'X-Shopify-Hmac-Sha256' => [$signature],
        ]));
    }

    /**
     * BAŞLIK ADI BÜYÜK/KÜÇÜK HARF DUYARSIZ okunur.
     *
     * Vekil sunucular başlıkları yeniden yazar; tam eşleşme aransaydı MEŞRU
     * webhook reddedilir ve kanal sonsuza kadar yeniden gönderirdi.
     */
    #[Test]
    public function the_signature_header_is_read_case_insensitively(): void
    {
        $raw = '{"id":1}';
        $secret = 's3cr3t';

        $adapter = $this->adapter(secrets: [
            'access_token' => 'shpat_x',
            'webhook_secret' => $secret,
        ]);

        $signature = base64_encode(hash_hmac('sha256', $raw, $secret, true));

        $this->assertTrue($adapter->verifyWebhookSignature($raw, [
            'x-shopify-hmac-sha256' => [$signature],
        ]));
    }

    /** Gövde değişmişse imza TUTMAZ. */
    #[Test]
    public function a_tampered_body_fails_verification(): void
    {
        $secret = 's3cr3t';

        $adapter = $this->adapter(secrets: [
            'access_token' => 'shpat_x',
            'webhook_secret' => $secret,
        ]);

        $signature = base64_encode(hash_hmac('sha256', '{"id":1}', $secret, true));

        $this->assertFalse($adapter->verifyWebhookSignature('{"id":2}', [
            'X-Shopify-Hmac-Sha256' => [$signature],
        ]));
    }

    /**
     * ⚠️ WEBHOOK SIRRI YOKSA DOĞRULAMA "GEÇTİ" DEMEZ.
     *
     * Güvenli taraf REDDETMEKTİR; kabul etmek imzasız sipariş enjeksiyonuna
     * kapı açardı (Hepsiburada'daki kuralın aynısı).
     */
    #[Test]
    public function verification_fails_when_no_webhook_secret_is_configured(): void
    {
        $adapter = $this->adapter(secrets: ['access_token' => 'shpat_x']);

        $raw = '{"id":1}';

        $this->assertFalse($adapter->verifyWebhookSignature($raw, [
            'X-Shopify-Hmac-Sha256' => [base64_encode(hash_hmac('sha256', $raw, 'x', true))],
        ]));
    }

    /**
     * Olay kimliği başlıktan okunur — v2.2 §6 tablosunda ZATEN kayıtlı.
     *
     * Türetilmiş kimliğe (`{id}:{status}`) gerek yoktur çünkü Shopify
     * gerçek bir olay kimliği veriyor.
     */
    #[Test]
    public function the_event_id_comes_from_the_shopify_header(): void
    {
        $adapter = $this->adapter();

        $this->assertSame('evt_123', $adapter->extractEventId([
            'X-Shopify-Event-Id' => ['evt_123'],
        ]));

        $this->assertSame('orders/create', $adapter->extractEventType([
            'X-Shopify-Topic' => ['orders/create'],
        ]));

        $this->assertSame('magaza.myshopify.com', $adapter->extractShopDomain([
            'X-Shopify-Shop-Domain' => ['magaza.myshopify.com'],
        ]));
    }

    // ────────────────────────────────────────────────────── hız sınırı

    /**
     * SINIR YANIT GÖVDESİNDEN ÖĞRENİLİR (§06.8).
     *
     * Shopify'da sınır istek sayısı DEĞİL SORGU MALİYETİDİR ve Plus
     * mağazalarında kova 2.000 puandır. Sabit profil Plus'ı yavaşlatır,
     * standardı 429'a sokardı.
     */
    #[Test]
    public function the_rate_limit_is_learned_from_the_response_body(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => ['shop' => ['id' => 'gid://x/1']],
                'extensions' => ['cost' => ['throttleStatus' => [
                    'maximumAvailable' => 2000.0,
                    'currentlyAvailable' => 1988.0,
                    'restoreRate' => 100.0,
                ]]],
            ], 200),
        ]);

        $adapter = $this->adapter();
        $response = Http::post('https://x.myshopify.com/graphql.json');

        $this->assertSame(
            ['bucket' => 2000, 'restoreRate' => 100],
            $adapter->learnedRateLimit($response),
        );
    }

    /**
     * ⚠️ SAYI OLMAYAN DEĞER YOK SAYILIR.
     *
     * Trendyol'da vekil sunucunun iki başlığı birleştirmesiyle yaşandı:
     * `(int)` dönüşümü sessizce ilk sayıya iner ve DÜŞÜK sınır yok
     * sayılırdı. Burada bozuk değer null döner ve varsayılan profil kalır.
     */
    #[Test]
    public function a_non_numeric_throttle_status_is_ignored(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [],
                'extensions' => ['cost' => ['throttleStatus' => [
                    'maximumAvailable' => 'bozuk',
                    'restoreRate' => 50.0,
                ]]],
            ], 200),
        ]);

        $response = Http::post('https://x.myshopify.com/graphql.json');

        $this->assertNull($this->adapter()->learnedRateLimit($response));
    }

    // ────────────────────────────────────────────────────── uç noktalar

    /**
     * ⚠️ SÜRÜMSÜZ İSTEK ASLA ATILMAZ.
     *
     * Shopify sürümsüz çağrıyı DESTEKLENEN EN ESKİ sürüme düşürür ve
     * alanlar habersizce kaybolur — yanıt 200 döner, alan yoktur, senkron
     * "başarılı" görünür.
     */
    #[Test]
    public function the_graphql_path_always_carries_an_api_version(): void
    {
        $url = ShopifyEndpoints::graphql('magaza.myshopify.com');

        $this->assertStringContainsString(ShopifyEndpoints::API_VERSION, $url);
        $this->assertStringNotContainsString('{version}', $url);
        $this->assertStringNotContainsString('unstable', $url);
    }

    /**
     * DOLDURULMAMIŞ YER TUTUCU İSTİSNA FIRLATIR.
     *
     * Geçseydi istek literal `{shopId}` içeren bir adrese gider ve 404'ün
     * sebebi hiçbir yerde görünmezdi (§05).
     */
    #[Test]
    public function an_unfilled_placeholder_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Doldurulmamış yer tutucu/');

        ShopifyEndpoints::path('admin/api/{version}/shop/{shopId}.json');
    }

    // ───────────────────────────────────────────── hata sınıflandırma

    #[Test]
    public function network_failures_are_classified_as_network(): void
    {
        $this->assertSame(
            ErrorClass::NETWORK,
            $this->adapter()->classifyError(new ConnectionException('timeout')),
        );
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /**
     * @param  array<string, mixed>  $secrets
     * @param  array<string, mixed>  $settings
     */
    private function adapter(
        string $shopDomain = 'magaza.myshopify.com',
        array $secrets = ['access_token' => 'shpat_test'],
        array $settings = ['location_gid' => 'gid://shopify/Location/12'],
    ): ShopifyAdapter {
        $tenant = $this->makeTenant();

        return $this->asTenant($tenant, function () use ($shopDomain, $secrets, $settings): ShopifyAdapter {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'shopify',
                'external_account_id' => $shopDomain,
                'settings' => $settings,
            ]);

            if ($secrets !== []) {
                app(CredentialVault::class)->store($connection, $secrets);
            }

            return new ShopifyAdapter(
                $connection,
                new ChannelHttpClient(
                    $connection,
                    app(CredentialVault::class),
                    app(PayloadRedactor::class),
                ),
            );
        });
    }

    private function makeTenant(): Tenant
    {
        $this->channelType();

        return (new CreateTenant)->run(
            name: 'Shopify '.uniqid(),
            owner: User::factory()->create(),
        );
    }

    private function channelType(): ChannelType
    {
        return $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'shopify'],
            [
                'name' => 'Shopify',
                'kind' => 'storefront',
                'adapter_class' => ShopifyAdapter::class,
                'supports_webhooks' => true,
                'is_active' => false,
            ],
        ));
    }
}
