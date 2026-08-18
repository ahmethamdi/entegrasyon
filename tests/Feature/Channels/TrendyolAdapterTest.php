<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\SupportsApprovalWorkflow;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Contracts\SupportsFulfillment;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Contracts\SupportsTaxonomy;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\PricePushBatch;
use App\Support\Logging\PayloadRedactor;
use App\Support\Tenancy\TenantContext;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TrendyolAdapter — ikinci kanal, pazaryeri.
 *
 * Mimari Karar Dokümanı v2.2 · §14 · Trendyol, §13 · Faz 2 ilk maddesi
 * ("Trendyol istemcisi, kimlik doğrulama, dinamik rate limit profili").
 *
 * DEĞİŞMEZ KURAL — PAZARYERİ KARMAŞIKLIĞI ÇEKİRDEĞE DOKUNMAZ (§14):
 *   Taksonomi, zorunlu öznitelik ve onay süreci Trendyol'a özgüdür ve
 *   yetenek arayüzleriyle taşınır. Stok akışı listing'in nasıl oluştuğunu
 *   BİLMEZ; `InventoryBatchBuilder` yalnızca `lifecycle_status = 'live'`
 *   kontrolü yapar.
 *
 * DEĞİŞMEZ KURAL — WEBHOOK YOK, YOKLAMA VAR:
 *   Trendyol webhook göndermez. `supports_webhooks = false` ve imza
 *   doğrulaması ANLAMSIZDIR — doğrulanacak bir imza hiç gelmez. Sipariş
 *   yoklamayla çekilir ve olay kimliği sipariş numarasından türer (§4).
 *
 * DEĞİŞMEZ KURAL — DİNAMİK RATE LIMIT (§14):
 *   Sınır satıcı seviyesine göre değişir ve yanıt başlığından öğrenilir.
 *   Profili ADAPTER bildirir, uygulamayı ÇEKİRDEK yapar — kova mantığı
 *   ortaktır ve `ChannelRateLimiter` değişmez.
 *
 * DEĞİŞMEZ KURAL — ADAPTER YAN ETKİSİZDİR: veritabanına yazmaz.
 */
