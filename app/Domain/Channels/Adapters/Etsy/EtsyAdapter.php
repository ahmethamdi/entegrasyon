<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Etsy;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Etsy\Taxonomy\EtsyTaxonomyClient;
use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\RefreshedCredentials;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Contracts\SupportsTaxonomy;
use App\Domain\Channels\Contracts\SupportsTokenRefresh;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\CategoryTreeSnapshot;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\ListingPayload;
use App\Domain\Sync\Support\PricePushBatch;
use App\Domain\Sync\Support\RemoteInventorySnapshot;
use App\Domain\Sync\Support\RemoteListing;
use App\Domain\Sync\Support\RemotePriceSnapshot;
use App\Support\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Throwable;

/**
 * Etsy Open API v3 adapter — BEŞİNCİ kanal.
 *
 * V3.0 · §11 · §20 · §21 · v2.2 §7.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KAPSAM — SLICE 3.1 · 3.2 · 3.3 · 3.4 · 3.5 · 3.6
 * ─────────────────────────────────────────────────────────────────────
 * Yazılan: kimlik/başlık katmanı, sağlık kontrolü, hata sınıflandırma,
 * hız sınırı profili, **token yenileme** (`SupportsTokenRefresh`),
 * **taksonomi** (`SupportsTaxonomy`), **katalog** (`SupportsCatalog`),
 * **stok** ve **fiyat** (`SupportsInventory` + `SupportsPricing` —
 * ikisi de AYNI oku-birleştir-yaz akışını paylaşır, §11.3).
 *
 * HENÜZ YAZILMADI (sonraki slice): sipariş yoklaması (3.7). O yetenek
 * arayüzü BU SINIFTA UYGULANMAZ — ilan edilen ama çalışmayan yetenek
 * panelde çalışmayan sekme demektir (§05) ve "yazılmamış yetenek
 * SESSİZCE BAŞARILI DÖNMEZ" kuralı (v2.2 · §7).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ İKİ AYRI KİMLİK BAŞLIĞI VARDIR (§11.2)
 * ─────────────────────────────────────────────────────────────────────
 * `Authorization: Bearer {token}` SATICININ kimliğidir ve YENİLENİR.
 * `x-api-key: {keystring}` UYGULAMANIN kimliğidir ve YENİLENMEZ.
 *
 * İkisi karıştırılırsa yenileme çalışır ama istek yine 401 alır — ve o
 * 401 `AUTHENTICATION` KALICI sayılır, listing'ler "anahtarın yanlış"
 * damgasıyla toplu ölür. Oysa anahtar doğrudur.
 *
 * Bearer'ı `ChannelHttpClient` `access_token` sırrından kendisi kurar;
 * `x-api-key` ADAPTER'dan gelir ve istemci taşır (`if ($channel ===
 * '...')` YAZILMAZ — Hepsiburada'nın `User-Agent` kararının aynısı).
 *
 * ⚠️ ANAHTAR YOKSA İSTEK HİÇ ATILMAZ. Boş `x-api-key` ile giden istek
 * 401 alır ve sebep hiçbir yerde görünmez ("satıcı kimliği yoksa istek
 * atılmaz" kuralı, `356a662`).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ WEBHOOK YOKTUR (§11.4)
 * ─────────────────────────────────────────────────────────────────────
 * `verifyWebhookSignature()` DAİMA `false` döner — Trendyol'daki kararın
 * aynısı. `true` dönmek Etsy adına imzasız sipariş enjekte etmenin
 * kapısını açardı. Sipariş YOKLAMAYLA gelir (slice 3.7).
 */
final class EtsyAdapter implements ChannelAdapter, SupportsCatalog, SupportsInventory, SupportsPricing, SupportsTaxonomy, SupportsTokenRefresh
{
    /**
     * Uygulama anahtarının `settings` içindeki yeri.
     *
     * ⚠️ `settings` ŞİFRESİZDİR ve panele Inertia prop'u olarak gider —
     * oraya YALNIZCA keystring yazılır ve o bir SIR DEĞİL, uygulamanın
     * KİMLİĞİDİR (§19 · madde 4: "kimlik ≠ sır"). Access ve refresh
     * token'lar `channel_credentials` kasasındadır.
     */
    public const KEYSTRING_KEY = 'etsy_keystring';

    /** Mağaza kimliğinin `settings` içindeki yeri — yol üzerinde taşınır. */
    public const SHOP_ID_KEY = 'shop_id';

    /**
     * Hız sınırı — 10 istek/sn (§21).
     *
     * ⚠️ ASIL SINIR GÜNLÜK KOTADIR: 10.000 istek/gün, HESAP BAŞINA.
     * Envanter yazma ilan başına ayrı çağrı gerektirdiği için (§11.3) bu
     * gerçek bir TAVANDIR ve 5.000+ ürünlü mağazalarda AŞILIR — §21'de
     * açıkça kayıtlı bir ölçek sınırıdır.
     *
     * `ChannelRateLimiter` günlük kova TUTMAZ ve bu bilinçlidir: kova
     * saniyeliktir ve günlük kotayı temsil edecek şekilde esnetilseydi
     * tek bir yoğun tur bütün günü kilitlerdi. Günlük kota izleme P2'dir
     * (§21: "%80 aşılınca panelde uyarı").
     */
    private const REQUESTS_PER_SECOND = 10;

    /**
     * ⚠️ ENVANTER PARTİSİ İLAN BAŞINA **1**'DİR (§11.3).
     *
     * Bu bir performans sorunu DEĞİL, KANALIN ŞEKLİDİR: envanter uç
     * noktası tek ilanı adresler ve o ilanın TÜM varyantlarını tek
     * gövdede ister. `InventoryBatchBuilder` operasyonları yine
     * birleştirir; adapter `external_parent_id`'ye göre gruplar ve her
     * grup için AYRI çağrı yapar.
     *
     * ⚠️ FİYAT PARTİSİ DE AYNI SABİTİ KULLANIR ve bu tesadüf değildir:
     * Etsy'de fiyat AYRI bir uç noktada değil, aynı envanter gövdesinin
     * içindedir. İki ayrı sabit tanımlansaydı biri değiştiğinde ötekinin
     * sessizce eski kalması an meselesi olurdu — oysa ikisini de
     * belirleyen tek gerçek AYNI uç noktadır.
     */
    private const MAX_INVENTORY_BATCH = 1;

