<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Shopify;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Support\ChannelImages;
use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresImageLimit;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\RefreshedCredentials;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Contracts\SupportsFulfillment;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Contracts\SupportsTokenRefresh;
use App\Domain\Channels\Contracts\SupportsWebhookRegistration;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Fulfillment;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\ListingPayload;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use App\Domain\Sync\Support\OrderPage;
use App\Domain\Sync\Support\PricePushBatch;
use App\Domain\Sync\Support\RemoteInventorySnapshot;
use App\Domain\Sync\Support\RemoteListing;
use App\Domain\Sync\Support\RemotePriceSnapshot;
use App\Domain\Sync\Support\RemoteProductPage;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Shopify kanal adapter'ı — GraphQL Admin API.
 *
 * V3.0 · §06 · Faz 1.
 *
 * ─────────────────────────────────────────────────────────────────────
 * MİMARİ KARAR — REMIX DEĞİL, LARAVEL ADAPTER
 * ─────────────────────────────────────────────────────────────────────
 * v2.2 §2 ve §11 Shopify'ı ayrı bir Node/Remix servisi olarak öngörüyor
 * ve o mimari App Store'a çıkmak için gereklidir (doküman Ay 8+ diyor).
 *
 * V3.0 bunu YAPMIYOR (§06.1 · onaylanmış proje kararı): satıcının kendi
 * **custom app** Admin API anahtarıyla bağlandığı, Woo/Trendyol ile AYNI
 * kalıpta bir adapter. OAuth YOK, Remix YOK, projeye ikinci teknoloji
 * yığını (Node) SOKULMAZ.
 *
 * §11'İN SERVİS TOKEN'I DEĞİŞMEZİ İPTAL EDİLMEDİ, ERTELENDİ. App Store
 * kararı verilirse o değişmez OLDUĞU GİBİ uygulanır ve şema hazırdır:
 * `UNIQUE(channel_type_code, external_account_id)` kısıtı shop domain'den
 * kiracı çözümünün tekil olmasını BUGÜNDEN garanti ediyor. O gün geldiğinde
 * **bu adapter atılmaz** — Remix yalnızca OAuth ve App Bridge yüzeyi olur,
 * ürün/stok/sipariş işi yine buradan geçer.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ EN TEHLİKELİ FARK — GRAPHQL 200 DÖNER AMA BAŞARISIZ OLABİLİR
 * ─────────────────────────────────────────────────────────────────────
 * REST'te hata HTTP kodudur; GraphQL'de HER ŞEY 200'DÜR ve hata gövdede
 * `errors` / `userErrors` altında yaşar. `$response->throw()` bunu GÖRMEZ.
 *
 * Kontrol edilmezse `SyncResultRecorder` başarı yazar, `synced_version`
 * ilerler ve kanalda hiçbir şey değişmemişken satır "senkron" görünür —
 * projenin en pahalı hata biçimi (P0-1). HER GraphQL yanıtı
 * `assertNoGraphqlErrors()` üzerinden geçer; İSTİSNASIZ.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KİMLİK — `X-Shopify-Access-Token`, BASIC AUTH DEĞİL
 * ─────────────────────────────────────────────────────────────────────
 * Başlık desteği `ChannelHttpClient`'a GENEL olarak eklenmişti
 * (`356a662`, Hepsiburada'nın `User-Agent`'ı için). Başlığı ADAPTER verir,
 * istemci taşır ve `if ($channel === '...')` YAZILMAZ.
 *
 * ⚠️ İstemcinin `access_token` → `withToken()` (Bearer) yolu Shopify için
 * YANLIŞTIR ve bu adapter kendi başlığını verir. Bearer gönderilseydi
 * Shopify 401 döner, `AUTHENTICATION` KALICI sayılır ve listing "anahtarın
 * yanlış" diyerek ölürdü — oysa anahtar doğrudur.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KAPSAM — SLICE 1.1–1.5
 * ─────────────────────────────────────────────────────────────────────
 * Yazılan: kimlik/başlık katmanı, sağlık kontrolü (konum kontrolü dahil),
 * hata sınıflandırma, hız sınırı profili, webhook imza doğrulaması,
 * GraphQL sarmalayıcı + `userErrors` kontrolü, **katalog** (create /
 * update / delist / findExisting / fetchListing), **ürün içe aktarma**,
 * **stok** (mutlak değer, `inventorySetOnHandQuantities`), **fiyat**
 * (mutlak değer, string, `productVariantsBulkUpdate`), **sipariş webhook'u**
 * (`ShopifyOrderNormalizer` — iptal AYRI konudur, Woo'nun ezme kuralı
 * KOPYALANMAZ).
 *
 * YAZILMAYAN: kargo (`SupportsFulfillment`) ve `app/uninstalled`
 * (§27 · slice 1.8–1.9). Yetenek arayüzleri o slice'larda İLAN EDİLİR —
 * ilan edilen ama çalışmayan yetenek panelde çalışmayan sekme demektir
 * (§05).
 */
final class ShopifyAdapter implements ChannelAdapter, DeclaresImageLimit, SupportsCatalog, SupportsCatalogImport, SupportsFulfillment, SupportsInventory, SupportsOrders, SupportsPricing, SupportsTokenRefresh, SupportsWebhookRegistration
{
    use DeclaresRequestQuota;

    /** Kimlik başlığı — Bearer DEĞİL (sınıf başlığı). */
    private const AUTH_HEADER = 'X-Shopify-Access-Token';

    /** Webhook HMAC başlığı — base64, ham gövde üzerinden. */
    private const SIGNATURE_HEADER = 'x-shopify-hmac-sha256';

    /** Olay kimliği başlığı — v2.2 §6 tablosunda ZATEN kayıtlı. */
    private const EVENT_ID_HEADER = 'x-shopify-event-id';

    /** Konum kimliğinin `settings` içindeki yeri (§06.4). */
    public const LOCATION_KEY = 'location_gid';

    /**
     * GraphQL maliyet kovası — varsayılan profil.
     *
     * Shopify'da sınır istek sayısı DEĞİL SORGU MALİYETİDİR: 1.000 puanlık
     * kova, saniyede 50 puan yenilenir. `ChannelRateLimiter` DEĞİŞMEZ —
     * bir "jeton" bir puan olarak yorumlanır (§06.8).
     *
     * Shopify Plus'ta kova 2.000 puandır ve gerçek değer yanıt gövdesinden
     * ÖĞRENİLİR (Trendyol kararının aynısı); bu sabit yalnızca ilk istek
     * için geçerlidir.
     */
    private const DEFAULT_RESTORE_RATE = 50;

    private const DEFAULT_BUCKET_SIZE = 1000;

    /**
     * `inventorySetOnHandQuantities` tek mutation'da çok kalem kabul eder.
     *
     * 250 seçildi (§06.5): Shopify'ın belgelenmiş sınırı budur. Aşımın
     * bedeli ağır — kanal isteği kısmen işlerse hangi kalemin gittiği
     * bilinmez (Hepsiburada'daki parti boyutu gerekçesinin aynısı).
     */
    private const MAX_INVENTORY_BATCH = 250;

    /**
     * Fiyat turunda kaç kalem gruplanır.
     *
     * Stokla AYNI değer: `productVariantsBulkUpdate` ürün başına ayrıştığı
     * için gerçek sınır kalem sayısı değil ÇAĞRI sayısıdır (§06.8'in
     * maliyet kovası). 250 kalem en kötü durumda — her kalem ayrı üründe —
     * 250 çağrı demektir ve daha büyük bir sayı kovayı tek turda boşaltır.
     */
    private const MAX_PRICE_BATCH = 250;

    /**
     * Son yanıtın bildirdiği kova durumu — bir sonraki çağrının kararı.
     *
     * @var array{available: float, restoreRate: float, lastCost: float}|null
     */
    private ?array $bucket = null;

    /**
     * Bu süreden uzun bekleme İŞ İÇİNDE yapılmaz; iş kuyruğa geri bırakılır.
     *
     * İş zaman aşımı 60 sn'dir (`config/horizon.php`); 250 kalemlik bir
     * fiyat turu birkaç kısa bekleme yapabilir ama tek bir uzun bekleme
     * worker'ı boşuna tutar.
     */
    private const MAX_INLINE_WAIT_SECONDS = 5;

    public function __construct(
        private readonly ChannelConnection $connection,
        private readonly ChannelHttpClient $client,
    ) {}

    /** Shopify: ürün başına en fazla 250 medya. */
    public function maxImages(): int
    {
        return 250;
    }

    public function connection(): ChannelConnection
    {
        return $this->connection;
    }

    // ---------------------------------------------------------------- sağlık

    /**
     * Mağaza bilgisi okunur, gecikme ölçülür ve KONUM varlığı doğrulanır.
     *
     * Sağlık kontrolü geçmeden bağlantı `active` OLMAZ (v2.2 · §13 · faz
     * 1.4): aktif ama çalışmayan bağlantı en pahalı hata biçimidir.
     *
     * ⚠️ KONUM SEÇİLMEMİŞSE BAĞLANTI SAĞLIKSIZDIR (P1-5 · §06.4).
     * Shopify bir mağazada birden çok konum destekler ve stok yazma
     * `location_gid` ister. Varsayılan konumu SESSİZCE seçmek, iki depolu
     * bir satıcının stoğunu YANLIŞ DEPOYA yazardı — geri alınamaz ve
     * satıcı bunu ancak siparişler yanlış depodan çıkınca fark eder.
     */
    public function healthCheck(): HealthResult
    {
        $startedAt = hrtime(true);

        try {
            $data = $this->gql(
                <<<'GQL'
                query ShopHealth {
                  shop { id name myshopifyDomain }
                }
                GQL,
                operation: 'ShopHealth',
            );

            $latency = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            if (! isset($data['shop']['id'])) {
                return HealthResult::unhealthy(
                    'Shopify yanıtı mağaza bilgisi taşımıyor.'
                );
            }

            if ($this->locationGid() === null) {
                return HealthResult::unhealthy(
                    'Stok konumu (location) seçilmedi. Konum seçilmeden stok '.
                    'gönderilemez; varsayılanı sessizce seçmek çok depolu '.
                    'mağazada stoğu yanlış depoya yazardı.'
                );
            }

            return HealthResult::healthy(latencyMs: $latency);
        } catch (Throwable $e) {
            return HealthResult::unhealthy($e->getMessage());
        }
    }

    // ------------------------------------------------------------ hız sınırı

