<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Contracts\SupportsTaxonomy;
use App\Domain\Channels\Contracts\SupportsTokenRefresh;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Enums\ErrorClass;
use App\Support\Logging\PayloadRedactor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Etsy adapter — slice 3.1 (bağlantı + kimlik + sağlık + token yenileme).
 *
 * V3.0 · §11.2 · §20 · §21 · P0-5 · T-V3-15 · v2.2 §7.
 */
final class EtsyAdapterTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────────── §11.2 · iki başlık

    /**
     * ⚠️ İKİ AYRI KİMLİK BAŞLIĞI GİDER — biri eksikse 401.
     *
     * `Bearer` SATICININ kimliğidir ve yenilenir; `x-api-key` UYGULAMANIN
     * kimliğidir ve yenilenmez. İkisi karıştırılırsa yenileme çalışır ama
     * istek yine 401 alır — ve o 401 `AUTHENTICATION` KALICI sayılır,
     * listing'ler "anahtarın yanlış" damgasıyla TOPLU ölür.
     */
    #[Test]
    public function both_identity_headers_are_sent(): void
    {
        Http::fake(['*' => Http::response(['user_id' => 12345], 200)]);

        $this->adapter()->healthCheck();

        Http::assertSent(fn ($request): bool => $request->hasHeader('x-api-key', 'key-abc')
            && $request->hasHeader('Authorization', 'Bearer 12345.token'));
    }

    /**
     * ⚠️ UYGULAMA ANAHTARI YOKSA İSTEK HİÇ ATILMAZ.
     *
     * Boş `x-api-key` ile giden istek 401 alır, `AUTHENTICATION` KALICI
     * sayılır ve listing "anahtarın yanlış" diyerek ölür — oysa anahtar
     * YOKTUR, yanlış değildir (Hepsiburada'nın "satıcı kimliği yoksa
     * istek atılmaz" kuralının aynısı).
     *
     * Sağlık kontrolü istisnayı YUTAR ve sağlıksız döner; ama asıl
     * kanıt HİÇ İSTEK ATILMAMASIDIR.
     */
    #[Test]
    public function no_request_is_sent_without_the_app_keystring(): void
    {
        Http::fake();

        $result = $this->adapter(keystring: null)->healthCheck();

        $this->assertFalse($result->healthy);
        Http::assertNothingSent();
    }

    // ──────────────────────────────────────────────────────────── sağlık

    /** Sağlıklı yanıt gecikme ölçer ve `healthy` döner. */
    #[Test]
    public function a_valid_account_is_healthy(): void
    {
        Http::fake(['*' => Http::response(['user_id' => 12345], 200)]);

        $result = $this->adapter()->healthCheck();

        $this->assertTrue($result->healthy);
        $this->assertNotNull($result->latencyMs);
    }

    /**
     * ⚠️ MAĞAZA SEÇİLMEMİŞSE BAĞLANTI SAĞLIKSIZDIR.
     *
     * `shop_id` yol üzerinde taşınır (§19); sipariş yoklaması ve katalog
     * okuması onsuz çalışamaz. Sağlıklı sayılsaydı bağlantı `active` olur,
     * satıcı ürün göndermeye başlar ve her çağrı doldurulmamış yer tutucu
     * istisnasıyla ölürdü — Shopify'ın "konum seçilmemişse sağlıksız"
     * kuralının (P1-5) aynısı.
     */
    #[Test]
    public function a_connection_without_a_shop_is_unhealthy(): void
    {
        Http::fake(['*' => Http::response(['user_id' => 12345], 200)]);

        $result = $this->adapter(shopId: null)->healthCheck();

        $this->assertFalse($result->healthy);
        $this->assertStringContainsString('mağaza', mb_strtolower((string) $result->message));
    }

    /** Kullanıcı bilgisi taşımayan yanıt sağlıklı sayılmaz. */
    #[Test]
    public function a_response_without_a_user_is_unhealthy(): void
    {
        Http::fake(['*' => Http::response(['baska' => 'alan'], 200)]);

        $this->assertFalse($this->adapter()->healthCheck()->healthy);
    }

    // ─────────────────────────────────────────────── §20 · token yenileme

    /**
     * ⚠️ YENİ REFRESH TOKEN SAKLANIR — TEK KULLANIMLIKTIR.
     *
     * Etsy her yenilemede YENİ bir refresh token döner ve ESKİSİNİ İPTAL
     * EDER. Dönen değer saklanmazsa bağlantı BİR SONRAKİ yenilemede ölür
     * ve satıcı sebebini bulamaz.
     */
    #[Test]
    public function refreshing_keeps_the_new_refresh_token(): void
    {
        Http::fake(['*' => Http::response([
            'access_token' => '12345.yeni-access',
            'refresh_token' => '12345.yeni-refresh',
            'expires_in' => 3600,
        ], 200)]);

        $result = $this->adapter()->refreshCredentials();

        $this->assertSame('12345.yeni-access', $result->secrets['access_token']);
        $this->assertSame('12345.yeni-refresh', $result->secrets['refresh_token']);
        $this->assertNotNull($result->expiresAt);
    }

    /**
     * ⚠️ YANITTA REFRESH TOKEN YOKSA ESKİSİ KORUNUR.
     *
     * Körlemesine üzerine yazılsaydı alan yokken refresh token NULL olur
     * ve bağlantı sonraki turda ölürdü.
     */
    #[Test]
    public function a_response_without_a_refresh_token_keeps_the_old_one(): void
    {
        Http::fake(['*' => Http::response([
            'access_token' => '12345.yeni-access',
            'expires_in' => 3600,
        ], 200)]);

        $result = $this->adapter()->refreshCredentials();

        $this->assertSame('12345.eski-refresh', $result->secrets['refresh_token']);
    }

    /**
     * ⚠️ YENİLEMEDE `code_verifier` GÖNDERİLMEZ, `refresh_token` GÖNDERİLİR.
     *
     * PKCE yalnızca ilk takasta anlamlıdır; yenilemede gönderilseydi Etsy
     * isteği reddeder ve 1 saatlik token bir saat içinde ölürdü.
     */
    #[Test]
    public function the_refresh_call_uses_the_refresh_grant(): void
    {
        Http::fake(['*' => Http::response([
            'access_token' => '12345.yeni',
            'expires_in' => 3600,
        ], 200)]);

        $this->adapter()->refreshCredentials();

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ($body['grant_type'] ?? null) === 'refresh_token'
                && ($body['refresh_token'] ?? null) === '12345.eski-refresh'
                && ! isset($body['code_verifier']);
        });
    }

    /**
     * ⚠️ ADAPTER KASAYA YAZMAZ — SONUÇ DÖNER (v2.2 · §7).
     *
     * Yazsaydı `channel_credentials`'ın tek yazma kapısı olan kasa devre
     * dışı kalır, anahtar sürümü ve maskeleme yüzeyi ikiye bölünürdü.
     * Yazmayı `TokenRefresher` yapar.
     */
    #[Test]
    public function refreshing_does_not_write_to_the_vault(): void
    {
        Http::fake(['*' => Http::response([
            'access_token' => '12345.yeni-access',
            'expires_in' => 3600,
        ], 200)]);

        [$tenant, $connection] = $this->connected();

        $this->asTenant($tenant, fn () => $this->adapterFor($connection)->refreshCredentials());

        $stored = TenantContext::runAsSystem(
            fn (): array => app(CredentialVault::class)->read($connection)
        );

        $this->assertSame(
            '12345.token',
            $stored['access_token'],
            'Adapter kasaya YAZDI — yan etkisizlik kuralı çiğnendi.',
        );
    }

    /**
     * ⚠️ REFRESH TOKEN YOKSA İSTİSNA — sessiz dönüş YOKTUR.
     *
     * "Yenilenemedi" ile "yenilendi" arasındaki fark bağlantının yaşamıdır.
     */
    #[Test]
    public function refreshing_without_a_token_throws(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);

        $this->adapter(refreshToken: null)->refreshCredentials();
    }

    /**
     * ⚠️ PAY TARAMA SIKLIĞINDAN KÜÇÜK OLAMAZ.
     *
     * Tarama 15 dakikada bir koşar (§20). Pay 15 dakikadan KISA olsaydı
     * token iki tur arasında hem "henüz aday değil" hem "artık ölmüş"
     * olabilirdi ve o aralıktaki her çağrı 401 alırdı.
     */
    #[Test]
    public function the_refresh_lead_covers_the_scan_interval(): void
    {
        $this->assertGreaterThanOrEqual(900, $this->adapter()->refreshLeadSeconds());
    }

    // ────────────────────────────────────────────── §21 · sınıflandırma

    /**
     * ⚠️ 401 `AUTHENTICATION` DÖNER ve KALICIDIR.
     *
     * Etsy'de bu "anahtar yanlış" demek DEĞİLDİR — token 1 SAATLİKTİR ve
     * büyük olasılıkla yalnızca süresi dolmuştur. Kalıcı sayılması yine de
     * doğrudur: yeniden denemek düzeltmez, düzelten şey
     * `credentials:refresh` taramasıdır (§21: "401 → yenileme dener,
     * sonra kalıcı").
     */
    #[Test]
    public function an_unauthorized_response_is_authentication(): void
    {
        $this->assertSame(
            ErrorClass::AUTHENTICATION,
            $this->classify(401),
        );
    }

    /** 429 hız sınırıdır — GEÇİCİ. */
    #[Test]
    public function a_throttled_response_is_rate_limited(): void
    {
        $this->assertSame(ErrorClass::RATE_LIMITED, $this->classify(429));
    }

    /** 400 iş kuralı ihlalidir — KALICI (§21: `invalid_*`). */
    #[Test]
    public function a_bad_request_is_validation(): void
    {
        $this->assertSame(ErrorClass::VALIDATION, $this->classify(400));
    }

    /** 500 sunucu hatasıdır — GEÇİCİ. */
    #[Test]
    public function a_server_error_is_retryable(): void
    {
        $this->assertSame(ErrorClass::SERVER_ERROR, $this->classify(503));
    }

    // ──────────────────────────────────────────────── §11.4 · webhook YOK

    /**
     * ⚠️ ETSY WEBHOOK SUNMAZ — imza doğrulaması DAİMA `false`.
     *
     * Trendyol'daki kararın aynısı: `true` dönmek Etsy adına İMZASIZ
     * SİPARİŞ ENJEKTE etmenin kapısını açardı. Güvenli taraf "evet"
     * DEMEMEKTİR.
     */
    #[Test]
    public function webhook_verification_always_fails(): void
    {
        $adapter = $this->adapter();

        $this->assertFalse($adapter->verifyWebhookSignature('{}', []));
        $this->assertFalse($adapter->verifyWebhookSignature('', []));
    }

    // ─────────────────────────────────────────────────────────── yetenekler

    /**
     * ⚠️ BU TEST HER SLICE'TA KIRILIR ve bu DOĞRUDUR.
     *
     * Yetenekler `capabilities` kolonundan DEĞİL `instanceof` yansımasından
     * okunur — yani gerçekten uygulamayı izler. Slice 3.4 katalog yazınca
     * o satır BURADAN yukarı taşınır; test GEVŞETİLMEZ
     * (`ShopifyCatalogImportTest`'in kuralı).
     */
    #[Test]
    public function only_the_written_capabilities_are_declared(): void
    {
        $adapter = $this->adapter();

        // YAZILDI — slice 3.1 · 3.3 · 3.4 · 3.5 · 3.6
        $this->assertInstanceOf(SupportsTokenRefresh::class, $adapter);
        $this->assertInstanceOf(SupportsTaxonomy::class, $adapter);
        $this->assertInstanceOf(SupportsCatalog::class, $adapter);
        $this->assertInstanceOf(SupportsInventory::class, $adapter);
        $this->assertInstanceOf(SupportsPricing::class, $adapter);

        // HENÜZ YAZILMADI — 3.7
        $this->assertNotInstanceOf(SupportsOrders::class, $adapter);
    }

    /** Registry gerçek istemciyle taze örnek kurar — asla paylaşılmaz. */
    #[Test]
    public function the_registry_builds_a_fresh_adapter(): void
    {
        [$tenant, $connection] = $this->connected();

        $this->asTenant($tenant, function () use ($connection): void {
            $registry = app(AdapterRegistry::class);

            $first = $registry->for($connection);
            $second = $registry->for($connection);

            $this->assertInstanceOf(EtsyAdapter::class, $first);
            $this->assertNotSame(
                $first,
                $second,
                'Registry AYNI örneği döndürdü — paylaşılan adapter kiracı '
                .'A\'nın kimlik bilgisini kiracı B\'nin işinde kullanır.',
            );
        });
    }

    /** Hız sınırı 10/sn — §21'in en düşük değeri. */
    #[Test]
    public function the_rate_limit_matches_the_document(): void
    {
        $this->assertSame(10, $this->adapter()->rateLimitProfile()->requestsPerSecond);
    }

    /**
     * ⚠️ ENVANTER PARTİSİ İLAN BAŞINA 1'DİR (§11.3).
     *
     * Bir performans sorunu değil KANALIN ŞEKLİDİR: envanter uç noktası
     * tek ilanı adresler ve o ilanın TÜM varyantlarını tek gövdede ister.
     */
    #[Test]
    public function the_inventory_batch_is_one_per_listing(): void
    {
        $this->assertSame(1, $this->adapter()->maxInventoryBatchSize());
    }

    // ────────────────────────────────────────────────────────── yardımcılar

    private function classify(int $status): ErrorClass
    {
        Http::fake(['*' => Http::response(['hata' => 'x'], $status)]);

        $adapter = $this->adapter();

        try {
            $adapter->healthCheck();
        } catch (\Throwable) {
            // healthCheck yutar; sınıflandırmayı doğrudan sınıyoruz
        }

        try {
            Http::throw()->get('https://openapi.etsy.com/v3/application/users/me');
        } catch (\Throwable $e) {
            return $adapter->classifyError($e);
        }

        $this->fail('Beklenen istisna fırlatılmadı.');
    }

    private function adapter(
        ?string $keystring = 'key-abc',
        ?string $shopId = '777',
        ?string $refreshToken = '12345.eski-refresh',
    ): EtsyAdapter {
        [$tenant, $connection] = $this->connected($keystring, $shopId, $refreshToken);

        return $this->asTenant($tenant, fn (): EtsyAdapter => $this->adapterFor($connection));
    }

    /** @return array{0: Tenant, 1: ChannelConnection} */
    private function connected(
        ?string $keystring = 'key-abc',
        ?string $shopId = '777',
        ?string $refreshToken = '12345.eski-refresh',
    ): array {
        $tenant = $this->makeTenant();

        $connection = $this->asTenant($tenant, function () use ($keystring, $shopId, $refreshToken): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'etsy',
                'external_account_id' => 'etsy-shop-'.uniqid(),
                'status' => 'active',
                'settings' => array_filter([
                    // ⚠️ `settings` ŞİFRESİZDİR — buraya YALNIZCA KİMLİK
                    // yazılır. Keystring uygulamanın kimliğidir, sır değil
                    // (§19 · madde 4). Token'lar kasadadır.
                    EtsyAdapter::KEYSTRING_KEY => $keystring,
                    EtsyAdapter::SHOP_ID_KEY => $shopId,
                ], static fn (mixed $v): bool => $v !== null),
            ]);

            app(CredentialVault::class)->store($connection, array_filter([
                'access_token' => '12345.token',
                'refresh_token' => $refreshToken,
            ], static fn (mixed $v): bool => $v !== null));

            return $connection;
        });

        return [$tenant, $connection];
    }

    private function adapterFor(ChannelConnection $connection): EtsyAdapter
    {
        return new EtsyAdapter(
            $connection,
            new ChannelHttpClient(
                $connection,
                app(CredentialVault::class),
                app(PayloadRedactor::class),
            ),
        );
    }

    private function makeTenant(): Tenant
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'etsy'],
            [
                'name' => 'Etsy',
                'kind' => 'marketplace',
                'adapter_class' => EtsyAdapter::class,
                // ⚠️ WEBHOOK YOKTUR (§11.4) — sipariş YOKLAMAYLA gelir.
                'supports_webhooks' => false,
                'is_active' => false,
            ],
        ));

        return (new CreateTenant)->run(
            name: 'Etsy '.uniqid(),
            owner: User::factory()->create(),
        );
    }
}