    /**
     * SKU araması — sayfa boyutu ve ÜST SINIR.
     *
     * ⚠️ ETSY SKU İLE ARAMA UÇ NOKTASI SUNMAZ; mağazanın ilanları sayfa
     * sayfa taranır. Üst sınır EMNİYETTİR: `results` sonsuza kadar dolu
     * dönen bozuk bir kanal turu HİÇ bitmezdi. Sınıra takılan arama
     * `null` döner ve `PushListing` yeni ilan açar — kopya riski vardır
     * ama SONSUZ DÖNGÜ kesin bir arızadır.
     */
    private const SEARCH_PAGE_SIZE = 100;

    private const MAX_SEARCH_PAGES = 20;

    public function __construct(
        private readonly ChannelConnection $connection,
        private readonly ChannelHttpClient $client,
    ) {}

    public function connection(): ChannelConnection
    {
        return $this->connection;
    }

    // ---------------------------------------------------------------- sağlık

    /**
     * Satıcının kendi kullanıcı kaydı okunur ve gecikme ölçülür.
     *
     * `users/me` SEÇİLDİ çünkü en ucuz kimlikli çağrıdır ve HER İKİ
     * kimlik başlığının doğruluğunu birlikte kanıtlar (§11.2): `x-api-key`
     * yanlışsa 401, `Bearer` yanlışsa yine 401 gelir ve ikisi de burada
     * yakalanır.
     *
     * ⚠️ MAĞAZA KİMLİĞİ SEÇİLMEMİŞSE BAĞLANTI SAĞLIKSIZDIR.
     * `shop_id` yol üzerinde taşınır (§19) ve sipariş yoklaması ile
     * katalog okuması onsuz çalışamaz. Sağlıklı sayılsaydı bağlantı
     * `active` olur, satıcı ürün göndermeye başlar ve her çağrı
     * doldurulmamış yer tutucu istisnasıyla ölürdü — Shopify'ın
     * "konum seçilmemişse sağlıksız" kuralının (P1-5) aynısı.
     */
    public function healthCheck(): HealthResult
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->client->get(
                EtsyEndpoints::url(EtsyEndpoints::ME),
                headers: $this->apiKeyHeader(),
            );

            $response->throw();

            $latency = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            if (! isset($response->json()['user_id'])) {
                return HealthResult::unhealthy(
                    'Etsy yanıtı kullanıcı bilgisi taşımıyor.'
                );
            }

            if ($this->shopId() === null) {
                return HealthResult::unhealthy(
                    'Etsy mağazası (shop) seçilmedi. Mağaza kimliği sipariş '.
                    've katalog çağrılarında yol üzerinde taşınır; seçilmeden '.
                    'hiçbir çağrı yapılamaz.'
                );
            }