    /**
     * Maliyet tabanlı profil — projede ilk.
     *
     * Sınır yanıt GÖVDESİNDEN öğrenilir (`extensions.cost.throttleStatus`)
     * ve bağlantıya yazılır; sabit profil Plus müşterisini yavaşlatır,
     * standart mağazayı 429'a sokardı (§06.8 · Trendyol kararının aynısı).
     *
     * Öğrenilen değer `channel_types.rate_limit_profile` üzerinden değil
     * BAĞLANTI üzerinden gelir — kova bağlantı başınadır.
     */
    public function rateLimitProfile(): RateLimitProfile
    {
        $profile = $this->connection->channelType?->rate_limit_profile;

        return is_array($profile) && $profile !== []
            ? RateLimitProfile::fromArray($profile)
            : new RateLimitProfile(
                requestsPerSecond: self::DEFAULT_RESTORE_RATE,
                burstCapacity: self::DEFAULT_BUCKET_SIZE,
            );
    }

    // -------------------------------------------------------- sınıflandırma

    /**
     * Shopify hatasını çekirdeğin anladığı sınıfa çevirir.
     *
     * SINIFLANDIRMA BURADA, KARAR ÇEKİRDEKTE (`RetryPolicy`).
     *
     * ⚠️ `userErrors` KALICIDIR (`VALIDATION`): iş kuralı ihlalidir ve
     * yeniden denemek AYNI sonucu verir — yalnızca kotayı harcar. Taşıma
     * hatası (`errors`) ise çoğunlukla şema/sorgu sorunudur ve o da
     * kalıcıdır; ikisi de düzeltme ister, yeniden deneme değil.
     *
     * ⚠️ İKİ TAŞIMA HATASI İSTİSNADIR ve 200 gövdesinde gelir: `THROTTLED`
     * (kova boş — bekle) ve `INTERNAL_SERVER_ERROR` (Shopify tarafı).
     * Önceden ikisi de VALIDATION sayılıyordu: toplu bir tur kovayı
     * boşaltınca yüzlerce listing kalıcı hataya düşerdi.
     */
    public function classifyError(Throwable $e): ErrorClass
    {
        if ($e instanceof ShopifyGraphqlException) {
            return match (true) {
                $e->hasCode(ShopifyGraphqlException::THROTTLED) => ErrorClass::RATE_LIMITED,
                $e->hasCode(ShopifyGraphqlException::INTERNAL_SERVER_ERROR) => ErrorClass::SERVER_ERROR,
                default => ErrorClass::VALIDATION,
            };
        }

        if ($e instanceof ConnectionException) {
            return ErrorClass::NETWORK;
        }

        if (! $e instanceof RequestException) {
            return ErrorClass::NETWORK;
        }

        $status = $e->response->status();

        return match (true) {
            $status === 429 => ErrorClass::RATE_LIMITED,
            $status === 401, $status === 403 => ErrorClass::AUTHENTICATION,
            $status === 404 => ErrorClass::NOT_FOUND,
            $status === 409 => ErrorClass::CONFLICT,
            $status === 408 => ErrorClass::TIMEOUT,
            $status >= 500 => ErrorClass::SERVER_ERROR,
            $status >= 400 => ErrorClass::VALIDATION,
            default => ErrorClass::SERVER_ERROR,
        };
    }

    // ------------------------------------------------------------- webhook

    /**
     * HMAC — HAM GÖVDE üzerinden, JSON AYRIŞTIRMADAN ÖNCE (P0-6).
     *
     * Shopify imzayı base64 olarak gönderir:
     * `base64(hmac_sha256(raw, secret))`.
     *
     * SABİT ZAMANLI KARŞILAŞTIRMA (`hash_equals`): `===` ilk farklı baytta
     * döner ve süre doğru ön ek uzunluğunu SIZDIRIR.
     *
     * WEBHOOK SIRRI YOKSA DOĞRULAMA "GEÇTİ" DEMEZ — güvenli taraf
     * REDDETMEKTİR; kabul etmek imzasız sipariş enjeksiyonuna kapı açardı.
     *
     * KİRACI BAĞLAMI BEKLENMEZ: webhook anonim gelir ve kiracı ancak
     * bağlantı bulunduktan sonra bilinir; sır `runAsSystem()` ile okunur.
     * Bağlam beklenirse MEŞRU HER WEBHOOK sessizce reddedilir ve kanal
     * sonsuza kadar yeniden gönderir.
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function verifyWebhookSignature(string $raw, array $headers): bool
    {
        $provided = $this->header($headers, self::SIGNATURE_HEADER);

        if ($provided === null || $provided === '') {
            return false;
        }

        // UYGULAMA ÜZERİNDEN bağlanan mağazada Shopify gövdeyi 34Pazar
        // uygulamasının İSTEMCİ SIRRIYLA imzalar — kasada mağazaya özel
        // sır yoktur.
        if (ShopifyAuth::webhookHmacValid($raw, $provided)) {
            return true;
        }

        // Eski yol: elle açılmış özel uygulama (`shpat_`) + formdan girilen
        // imza anahtarı. Uygulamaya geçmemiş bağlantılar için korunur.
        $secret = $this->webhookSecret();

        if ($secret === null || $secret === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $raw, $secret, true));

        return hash_equals($expected, $provided);
    }

    // ------------------------------------------------------------ uygulama (OAuth)

    /**
     * Süresi dolan erişim anahtarını yeniler (`credentials:refresh` turu).
     *
     * Shopify yeni anahtar verince ESKİSİNİ emekliye ayırır; yenileme
     * anahtarı da DÖNER (90 gün) — dönmezse eskisi korunur.
     */
    public function refreshCredentials(): RefreshedCredentials
    {
        $secrets = $this->secrets();
        $refresh = $secrets['refresh_token'] ?? null;

        if (! is_string($refresh) || $refresh === '') {
            throw new RuntimeException(
                'Shopify yenileme anahtarı yok — bağlantı elle açılmış özel '.
                'uygulamayla kurulmuş olabilir; uygulama üzerinden yeniden bağlanmalı.'
            );
        }

        $response = $this->client->post(
            endpoint: ShopifyAuth::tokenUrl($this->shopDomain()),
            body: ShopifyAuth::refreshRequest($refresh),
            asForm: true,
        );

        $response->throw();

        $fresh = ShopifyAuth::credentialsFrom((array) $response->json(), $refresh);

        return new RefreshedCredentials(
            secrets: [...$secrets, ...$fresh['secrets']],
            expiresAt: $fresh['expires_at'],
            scope: $fresh['scope'],
            refreshExpiresAt: $fresh['refresh_expires_at'],
        );
    }

    /**
     * Erişim anahtarı 1 SAAT yaşar, tur 15 dakikada bir koşar: 30 dakika
     * önden yenilemek bir turun kaçmasına (sunucu yeniden başlıyor) yer
     * bırakır. 15 dakika seçilseydi tek kaçan tur anahtarı öldürürdü.
     */
    public function refreshLeadSeconds(): int
    {
        return 1800;
    }

    /**
     * Uygulamanın dinlediği konular. Normalleştirici (`ShopifyOrderNormalizer`)
     * sipariş konularını, yaşam döngüsü yönlendiricisi `app/uninstalled`'ı okur.
     */
    private const WEBHOOK_TOPICS = [
        'ORDERS_CREATE', 'ORDERS_UPDATED', 'ORDERS_CANCELLED', 'ORDERS_FULFILLED',
        'ORDERS_PARTIALLY_FULFILLED', 'REFUNDS_CREATE', 'APP_UNINSTALLED',
    ];

    /**
     * Webhook aboneliklerini mağazada kurar; yeniden çağrılırsa kopya açmaz.
     *
     * `$secret` YOK SAYILIR: Shopify uygulama webhook'larını uygulamanın
     * istemci sırrıyla imzalar, abone başına sır kabul etmez.
     * Abonelik ADRES + KONU ile tanınır; aynı uygulamanın başka adrese
     * giden aboneliği (eski sunucu) dokunulmadan kalır.
     */
    public function registerWebhooks(string $deliveryUrl, string $secret): AdapterResult
    {
        $data = $this->gql(
            <<<'GQL'
            query WebhookList {
              webhookSubscriptions(first: 100) { nodes { id topic uri } }
            }
            GQL,
            operation: 'WebhookList',
        );

        $existing = [];
        foreach ($data['webhookSubscriptions']['nodes'] ?? [] as $node) {
            if (is_array($node) && ($node['uri'] ?? null) === $deliveryUrl) {
                $existing[(string) ($node['topic'] ?? '')] = true;
            }
        }

        $created = [];
        foreach (self::WEBHOOK_TOPICS as $topic) {
            if (isset($existing[$topic])) {
                continue;
            }

            $this->gql(
                <<<'GQL'
                mutation WebhookCreate($topic: WebhookSubscriptionTopic!, $sub: WebhookSubscriptionInput!) {
                  webhookSubscriptionCreate(topic: $topic, webhookSubscription: $sub) {
                    webhookSubscription { id }
                    userErrors { field message }
                  }
                }
                GQL,
                variables: ['topic' => $topic, 'sub' => ['uri' => $deliveryUrl]],
                operation: 'WebhookCreate',
                userErrorPath: 'webhookSubscriptionCreate',
            );

            $created[] = $topic;
        }

        return AdapterResult::success(['created' => $created, 'kept' => array_keys($existing)]);
    }

    /**
     * Mağazanın AKTİF stok konumları — bağlantıda konum seçimi için.
     *
     * @return list<array{id: string, name: string}>
     */
    public function fetchLocations(): array
    {
        $data = $this->gql(
            <<<'GQL'
            query Locations {
              locations(first: 50) { nodes { id name isActive } }
            }
            GQL,
            operation: 'Locations',
        );

        $out = [];
        foreach ($data['locations']['nodes'] ?? [] as $node) {
            if (is_array($node) && ($node['isActive'] ?? false) === true && is_string($node['id'] ?? null)) {
                $out[] = ['id' => $node['id'], 'name' => (string) ($node['name'] ?? $node['id'])];
            }
        }

        return $out;
    }