final class TrendyolAdapterTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────── yetenekler

    /**
     * YETENEKLER TİP SİSTEMİNDE DURUR (§7 · Karar 19).
     *
     * Panelde `if type === 'trendyol'` bloğu YAZILMAZ; sekmeler
     * `instanceof` ile açılır. Trendyol'da Woo'da olmayan iki yetenek
     * vardır: taksonomi ve onay süreci.
     */
    #[Test]
    public function trendyol_declares_marketplace_capabilities(): void
    {
        $adapter = $this->adapter();

        $this->assertInstanceOf(ChannelAdapter::class, $adapter);
        $this->assertInstanceOf(SupportsCatalog::class, $adapter);
        $this->assertInstanceOf(SupportsInventory::class, $adapter);
        $this->assertInstanceOf(SupportsPricing::class, $adapter);
        $this->assertInstanceOf(SupportsOrders::class, $adapter);

        // Woo'da OLMAYAN iki yetenek — §14'ün tamamı bunun üzerine kurulu.
        $this->assertInstanceOf(SupportsTaxonomy::class, $adapter);
        $this->assertInstanceOf(SupportsApprovalWorkflow::class, $adapter);
    }

    /**
     * KARGO KAPSAM DIŞI (§14 · adapter taslağı).
     *
     * "Fulfillment ayrı kargo entegrasyonu gerektirir; kapsam dışı."
     * Uygulanmış gibi görünmesi, panelde çalışmayan bir sekme açardı.
     */
    #[Test]
    public function trendyol_does_not_claim_fulfillment(): void
    {
        $this->assertNotInstanceOf(SupportsFulfillment::class, $this->adapter());
    }

    /**
     * REGISTRY HER ÇAĞRIDA YENİ ÖRNEK ÜRETİR.
     *
     * Adapter bağlantı taşır; paylaşılan örnek kiracı A'nın kimlik
     * bilgisini kiracı B'nin işinde kullanırdı. Container'da `bind`,
     * ASLA `singleton`.
     */
    #[Test]
    public function registry_never_shares_a_trendyol_adapter(): void
    {
        [$tenant] = $this->makeTenant();

        $this->asTenant($tenant, function (): void {
            $connection = $this->connection();
            $registry = app(AdapterRegistry::class);

            $first = $registry->for($connection);
            $second = $registry->for($connection);

            $this->assertInstanceOf(TrendyolAdapter::class, $first);
            $this->assertNotSame($first, $second, 'Adapter paylaşılmamalı.');
        });
    }

    /**
     * YAZILMAMIŞ YETENEK SESSİZCE BAŞARILI DÖNMEZ.
     *
     * Yetenek arayüzleri §14'teki sözleşmenin tamamını ilan eder ama Faz
     * 2'nin ilk maddesi yalnızca istemci, kimlik doğrulama ve hız sınırını
     * kapsar. Kalan gövdeler `AdapterResult::success()` dönseydi senkron
     * operasyonu TAMAMLANDI sanılır, `synced_version` ilerler ve kanalda
     * hiçbir şey değişmemişken satır panelde "senkron" görünürdü.
     *
     * Sessizlik en pahalı biçimdir: hata mutabakat sürüklenmeyi bulana
     * kadar — ya da hiç — fark edilmez. Açık istisna operasyonu
     * `error_transient` yapar ve satır panelde görünür kalır.
     */
    #[Test]
    public function unimplemented_capabilities_never_report_silent_success(): void
    {
        $adapter = $this->adapter();

        $calls = [
            'stok itme' => fn () => $adapter->pushInventory(
                new InventoryPushBatch('c1', []),
            ),
            'fiyat itme' => fn () => $adapter->pushPrices(
                new PricePushBatch('c1', []),
            ),
            // TAKSONOMİ, KATALOG AKTARIMI ve ONAY DURUMU ARTIK BU LİSTEDE
            // DEĞİL: §13 · Faz 2'nin ikinci ve üçüncü maddeleriyle
            // yazıldılar (`TaxonomySyncTest`, `TrendyolCatalogTest`,
            // `ApprovalStatusTest` onları doğruluyor). Bu liste madde
            // kapandıkça KÜÇÜLÜR; yazılan bir gövde buradan çıkarılmazsa
            // test yazılmış kodu "yazılmamış" sanarak kırmızıya döner.
            'listeden çıkarma' => fn () => $adapter->delist(new Listing),
            'uzak listing okuma' => fn () => $adapter->fetchListing(new Listing),
        ];

        foreach ($calls as $what => $call) {
            try {
                $call();
                $this->fail("Yazılmamış yetenek sessizce döndü: {$what}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString(
                    'henüz yazılmadı',
                    $e->getMessage(),
                    "İstisna açıkça 'yazılmadı' demeli: {$what}",
                );
            }
        }
    }

    // ─────────────────────────────────────────────── kimlik doğrulama

    /**
     * KİMLİK DOĞRULAMA BASIC AUTH + SATICI KİMLİĞİDİR.
     *
     * Trendyol API anahtar/şifre çiftini Basic auth ile taşır ve satıcı
     * kimliği YOL üzerindedir (`/suppliers/{id}/...`). İkisi birden
     * gerekir: doğru anahtarla yanlış satıcı kimliği başka bir satıcının
     * kaynağını ister ve 403 alır.
     */
    #[Test]
    public function requests_carry_basic_auth_and_supplier_id(): void
    {
        Http::fake(['*' => Http::response(['suppliers' => []], 200)]);

        $this->adapter(supplierId: '123456')->healthCheck();

        Http::assertSent(function (Request $request): bool {
            $expected = 'Basic '.base64_encode('anahtar:sifre');

            // Başlık dizi olarak gelir; ilk değere bakılır.
            $sent = $request->header('Authorization')[0] ?? '';

            // Satıcı kimliği YOL üzerindedir: doğru anahtarla yanlış kimlik
            // başka bir satıcının kaynağını ister ve 403 alır.
            return $sent === $expected
                && str_contains($request->url(), '/suppliers/123456/');
        });
    }

    /**
     * KİMLİK BİLGİSİ KİRACI BAĞLAMI OLMADAN DA GÖNDERİLİR.
     *
     * `channel_credentials` kiracıya göre kapsanır ve istemci bağlam
     * OLMADAN çağrılabilir: kiracı bağlamını kurmayan bir kuyruk işi,
     * `runAsSystem` ile koşan bir tarama, ya da panelden tetiklenen sağlık
     * kontrolü. Kapsanmış sorgu o durumda istisna fırlatır ve istemci onu
     * yutup isteği SESSİZCE KİMLİKSİZ gönderirdi.
     *
     * Bedeli en pahalı hata biçimidir: kanal 401 döner, adapter bunu
     * AUTHENTICATION diye sınıflandırır, `RetryPolicy` KALICI hata sayar ve
     * listing "anahtarın yanlış" diyerek ölür — oysa anahtar doğrudur ve
     * yalnızca hiç gönderilmemiştir. Kullanıcı anahtarı defalarca yeniden
     * girer, hiçbiri işe yaramaz.
     *
     * Bu test bağlamı BİLEREK bırakır ve isteğin yine de kimlikli gitmesini
     * şart koşar.
     */
    #[Test]
    public function credentials_are_sent_even_without_tenant_context(): void
    {
        [$tenant] = $this->makeTenant();

        // Bağlantı ve kimlik bilgisi bağlam İÇİNDE kurulur…
        $connection = $this->asTenant(
            $tenant,
            fn (): ChannelConnection => $this->connection(),
        );

        Http::fake(['*' => Http::response(['suppliers' => []], 200)]);

        // …ama çağrı bağlam DIŞINDA yapılır: gerçek kuyruk işinin hâli bu.
        $this->assertFalse(
            TenantContext::hasTenant(),
            'Test kurgusu gereği bağlam bırakılmış olmalı.',
        );

        $this->adapterFor($connection)->healthCheck();

        Http::assertSent(function (Request $request): bool {
            $sent = $request->header('Authorization')[0] ?? '';

            return $sent === 'Basic '.base64_encode('anahtar:sifre');
        });
    }

    /**
     * SATICI KİMLİĞİ HESABIN KİMLİĞİDİR — mağaza adresi DEĞİL.
     *
     * Woo'da hesap kimliği mağaza alan adıdır (`StoreUrl` ile
     * normalleştirilir); Trendyol'da tek bir API adresi vardır ve satıcılar
     * onu paylaşır. Alan adı kimlik sayılsaydı TÜM Trendyol satıcıları aynı
     * `external_account_id` ile çakışır ve `(tenant, type, account)` tekillik
     * kısıtı ikinci satıcıyı reddederdi.
     */
    #[Test]
    public function supplier_id_is_the_account_identity(): void
    {
        [$tenant] = $this->makeTenant();

        $this->asTenant($tenant, function (): void {
            $first = $this->connection(supplierId: '111');
            $second = $this->connection(supplierId: '222');

            $this->assertSame('111', $first->external_account_id);
            $this->assertSame('222', $second->external_account_id);
            $this->assertNotSame(
                $first->id,
                $second->id,
                'İki farklı satıcı ayrı bağlantıdır.',
            );
        });
    }

    // ─────────────────────────────────────────────── sağlık kontrolü

    /** Sağlık kontrolü satıcı adresine gider ve gecikmeyi ölçer. */
    #[Test]
    public function health_check_reports_healthy_with_latency(): void
    {
        Http::fake(['*' => Http::response(['suppliers' => [['id' => 123456]]], 200)]);

        $result = $this->adapter()->healthCheck();

        $this->assertTrue($result->healthy);
        $this->assertNotNull($result->latencyMs);
    }

    /**
     * YANLIŞ ANAHTAR SAĞLIKSIZDIR.
     *
     * Sağlık kontrolü geçmeden bağlantı `active` olmaz; aktif ama
     * çalışmayan bağlantı en pahalı hata biçimidir.
     */
    #[Test]
    public function health_check_fails_on_unauthorized(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['message' => 'Unauthorized']]], 401)]);

        $result = $this->adapter()->healthCheck();

        $this->assertFalse($result->healthy);
    }

    /** Ağ hatası sağlık kontrolünü DÜŞÜRMEZ, sağlıksız olarak döner. */
    #[Test]
    public function health_check_survives_network_failure(): void
    {
        Http::fake(fn () => throw new ConnectionException('DNS çözülemedi'));

        $result = $this->adapter()->healthCheck();

        $this->assertFalse($result->healthy);
        $this->assertNotNull($result->message);
    }

    // ──────────────────────────────────────────── dinamik rate limit

    /**
     * PROFİL VARSAYILAN OLARAK KANAL TÜRÜNDEN OKUNUR.
     *
     * `channel_types.rate_limit_profile` seed'de tanımlıdır; hesaba özgü
     * bilgi henüz öğrenilmediğinde bu kullanılır.
     */
    #[Test]
    public function rate_limit_profile_falls_back_to_channel_type(): void
    {
        $profile = $this->adapter()->rateLimitProfile();

        // Seed: 50 istek / 60 saniye → saniyede 50/60 değil, dokümandaki
        // profil alanları neyse o. Burada yalnızca "türden geldi" sınanır.
        $this->assertGreaterThan(0, $profile->requestsPerSecond);
        $this->assertGreaterThan(0, $profile->burstCapacity);
    }

    /**
     * SINIR YANIT BAŞLIĞINDAN ÖĞRENİLİR VE PROFİLİ EZER (§14).
     *
     * Trendyol'da limit SATICI SEVİYESİNE göre değişir; sabit bir profil
     * yüksek seviyeli satıcıyı gereksiz yavaşlatır, düşük seviyeliyi ise
     * sürekli 429'a sokar. Kanal kendi sınırını başlıkta söylüyorsa ona
     * uyulur.
     */
    #[Test]
    public function rate_limit_profile_is_learned_from_response_headers(): void
    {
        Http::fake(['*' => Http::response(
            ['suppliers' => []],
            200,
            ['X-RateLimit-Limit' => '600', 'X-RateLimit-Remaining' => '599'],
        )]);

        $adapter = $this->adapter();

        $adapter->healthCheck();

        $profile = $adapter->rateLimitProfile();

        // 600 istek/dakika → saniyede 10.
        $this->assertSame(10, $profile->requestsPerSecond);
    }

    /**
     * ÖĞRENİLEN SINIR BAĞLANTIYA YAZILIR, SÜREÇLE ÖLMEZ.
     *
     * Her worker kendi başına yeniden öğrenseydi ilk istekler daima
     * varsayılan profille giderdi ve yüksek seviyeli satıcı kotasının
     * çoğunu hiç kullanamazdı.
     *
     * ADAPTER YAN ETKİSİZDİR kuralının sınırı burada: öğrenilen sınır
     * DURUM DEĞİL YAPILANDIRMADIR ve senkron sonucunu değiştirmez.
     * Yine de yazımı adapter değil, ona sahip olan bağlantı yapar.
     */
    #[Test]
    public function learned_rate_limit_is_cached_on_the_connection(): void
    {
        [$tenant] = $this->makeTenant();

        $this->asTenant($tenant, function (): void {
            Http::fake(['*' => Http::response(
                ['suppliers' => []],
                200,
                ['X-RateLimit-Limit' => '300'],
            )]);

            $connection = $this->connection();
            $this->adapterFor($connection)->healthCheck();

            // SATIRIN KENDİSİ okunur, model DEĞİL.
            //
            // `ChannelConnection::find()` çalışan modelin kendisini geri
            // verebilir ve bellekteki değeri "kalıcı olmuş" gibi gösterir;
            // o zaman `save()` çağrısı silinse bile test yeşil kalırdı
            // (mutasyonla bulundu). Ham satır bu yanılgıyı barındırmaz.
            $stored = DB::table('channel_connections')
                ->where('id', $connection->id)
                ->value('settings');

            $settings = json_decode((string) $stored, true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(
                5,                                   // 300/dakika → 5/saniye
                $settings['learned_rate_limit']['requests_per_second'] ?? null,
                'Öğrenilen sınır SATIRA yazılmalı; süreçle ölmemeli.',
            );

            // Ve taze bir adapter onu profil olarak okumalı.
            $fresh = ChannelConnection::query()->findOrFail($connection->id);
            $fresh->refresh();

            $this->assertSame(
                5,
                $this->adapterFor($fresh)->rateLimitProfile()->requestsPerSecond,
            );
        });
    }

    /**
     * SAYI OLMAYAN BAŞLIK YOK SAYILIR — kanal varsayılanı korunur.
     *
     * `X-RateLimit-Limit: 600, 300` gerçek bir vakadır: araya giren bir
     * vekil sunucu aynı başlığı iki kez görürse değerleri virgülle
     * birleştirir. `(int)` dönüşümü böyle bir değeri SESSİZCE ilk sayıya
     * indirger (`600`) ve iki sınırın DÜŞÜK olanı (300) yok sayılırdı —
     * kova kanalın izin verdiğinin iki katı hızla açılır ve sürekli 429
     * yerdik.
     *
     * Bu yüzden filtre `(int)` değil `ctype_digit`'tir: tamamı rakam
     * olmayan hiçbir değere güvenilmez ve bilinmeyen karşısında mevcut
     * profil korunur.
     */
    #[Test]
    public function malformed_rate_limit_header_is_ignored(): void
    {
        [$tenant] = $this->makeTenant();

        $this->asTenant($tenant, function (): void {
            Http::fake(['*' => Http::response(
                ['suppliers' => []],
                200,
                // Vekil sunucu iki başlığı birleştirmiş: yorumlanamaz.
                ['X-RateLimit-Limit' => '600, 300'],
            )]);

            $connection = $this->connection();
            $adapter = $this->adapterFor($connection);
            $adapter->healthCheck();

            // Kanal türündeki varsayılan korunur; 600'den öğrenilmiş
            // OLMAMALI (600/60 = 10 olurdu).
            $this->assertSame(
                5,
                $adapter->rateLimitProfile()->requestsPerSecond,
                'Yorumlanamayan başlıktan sınır ÖĞRENİLMEMELİ.',
            );

            $stored = DB::table('channel_connections')
                ->where('id', $connection->id)
                ->value('settings');

            $settings = json_decode((string) $stored, true, 512, JSON_THROW_ON_ERROR);

            $this->assertArrayNotHasKey(
                'learned_rate_limit',
                $settings,
                'Bozuk başlık satıra hiç yazılmamalı.',
            );
        });
    }

    // ─────────────────────────────────────────── hata sınıflandırma

    /**
     * SINIFLANDIRMAYI ADAPTER YAPAR, KARARI ÇEKİRDEK VERİR.
     *
     * `VALIDATION` ve `AUTHENTICATION` KALICIDIR; diğerleri geçicidir.
     */
    #[Test]
    public function classifies_authentication_errors_as_permanent(): void
    {
        $adapter = $this->adapter();

        $this->assertSame(
            ErrorClass::AUTHENTICATION,
            $adapter->classifyError($this->httpError(401)),
        );
        $this->assertSame(
            ErrorClass::AUTHENTICATION,
            $adapter->classifyError($this->httpError(403)),
        );
    }

    #[Test]
    public function classifies_rate_limit_as_its_own_class(): void
    {
        $this->assertSame(
            ErrorClass::RATE_LIMITED,
            $this->adapter()->classifyError($this->httpError(429)),
        );
    }

    #[Test]
    public function classifies_validation_errors_as_permanent(): void
    {
        $this->assertSame(
            ErrorClass::VALIDATION,
            $this->adapter()->classifyError($this->httpError(400)),
        );
    }

    #[Test]
    public function classifies_server_errors_as_transient(): void
    {
        $this->assertSame(
            ErrorClass::SERVER_ERROR,
            $this->adapter()->classifyError($this->httpError(503)),
        );
    }

    #[Test]
    public function classifies_connection_failure_as_network(): void
    {
        $this->assertSame(
            ErrorClass::NETWORK,
            $this->adapter()->classifyError(new ConnectionException('timeout')),
        );
    }

    // ─────────────────────────────────────────────────── webhook yok

    /**
     * TRENDYOL WEBHOOK GÖNDERMEZ — İMZA DOĞRULAMASI HER ZAMAN FALSE.
     *
     * `true` dönseydi, Trendyol adına sahte bir istek uydurup imzasız
     * sipariş enjekte etmenin kapısı açılırdı. Doğrulanacak imza hiç
     * gelmediği için doğru cevap "hayır"dır; sipariş yoklamayla gelir.
     */
    #[Test]
    public function webhook_signature_is_never_accepted(): void
    {
        $adapter = $this->adapter();

        $this->assertFalse($adapter->verifyWebhookSignature('{"a":1}', []));
        $this->assertFalse($adapter->verifyWebhookSignature('', []));
    }

    /** Olay kimliği başlıktan gelmez: yoklamada sipariş numarası kullanılır. */
    #[Test]
    public function event_id_is_not_taken_from_headers(): void
    {
        $this->assertNull($this->adapter()->extractEventId([]));
    }

    // ──────────────────────────────────────────────── günlükleme

    /**
     * HER ÇAĞRI `api_calls`'A YAZILIR ve SIR SIZMAZ.
     *
     * Anahtar ve şifre günlükte düz metin geçmemeli; maskeleme iki
     * katmanlıdır ve ikinci katman kasadaki DEĞERLERİ arar.
     */
    #[Test]
    public function api_calls_are_logged_without_leaking_secrets(): void
    {
        [$tenant] = $this->makeTenant();

        $this->asTenant($tenant, function (): void {
            Http::fake(['*' => Http::response(['suppliers' => []], 200)]);

            $this->adapterFor($this->connection())->healthCheck();

            $rows = DB::table('api_calls')->get();

            $this->assertCount(1, $rows);

            $dump = json_encode($rows, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('sifre', $dump, 'Şifre günlüğe sızmamalı.');
            $this->assertStringNotContainsString('anahtar', $dump, 'Anahtar günlüğe sızmamalı.');
        });
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @return array{0: Tenant, 1: User} */
    private function makeTenant(string $name = 'Trendyol'): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: $name.' '.uniqid(), owner: $user);

        return [$tenant, $user];
    }

    /** Kanal türünü seed'deki tanımıyla açar. */
    private function channelType(): ChannelType
    {
        return $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'trendyol'],
            [
                'name' => 'Trendyol',
                'kind' => 'marketplace',
                'adapter_class' => TrendyolAdapter::class,
                'capabilities' => [
                    'catalog' => true, 'inventory' => true, 'pricing' => true,
                    'orders' => true, 'taxonomy' => true, 'approval' => true,
                    'fulfillment' => false,
                ],
                'rate_limit_profile' => [
                    'requests_per_second' => 5,
                    'burst_capacity' => 10,
                ],
                'supports_webhooks' => false,
                'is_active' => true,
            ],
        ));
    }

    private function connection(string $supplierId = '123456'): ChannelConnection
    {
        $this->channelType();

        $connection = ChannelConnection::factory()->create([
            'channel_type_code' => 'trendyol',
            'external_account_id' => $supplierId,
            'settings' => [
                'base_url' => 'https://api.trendyol.com/sapigw',
                'supplier_id' => $supplierId,
            ],
        ]);

        app(CredentialVault::class)->store($connection, [
            'api_key' => 'anahtar',
            'api_secret' => 'sifre',
        ]);

        return $connection;
    }

    private function adapterFor(ChannelConnection $connection): TrendyolAdapter
    {
        return new TrendyolAdapter(
            $connection,
            new ChannelHttpClient(
                $connection,
                app(CredentialVault::class),
                app(PayloadRedactor::class),
            ),
        );
    }

    /** Kiracı bağlamı içinde kurulmuş adapter — çoğu test için yeterli. */
    private function adapter(string $supplierId = '123456'): TrendyolAdapter
    {
        [$tenant] = $this->makeTenant();

        return $this->asTenant(
            $tenant,
            fn (): TrendyolAdapter => $this->adapterFor($this->connection($supplierId)),
        );
    }

    private function httpError(int $status): RequestException
    {
        return new RequestException(new Response(
            new PsrResponse($status, [], json_encode(['errors' => [['message' => 'hata']]]))
        ));
    }
}