            return HealthResult::healthy(latencyMs: $latency);
        } catch (Throwable $e) {
            return HealthResult::unhealthy($e->getMessage());
        }
    }

    // ------------------------------------------------------------ hız sınırı

    /**
     * Sabit profil — Etsy sınırı yanıtta BİLDİRMEZ.
     *
     * Trendyol'da sınır yanıt başlığından öğrenilir ve bağlantıya
     * yazılır; Etsy'de öğrenilecek bir başlık YOKTUR. Öğrenme kodu
     * yazmak, hiç çalışmayan ve hiç sınanamayan bir yol bırakırdı
     * (Hepsiburada'daki kararın aynısı).
     */
    public function rateLimitProfile(): RateLimitProfile
    {
        $profile = $this->connection->channelType?->rate_limit_profile;

        return is_array($profile) && $profile !== []
            ? RateLimitProfile::fromArray($profile)
            : new RateLimitProfile(
                requestsPerSecond: self::REQUESTS_PER_SECOND,
                burstCapacity: self::REQUESTS_PER_SECOND,
            );
    }

    public function maxInventoryBatchSize(): int
    {
        return self::MAX_INVENTORY_BATCH;
    }

    // -------------------------------------------------------- sınıflandırma

    /**
     * Etsy hatasını çekirdeğin anladığı sınıfa çevirir (§21).
     *
     * SINIFLANDIRMA BURADA, KARAR ÇEKİRDEKTE (`RetryPolicy`).
     *
     * ⚠️ 401 `AUTHENTICATION` DÖNER ve o KALICIDIR — ama bu Etsy'de
     * "anahtar yanlış" demek DEĞİLDİR: token 1 SAATLİKTİR ve büyük
     * olasılıkla yalnızca SÜRESİ DOLMUŞTUR. Kalıcı sayılması doğru
     * davranıştır çünkü yeniden denemek düzeltmez; düzelten şey
     * `credentials:refresh` taramasıdır (§20) ve o 15 dakikada bir koşar.
     *
     * §21'in açık kuralı: "401 → yenileme dener, sonra kalıcı."
     */
    public function classifyError(Throwable $e): ErrorClass
    {
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
     * ⚠️ ETSY WEBHOOK SUNMAZ — DAİMA `false` (§11.4).
     *
     * Trendyol'daki kararın aynısı ve gerekçesi birebir: `true` dönmek
     * Etsy adına İMZASIZ SİPARİŞ ENJEKTE etmenin kapısını açardı. Güvenli
     * taraf "evet" DEMEMEKTİR.
     *
     * Sipariş yoklamayla gelir (slice 3.7) ve olay kimliği
     * `{receipt_id}:{status}` biçiminde TÜRETİLİR.
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function verifyWebhookSignature(string $raw, array $headers): bool
    {
        return false;
    }

    /** @param array<string, array<int, string|null>> $headers */
    public function extractEventId(array $headers): ?string
    {
        return null;                    // yoklama kimliği normalizer türetir
    }

    /** @param array<string, array<int, string|null>> $headers */
    public function extractEventType(array $headers): string
    {
        return 'unknown';
    }

    // ------------------------------------------------------------- katalog

    /**
     * Yeni ilan açar — İKİ ADIM, ve bu KAÇINILMAZDIR (§11.1 · §11.3).
     *
     * ⚠️ ETSY İÇERİK VE ENVANTERİ AYRI UÇ NOKTALARDA TUTAR. İlan gövdesi
     * fiyat ve stok TAŞIMAZ; ikisi de `listings/{id}/inventory` altında
     * yaşar. Tek çağrıda birleştirilemez — Shopify'ın `productSet`'i gibi
     * bir "hepsi bir arada" mutation'ı Etsy'de YOKTUR.
     *
     * ⚠️ ARA BAŞARISIZLIK KABUKLU İLAN BIRAKIR ve bu DÜRÜST bir sınırdır:
     * ilan yaratıldı ama envanteri yazılamadıysa kanalda TASLAK bir ilan
     * kalır. `state => draft` ile açılmasının sebebi tam budur — taslak
     * ilan YAYINDA DEĞİLDİR ve satıcı stoksuz ürün satmaz. Sonraki tur
     * `external_parent_id`'yi görür ve UPDATE yoluna girer; kopya ilan
     * AÇILMAZ.
     *
     * Bu slice ENVANTER YAZMAZ (o slice 3.5'tir ve oku-birleştir-yaz
     * gerektirir). Kimlik üçlüsünden `offering_id` ancak envanter
     * yazıldıktan sonra dolar; o güne kadar `external_id` de boş kalabilir
     * ve `PushListing` bunu VALIDATION hatası olarak görür — sessizce
     * başarı DÖNÜLMEZ.
     */
    public function createListing(ListingPayload $payload): AdapterResult
    {
        $response = $this->client->post(
            EtsyEndpoints::url(EtsyEndpoints::SHOP_LISTINGS, ['shop_id' => $this->requireShopId()]),
            EtsyProductMapper::toListingBody($payload),
            headers: $this->apiKeyHeader(),
        );

        $response->throw();

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if (! isset($body['listing_id'])) {
            // Yanıt 200 ama ilan kimliği YOK: sözleşme ihlali. Başarı
            // dönülseydi `synced_version` ilerler ve satır kanalda
            // karşılığı olmadan "senkron" görünürdü.
            return AdapterResult::failure(
                ErrorClass::VALIDATION,
                'Etsy yanıtı ilan kimliği (listing_id) taşımıyor.',
            );
        }

        return AdapterResult::success(EtsyProductMapper::toIdentityResult(
            $body,
            $payload->listing->variant?->sku,
        ));
    }

    /**
     * Var olan ilanı GÜNCELLER.
     *
     * ⚠️ HEDEF `listing_id`'DİR (`external_parent_id`), `product_id`
     * DEĞİL. İçerik İLAN seviyesindedir; `external_id` (product_id) tek
     * başına ilan uç noktasına verilemez — istek var olmayan bir ilana
     * gider ve 404 alınır.
     */
    public function updateListing(ListingPayload $payload): AdapterResult
    {
        $listingId = $payload->listing->external_parent_id;

        if ($listingId === null || $listingId === '') {
            return AdapterResult::failure(
                ErrorClass::VALIDATION,
                'Güncellenecek Etsy ilanı bilinmiyor (external_parent_id boş).',
            );
        }

        $response = $this->client->request(
            'PATCH',
            EtsyEndpoints::url(EtsyEndpoints::LISTING, ['listing_id' => $listingId]),
            body: EtsyProductMapper::toListingBody($payload),
            headers: $this->apiKeyHeader(),
        );

        $response->throw();

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        return AdapterResult::success(EtsyProductMapper::toIdentityResult(
            $body,
            $payload->listing->variant?->sku,
        ));
    }

    /**
     * İlanı YAYINDAN ÇEKER — SİLMEZ.
     *
     * ⚠️ `state => inactive`, silme DEĞİL. Silme GERİ ALINAMAZ ve
     * kanaldaki yorumları, favorileri, arama sıralamasını ve SEO
     * geçmişini de götürür (v2.2 · `delist` kuralı). Etsy'de bu özellikle
     * ağırdır: bir ilanın "favori" sayısı satıcının en değerli sinyalidir.
     */
    public function delist(Listing $listing): AdapterResult
    {
        $listingId = $listing->external_parent_id;

        if ($listingId === null || $listingId === '') {
            return AdapterResult::failure(
                ErrorClass::VALIDATION,
                'Yayından çekilecek Etsy ilanı bilinmiyor (external_parent_id boş).',
            );
        }

        $response = $this->client->request(
            'PATCH',
            EtsyEndpoints::url(EtsyEndpoints::LISTING, ['listing_id' => $listingId]),
            body: ['state' => 'inactive'],
            headers: $this->apiKeyHeader(),
        );

        $response->throw();

        return AdapterResult::success();
    }

    /**
     * Kanalda ZATEN var olan ilanı SKU ile bulur.
     *
     * ⚠️ BU ADIM ATLANIRSA KOPYA İLAN AÇILIR ve geri alınamaz: yorumlar,
     * favoriler ve arama sıralaması ilk ilanda kalır (v2.2 · ürün
     * aktarımı kuralı).
     *
     * ⚠️ ETSY SKU İLE ARAMA UÇ NOKTASI SUNMAZ. Mağazanın ilanları
     * sayfa sayfa taranır ve envanterindeki `products[].sku` eşleştirilir.
     * Bu PAHALIDIR ama alternatifi kopya ilandır; sayfa üst sınırı
     * emniyettir (bozuk bir kanal turu sonsuza kadar sürdürmemelidir).
     */
    public function findExistingListing(Variant $variant): ?RemoteListing
    {
        $sku = (string) $variant->sku;

        if ($sku === '') {
            return null;
        }

        $offset = 0;

        for ($page = 0; $page < self::MAX_SEARCH_PAGES; $page++) {
            $response = $this->client->get(
                EtsyEndpoints::url(EtsyEndpoints::SHOP_LISTINGS, ['shop_id' => $this->requireShopId()]),
                query: ['limit' => self::SEARCH_PAGE_SIZE, 'offset' => $offset, 'includes' => 'Inventory'],
                headers: $this->apiKeyHeader(),
            );

            $response->throw();

            /** @var array<string, mixed> $body */
            $body = $response->json() ?? [];

            /** @var list<array<string, mixed>> $results */
            $results = $body['results'] ?? [];

            if ($results === []) {
                return null;
            }

            foreach ($results as $listing) {
                if (! is_array($listing)) {
                    continue;
                }

                $identity = EtsyProductMapper::toIdentityResult($listing, $sku);

                // SKU EŞLEŞMEDİYSE `external_id` DOLMAZ — mapper ilk
                // elemana DÜŞMEZ. Burada da o sözleşmeye güvenilir.
                if (isset($identity['external_id'])) {
                    return $this->toRemoteListing($listing, $identity);
                }
            }

            $offset += self::SEARCH_PAGE_SIZE;
        }

        return null;
    }

    /**
     * Uzak durumu okur — mutabakat ve çakışma tespiti için.
     */
    public function fetchListing(Listing $listing): ?RemoteListing
    {
        $listingId = $listing->external_parent_id;

        if ($listingId === null || $listingId === '') {
            return null;
        }

        $response = $this->client->get(
            EtsyEndpoints::url(EtsyEndpoints::LISTING, ['listing_id' => $listingId]),
            query: ['includes' => 'Inventory'],
            headers: $this->apiKeyHeader(),
        );

        // ⚠️ 404 "İLAN SİLİNMİŞ" DEMEKTİR ve İSTİSNA DEĞİLDİR: mutabakat
        // bunu `REMOTE_MISSING` olarak görmeli, tur çökmemelidir.
        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if (! isset($body['listing_id'])) {
            return null;
        }

        return $this->toRemoteListing(
            $body,
            EtsyProductMapper::toIdentityResult($body, $listing->variant?->sku),
        );
    }

    /**
     * Etsy ilanı → `RemoteListing`.
     *
     * @param  array<string, mixed>  $listing
     * @param  array<string, mixed>  $identity
     */
    private function toRemoteListing(array $listing, array $identity): RemoteListing
    {
        $price = is_array($listing['price'] ?? null)
            ? EtsyProductMapper::money($listing['price'])
            : null;

        return new RemoteListing(
            externalId: (string) ($identity['external_id'] ?? $listing['listing_id']),
            title: isset($listing['title']) ? (string) $listing['title'] : null,
            quantity: isset($listing['quantity']) ? (int) $listing['quantity'] : null,
            price: $price,
            status: isset($listing['state']) ? (string) $listing['state'] : null,
            url: isset($listing['url']) ? (string) $listing['url'] : null,
            raw: $listing,
            observedAt: new DateTimeImmutable,
        );
    }

    private function requireShopId(): string
    {
        $shopId = $this->shopId();

        if ($shopId === null) {
            throw new RuntimeException(
                'Etsy mağaza kimliği (shop_id) tanımsız — istek yol üzerinde '.
                'doldurulmamış yer tutucuyla giderdi.'
            );
        }

        return $shopId;
    }

    // ---------------------------------------------------------------- stok

    /**
     * Stok yazar — OKU-BİRLEŞTİR-YAZ (§11.3).
     *
     * ═════════════════════════════════════════════════════════════════
     * ⚠️ ETSY'NİN EN TEHLİKELİ MADDESİ: BU ÇAĞRI TÜM ENVANTERİ EZER
     * ═════════════════════════════════════════════════════════════════
     * Etsy KISMİ GÜNCELLEME DESTEKLEMEZ. `PUT .../inventory` gövdesi o
     * ilanın BÜTÜN `products` ve `offerings` dizisini taşımak
     * ZORUNDADIR. Yalnızca değiştirdiğimiz varyant gönderilseydi
     * ÖTEKİLER KANALDAN SİLİNİRDİ — sessiz, GERİ ALINAMAZ ve satıcı
     * bunu ancak siparişler kesilince fark eder.
     *
     * Bu yüzden akış ÜÇ ADIMDIR ve kısaltılamaz:
     *   1. GET  — mevcut TÜM envanter okunur
     *   2. Bizim değişikliğimiz İLGİLİ offering'e uygulanır
     *   3. PUT  — TAM gövde geri yazılır
     *
     * ⚠️ BU, "MUTLAK DEĞER GÖNDERİLİR" KURALININ İHLALİ DEĞİLDİR.
     * Gönderilen değer hâlâ mutlaktır; okunan şey BİZİM YAZMADIĞIMIZ
     * kardeş varyantlardır. Woo'da yük BİZİM gerçeğimizi taşır; Etsy'de
     * yük KANALIN gerçeğini de taşımak zorundadır.
     *
     * ⚠️ YARIŞ PENCERESİ VARDIR ve KABUL EDİLİR: okuma ile yazma
     * arasında satıcı Etsy panelinden kardeş varyantı değiştirirse o
     * değişiklik ezilir. Pencere saniyelerdir ve mutabakat turu farkı
     * SONRAKİ turda yakalar. Alternatif (varyant başına kilit) KANAL
     * TARAFINDA YOKTUR.
     *
     * ⚠️ İLAN BAŞINA AYRI ÇAĞRI. `maxInventoryBatchSize()` 1'dir ama
     * `InventoryBatchBuilder` yine gruplama yapar; burada kalemler
     * `external_parent_id`'ye göre toplanır ve AYNI ilanın varyantları
     * TEK çağrıda gider. Gruplanmasaydı iki varyantlı bir ürünün ikinci
     * çağrısı birincinin yazdığını okumadan ezerdi.
     */
    public function pushInventory(InventoryPushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            // Boş yük için çağrı yapılmaz; kota boşa harcanmaz.
            return AdapterResult::success(['pushed' => 0]);
        }

        $parents = $this->parentListingIdsFor($batch);

        // Kalemler İLAN BAŞINA gruplanır — gerekçe metot başlığında.
        $byListing = [];
        $missing = [];

        foreach ($batch->toArray() as $item) {
            $parentId = $parents[$item['listing_id']] ?? null;

            if ($parentId === null) {
                $missing[] = (string) $item['sku'];

                continue;
            }

            $byListing[$parentId][] = $item;
        }

        if ($byListing === []) {
            // ⚠️ SESSİZCE BAŞARILI DÖNÜLMEZ (v2.2 · §7): dönülseydi
            // operasyon tamamlandı sanılır, `synced_version` ilerler ve
            // satır kanalda hiçbir şey değişmemişken "senkron" görünürdü.
            return AdapterResult::failure(
                ErrorClass::VALIDATION,
                'Etsy stok yükündeki hiçbir kalemin ilan kimliği yok: '
                .implode(', ', $missing),
            );
        }

        $pushed = 0;

        foreach ($byListing as $listingId => $items) {
            $quantityBySku = [];

            foreach ($items as $item) {
                $quantityBySku[(string) $item['sku']] = (int) $item['quantity'];
            }

            $this->writeInventory((string) $listingId, quantityBySku: $quantityBySku);
            $pushed += count($items);
        }

        return AdapterResult::success(array_filter([
            'pushed' => $pushed,
            // Kimliği çözülemeyen kalemler SESSİZCE yutulmaz; sonuç
            // veride görünür ve `SyncResultRecorder` bunu yazar.
            'skipped_skus' => $missing === [] ? null : $missing,
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * Tek ilanın envanterini OKU-BİRLEŞTİR-YAZ ile günceller.
     *
     * ⚠️ STOK VE FİYAT BU TEK AKIŞI PAYLAŞIR ve bu BİLİNÇLİDİR.
     * Etsy'de ikisi AYNI uç noktada ve AYNI offering nesnesinde yaşar
     * (§11.3); iki ayrı kopya yazılsaydı "önce oku, boşsa yazma"
     * emniyeti İKİ yerde yaşar ve biri değiştiğinde ötekinin sessizce
     * eski kalması an meselesi olurdu. Değişen tek şey, birleştiriciye
     * hangi haritanın verildiğidir.
     *
     * @param  array<string, int>  $quantityBySku  Stok turunda dolu
     * @param  array<string, string>  $priceByProductId  Fiyat turunda dolu
     */
    private function writeInventory(
        string $listingId,
        array $quantityBySku = [],
        array $priceByProductId = [],
    ): void {
        // ① OKU — mevcut TÜM envanter.
        $response = $this->client->get(
            EtsyEndpoints::url(EtsyEndpoints::LISTING_INVENTORY, ['listing_id' => $listingId]),
            headers: $this->apiKeyHeader(),
        );

        $response->throw();

        /** @var array<string, mixed> $current */
        $current = $response->json() ?? [];

        /** @var list<array<string, mixed>> $products */
        $products = $current['products'] ?? [];

        if ($products === []) {
            // ⚠️ BOŞ ENVANTER OKUNDUYSA YAZILMAZ. Yazılsaydı gövde bizim
            // tek varyantımızı taşır ve kanaldaki DİĞER TÜM varyantlar
            // silinirdi — tam olarak bu metodun önlemek için var olduğu
            // felaket. Okuma başarısızsa yazma HAKKI DA YOKTUR.
            throw new RuntimeException(
                "Etsy ilanı {$listingId} için envanter okunamadı; boş gövdeyle "
                .'yazmak kanaldaki tüm varyantları SİLERDİ.'
            );
        }

        // ② BİRLEŞTİR — yalnızca bizim kalemlerimiz değişir; kardeş
        // varyantların HEM miktarı HEM fiyatı kanaldaki hâliyle korunur.
        $merged = EtsyInventoryMerger::merge($products, $quantityBySku, $priceByProductId);

        // ③ YAZ — TAM gövde.
        $write = $this->client->request(
            'PUT',
            EtsyEndpoints::url(EtsyEndpoints::LISTING_INVENTORY, ['listing_id' => $listingId]),
            body: ['products' => $merged],
            headers: $this->apiKeyHeader(),
        );

        $write->throw();
    }

    /**
     * Uzak stok durumunu okur — mutabakat için.
     *
     * ⚠️ İLAN BAŞINA TEK ÇAĞRI, VARYANT BAŞINA DEĞİL. Aynı ilanın üç
     * varyantı tek okumadan çözülür; varyant başına istek atılsaydı
     * ölçek hesabı üç katına çıkar ve Etsy'nin GÜNLÜK kotası (§21)
     * mutabakat turlarıyla dolardı.
     *
     * @param  list<Listing>  $listings
     */
    public function fetchInventory(array $listings): RemoteInventorySnapshot
    {
        $quantities = [];
        $seen = [];

        foreach ($listings as $listing) {
            $parentId = $listing->external_parent_id;
            $externalId = $listing->external_id;

            if (! is_string($parentId) || $parentId === ''
                || ! is_string($externalId) || $externalId === '') {
                continue;
            }

            // AYNI İLAN İKİNCİ KEZ OKUNMAZ.
            if (! isset($seen[$parentId])) {
                $seen[$parentId] = $this->readInventoryProducts($parentId);
            }

            foreach ($seen[$parentId] as $product) {
                if ((string) ($product['product_id'] ?? '') !== $externalId) {
                    continue;
                }

                $quantity = EtsyInventoryMerger::quantityOf($product);

                if ($quantity !== null) {
                    $quantities[$externalId] = $quantity;
                }

                break;
            }
        }

        return new RemoteInventorySnapshot(
            quantitiesByExternalId: $quantities,
            observedAt: new DateTimeImmutable,
        );
    }

    /**
     * Bir ilanın envanterindeki `products` dizisi.
     *
     * ⚠️ 404 İSTİSNA DEĞİLDİR — ilan silinmiş olabilir ve mutabakat bunu
     * `REMOTE_MISSING` görmelidir; istisna tek silinmiş ilanla tüm turu
     * düşürürdü.
     *
     * @return list<array<string, mixed>>
     */
    private function readInventoryProducts(string $listingId): array
    {
        $response = $this->client->get(
            EtsyEndpoints::url(EtsyEndpoints::LISTING_INVENTORY, ['listing_id' => $listingId]),
            headers: $this->apiKeyHeader(),
        );

        if ($response->status() === 404) {
            return [];
        }

        $response->throw();

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        /** @var list<array<string, mixed>> $products */
        $products = $body['products'] ?? [];

        return $products;
    }

    /**
     * Yükteki kalemlerin İLAN kimlikleri — TEK sorguda.
     *
     * ⚠️ KALEM BAŞINA AYRI SORGU YAPILMAZ. Yüzlük bir yük yüz sorgu
     * atardı (Shopify'daki kararın aynısı).
     *
     * ⚠️ OKUMA AÇIKÇA SİSTEM BAĞLAMINDA YAPILIR: bu adapter hem kuyruk
     * işinden (bağlam VAR) hem mutabakat taramasından (`runAsSystem`,
     * bağlam YOK) çağrılır; kapsanmış sorgu ikincisinde istisna fırlatır
     * ve tur o bağlantıda çökerdi (`97a7eb7` hata biçimi).
     *
     * HAM SORGU KULLANILMAZ: `DB::table()` kiracı filtresini ELLE
     * yazdırır ve o filtre projede BEŞ KEZ unutuldu.
     *
     * @return array<string, string> listing id → Etsy listing_id
     */
    private function parentListingIdsFor(InventoryPushBatch $batch): array
    {
        $listingIds = array_map(
            static fn (array $item): string => (string) $item['listing_id'],
            $batch->toArray(),
        );

        return TenantContext::runAsSystem(function () use ($listingIds): array {
            $map = [];

            foreach (Listing::query()->whereIn('id', $listingIds)->get() as $listing) {
                $parentId = $listing->external_parent_id;

                if (is_string($parentId) && $parentId !== '') {
                    $map[(string) $listing->id] = $parentId;
                }
            }

            return $map;
        });
    }

    // --------------------------------------------------------------- fiyat

    /**
     * Fiyat yazar — STOKLA AYNI UÇ NOKTA, AYNI OKU-BİRLEŞTİR-YAZ (§11.3).
     *
     * ═════════════════════════════════════════════════════════════════
     * ⚠️ FİYAT TURU SESSİZCE BİR STOK SIFIRLAMASI YAPABİLİR
     * ═════════════════════════════════════════════════════════════════
     * Etsy'de fiyat AYRI bir uç noktada değil, offering nesnesinin
     * içindedir — miktarla YAN YANA. `PUT .../inventory` tüm envanteri
     * ezdiği için gövdede eksik bırakılan `quantity` kanalda SIFIRLANIR
     * ve ürün SATIŞA KAPANIR.
     *
     * Slice 3.5'in tuzağının AYNASIDIR: orada bir stok turu fiyatı
     * sıfırlıyordu, burada bir fiyat turu stoğu sıfırlar. İkincisi daha
     * ağırdır — yanlış fiyattan satış devam eder, sıfır stokta satış
     * DURUR.
     *
     * ⚠️ TRENDYOL'UN "FİYAT YÜKÜ STOK ALANI TAŞIMAZ" KURALI BURADA
     * GEÇERSİZDİR ve bu kanal farkının ta kendisidir. Orada tek uç nokta
     * KISMİ güncellemeyi destekler, bu yüzden alanı GÖNDERMEMEK onu
     * korumanın yoludur. Etsy'de kısmi güncelleme YOKTUR: alanı
     * göndermemek onu SİLMEKTİR. Aynı cümle iki kanalda ters sonuç
     * verir — kopyalanmaz.
     *
     * ⚠️ EŞLEŞME `external_id` (= `product_id`) İLEDİR, SKU İLE DEĞİL.
     * `PricePushBatch` kalemi `sku` TAŞIMAZ; SKU ile eşlenseydi kalemin
     * taşımadığı bir alan uydurulmak zorunda kalınırdı. Stok tarafı SKU
     * ile eşlenir çünkü `InventoryPushItem` `product_id` bilmez.
     *
     * ⚠️ `compare_at_price` GÖNDERİLMEZ. Etsy'nin offering nesnesinde
     * üstü çizili fiyat alanı YOKTUR; kalem onu taşısa bile burada
     * bırakılır. Uydurma bir alan `VALIDATION` döndürür ve o hata
     * KALICIDIR.
     */
    public function pushPrices(PricePushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            // Boş yük için çağrı yapılmaz; kota boşa harcanmaz.
            return AdapterResult::success(['pushed' => 0]);
        }

        $parents = $this->parentListingIdsForPrices($batch);

        // Kalemler İLAN BAŞINA gruplanır — stoktakiyle aynı gerekçe:
        // gruplanmasaydı ikinci çağrı birincinin yazdığını OKUMADAN
        // ezerdi.
        $byListing = [];
        $missing = [];

        foreach ($batch->items as $item) {
            $parentId = $parents[(string) $item['listing_id']] ?? null;

            if ($parentId === null) {
                $missing[] = (string) $item['external_id'];

                continue;
            }

            $byListing[$parentId][(string) $item['external_id']] = (string) $item['price'];
        }

        if ($byListing === []) {
            // ⚠️ SESSİZCE BAŞARILI DÖNÜLMEZ (v2.2 · §7): dönülseydi
            // operasyon tamamlandı sanılır, `synced_version` ilerler ve
            // satır kanalda hiçbir şey değişmemişken "senkron" görünürdü.
            return AdapterResult::failure(
                ErrorClass::VALIDATION,
                'Etsy fiyat yükündeki hiçbir kalemin ilan kimliği yok: '
                .implode(', ', $missing),
            );
        }

        $pushed = 0;

        foreach ($byListing as $listingId => $priceByProductId) {
            $this->writeInventory((string) $listingId, priceByProductId: $priceByProductId);
            $pushed += count($priceByProductId);
        }

        return AdapterResult::success(array_filter([
            'pushed' => $pushed,
            // Kimliği çözülemeyen kalemler SESSİZCE yutulmaz.
            'skipped_external_ids' => $missing === [] ? null : $missing,
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * Uzak fiyat durumunu okur — mutabakat ve §9 çakışma tespiti için.
     *
     * ⚠️ İLAN BAŞINA TEK ÇAĞRI, VARYANT BAŞINA DEĞİL —
     * `fetchInventory()` ile aynı gerekçe: Etsy'nin GÜNLÜK kotası (§21)
     * mutabakat turlarıyla dolardı.
     *
     * ⚠️ FİYAT İLAN SEVİYESİNDEN DEĞİL, OFFERING'DEN OKUNUR. İlan
     * gövdesindeki `price` çok varyantlı üründe yalnızca EN DÜŞÜK
     * varyantın fiyatıdır; oradan okunsaydı pahalı varyantlar her tur
     * SAHTE çakışma raporlar ve satıcı aynı kararı sonsuza kadar
     * verirdi (§9).
     *
     * @param  list<Listing>  $listings
     */
    public function fetchPrices(array $listings): RemotePriceSnapshot
    {
        $prices = [];
        $seen = [];

        foreach ($listings as $listing) {
            $parentId = $listing->external_parent_id;
            $externalId = $listing->external_id;

            if (! is_string($parentId) || $parentId === ''
                || ! is_string($externalId) || $externalId === '') {
                continue;
            }

            // AYNI İLAN İKİNCİ KEZ OKUNMAZ.
            if (! isset($seen[$parentId])) {
                $seen[$parentId] = $this->readInventoryProducts($parentId);
            }

            foreach ($seen[$parentId] as $product) {
                if ((string) ($product['product_id'] ?? '') !== $externalId) {
                    continue;
                }

                $price = EtsyInventoryMerger::priceOf($product);

                // ⚠️ FİYATI OLMAYAN VARYANT ATLANIR, `"0"` YAZILMAZ:
                // mutabakat "kanalda 0 TL" sanır ve satıcıyı var olmayan
                // bir fiyat için karar vermeye zorlardı.
                if ($price !== null) {
                    $prices[$externalId] = $price;
                }

                break;
            }
        }

        return new RemotePriceSnapshot(
            pricesByExternalId: $prices,
            observedAt: new DateTimeImmutable,
        );
    }

    /**
     * ⚠️ FİYAT PARTİSİ DE İLAN BAŞINA 1'DİR — stokla AYNI gerekçe.
     *
     * Uç nokta tek ilanı adresler ve o ilanın TÜM varyantlarını tek
     * gövdede ister (§11.3). `PriceBatchBuilder` operasyonları yine
     * birleştirir; adapter `external_parent_id`'ye göre gruplar.
     */
    public function maxPriceBatchSize(): int
    {
        return self::MAX_INVENTORY_BATCH;
    }

    /**
     * Fiyat yükündeki listing'lerin İLAN kimlikleri — TEK sorguda.
     *
     * `parentListingIdsFor()` ile aynı iş; ayrı durmasının sebebi iki
     * yükün ŞEKLİNİN farklı olmasıdır (`InventoryPushBatch` nesne
     * kalemleri, `PricePushBatch` dizi kalemleri taşır).
     *
     * ⚠️ OKUMA AÇIKÇA SİSTEM BAĞLAMINDA — mutabakat taraması
     * `runAsSystem()` altında koşar ve bağlam YOKTUR (`97a7eb7`).
     *
     * @return array<string, string> listing id → Etsy listing_id
     */
    private function parentListingIdsForPrices(PricePushBatch $batch): array
    {
        $listingIds = array_map(
            static fn (array $item): string => (string) $item['listing_id'],
            $batch->items,
        );

        return TenantContext::runAsSystem(function () use ($listingIds): array {
            $map = [];

            foreach (Listing::query()->whereIn('id', $listingIds)->get() as $listing) {
                $parentId = $listing->external_parent_id;

                if (is_string($parentId) && $parentId !== '') {
                    $map[(string) $listing->id] = $parentId;
                }
            }

            return $map;
        });
    }

    // ---------------------------------------------------------- taksonomi

    /**
     * Kategori ağacı — KANALIN GERÇEĞİ, kiracısız saklanır (§11.5).
     *
     * Uç nokta satıcıya özgü DEĞİLDİR (`shops/{shop_id}` öneki yok) ve bu,
     * ağacın neden kiracısız saklandığının API tarafındaki karşılığıdır.
     * Yine de çağrı KİMLİKLİDİR: Etsy anonim istek kabul etmez.
     */
    public function fetchCategoryTree(): CategoryTreeSnapshot
    {
        return $this->taxonomy()->fetchTree();
    }

    /**
     * Yaprak kategorinin öznitelikleri.
     *
     * ⚠️ YALNIZCA YAPRAK İÇİN ÇAĞRILIR — `SyncTaxonomy` bunu garanti eder.
     * Ara kategoriye ürün açılamaz; öznitelik istemek boşuna istek ve
     * boşuna KOTADIR (§21: 10.000 istek/gün, hesap başına).
     *
     * @return array<string, mixed>
     */
    public function fetchCategoryAttributes(string $categoryId): array
    {
        return $this->taxonomy()->fetchAttributes($categoryId);
    }

    /**
     * Ağacın SÜRÜMÜ — içerikten türer.
     *
     * ⚠️ AĞACI ÇEKER. Etsy bir sürüm numarası yayımlamaz ve parmak izi
     * ancak ağacın kendisinden hesaplanabilir. `SyncTaxonomy` bu metodu
     * ağacı zaten çektikten SONRA çağırmaz — sürümü snapshot'tan okur;
     * burası arayüz sözleşmesini karşılar ve tek başına çağrılırsa
     * DOĞRU cevabı verir (uydurma bir sabit dönseydi sürüm ağaçla
     * ayrışır ve eşleştirmeler yanlış sürüme bağlanırdı).
     */
    public function taxonomyVersion(): string
    {
        return $this->fetchCategoryTree()->version;
    }

    private function taxonomy(): EtsyTaxonomyClient
    {
        return new EtsyTaxonomyClient($this->client, $this->apiKeyHeader());
    }

    // ------------------------------------------------------- token yenileme

    /**
     * Access token'ı refresh token ile tazeler (§11.2 · §20 · P0-5).
     *
     * ⚠️ BU METOT KASAYA YAZMAZ — `RefreshedCredentials` DÖNER ve yazmayı
     * `TokenRefresher` yapar (v2.2 · "adapter yan etkisizdir"). Yazsaydı
     * `channel_credentials`'ın tek yazma kapısı olan kasa devre dışı
     * kalır, anahtar sürümü ve maskeleme yüzeyi ikiye bölünürdü.
     *
     * ⚠️ REFRESH TOKEN TEK KULLANIMLIKTIR. Etsy her yenilemede YENİ bir
     * refresh token döner ve ESKİSİNİ İPTAL EDER; dönen değer
     * saklanmazsa bağlantı bir sonraki yenilemede ölür. Bu yüzden sır
     * kümesi TAM olarak yazılır, yalnızca access token değil.
     *
     * Paralel iki yenilemenin ilkini iptal etme tuzağını çekirdek çözer:
     * tarama `FOR UPDATE SKIP LOCKED` ile tek satırı kilitler (§20).
     *
     * ⚠️ BAŞARISIZLIKTA İSTİSNA FIRLATILIR, sessiz dönüş YOKTUR:
     * "yenilenemedi" ile "yenilendi" arasındaki fark bağlantının yaşamıdır.
     */
    public function refreshCredentials(): RefreshedCredentials
    {
        $secrets = $this->secrets();
        $refreshToken = $secrets['refresh_token'] ?? null;

        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new RuntimeException(
                'Etsy refresh token yok — bağlantı yeniden yetkilendirilmelidir.'
            );
        }

        $response = $this->client->post(
            EtsyEndpoints::url(EtsyEndpoints::TOKEN),
            EtsyAuth::refreshRequest($this->keystring(), $refreshToken),
        );

        $response->throw();

        /** @var array<string, mixed> $body */
        $body = $response->json();

        $access = $body['access_token'] ?? null;

        if (! is_string($access) || $access === '') {
            throw new RuntimeException('Etsy yenileme yanıtı access token taşımıyor.');
        }

        // YENİ REFRESH TOKEN DÖNERSE O YAZILIR; dönmezse eskisi korunur.
        // Körlemesine üzerine yazılsaydı yanıtta alan yoksa refresh token
        // NULL olur ve bağlantı sonraki turda ölürdü.
        $newRefresh = $body['refresh_token'] ?? null;

        return new RefreshedCredentials(
            secrets: [
                ...$secrets,
                'access_token' => $access,
                'refresh_token' => is_string($newRefresh) && $newRefresh !== ''
                    ? $newRefresh
                    : $refreshToken,
            ],
            expiresAt: $this->expiryFrom($body),
        );
    }

    /**
     * Süre dolmadan 15 dakika önce yenile.
     *
     * ⚠️ PAY TARAMA SIKLIĞINDAN KÜÇÜK OLAMAZ. Tarama 15 dakikada bir
     * koşar (§20); pay 15 dakikadan KISA olsaydı token, iki tur arasında
     * hem "henüz aday değil" hem "artık ölmüş" olabilirdi ve o aralıktaki
     * her çağrı 401 alırdı.
     *
     * 1 saatlik token için 15 dakikalık pay ÜÇ DENEME hakkı verir (§20:
     * "sıklık en kısa TTL'in dörtte biridir").
     */
    public function refreshLeadSeconds(): int
    {
        return 900;
    }

    // ────────────────────────────────────────────────────────── yardımcılar

    /**
     * `x-api-key` başlığı — UYGULAMANIN kimliği.
     *
     * ⚠️ ANAHTAR YOKSA İSTEK HİÇ ATILMAZ. Boş başlıkla giden istek 401
     * alır, `AUTHENTICATION` KALICI sayılır ve listing "anahtarın yanlış"
     * diyerek ölür — oysa anahtar YOKTUR, yanlış değildir. Hepsiburada'nın
     * "satıcı kimliği yoksa istek atılmaz" kuralının aynısı.
     *
     * @return array<string, string>
     */
    private function apiKeyHeader(): array
    {
        return ['x-api-key' => $this->keystring()];
    }

    private function keystring(): string
    {
        $settings = $this->connection->settings;
        $keystring = is_array($settings) ? ($settings[self::KEYSTRING_KEY] ?? null) : null;

        if (! is_string($keystring) || $keystring === '') {
            throw new RuntimeException(
                'Etsy uygulama anahtarı (keystring) tanımsız — istek kimliksiz '.
                'gider ve kanal 401 döner; sebep hiçbir yerde görünmezdi.'
            );
        }

        return $keystring;
    }

    /** Mağaza kimliği — yol üzerinde taşınır (§19). */
    private function shopId(): ?string
    {
        $settings = $this->connection->settings;
        $shopId = is_array($settings) ? ($settings[self::SHOP_ID_KEY] ?? null) : null;

        return is_string($shopId) && $shopId !== '' ? $shopId : null;
    }

    /**
     * Kasadaki sırlar — AÇIKÇA SİSTEM BAĞLAMINDA.
     *
     * `channel_credentials` kiracıya göre kapsanır ama bu adapter kuyruk
     * işinden ve `runAsSystem()` taramasından da çağrılır; oralarda bağlam
     * YOKTUR. Kapsanmış sorgu istisna fırlatır ve istek SESSİZCE KİMLİKSİZ
     * giderdi (`97a7eb7`'de yaşanmış hata biçimi).
     *
     * Token yenileme tam olarak böyle bir yerden çağrılır: `TokenRefresher`
     * `runAsSystem()` altında koşar.
     *
     * ⚠️ HATA YUTULMAZ — `ChannelHttpClient` ve `ShopifyAdapter`'ın
     * AKSİNE. Orada boş dizi dönmek doğrudur: istek kimliksiz gider,
     * kanal 401 verir ve durum `last_error`'a yazılır. Burada ise boş
     * dizi "refresh token yok" demeye dönüşür ve bu, kasası okunamayan
     * bir bağlantıyı "yeniden yetkilendir" damgasıyla ÖLDÜRÜRDÜ — oysa
     * sorun geçici olabilir (şifre çözme hatası, bağlam sorunu).
     * İstisna yükselirse `TokenRefresher` turu işaretler ve SONRAKİ TURDA
     * yeniden dener (§20 · "başarısız yenileme bağlantıyı öldürmez").
     *
     * @return array<string, mixed>
     */
    private function secrets(): array
    {
        return TenantContext::runAsSystem(
            fn (): array => app(CredentialVault::class)->read($this->connection)
        );
    }

    /**
     * Yanıttaki `expires_in` (saniye) → mutlak an.
     *
     * ⚠️ ALAN YOKSA NULL DÖNER, UYDURULMAZ. Varsayılan bir süre
     * yazılsaydı (ör. "1 saat") ve Etsy o süreyi değiştirseydi, tarama
     * token'ı ya çok geç ya hiç yenilemez; ikisi de bağlantıyı öldürür.
     * NULL, `TokenRefresher`'a "süre bilinmiyor" der.
     *
     * @param  array<string, mixed>  $body
     */
    private function expiryFrom(array $body): ?DateTimeImmutable
    {
        $seconds = $body['expires_in'] ?? null;

        if (! is_int($seconds) && ! (is_string($seconds) && ctype_digit($seconds))) {
            return null;
        }

        return new DateTimeImmutable('@'.(time() + (int) $seconds));
    }
}