    /**
     * Olay kimliği — tekilleştirmenin BİRİNCİL çıpası.
     *
     * `X-Shopify-Event-Id` v2.2 §6 tablosunda ZATEN kayıtlıdır; türetilmiş
     * kimliğe (`{id}:{status}`) gerek yoktur çünkü kanal gerçek bir olay
     * kimliği veriyor.
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function extractEventId(array $headers): ?string
    {
        return $this->header($headers, self::EVENT_ID_HEADER);
    }

    /**
     * Olay tipi — Shopify'da "topic" denir (`orders/create`).
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function extractEventType(array $headers): string
    {
        return $this->header($headers, 'x-shopify-topic') ?? 'unknown';
    }

    /**
     * Webhook'u gönderen mağazanın alan adı — bağlantıyı bulan anahtar.
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function extractShopDomain(array $headers): ?string
    {
        return $this->header($headers, 'x-shopify-shop-domain');
    }

    // ------------------------------------------------------------- GraphQL

    /**
     * GraphQL sorgusu çalıştırır ve `data` bloğunu döner.
     *
     * TEK POST İSTEĞİDİR — `ChannelHttpClient` DEĞİŞMEZ (§06.3): GraphQL
     * gövdede taşınan bir sorgudur, yeni bir taşıma katmanı gerektirmez.
     *
     * ⚠️ HER YANIT `assertNoGraphqlErrors()` ÜZERİNDEN GEÇER — İSTİSNASIZ.
     * Bu metodu atlayıp `$response->json()` okuyan her yol, 200 dönen bir
     * başarısızlığı BAŞARI sayar (P0-1).
     *
     * @param  array<string, mixed>  $variables
     * @param  string|null  $userErrorPath  Mutation'ın `userErrors` alanının
     *                                      yolu (`productUpdate`). Query'lerde
     *                                      null — query `userErrors` taşımaz.
     * @return array<string, mixed> `data` bloğu
     */
    public function gql(
        string $query,
        array $variables = [],
        string $operation = 'graphql',
        ?string $userErrorPath = null,
        ?string $attemptId = null,
    ): array {
        $this->waitForBucket($operation);

        $response = $this->client->post(
            endpoint: ShopifyEndpoints::graphql($this->shopDomain()),
            body: array_filter([
                'query' => $query,
                'variables' => $variables === [] ? null : $variables,
            ], static fn ($v): bool => $v !== null),
            attemptId: $attemptId,
            headers: $this->defaultHeaders(),
        );

        // HTTP katmanı yine de kontrol edilir: 401/429/5xx GraphQL'e hiç
        // ulaşmaz ve gövdesi `errors` taşımaz.
        $response->throw();

        $this->rememberBucket($response);

        return $this->assertNoGraphqlErrors($response, $operation, $userErrorPath);
    }

    /**
     * ⚠️ KOVA ÖNCEDEN GÖZETİLİR — Shopify'ın önerdiği istemci davranışı.
     *
     * Sınır istek sayısı değil SORGU MALİYETİDİR (§06.8) ve `ChannelRateLimiter`
     * iş başına tek jeton düşer: 250 ayrı ürünlü bir fiyat turu tek işte 250
     * mutation atar. Kova durumu her yanıtın gövdesinde gelir
     * (`extensions.cost.throttleStatus`); son çağrının maliyeti kalan
     * puanı aşıyorsa yenilenene kadar KISA bir süre beklenir. Gerçek kova
     * boyutu da buradan okunduğu için Plus mağazasının 2.000 puanı kendiliğinden
     * kullanılır.
     *
     * Bekleme uzunsa istek HİÇ ATILMAZ: THROTTLED fırlatılır, sınıfı
     * RATE_LIMITED'dır ve iş hesaplanan süre sonra yeniden denenir —
     * kovayı boşaltıp 200 içinde THROTTLED almaktan iyidir.
     */
    private function waitForBucket(string $operation): void
    {
        if ($this->bucket === null || $this->bucket['available'] >= $this->bucket['lastCost']) {
            return;
        }

        $seconds = (int) ceil(
            ($this->bucket['lastCost'] - $this->bucket['available']) / max($this->bucket['restoreRate'], 1.0)
        );

        if ($seconds > self::MAX_INLINE_WAIT_SECONDS) {
            throw new ShopifyGraphqlException(
                $operation,
                [['message' => 'Maliyet kovası boş — istek atılmadı.', 'extensions' => ['code' => ShopifyGraphqlException::THROTTLED]]],
                retryAfter: $seconds,
            );
        }

        Sleep::for($seconds)->seconds();

        // Bekleme boyunca kova dolmuştur; yeni yanıt gerçek değeri yazar.
        $this->bucket['available'] += $seconds * $this->bucket['restoreRate'];
    }

    private function rememberBucket(Response $response): void
    {
        $cost = $response->json('extensions.cost');

        if (! is_array($cost)) {
            return;
        }

        $available = $cost['throttleStatus']['currentlyAvailable'] ?? null;
        $restore = $cost['throttleStatus']['restoreRate'] ?? null;
        $spent = $cost['actualQueryCost'] ?? $cost['requestedQueryCost'] ?? null;

        // SAYI OLMAYAN DEĞER YOK SAYILIR (`learnedRateLimit` kuralı).
        if (! is_numeric($available) || ! is_numeric($restore) || ! is_numeric($spent)) {
            return;
        }

        $this->bucket = [
            'available' => (float) $available,
            'restoreRate' => (float) $restore,
            'lastCost' => (float) $spent,
        ];
    }

    /**
     * THROTTLED yanıtında kovanın istenen maliyeti karşılamasına kalan süre.
     *
     * Shopify `Retry-After` GÖNDERMEZ; süre gövdeden hesaplanır (§06.8).
     *
     * @param  array<string, mixed>  $body
     */
    private static function secondsUntilAffordable(array $body): ?int
    {
        $requested = $body['extensions']['cost']['requestedQueryCost'] ?? null;
        $available = $body['extensions']['cost']['throttleStatus']['currentlyAvailable'] ?? null;
        $restore = $body['extensions']['cost']['throttleStatus']['restoreRate'] ?? null;

        if (! is_numeric($requested) || ! is_numeric($available) || ! is_numeric($restore) || $restore <= 0) {
            return null;
        }

        return max(1, (int) ceil(((float) $requested - (float) $available) / (float) $restore));
    }

    /**
     * ⚠️ P0-1 · T-V3-11 — 200 GÖVDE KODU BAŞARI SAYILMAZ.
     *
     * İKİ AYRI HATA KANALI VARDIR ve ikisi de kontrol edilir:
     *
     *   `errors`      — taşıma/şema hatası (sorgu bozuk, alan yok, yetki yok)
     *   `userErrors`  — İŞ KURALI hatası, mutation'a ÖZGÜ alan altında
     *                   (`productUpdate.userErrors`)
     *
     * `userErrors` YOLU ADAPTER TARAFINDAN VERİLİR çünkü her mutation onu
     * kendi adının altında döndürür; tek bir sabit yol yazılamaz. Yol
     * verilmezse (query çağrıları) o kontrol atlanır — query `userErrors`
     * taşımaz ve uydurma bir yol aramak her query'yi kırardı.
     *
     * @return array<string, mixed>
     */
    private function assertNoGraphqlErrors(
        Response $response,
        string $operation,
        ?string $userErrorPath,
    ): array {
        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        // 1 · TAŞIMA / ŞEMA HATASI
        $errors = $body['errors'] ?? null;

        if (is_array($errors) && $errors !== []) {
            /** @var list<array<string, mixed>> $errors */
            throw new ShopifyGraphqlException(
                $operation,
                array_values($errors),
                retryAfter: self::secondsUntilAffordable($body),
            );
        }

        /** @var array<string, mixed> $data */
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        // 2 · İŞ KURALI HATASI — mutation başına ayrı alan
        if ($userErrorPath !== null) {
            $userErrors = $data[$userErrorPath]['userErrors'] ?? null;

            if (is_array($userErrors) && $userErrors !== []) {
                /** @var list<array<string, mixed>> $userErrors */
                throw new ShopifyGraphqlException(
                    $operation,
                    array_values($userErrors),
                    isUserError: true,
                );
            }
        }

        return $data;
    }

    /**
     * Yanıt gövdesinden ÖĞRENİLEN hız sınırı — yoksa null.
     *
     * `extensions.cost.throttleStatus` gövdededir (§06.8). Shopify Plus'ta
     * kova 2.000 puandır; sabit profil Plus'ı yavaşlatır, standardı 429'a
     * sokar.
     *
     * ⚠️ SAYI OLMAYAN DEĞER YOK SAYILIR. Trendyol'da vekil sunucunun iki
     * başlığı birleştirmesiyle yaşandı (`600, 300` → `(int)` sessizce 600'e
     * iner ve DÜŞÜK sınır yok sayılırdı). Burada değerler float gelir,
     * bu yüzden `is_numeric` süzgeci kullanılır.
     *
     * @return array{bucket: int, restoreRate: int}|null
     */
    public function learnedRateLimit(Response $response): ?array
    {
        $throttle = $response->json('extensions.cost.throttleStatus');

        if (! is_array($throttle)) {
            return null;
        }

        $bucket = $throttle['maximumAvailable'] ?? null;
        $restore = $throttle['restoreRate'] ?? null;

        if (! is_numeric($bucket) || ! is_numeric($restore)) {
            return null;
        }

        $bucket = (int) $bucket;
        $restore = (int) $restore;

        // Sıfır veya negatif sınır anlamsızdır ve kovayı kilitlerdi.
        if ($bucket <= 0 || $restore <= 0) {
            return null;
        }

        return ['bucket' => $bucket, 'restoreRate' => $restore];
    }

    // ------------------------------------------------------------- katalog

    /**
     * Ürünü kanalda YARATIR — tek `productSet` mutation'ı.
     *
     * TEK ÇAĞRI, ARA KİMLİK YOK: Shopify ürünü ve varyantını birlikte
     * yazar. Ayrı `productCreate` + `productVariantsBulkCreate` çağrılsaydı
     * ara başarısızlıkta ürün yaratılmış ama varyantı olmayan bir kabuk
     * kalırdı — eBay'in üç adımlı zincirindeki tuzağın aynısı (§13.2) ve
     * Shopify'da buna GEREK YOKTUR (`SupportsOfferLifecycle` bu kanalda
     * UYGULANMAZ).
     *
     * ÜÇ KİMLİK BİRDEN OKUNUR: variant gid (`external_id`), product gid
     * (`external_parent_id`) ve **inventory item gid** (`channel_metadata`).
     * Sonuncusu stok yazma hedefidir ve burada okunmasaydı her stok itmesi
     * ek bir GraphQL sorgusu gerektirir, kritik yolu İKİ KATINA çıkarırdı
     * (§06.4).
     *
     * ⚠️ `userErrors` KONTROLÜ `gql()` İÇİNDE — 200 dönen bir başarısızlık
     * burada BAŞARI sayılmaz (P0-1).
     */
    public function createListing(ListingPayload $payload): AdapterResult
    {
        $data = $this->gql(
            <<<'GQL'
            mutation CreateProduct($input: ProductSetInput!) {
              productSet(synchronous: true, input: $input) {
                product {
                  id
                  variants(first: 1) {
                    nodes { id sku inventoryItem { id } }
                  }
                }
                userErrors { field message code }
              }
            }
            GQL,
            variables: ['input' => ShopifyProductMapper::toProductSetInput($payload)],
            operation: 'productSet',
            userErrorPath: 'productSet',
        );

        $product = $data['productSet']['product'] ?? null;

        if (! is_array($product)) {
            // Yanıt 200, `userErrors` boş ama ürün YOK: sözleşme ihlali.
            // Başarı dönülseydi `synced_version` ilerler ve satır kanalda
            // karşılığı olmadan "senkron" görünürdü (P0-1'in kardeşi).
            return AdapterResult::failure(
                ErrorClass::VALIDATION,
                'Shopify productSet yanıtı ürün taşımıyor.',
            );
        }

        $identity = ShopifyProductMapper::toIdentityResult($product, $this->shopDomain());

        if (! isset($identity['external_id'])) {
            // Varyant kimliği yoksa listing kanalda ADRESLENEMEZ: sonraki
            // tur update çağırır, Shopify boş gid'i tanımaz ve `VALIDATION`
            // döner — o hata KALICIDIR.
            return AdapterResult::failure(
                ErrorClass::VALIDATION,
                'Shopify yanıtı varyant kimliği taşımıyor; listing adreslenemez.',
            );
        }

        return AdapterResult::success($this->withNewMedia($payload, (string) ($identity['external_parent_id'] ?? ''), $identity));
    }

    /**
     * Var olan ürünü GÜNCELLER.
     *
     * HEDEF ÜRÜNDÜR, VARYANT DEĞİL: içerik (başlık, açıklama) ürün
     * seviyesindedir. `external_parent_id` yoksa güncelleme yapılamaz —
     * `external_id` (varyant gid) tek başına ürün mutation'ına verilemez.
     */
    public function updateListing(ListingPayload $payload): AdapterResult
    {
        $listing = $payload->listing;
        $productGid = $listing->external_parent_id;
        $variantGid = $listing->external_id;

        if ($productGid === null || $productGid === '' || $variantGid === null || $variantGid === '') {
            return AdapterResult::failure(
                ErrorClass::VALIDATION,
                'Güncellenecek Shopify ürünü veya varyantı bilinmiyor (external_parent_id / external_id boş).',
            );
        }

        // ① ÜRÜN ALANLARI — `productUpdate`, `productSet` DEĞİL.
        //
        // ⚠️ `productSet` varyant listesini TAMAMEN yazar ve listede olmayan
        // varyantları SİLER. Bizde listing varyant başınadır; satıcının
        // Shopify'da açtığı çok varyantlı bir ürüne tek varyantla
        // `productSet` atmak kardeş varyantları kanaldan silerdi
        // (`ShopifyProductMapper::toProductUpdateInput`).
        $this->gql(
            <<<'GQL'
            mutation UpdateProduct($product: ProductUpdateInput!) {
              productUpdate(product: $product) {
                product { id }
                userErrors { field message code }
              }
            }
            GQL,
            variables: ['product' => ShopifyProductMapper::toProductUpdateInput($payload, $productGid)],
            operation: 'productUpdate',
            userErrorPath: 'productUpdate',
        );

        // ② YALNIZCA BU VARYANT — kardeşlere dokunulmaz.
        //
        // Fiyat burada GÖNDERİLMEZ: kendi domainindedir (`PushPrices`) ve
        // içerik düzenlemesi kampanya fiyatını ezmemeli. Stok da aynı
        // gerekçeyle yoktur.
        $data = $this->gql(
            <<<'GQL'
            mutation UpdateVariant($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
              productVariantsBulkUpdate(productId: $productId, variants: $variants) {
                productVariants { id sku inventoryItem { id } }
                userErrors { field message code }
              }
            }
            GQL,
            variables: [
                'productId' => $productGid,
                'variants' => [[
                    'id' => $variantGid,
                    'inventoryItem' => array_filter([
                        'sku' => $listing->variant?->sku,
                        'tracked' => true,
                    ], static fn (mixed $v): bool => $v !== null),
                ]],
            ],
            operation: 'productVariantsBulkUpdate',
            userErrorPath: 'productVariantsBulkUpdate',
        );

        $variant = $data['productVariantsBulkUpdate']['productVariants'][0] ?? null;

        if (! is_array($variant) || ! isset($variant['id'])) {
            return AdapterResult::failure(
                ErrorClass::VALIDATION,
                'Shopify varyant güncelleme yanıtı varyant taşımıyor.',
            );
        }

        // Kimlikler YENİDEN okunur — ve BU VARYANTTAN okunur. Satıcı
        // varyantı silip yeniden yaratmışsa inventory item gid DEĞİŞİR;
        // ürünün "ilk varyantından" okunsaydı çok varyantlı üründe BAŞKA
        // varyantın stok hedefi yazılır ve stok sessizce yanlış varyanta
        // giderdi.
        return AdapterResult::success($this->withNewMedia($payload, $productGid, ShopifyProductMapper::toIdentityResult(
            ['id' => $productGid, 'variants' => ['nodes' => [$variant]]],
            $this->shopDomain(),
        )));
    }

    /**
     * Daha önce GÖNDERİLMEMİŞ görselleri ürüne EKLER (A15).
     *
     * ⚠️ YALNIZCA EKLER, DEĞİŞTİRMEZ. `productSet files` tam değiştirme
     * yapar: bizim listemiz (bu mağazadan gelenler hariç) gönderilseydi
     * satıcının Shopify'daki kendi görselleri SİLİNİRDİ. Bizde silinen
     * görsel de Shopify'dan silinmez — satıcının medyasını silmek geri
     * alınamaz.
     *
     * ⚠️ GÖNDERİLENLER LİSTING'DE TUTULUR (`pushed_image_urls`). Shopify
     * her adresi yeniden indirir; liste olmasaydı her içerik turunda
     * bütün görseller bir kez daha eklenir ve ürün kopyalarla dolardı.
     *
     * MEDYA HATASI ÜRÜNÜ GERİ SAYMAZ: ürün yazıldı ve kimliği
     * saklanmalı; aksi hâlde yeniden deneme ikinci bir ürün yaratabilirdi.
     * Başarısız adresler listeye EKLENMEZ ve sonraki turda yeniden denenir.
     *
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    private function withNewMedia(ListingPayload $payload, string $productGid, array $identity): array
    {
        $variant = $payload->listing->variant;

        if ($variant === null || $productGid === '') {
            return $identity;
        }

        $pushed = (array) ($payload->listing->channel_metadata['pushed_image_urls'] ?? []);

        $new = array_values(array_diff(
            array_slice(ChannelImages::urlsFor($variant, 'shopify', $this->connection->id), 0, $this->maxImages()),
            $pushed,
        ));

        if ($new === []) {
            return $identity;
        }

        try {
            $this->gql(
                <<<'GQL'
                mutation AddMedia($product: ProductUpdateInput!, $media: [CreateMediaInput!]) {
                  productUpdate(product: $product, media: $media) {
                    product { id }
                    userErrors { field message }
                  }
                }
                GQL,
                variables: [
                    'product' => ['id' => $productGid],
                    'media' => array_map(
                        static fn (string $url): array => ['originalSource' => $url, 'mediaContentType' => 'IMAGE'],
                        $new,
                    ),
                ],
                operation: 'productUpdate',
                userErrorPath: 'productUpdate',
            );
        } catch (Throwable $e) {
            Log::warning('shopify.media_add_failed', [
                'connection' => $this->connection->id,
                'product' => $productGid,
                'urls' => count($new),
                'error' => $e->getMessage(),
            ]);

            return $identity;
        }

        return [
            ...$identity,
            'channel_metadata' => [
                ...($identity['channel_metadata'] ?? []),
                'pushed_image_urls' => array_values(array_unique([...$pushed, ...$new])),
            ],
        ];
    }

    /**
     * Yayından kaldırır — SİLMEZ, ARŞİVLER.
     *
     * v2.2 · `delist` kuralı: silme geri alınamaz ve kanaldaki yorumları,
     * sıralamayı, SEO geçmişini de götürür. Shopify'da karşılık `ARCHIVED`
     * durumudur; ürün satıcının panelinde durur ama satışta değildir.
     *
     * `DRAFT` DEĞİL `ARCHIVED` seçildi: taslak "henüz yayınlanmadı" der,
     * arşiv "yayındaydı, kaldırıldı" der. İkisi satıcıya farklı şey söyler
     * ve delist ikincisidir.
     */
    public function delist(Listing $listing): AdapterResult
    {
        $productGid = $listing->external_parent_id;

        if ($productGid === null || $productGid === '') {
            // Kanalda karşılığı yoksa yapılacak bir şey de yok.
            return AdapterResult::success(['already_absent' => true]);
        }

        // `productUpdate` — `productSet` DEĞİL: yalnızca durum yazılır ve
        // varyant listesine dokunan bir mutation burada riskten başka bir
        // şey getirmez.
        $this->gql(
            <<<'GQL'
            mutation ArchiveProduct($product: ProductUpdateInput!) {
              productUpdate(product: $product) {
                product { id status }
                userErrors { field message code }
              }
            }
            GQL,
            variables: ['product' => ['id' => $productGid, 'status' => 'ARCHIVED']],
            operation: 'productUpdate',
            userErrorPath: 'productUpdate',
        );

        return AdapterResult::success(['external_id' => $listing->external_id]);
    }

    /**
     * Kanalda AYNI SKU'yu arar — create'ten ÖNCE sorulur.
     *
     * Bu adım olmadan satıcının Shopify panelinden elle açtığı ürünler
     * yeniden yaratılır ve kanalda KOPYA listeler oluşur; geri alınamaz ve
     * yorumlar, sıralama, SEO geçmişi ilk üründe kalır (v2.2 · §7).
     *
     * SKU İLE ARANIR, başlıkla değil: başlık değişebilir ve iki farklı ürün
     * aynı başlığı taşıyabilir. SKU kiracı içinde tekildir ve kanalla
     * eşleşmenin anahtarıdır.
     */
    public function findExistingListing(Variant $variant): ?RemoteListing
    {
        $sku = trim((string) $variant->sku);

        if ($sku === '') {
            return null;
        }

        $data = $this->gql(
            <<<'GQL'
            query FindVariantBySku($query: String!) {
              productVariants(first: 1, query: $query) {
                nodes {
                  id sku price inventoryQuantity
                  inventoryItem { id }
                  product { id title status }
                }
              }
            }
            GQL,
            // Arama dizesi TIRNAK İÇİNDE verilir: boşluk veya tire içeren
            // SKU (`TSH-KIRMIZI-M`) tırnaksız yazılsaydı Shopify onu birden
            // çok terime böler ve BAŞKA ürünü döndürürdü.
            variables: ['query' => 'sku:"'.$this->escapeSearchTerm($sku).'"'],
            operation: 'productVariants',
        );

        $node = $data['productVariants']['nodes'][0] ?? null;

        if (! is_array($node) || ! isset($node['id'])) {
            return null;
        }

        // SKU TAM EŞLEŞMELİ: Shopify'ın arama motoru ön ek eşleşmesi
        // yapabilir ve `TSH-1` sorgusu `TSH-10`'u döndürebilir. Yanlış
        // ürünü benimsemek, satıcının BAŞKA ürününü bizim listing'imiz
        // sanıp üzerine yazmak demektir.
        if (! isset($node['sku']) || (string) $node['sku'] !== $sku) {
            return null;
        }

        return ShopifyProductMapper::toRemoteListing($node, $this->shopDomain());
    }

    /**
     * Uzak durumu okur — mutabakat ve çakışma tespiti için (§10).
     *
     * VARYANT gid ile sorgulanır: `external_id` odur ve stok/fiyat orada
     * yaşar.
     */
    public function fetchListing(Listing $listing): ?RemoteListing
    {
        $variantGid = $listing->external_id;

        if ($variantGid === null || $variantGid === '') {
            return null;
        }

        $data = $this->gql(
            <<<'GQL'
            query FetchVariant($id: ID!) {
              productVariant(id: $id) {
                id sku price inventoryQuantity
                inventoryItem { id }
                product { id title status }
              }
            }
            GQL,
            variables: ['id' => $variantGid],
            operation: 'productVariant',
        );

        $node = $data['productVariant'] ?? null;

        // NULL "KANALDA YOK" DEMEKTİR ve mutabakat bunu `REMOTE_MISSING`
        // olarak sınıflandırır; otomatik onarım AÇILMAZ (v2.2 · §10) —
        // sessizce yeniden yaratmak kanalda kopya ürün açardı.
        if (! is_array($node) || ! isset($node['id'])) {
            return null;
        }

        return ShopifyProductMapper::toRemoteListing($node, $this->shopDomain());
    }

    /**
     * Shopify arama dizesindeki özel karakterleri kaçırır.
     *
     * Tırnak ve ters eğik çizgi sorguyu BOZAR; kaçırılmazsa `12"` SKU'su
     * sorguyu ortadan böler ve sorgu ya hata verir ya BAŞKA ürünü döndürür.
     */
    private function escapeSearchTerm(string $term): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $term);
    }

    // ---------------------------------------------------------------- stok

    /**
     * Stok yazar — MUTLAK DEĞER, delta ASLA.
     *
     * V3.0 · §06.5 · v2.2 §7 · Karar 25.
     *
     * ⚠️ DELTA MUTATION'I (`inventoryAdjustQuantities`) YASAKTIR ve sebebi
     * v2.2'de yazılı: kaybolan veya İKİ KEZ işlenen bir istek kanaldaki
     * bakiyeyi KALICI olarak kaydırır ve fark geri kazanılamaz. Mutlak
     * değerde tekrar ZARARSIZDIR — aynı sayıyı ikinci kez yazmak durumu
     * değiştirmez. Yeniden denemenin güvenli olmasının ve mutabakatın
     * çalışabilmesinin dayanağı budur. Shopify'ın delta API'si daha
     * "verimli" görünür; bu görüntü ALDATICIDIR.
     *
     * ⚠️ HEDEF `inventoryItemId`, VARIANT GID DEĞİL. Mutation variant
     * gid'ini KABUL ETMEZ. Kimlik listing yaratılırken `channel_metadata`'ya
     * yazıldı (slice 1.3) ve burada TEK sorguyla okunur — her kalem için
     * ayrı GraphQL çevrimi yapılsaydı stok yolu (projenin en kritik yolu,
     * `inventory:high`, 45 sn) İKİ KATINA çıkardı.
     *
     * Okuma adapter'ın YAN ETKİSİ DEĞİLDİR: veritabanına yazmaz, yalnızca
     * kendi bağlantısının listing'lerini okur ve `Listing::query()` global
     * scope'a tabidir (ham sorgu kullanılmaz, §24).
     *
     * ⚠️ KİMLİĞİ OLMAYAN KALEM YÜKE ALINMAZ ve bu SESSİZ BİR ATLAMA
     * DEĞİLDİR — sonuçta raporlanır. Boş `inventoryItemId` ile giden
     * mutation `userErrors` döner, o hata `VALIDATION` yani KALICIDIR ve
     * listing "düzeltilemez" damgasıyla ölürdü. Kimlik eksikse listing'in
     * yeniden gönderilmesi gerekir (Hepsiburada'daki "satıcı kimliği yoksa
     * istek atılmaz" kuralının aynısı).
     */
    public function pushInventory(InventoryPushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            // Boş yük için çağrı yapılmaz; kota boşa harcanmaz.
            return AdapterResult::success(['pushed' => 0]);
        }

        $locationGid = $this->locationGid();

        if ($locationGid === null) {
            // Konum olmadan stok YAZILAMAZ. Sağlık kontrolü bunu bağlantı
            // kurulurken yakalar (P1-5); buraya düşmesi bağlantının sonradan
            // bozulduğu anlamına gelir.
            throw new RuntimeException(
                'Shopify stok konumu (location_gid) tanımsız — stok hangi '.
                'depoya yazılacağı bilinmeden gönderilemez.'
            );
        }

        $inventoryItemGids = $this->inventoryItemGidsFor($batch);

        $quantities = [];
        $missing = [];

        foreach ($batch->toArray() as $item) {
            $gid = $inventoryItemGids[$item['listing_id']] ?? null;

            if ($gid === null) {
                $missing[] = $item['sku'];

                continue;
            }

            $quantities[] = [
                'inventoryItemId' => $gid,
                'locationId' => $locationGid,
                // MUTLAK değer. Kırpma `OutboundQuantity::forChannel()`
                // içinde YAPILDI; burada tekrar YOK.
                'quantity' => $item['quantity'],
            ];
        }

        if ($quantities === []) {
            return AdapterResult::failure(
                ErrorClass::VALIDATION,
                'Shopify stok yükündeki hiçbir kalemin inventory item kimliği yok: '
                .implode(', ', $missing),
            );
        }

        $this->gql(
            <<<'GQL'
            mutation SetOnHand($input: InventorySetOnHandQuantitiesInput!) {
              inventorySetOnHandQuantities(input: $input) {
                inventoryAdjustmentGroup { createdAt }
                userErrors { field message code }
              }
            }
            GQL,
            variables: ['input' => [
                // Shopify denetim izinde görünür; satıcı stok değişiminin
                // NEREDEN geldiğini panelde görebilmelidir.
                'reason' => 'correction',
                'setQuantities' => $quantities,
            ]],
            operation: 'inventorySetOnHandQuantities',
            userErrorPath: 'inventorySetOnHandQuantities',
        );

        return AdapterResult::success(array_filter([
            'pushed' => count($quantities),
            // Atlanan kalemler SESSİZ DEĞİL: sonuç verisinde taşınır ve
            // denetim izine yazılır.
            'skipped_without_inventory_item' => $missing === [] ? null : $missing,
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * Uzak stok durumunu okur — mutabakatın karşılaştırma girdisi (§10).
     *
     * ⚠️ TOPLU OKUNUR — 50 listing TEK istekte. Listing başına ayrı istek
     * ölçek hesabını YÜZ KATINA çıkarırdı (v2.2 · §10).
     *
     * ⚠️ ANAHTAR `external_id` (VARIANT GID) OLMALIDIR — mutabakat kalemi
     * onunla eşleştirir. `inventoryItemId` ile anahtarlansaydı karşılaştırma
     * HİÇBİR listing'i bulamaz ve her tur "uzak değer okunamadı" derdi.
     *
     * @param  list<Listing>  $listings
     */
    public function fetchInventory(array $listings): RemoteInventorySnapshot
    {
        $gids = [];

        foreach ($listings as $listing) {
            if (is_string($listing->external_id) && $listing->external_id !== '') {
                $gids[] = $listing->external_id;
            }
        }

        if ($gids === []) {
            return new RemoteInventorySnapshot([]);
        }

        $data = $this->gql(
            <<<'GQL'
            query FetchInventory($ids: [ID!]!) {
              nodes(ids: $ids) {
                ... on ProductVariant { id inventoryQuantity }
              }
            }
            GQL,
            variables: ['ids' => $gids],
            operation: 'nodes',
        );

        $quantities = [];

        foreach ((array) ($data['nodes'] ?? []) as $node) {
            // SİLİNMİŞ varyant `null` döner ve ATLANIR: sıfır yazılsaydı
            // mutabakat "kanalda 0 var" sanır ve sürüklenme raporlardı;
            // oysa satır kanalda HİÇ YOKTUR ve doğru sınıflandırma
            // `REMOTE_MISSING`'dir (v2.2 · §10).
            if (! is_array($node) || ! isset($node['id'])) {
                continue;
            }

            if (! array_key_exists('inventoryQuantity', $node) || $node['inventoryQuantity'] === null) {
                continue;
            }

            $quantities[(string) $node['id']] = (int) $node['inventoryQuantity'];
        }

        return new RemoteInventorySnapshot($quantities, new \DateTimeImmutable);
    }

    /**
     * Tek mutation'da kaç kalem gönderilebilir.
     *
     * `InventoryBatchBuilder` bu sınıra göre GRUPLAMA yapar; operasyon
     * sayısı DEĞİŞMEZ (v2.2 · fan-out kuralı).
     */
    public function maxInventoryBatchSize(): int
    {
        return self::MAX_INVENTORY_BATCH;
    }

    /**
     * Yükteki listing'lerin `inventory_item_gid` haritası — TEK sorgu.
     *
     * `channel_metadata` Faz 0'da eklendi ve slice 1.3'te dolduruldu.
     * Kalem başına ayrı GraphQL çevrimi yapılsaydı 50'lik bir yük 51 istek
     * atardı.
     *
     * ⚠️ OKUMA AÇIKÇA SİSTEM BAĞLAMINDA YAPILIR — kimlik bilgisi okumayla
     * AYNI gerekçe (`97a7eb7`'de yaşanmış hata). Bu adapter iki farklı
     * bağlamdan çağrılır:
     *
     *   `PushInventory` işi  → kendi kiracı bağlamını kurar
     *   Mutabakat taraması   → `runAsSystem()` altında, KİRACI BAĞLAMI YOK
     *
     * Kapsanmış sorgu ikincisinde İSTİSNA fırlatır ve mutabakat turu o
     * bağlantıda çöker. Kapsama burada bir şey KORUMAZ: sorgu zaten yükün
     * taşıdığı listing kimlikleriyle sınırlıdır ve o kimlikler bu
     * bağlantının fan-out'undan gelir — başka kiracının satırı yüke HİÇ
     * girmez.
     *
     * HAM SORGU KULLANILMAZ (§24): `Listing::query()` model üzerinden
     * gider; `DB::table()` kullanılsaydı kiracı filtresi ELLE yazılmak
     * zorunda kalırdı ve o filtre projede BEŞ KEZ unutuldu.
     *
     * @return array<string, string> listing id → inventory item gid
     */
    private function inventoryItemGidsFor(InventoryPushBatch $batch): array
    {
        $listingIds = array_map(
            static fn (array $item): string => (string) $item['listing_id'],
            $batch->toArray(),
        );

        return TenantContext::runAsSystem(function () use ($listingIds): array {
            $map = [];

            foreach (Listing::query()->whereIn('id', $listingIds)->get() as $listing) {
                $gid = $listing->channel_metadata['inventory_item_gid'] ?? null;

                if (is_string($gid) && $gid !== '') {
                    $map[(string) $listing->id] = $gid;
                }
            }

            return $map;
        });
    }

    // --------------------------------------------------------------- fiyat

    /**
     * Fiyat yazar — MUTLAK DEĞER ve STRING.
     *
     * V3.0 · §04 (capability matrisi) · §22 · v2.2 §7 · §9 · slice 1.6.
     *
     * ⚠️ FİYAT STRING TAŞINIR, FLOAT ASLA. `19.90 * 100` IEEE-754'te
     * `1989.99...` olur ve `(int)` cast'i onu AŞAĞI keser. Kolon
     * `decimal(12,2)` ve PHP'ye zaten string döner; buradaki tek görev o
     * stringi BOZMADAN taşımaktır. Trendyol adapter'ı `(float)` dönüşümü
     * yapıyor çünkü o kanalın uç noktası sayı bekliyor — Shopify string
     * kabul eder ve o satır BURAYA KOPYALANMAZ.
     *
     * ⚠️ `compareAtPrice` YÜKE HİÇ KONULMAZ. Kanonik `compare_at_price`
     * alanımız DOLU olsa bile gönderilmez: o alan Shopify'da satıcının
     * KAMPANYASIDIR ve §9'un politikası "fiyatta ÜZERİNE YAZILMAZ" der —
     * "sessizce ezmek EN SIK ŞİKAYET". Trendyol'daki `listPrice` kuralı
     * (üstü çizili fiyat yoksa satış fiyatına düş) TERS SEBEPTEN doğdu:
     * orada alan ZORUNLUDUR ve atlanırsa kanal `VALIDATION` döner. Burada
     * alan İSTEĞE BAĞLIDIR; göndermek kazanç değil KAYIPTIR.
     *
     * ⚠️ `productSet` KULLANILMAZ. O ürünün TAMAMINI yazar; fiyat turu
     * başlığa, açıklamaya ve duruma DOKUNMAMALIDIR. İçerik kendi
     * domainindedir ve `PushListing` üzerinden gider — burada yazılsaydı
     * her fiyat değişimi içeriği de gönderir ve `content_version`
     * kapısının anlamı kalmazdı.
     *
     * ⚠️ MUTATION ÜRÜN BAŞINADIR ve bu Shopify'ın GERÇEK SINIRIDIR:
     * `productVariantsBulkUpdate` tekil bir `productId` ister. Yük bu
     * yüzden ÜST ÜRÜNE GÖRE gruplanır ve ürün başına bir çağrı atılır.
     * Tek çağrıya sıkıştırılsaydı Shopify ikinci ürünün varyantlarını
     * TANIMAZ, `userErrors` döner ve o hata KALICIDIR.
     *
     * ⚠️ ÜST ÜRÜN KİMLİĞİ OLMAYAN KALEM YÜKE ALINMAZ ve bu SESSİZ BİR
     * ATLAMA DEĞİLDİR — sonuçta raporlanır (stoktaki "kimliği olmayan
     * kalem" kuralının aynısı).
     */
    public function pushPrices(PricePushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            // Boş yük için çağrı yapılmaz; kota boşa harcanmaz.
            return AdapterResult::success(['pushed' => 0]);
        }

        $productGids = $this->productGidsFor($batch);

        $byProduct = [];
        $missing = [];

        foreach ($batch->items as $item) {
            $productGid = $productGids[(string) $item['listing_id']] ?? null;

            if ($productGid === null) {
                $missing[] = (string) $item['external_id'];

                continue;
            }

            $byProduct[$productGid][] = [
                'id' => (string) $item['external_id'],
                // STRING — dönüşüm YOK. `compareAtPrice` BİLİNÇLİ OLARAK
                // yoktur ve eklenmemelidir (metot başlığı).
                'price' => (string) $item['price'],
            ];
        }

        if ($byProduct === []) {
            // Başarı dönülseydi `synced_version` ilerler ve satır kanalda
            // hiçbir şey değişmemişken "senkron" görünürdü (P0-1'in kardeşi).
            return AdapterResult::failure(
                ErrorClass::VALIDATION,
                'Shopify fiyat yükündeki hiçbir kalemin üst ürün kimliği yok: '
                .implode(', ', $missing),
            );
        }

        $pushed = 0;

        foreach ($byProduct as $productGid => $variants) {
            $this->gql(
                <<<'GQL'
                mutation UpdateVariantPrices($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
                  productVariantsBulkUpdate(productId: $productId, variants: $variants) {
                    productVariants { id price }
                    userErrors { field message code }
                  }
                }
                GQL,
                variables: [
                    'productId' => $productGid,
                    'variants' => $variants,
                ],
                operation: 'productVariantsBulkUpdate',
                userErrorPath: 'productVariantsBulkUpdate',
            );

            $pushed += count($variants);
        }

        return AdapterResult::success(array_filter([
            'pushed' => $pushed,
            // Atlanan kalemler SESSİZ DEĞİL: sonuç verisinde taşınır ve
            // denetim izine yazılır.
            'skipped_without_product' => $missing === [] ? null : $missing,
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * Uzak fiyat durumunu okur — mutabakatın karşılaştırma girdisi (§9 · §10).
     *
     * ⚠️ TOPLU OKUNUR — 50 listing TEK istekte (v2.2 · §10).
     *
     * ⚠️ ANAHTAR `external_id` (VARIANT GID) OLMALIDIR — mutabakat kalemi
     * onunla eşleştirir. Ürün gid'i ile anahtarlansaydı karşılaştırma
     * HİÇBİR listing'i bulamaz ve her tur "uzak değer okunamadı" derdi.
     *
     * ⚠️ FİYAT STRING KALIR. §9'un para karşılaştırması kuruş ölçeğinde
     * TAM SAYIDIR ve `round()` zorunludur; snapshot float taşısaydı o hesap
     * daha kaynağında bozulurdu.
     *
     * @param  list<Listing>  $listings
     */
    public function fetchPrices(array $listings): RemotePriceSnapshot
    {
        $gids = [];

        foreach ($listings as $listing) {
            if (is_string($listing->external_id) && $listing->external_id !== '') {
                $gids[] = $listing->external_id;
            }
        }

        if ($gids === []) {
            return new RemotePriceSnapshot([]);
        }

        $data = $this->gql(
            <<<'GQL'
            query FetchPrices($ids: [ID!]!) {
              nodes(ids: $ids) {
                ... on ProductVariant { id price }
              }
            }
            GQL,
            variables: ['ids' => $gids],
            operation: 'nodes',
        );

        $prices = [];

        foreach ((array) ($data['nodes'] ?? []) as $node) {
            // SİLİNMİŞ varyant `null` döner ve ATLANIR: `"0"` yazılsaydı
            // mutabakat "kanalda 0 TL" sanır ve `PRICE_CONFLICT` açardı —
            // satıcı var olmayan bir fiyat için karar vermeye zorlanırdı.
            // Doğru sınıflandırma `REMOTE_MISSING`'dir (v2.2 · §10).
            if (! is_array($node) || ! isset($node['id'])) {
                continue;
            }

            // FİYATI OLMAYAN DÜĞÜM DE SIFIR OKUNMAZ: kimlik dolu olduğu için
            // yukarıdaki elemeye TAKILMAZ ve `"0"` yazılsaydı her tur SAHTE
            // bir çakışma üretirdi (slice 1.5'in "takibi kapalı varyant"
            // tuzağının fiyat karşılığı).
            if (! isset($node['price']) || $node['price'] === '') {
                continue;
            }

            $prices[(string) $node['id']] = (string) $node['price'];
        }

        return new RemotePriceSnapshot($prices, new \DateTimeImmutable);
    }

    /**
     * Tek turda kaç kalem gruplanabilir.
     *
     * `PriceBatchBuilder` bu sınıra göre GRUPLAMA yapar; operasyon sayısı
     * DEĞİŞMEZ (v2.2 · fan-out kuralı). Stokla AYNI değer seçildi —
     * mutation ürün başına ayrıştığı için gerçek sınır Shopify'ın kalem
     * sayısı değil ÇAĞRI sayısıdır ve 250 kalem en kötü durumda 250 çağrı
     * demektir; daha büyük bir sayı maliyet kovasını (§06.8) tek turda
     * boşaltırdı.
     */
    public function maxPriceBatchSize(): int
    {
        return self::MAX_PRICE_BATCH;
    }

    /**
     * Yükteki listing'lerin `external_parent_id` haritası — TEK sorgu.
     *
     * ⚠️ KALEM BAŞINA AYRI SORGU YAPILMAZ — `inventoryItemGidsFor()` ile
     * aynı gerekçe: 250'lik bir yük 250 sorgu atardı.
     *
     * ⚠️ OKUMA AÇIKÇA SİSTEM BAĞLAMINDA YAPILIR. Bu adapter hem kuyruk
     * işinden (kiracı bağlamı VAR) hem mutabakat taramasından
     * (`runAsSystem`, bağlam YOK) çağrılır; kapsanmış sorgu ikincisinde
     * istisna fırlatır ve fiyat turu o bağlantıda çökerdi (`97a7eb7`
     * hata biçimi, slice 1.5'te testte yakalandı).
     *
     * HAM SORGU KULLANILMAZ (§24): `Listing::query()` model üzerinden
     * gider; `DB::table()` kiracı filtresini ELLE yazdırır ve o filtre
     * projede BEŞ KEZ unutuldu.
     *
     * @return array<string, string> listing id → product gid
     */
    private function productGidsFor(PricePushBatch $batch): array
    {
        $listingIds = array_map(
            static fn (array $item): string => (string) $item['listing_id'],
            $batch->items,
        );

        return TenantContext::runAsSystem(function () use ($listingIds): array {
            $map = [];

            foreach (Listing::query()->whereIn('id', $listingIds)->get() as $listing) {
                $gid = $listing->external_parent_id;

                if (is_string($gid) && $gid !== '') {
                    $map[(string) $listing->id] = $gid;
                }
            }

            return $map;
        });
    }

    // ------------------------------------------------------------- sipariş

    /**
     * Ham webhook gövdesini kanonik olaya çevirir.
     *
     * V3.0 · §06.6 · slice 1.7. Dönüşüm `ShopifyOrderNormalizer`'dadır ve
     * TİP AYRIMININ gerekçesi orada yazılıdır: Shopify'da iptal AYRI bir
     * konudur ve Woo'nun "durum topic'i ezer" kuralı BURAYA KOPYALANMAZ.
     */
    public function parseOrderEvent(InboxMessage $message): ?NormalizedOrderEvent
    {
        return ShopifyOrderNormalizer::normalize($message);
    }

    /**
     * ⚠️ SHOPIFY YOKLANMAZ — WEBHOOK GÖNDERİR (§19 · `supports_webhooks`).
     *
     * Bu metot çağrılırsa bir PROGRAMLAMA HATASI vardır: yoklama turu
     * `supports_webhooks` kapısını okur ve bu kanalı HİÇ seçmemelidir.
     *
     * SESSİZCE BOŞ SAYFA DÖNÜLMEZ. Dönülseydi hata gizlenir, yoklama turu
     * her seferinde "sipariş yok" der ve kimse sebebini aramazdı —
     * "yazılmamış yetenek SESSİZCE BAŞARILI DÖNMEZ" kuralı (v2.2 · §7).
     * Trendyol'un `verifyWebhookSignature` `false` dönmesiyle simetriktir:
     * orada da güvenli taraf "evet" DEMEMEKTİR.
     */
    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage
    {
        throw new RuntimeException(
            'Shopify siparişleri webhook ile gelir ve YOKLANMAZ. Bu çağrı '.
            'yoklama turunun `supports_webhooks` kapısını atladığı anlamına '.
            'gelir; sessizce boş sayfa dönmek hatayı gizlerdi.'
        );
    }

    /**
     * ⚠️ SHOPIFY YOKLANMAZ — bu metot da ÇAĞRILMAMALIDIR.
     *
     * `fetchOrders()` ile AYNI gerekçe: çağrılması, yoklama turunun
     * `supports_webhooks` kapısını atladığı anlamına gelir. Sessizce
     * `null` dönmek o arızayı GİZLERDİ — kimlik üretilmez, tekilleştirme
     * saatlik hash yoluna düşer ve sorunun sebebi hiçbir yerde görünmez.
     *
     * @param  array<string, mixed>  $order
     */
    public function pollingEventIdFor(array $order): ?string
    {
        throw new RuntimeException(
            'Shopify siparişleri webhook ile gelir ve YOKLANMAZ; olay kimliği '.
            'webhook başlığından (`extractEventId`) okunur.'
        );
    }

    /**
     * Ayrı bir onay adımı YOKTUR.
     *
     * Sipariş webhook ile gelir ve kanal onu zaten kabul etmiştir; sözleşme
     * gereği başarı döner (Woo ile aynı). Pazaryerlerinde (Trendyol,
     * Hepsiburada) bu adım gerçektir ve satıcının siparişi üstlenmesini
     * bildirir; storefront'ta böyle bir kavram yoktur.
     */
    public function acknowledgeOrder(Order $order): AdapterResult
    {
        return AdapterResult::success(['acknowledged' => true]);
    }

    // ---------------------------------------------------------------- kargo

    /**
     * Kargo bildirimini kanala gönderir — slice 1.8.
     *
     * V3.0 · §04 (capability matrisi) · §06.6 · v2.2 §7.
     *
     * ─────────────────────────────────────────────────────────────────
     * ⚠️ HEDEF `fulfillmentOrder`'DIR, SİPARİŞ DEĞİL
     * ─────────────────────────────────────────────────────────────────
     * Shopify'ın modern kargo modelinde sipariş, kargolanabilir parçalara
     * (fulfillment order) bölünür — çok konumlu mağazada her konum kendi
     * parçasını taşır. `fulfillmentCreateV2` bu parçaları ister; sipariş
     * gid'i verilseydi Shopify `userErrors` döner ve o hata `VALIDATION`
     * yani KALICIDIR: kargo bildirimi "düzeltilemez" damgasıyla ölürdü.
     *
     * İKİ ÇAĞRI GEREKİR ve bu Shopify'ın modelinin sonucudur: parçalar
     * ÖNCE okunur, sonra kargolanır. Tek çağrıya indirilemez.
     *
     * ⚠️ KARGOLANACAK PARÇA YOKSA İSTEK ATILMAZ ve bu HATA DEĞİLDİR.
     * Sipariş zaten tamamen kargolanmışsa liste boş döner; mutation yine
     * de çağrılsaydı `userErrors` alır ve KALICI hata yazılırdı — oysa
     * yapılacak bir şey yoktur. "Yazılmamış yetenek sessizce başarılı
     * dönmez" kuralı burada İHLAL EDİLMEZ: yetenek YAZILMIŞTIR ve
     * yapılacak iş GERÇEKTEN yoktur; sonuç bunu ADIYLA söyler.
     */
    public function pushFulfillment(Fulfillment $fulfillment): AdapterResult
    {
        $externalOrderId = $fulfillment->order?->external_id;

        if ($externalOrderId === null || $externalOrderId === '') {
            // Kimlik yoksa istek HİÇ atılmaz: boş gid ile giden mutation
            // `userErrors` döner ve o hata KALICIDIR.
            return AdapterResult::failure(
                ErrorClass::VALIDATION,
                'Kargo bildirimi için siparişin Shopify kimliği yok.',
            );
        }

        // ⚠️ SİPARİŞ KİMLİĞİ SAYISALDIR, SORGU GID İSTER. Sipariş webhook'tan
        // ve yoklamadan gövdenin sayısal `id`'siyle kaydedilir; olduğu gibi
        // `order(id:)`'ye verilseydi Shopify "geçersiz global kimlik" der ve
        // kargo bildirimi gerçek mağazada HİÇ çalışmazdı.
        $fulfillmentOrderIds = $this->openFulfillmentOrderIds(
            str_starts_with($externalOrderId, 'gid://') ? $externalOrderId : 'gid://shopify/Order/'.$externalOrderId,
        );

        if ($fulfillmentOrderIds === []) {
            return AdapterResult::success(['already_fulfilled' => true]);
        }

        $data = $this->gql(
            <<<'GQL'
            mutation CreateFulfillment($fulfillment: FulfillmentV2Input!) {
              fulfillmentCreateV2(fulfillment: $fulfillment) {
                fulfillment { id status }
                userErrors { field message code }
              }
            }
            GQL,
            variables: ['fulfillment' => array_filter([
                'lineItemsByFulfillmentOrder' => array_map(
                    static fn (string $id): array => ['fulfillmentOrderId' => $id],
                    $fulfillmentOrderIds,
                ),
                // Takip bilgisi satıcının müşteriye vereceği veridir.
                // Shopify tanıdığı firma adlarında takip BAĞLANTISINI
                // kendisi kurar; `company` serbest metindir.
                'trackingInfo' => $this->trackingInfo($fulfillment),
                // Müşteriye bildirim gönderme kararı SATICINIYDI ve
                // Shopify panelinden yönetilir; burada zorlanmaz.
            ], static fn (mixed $v): bool => $v !== null)],
            operation: 'fulfillmentCreateV2',
            userErrorPath: 'fulfillmentCreateV2',
        );

        $created = $data['fulfillmentCreateV2']['fulfillment'] ?? null;

        return AdapterResult::success(array_filter([
            // Paket kimliği webhook BİÇİMİNDE (sayısal) döner: kanal yankısı
            // (`fulfillments/create`) satırı `(order_id, external_id)` ile
            // bulur ve gövdede sayısal `id` vardır. Gid yazılsaydı yankı
            // eşleşmez ve aynı kargo panelde iki kez görünürdü.
            'external_id' => is_array($created) && isset($created['id'])
                ? self::numericTail((string) $created['id'])
                : null,
            'status' => is_array($created) && isset($created['status'])
                ? (string) $created['status']
                : null,
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * Kargo firması listesi — BOŞ ve bu bir eksiklik DEĞİLDİR.
     *
     * Shopify sabit bir firma listesi DAYATMAZ: `trackingInfo.company`
     * serbest metindir ve tanıdığı adlarda takip bağlantısını kendisi
     * kurar. Uydurma bir liste dönmek satıcıya olmayan bir kısıt
     * gösterirdi (Woo'daki kararın aynısı).
     *
     * @return array<string, string>
     */
    public function fetchCarriers(): array
    {
        return [];
    }

    /**
     * Siparişin HENÜZ KARGOLANMAMIŞ parçaları.
     *
     * ⚠️ `status: OPEN` FİLTRESİ ZORUNLUDUR. Kapanmış (`CLOSED`) veya iptal
     * edilmiş parça yeniden kargolanmaya çalışılsaydı Shopify `userErrors`
     * döner ve o hata KALICIDIR — üstelik siparişin GERÇEKTEN kargolanacak
     * parçası varken de tüm çağrı düşerdi.
     *
     * @return list<string>
     */
    private function openFulfillmentOrderIds(string $externalOrderId): array
    {
        $data = $this->gql(
            <<<'GQL'
            query FulfillmentOrders($id: ID!) {
              order(id: $id) {
                fulfillmentOrders(first: 50, query: "status:open") {
                  nodes { id status }
                }
              }
            }
            GQL,
            variables: ['id' => $externalOrderId],
            operation: 'order',
        );

        $ids = [];

        foreach ((array) ($data['order']['fulfillmentOrders']['nodes'] ?? []) as $node) {
            if (! is_array($node) || ! isset($node['id'])) {
                continue;
            }

            // Sunucu filtresine EK OLARAK burada da elenir: `query`
            // dizesi Shopify tarafında yorumlanır ve sözdizimi değişirse
            // sessizce TÜM parçaları döndürürdü.
            $status = mb_strtoupper((string) ($node['status'] ?? ''));

            if ($status !== '' && $status !== 'OPEN' && $status !== 'IN_PROGRESS') {
                continue;
            }

            $ids[] = (string) $node['id'];
        }

        return $ids;
    }

    /**
     * Takip bilgisi — hiçbiri yoksa alan HİÇ GÖNDERİLMEZ.
     *
     * Boş dizelerle gönderilseydi Shopify'da takip numarası olarak BOŞ bir
     * kayıt oluşur ve müşteriye "kargo takip: —" gösterilirdi.
     *
     * @return array<string, string>|null
     */
    private function trackingInfo(Fulfillment $fulfillment): ?array
    {
        $info = array_filter([
            'number' => $fulfillment->tracking_number,
            'company' => $fulfillment->carrier,
        ], static fn (mixed $v): bool => is_string($v) && $v !== '');

        return $info === [] ? null : $info;
    }

    /** `gid://shopify/Fulfillment/55` → `55`; gid değilse olduğu gibi. */
    private static function numericTail(string $gid): string
    {
        $slash = strrpos($gid, '/');

        if ($slash === false) {
            return $gid;
        }

        $tail = substr($gid, $slash + 1);

        return ctype_digit($tail) ? $tail : $gid;
    }

    // ------------------------------------------------------ ürün içe aktarma

    /**
     * Kanaldaki ürünleri sayfa sayfa okur — YEREL KAYIT GEREKTİRMEZ.
     *
     * V3.0 · §06 · slice 1.4 · v2.2 §13 · Faz 3 · madde 5.
     *
     * ⚠️ VARYANT SORGULANIR, ÜRÜN DEĞİL. Bizim kanonik modelimizde
     * satılabilir birim VARYANTTIR ve SKU orada yaşar. `products` sorgusu
     * kullanılsaydı çok varyantlı bir Shopify ürünü tek satıra çöker ve
     * varyantların SKU'ları KAYBOLURDU — satıcı "50 ürünüm vardı, 12'si
     * geldi" der ve sebebini bulamazdı.
     *
     * ⚠️ İMLEÇ OPAKTIR ve Shopify'da GERÇEKTEN opak bir token'dır
     * (`pageInfo.endCursor`) — Woo'daki sayfa numarasının aksine. Çekirdek
     * onu YORUMLAMAZ; sayı varsayılsaydı bu kanal eklenirken kırılırdı
     * (`OrderPage` ile aynı kural).
     *
     * ⚠️ SAYFA 50 VARYANT — görsel alanları yüzünden (A15). Sorgu maliyeti
     * varyant × görsel sayısıyla büyür; 100 × 10 görsel Shopify'ın tek
     * sorgu sınırını (1000) aşar ve sorgu `MAX_COST_EXCEEDED` ile reddedilir.
     * `Product.images`/`ProductVariant.image` 2026-01'de kullanımdan
     * kalktığı için `media` okunur.
     *
     * ⚠️ `hasMore` `nextCursor !== null` İLE AYNI ŞEY DEĞİLDİR. Shopify son
     * sayfada bile `endCursor` döndürür; turu durduran `hasNextPage`'dir.
     * İmlece bakılsaydı tur sonsuza kadar boş sayfa çeker ve kotayı yakardı.
     */
    public function fetchProductPage(?string $cursor = null): RemoteProductPage
    {
        $data = $this->gql(
            <<<'GQL'
            query ImportVariants($cursor: String) {
              productVariants(first: 50, after: $cursor) {
                nodes {
                  id sku barcode price inventoryQuantity
                  inventoryItem { id }
                  media(first: 1) { nodes { ... on MediaImage { image { url } } } }
                  product {
                    id title status vendor descriptionHtml
                    media(first: 10) { nodes { ... on MediaImage { image { url } } } }
                  }
                }
                pageInfo { hasNextPage endCursor }
              }
            }
            GQL,
            variables: ['cursor' => $cursor],
            operation: 'productVariants',
        );

        $nodes = $data['productVariants']['nodes'] ?? [];
        $pageInfo = $data['productVariants']['pageInfo'] ?? [];

        $products = [];

        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if (is_array($node)) {
                $products[] = ShopifyProductMapper::toRemoteProduct($node);
            }
        }

        $hasMore = (bool) ($pageInfo['hasNextPage'] ?? false);

        return new RemoteProductPage(
            products: $products,
            // İmleç YALNIZCA devam edilecekse taşınır: son sayfada
            // döndürülen imleci saklamak, bir sonraki turun boş sayfadan
            // başlamasına yol açardı.
            nextCursor: $hasMore && isset($pageInfo['endCursor'])
                ? (string) $pageInfo['endCursor']
                : null,
            hasMore: $hasMore,
        );
    }

    /**
     * Tur başına en fazla 100 sayfa — 50'lik sayfayla 5.000 varyant.
     * Sayfa görseller yüzünden 50'ye indi (A15); sınır iki katına
     * çıkmasaydı bir turda okunan katalog sessizce YARIYA düşerdi.
     *
     * SINIR KOTA DEĞİL EMNİYETTİR: `hasNextPage` sonsuza kadar `true`
     * dönen bozuk bir kanalda tur hiç bitmez ve worker'ı süresiz meşgul
     * ederdi. Sınıra takılan tur kullanıcıya SÖYLER — sessiz kırpma yok
     * (§13 · madde 5). Kalan ürünler ikinci turda gelir; içe aktarma var
     * olan SKU'yu günceller, yani tekrar zararsızdır.
     */
    public function maxImportPages(): int
    {
        return 100;
    }

    // ------------------------------------------------------------------- iç

    /**
     * Her isteğe eklenen başlıklar.
     *
     * `X-Shopify-Access-Token` — Bearer DEĞİL. İstemcinin `access_token`
     * yolu Bearer üretir ve Shopify onu kabul etmez (sınıf başlığı).
     *
     * TOKEN YOKSA İSTEK HİÇ ATILMAZ: boş başlıkla giden istek 401 alır,
     * `AUTHENTICATION` KALICI sayılır ve listing "anahtarın yanlış" diyerek
     * ölür — oysa anahtar yoktur, yanlış değildir. Hepsiburada'daki
     * "satıcı kimliği yoksa istek atılmaz" kuralının aynısı.
     *
     * @return array<string, string>
     */
    private function defaultHeaders(): array
    {
        $token = $this->accessToken();

        if ($token === null || $token === '') {
            throw new RuntimeException(
                'Shopify Admin API access token tanımsız — istek kimliksiz '.
                'gider, kanal 401 döner ve listing "anahtarın yanlış" '.
                'damgasıyla ölür.'
            );
        }

        return [self::AUTH_HEADER => $token];
    }

    /**
     * Mağaza alan adı — `magaza.myshopify.com`.
     *
     * `external_account_id` BİRİNCİL kaynaktır (§06.2). Shopify'da tek API
     * ana bilgisayarı YOKTUR; her mağaza kendi alt alan adına sahiptir ve o
     * ad kalıcı kimliktir. Mağaza özel alan adı kullansa bile
     * `.myshopify.com` adresi DEĞİŞMEZ.
     */
    private function shopDomain(): string
    {
        $domain = $this->connection->external_account_id;

        if (! is_string($domain) || $domain === '') {
            throw new RuntimeException(
                'Shopify mağaza alan adı (external_account_id) tanımsız — '.
                'istek atılacak adres bilinmiyor.'
            );
        }

        return $domain;
    }

    /**
     * Stok konumu — bağlantı başına TEK (§06.4).
     *
     * LISTING'DE DEĞİL BAĞLANTIDA saklanır: bir mağazanın stok konumu tüm
     * ürünler için aynıdır ve listing başına saklamak aynı değeri 5.000
     * satıra kopyalardı.
     */
    public function locationGid(): ?string
    {
        $gid = $this->connection->settings[self::LOCATION_KEY] ?? null;

        return is_string($gid) && $gid !== '' ? $gid : null;
    }

    private function accessToken(): ?string
    {
        $token = $this->secrets()['access_token'] ?? null;

        return is_string($token) ? $token : null;
    }

    /** Webhook sırrı kasadan okunur; bağlam beklenmez. */
    private function webhookSecret(): ?string
    {
        $secret = $this->secrets()['webhook_secret'] ?? null;

        return is_string($secret) ? $secret : null;
    }

    /**
     * Kasadaki sırlar — AÇIKÇA SİSTEM BAĞLAMINDA.
     *
     * `channel_credentials` kiracıya göre kapsanır ama bu adapter kuyruk
     * işinden, `runAsSystem()` taramasından ve webhook doğrulamasından da
     * çağrılır; oralarda bağlam YOKTUR. Kapsanmış sorgu istisna fırlatır ve
     * istek SESSİZCE KİMLİKSİZ giderdi (`97a7eb7`'de yaşanmış hata).
     *
     * @return array<string, mixed>
     */
    private function secrets(): array
    {
        return TenantContext::runAsSystem(function (): array {
            try {
                return app(CredentialVault::class)->read($this->connection);
            } catch (Throwable) {
                return [];
            }
        });
    }

    /**
     * Başlık okuma — ad BÜYÜK/KÜÇÜK HARFTEN bağımsızdır.
     *
     * HTTP başlık adları büyük/küçük harf duyarsızdır ve vekil sunucular
     * onları yeniden yazar. Tam eşleşme aransaydı `X-Shopify-Hmac-Sha256`
     * gönderen kanal `x-shopify-hmac-sha256` aranırken bulunamaz ve MEŞRU
     * webhook reddedilirdi; kanal sonsuza kadar yeniden gönderirdi.
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $values) {
            if (mb_strtolower((string) $key) !== $name) {
                continue;
            }

            $value = is_array($values) ? ($values[0] ?? null) : $values;

            return is_string($value) ? $value : null;
        }

        return null;
    }
}
