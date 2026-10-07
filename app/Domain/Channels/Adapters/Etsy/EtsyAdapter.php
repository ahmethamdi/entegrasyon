<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Etsy;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Support\ChannelImages;
use App\Domain\Channels\Adapters\Etsy\Taxonomy\EtsyTaxonomyClient;
use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresConnectionSettings;
use App\Domain\Channels\Contracts\DeclaresImageLimit;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\RefreshedCredentials;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Contracts\SupportsTaxonomy;
use App\Domain\Channels\Contracts\SupportsTokenRefresh;
use App\Domain\Channels\Models\CategoryMapping;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\ConnectionSettingField;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\CategoryTreeSnapshot;
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
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Etsy Open API v3 adapter — BEŞİNCİ kanal.
 *
 * V3.0 · §11 · §20 · §21 · v2.2 §7.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KAPSAM — SLICE 3.1 · 3.2 · 3.3 · 3.4 · 3.5 · 3.6 · 3.7
 * ─────────────────────────────────────────────────────────────────────
 * Yazılan: kimlik/başlık katmanı, sağlık kontrolü, hata sınıflandırma,
 * hız sınırı profili, **token yenileme** (`SupportsTokenRefresh`),
 * **taksonomi** (`SupportsTaxonomy`), **katalog** (`SupportsCatalog`),
 * **stok** ve **fiyat** (`SupportsInventory` + `SupportsPricing` —
 * ikisi de AYNI oku-birleştir-yaz akışını paylaşır, §11.3) ve
 * **sipariş yoklaması** (`SupportsOrders` — §11.4).
 *
 * UYGULANMAYAN YETENEKLER ve GEREKÇELERİ:
 *   · `SupportsApprovalWorkflow` — Etsy'de onay süreci YOKTUR ve ilan
 *     yayınlanır yayınlanmaz canlıdır (§11.5). Uygulansaydı panelde hiç
 *     dolmayacak bir sekme açılırdı.
 *   · `SupportsFulfillment` — §11.4 bunu öngörüyor ama slice tablosunda
 *     kendi satırı YOKTUR; ilan edilip yazılmasaydı panelde çalışmayan
 *     bir sekme açardı (§05).
 *
 * `SupportsCatalogImport` 7 Eki 2026'da eklendi: mağazası dolu satıcı
 * bağlandığında ilanları 34Pazar'a gelmiyordu.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ İKİ AYRI KİMLİK BAŞLIĞI VARDIR (§11.2)
 * ─────────────────────────────────────────────────────────────────────
 * `Authorization: Bearer {token}` SATICININ kimliğidir ve YENİLENİR.
 * `x-api-key: {keystring}:{shared_secret}` UYGULAMANIN kimliğidir,
 * YENİLENMEZ ve sunucu ayarındadır (`EtsyApp`) — satıcı başına değil.
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
final class EtsyAdapter implements ChannelAdapter, DeclaresConnectionSettings, DeclaresImageLimit, SupportsCatalog, SupportsCatalogImport, SupportsInventory, SupportsOrders, SupportsPricing, SupportsTaxonomy, SupportsTokenRefresh
{
    use DeclaresRequestQuota;

    /**
     * Etsy receipt durumu → kanonik olay tipi (§11.4).
     *
     * ⚠️ `returned` HİÇ ÜRETİLMEZ ve bu DÜRÜST bir sınırdır. Etsy iade
     * için ayrı uç nokta VERMİYOR; satıcı iadeyi panelden işler ve
     * `receipt` durumu `refunded` olur. `returned` sayılsaydı SATILMIŞ
     * stok geri eklenir ve bakiye bozulurdu. `updated` stok hareketi
     * ÜRETMEZ ve doğru davranıştır — gerçek iade panelden elle girilir.
     *
     * ⚠️ LİSTEDE OLMAYAN DURUM `updated` SAYILIR (`parseOrderEvent`):
     * Etsy listeyi genişletebilir ve bilinmeyeni `created` ya da
     * `cancelled` saymak bakiyeyi bozardı.
     *
     * `completed` stok hareketi ÜRETMEZ: stok sipariş oluştuğunda zaten
     * düşülmüştür ve tamamlanma yalnızca anlık görüntüyü tazeler.
     *
     * ⚠️ ANAHTARLAR NORMALLEŞTİRİLMİŞ BİÇİMDEDİR (`statusKey()`). Etsy
     * durumu "Payment Processing", "Paid", "Canceled", "Fully Refunded"
     * biçiminde gönderir (canlıda 7 Eki ölçüldü). Ham değerle bakılsaydı
     * HİÇBİR durum eşleşmez, her sipariş `updated` sayılır ve iptal
     * edilen siparişin stoğu GERİ EKLENMEZDİ.
     *
     * `payment_processing` `created`'dır: Etsy stoğu sipariş anında düşer,
     * ödeme onayını beklemez — biz de beklersek iki kanal arasında
     * fazla satış penceresi açılır.
     */
    private const STATUS_TO_TYPE = [
        'paid' => 'created',
        'open' => 'created',
        'payment_processing' => 'created',
        'completed' => 'updated',
        'processing' => 'updated',
        'refunded' => 'updated',
        'fully_refunded' => 'updated',
        'partially_refunded' => 'updated',
        'canceled' => 'cancelled',
        'cancelled' => 'cancelled',
    ];

    /**
     * Sipariş sayfası boyutu — Etsy'nin uç nokta üst sınırı 100 (§11.4).
     */
    private const ORDER_PAGE_SIZE = 100;

    /**
     * Etsy beyanları ve profiller — bağlantı ayarı (`settings`). Beyanlar
     * (`who_made`, `when_made`) satıcının YASAL beyanıdır, uydurulmaz.
     */
    public const WHO_MADE_KEY = 'etsy_who_made';

    public const WHEN_MADE_KEY = 'etsy_when_made';

    public const SHIPPING_PROFILE_KEY = 'etsy_shipping_profile_id';

    public const READINESS_KEY = 'etsy_readiness_state_id';

    /**
     * Mağaza kimliğinin `settings` içindeki yeri — yol üzerinde taşınır.
     * Satıcıya SORULMAZ: OAuth dönüşünde `GET /users/me`'den yazılır.
     */
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

    /** İçe aktarma sayfası — Etsy'nin ilan listesi üst sınırı 100. */
    private const IMPORT_PAGE_SIZE = 100;

    /** İçe aktarılan ilan durumları, SIRAYLA (`fetchProductPage`). */
    private const IMPORT_STATES = ['active', 'sold_out', 'inactive', 'expired'];

    public function __construct(
        private readonly ChannelConnection $connection,
        private readonly ChannelHttpClient $client,
    ) {}

    /** Etsy: ilan başına en fazla 10 görsel. */
    public function maxImages(): int
    {
        return 10;
    }

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
     * Sipariş YOKLAMAYLA gelir (slice 3.7 ✓) ve olay kimliği
     * `{receipt_id}:{status}` biçiminde `pollingEventIdFor()` içinde
     * TÜRETİLİR.
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function verifyWebhookSignature(string $raw, array $headers): bool
    {
        return false;
    }

    /**
     * ⚠️ BAŞLIKTAN KİMLİK OKUNMAZ — Etsy webhook GÖNDERMEZ.
     *
     * Bu metot WEBHOOK yolunundur ve o yol Etsy'de HİÇ çalışmaz.
     * Yoklamanın kimliği `pollingEventIdFor()` içinde ve gövdeden
     * türetilir; ikisi KARIŞTIRILMAZ.
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function extractEventId(array $headers): ?string
    {
        return null;
    }

    /** @param array<string, array<int, string|null>> $headers */
    public function extractEventType(array $headers): string
    {
        return 'unknown';
    }

    // ------------------------------------------------------------- katalog

    /**
     * Yeni ilan açar — TASLAK olarak (Etsy taslak dışında yaratmaz).
     *
     * 7 Eki 2026, ilk gerçek mağaza öncesi Etsy OAS'a göre yeniden yazıldı.
     * Önceki hâl hiç çalışamazdı:
     *   · zorunlu `price`/`quantity` gönderilmiyordu,
     *   · `taxonomy_id` olarak 34Pazar'ın İÇ kategori kimliği gidiyordu,
     *   · zorunlu `who_made`/`when_made` hiçbir yerden dolmuyordu,
     *   · fizikselde zorunlu `shipping_profile_id` yoktu,
     *   · gövde JSON'du; uç nokta form (`x-www-form-urlencoded`) bekler.
     *
     * ⚠️ BEYAN UYDURULMAZ: `who_made` ("bunu kim yaptı") ve `when_made`
     * satıcının Etsy'ye YASAL beyanıdır; bağlantı ayarında yoksa ilan
     * açılmaz ve sebep söylenir. Kargo ve hazırlık profili mağazada TEK ise
     * o seçilir, birden çoksa ayardan okunur.
     *
     * ⚠️ ADET YER TUTUCUDUR (1): Etsy sıfır kabul etmez ve ilan taslaktır,
     * satılmaz; gerçek stok envanter yazımıyla gider.
     *
     * Yaratma sonrası envanter okunur: tek varyantın SKU'su boşsa BİZİM
     * SKU'muz yazılır (sonraki eşleşmeler için) ve kimlik (`product_id`,
     * `offering_id`) bu okumadan alınır — yaratma yanıtı envanter taşımaz.
     */
    public function createListing(ListingPayload $payload): AdapterResult
    {
        $variant = $payload->listing->variant;
        $taxonomyId = $this->taxonomyIdFor($payload);

        if ($taxonomyId === null) {
            return AdapterResult::failure(ErrorClass::VALIDATION, 'Ürünün kategorisi Etsy\'de eşleştirilmemiş; kategori eşleştirme ekranından tamamlanmalı.');
        }

        $whoMade = $this->setting(self::WHO_MADE_KEY);
        $whenMade = $this->setting(self::WHEN_MADE_KEY);

        if ($whoMade === null || $whenMade === null) {
            return AdapterResult::failure(ErrorClass::VALIDATION, 'Etsy bağlantı ayarında "kim yaptı" ve "ne zaman yapıldı" beyanı eksik; Etsy bu beyan olmadan ilan açmaz.');
        }

        $price = $variant?->price;

        if (! is_numeric($price) || (float) $price <= 0) {
            return AdapterResult::failure(ErrorClass::VALIDATION, 'Ürünün fiyatı yok; Etsy fiyatsız ilan açmaz.');
        }

        $shippingProfileId = $this->setting(self::SHIPPING_PROFILE_KEY) ?? $this->onlyProfileId(EtsyEndpoints::SHIPPING_PROFILES, 'shipping_profile_id');

        if ($shippingProfileId === null) {
            return AdapterResult::failure(ErrorClass::VALIDATION, 'Etsy mağazasında kargo profili seçilemedi (hiç yok ya da birden çok); bağlantı ayarından seçilmeli.');
        }

        $response = $this->client->request(
            'POST',
            EtsyEndpoints::url(EtsyEndpoints::SHOP_LISTINGS, ['shop_id' => $this->requireShopId()]),
            body: EtsyProductMapper::toDraftBody(
                $payload,
                taxonomyId: $taxonomyId,
                whoMade: $whoMade,
                whenMade: $whenMade,
                price: (string) $price,
                shippingProfileId: $shippingProfileId,
                readinessStateId: $this->setting(self::READINESS_KEY) ?? $this->onlyProfileId(EtsyEndpoints::READINESS_STATES, 'readiness_state_id'),
            ),
            headers: $this->apiKeyHeader(),
            asForm: true,
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

        $listingId = (string) $body['listing_id'];
        $products = $this->claimSingleVariantSku($listingId, (string) ($variant?->sku ?? ''));

        return AdapterResult::success($this->withNewImages($payload, $listingId, EtsyProductMapper::toIdentityResult(
            ['listing_id' => $listingId, 'inventory' => ['products' => $products]],
            $variant?->sku,
        )));
    }

    /**
     * Var olan ilanı GÜNCELLER — başlık, açıklama, (eşleştirildiyse) kategori.
     *
     * ⚠️ YOL MAĞAZA ALTINDADIR (`SHOP_LISTING`); mağazasız yol PATCH kabul
     * etmez. Gövde form biçimindedir.
     *
     * ⚠️ DURUM (`state`) GÖNDERİLMEZ. Önceki gövde her güncellemede
     * `state => draft` taşıyordu: içe aktarılmış YAYINDAKİ bir ilanın
     * başlığı düzeltilince ilan satıştan düşmeye zorlanırdı.
     *
     * ⚠️ HEDEF `listing_id`'DİR (`external_parent_id`), `product_id` DEĞİL.
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
            EtsyEndpoints::url(EtsyEndpoints::SHOP_LISTING, ['shop_id' => $this->requireShopId(), 'listing_id' => $listingId]),
            body: EtsyProductMapper::toUpdateBody($payload, $this->taxonomyIdFor($payload)),
            headers: $this->apiKeyHeader(),
            asForm: true,
        );

        $response->throw();

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        return AdapterResult::success($this->withNewImages($payload, (string) $listingId, EtsyProductMapper::toIdentityResult(
            $body,
            $payload->listing->variant?->sku,
        )));
    }

    /**
     * Ürünün iç kategorisinin Etsy karşılığı (taksonomi kimliği); eşleştirme
     * yoksa null. Kategori ağacı KİRACISIZDIR (kanalın gerçeği).
     */
    private function taxonomyIdFor(ListingPayload $payload): ?int
    {
        $internal = $payload->categoryId;

        if ($internal === null || trim($internal) === '') {
            return null;
        }

        // Adapter bağlamsız da çağrılır (kuyruk, tarama): eşleştirme
        // kiracıya bağlıdır → sistem bağlamında BAĞLANTININ kiracısıyla süzülür.
        $external = TenantContext::runAsSystem(function () use ($internal): mixed {
            $mapping = CategoryMapping::query()
                ->where('tenant_id', $this->connection->tenant_id)
                ->where('internal_category_id', $internal)
                ->where('channel_type_code', 'etsy')
                ->first();

            return $mapping === null ? null : ChannelCategory::query()->find($mapping->channel_category_id)?->external_id;
        });

        return is_numeric($external) ? (int) $external : null;
    }

    /**
     * Mağazada TEK profil varsa onun kimliği; yoksa ya da birden çoksa null.
     * Okuma hatası null döner — çağıran sebebi söyler, tahmin yapılmaz.
     */
    private function onlyProfileId(string $template, string $idField): ?string
    {
        try {
            $response = $this->client->get(
                EtsyEndpoints::url($template, ['shop_id' => $this->requireShopId()]),
                headers: $this->apiKeyHeader(),
            );
        } catch (Throwable) {
            return null;
        }

        $results = $response->successful() ? (array) ($response->json('results') ?? []) : [];

        return count($results) === 1 && isset($results[0][$idField]) ? (string) $results[0][$idField] : null;
    }

    /**
     * Yeni ilanın envanterini okur; TEK varyantlıysa ve SKU'su boşsa bizim
     * SKU'muzu yazar (oku-birleştir-yaz, fiyat/adet korunur). Son envanter
     * ürünlerini döner — kimlik buradan okunur.
     *
     * Yazma başarısız olursa okunan envanterle devam edilir: ilan açıldı,
     * kimliği yine alınmalı (eşleşme `product_id` ile de çalışır).
     *
     * @return list<array<string, mixed>>
     */
    private function claimSingleVariantSku(string $listingId, string $sku): array
    {
        $read = $this->client->get(
            EtsyEndpoints::url(EtsyEndpoints::LISTING_INVENTORY, ['listing_id' => $listingId]),
            headers: $this->apiKeyHeader(),
        );

        if (! $read->successful()) {
            return [];
        }

        /** @var list<array<string, mixed>> $products */
        $products = array_values(array_filter((array) ($read->json('products') ?? []), 'is_array'));

        if ($sku === '' || count($products) !== 1 || (string) ($products[0]['sku'] ?? '') !== '') {
            return $products;
        }

        $products[0]['sku'] = $sku;

        try {
            $write = $this->client->request(
                'PUT',
                EtsyEndpoints::url(EtsyEndpoints::LISTING_INVENTORY, ['listing_id' => $listingId]),
                body: ['products' => EtsyInventoryMerger::merge($products, [])],
                headers: $this->apiKeyHeader(),
            );

            $write->throw();

            $after = array_values(array_filter((array) ($write->json('products') ?? []), 'is_array'));

            return $after !== [] ? $after : $products;
        } catch (Throwable $e) {
            Log::warning('etsy.sku_claim_failed', ['connection' => $this->connection->id, 'listing' => $listingId, 'error' => $e->getMessage()]);

            return $products;
        }
    }

    /** Bağlantı ayarı (`settings`) — boşsa null. */
    private function setting(string $key): ?string
    {
        $value = $this->connection->settings[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * Etsy'nin `who_made` / `when_made` değerleri — Etsy OAS'tan (7 Eki 2026).
     *
     * ⚠️ `when_made` YILLIK DEĞİŞİR: en yeni aralık her yıl uzar
     * (`2020_2025` → `2020_2026`). Eski değer kayıtlıysa seçenek listesinde
     * bulunmaz, ekran onu "seçilmemiş" gösterir ve satıcı yeniden seçer;
     * eski değerle ilan açmak Etsy'de 400 olurdu.
     */
    private const WHO_MADE_VALUES = ['i_did', 'someone_else', 'collective'];

    private const WHEN_MADE_VALUES = [
        'made_to_order', '2020_2026', '2010_2019', '2007_2009', 'before_2007', '2000_2006',
        '1990s', '1980s', '1970s', '1960s', '1950s', '1940s', '1930s', '1920s', '1910s', '1900s',
        '1800s', '1700s', 'before_1700',
    ];

    public function missingConnectionSettings(): array
    {
        return array_values(array_filter(
            [self::WHO_MADE_KEY, self::WHEN_MADE_KEY, self::SHIPPING_PROFILE_KEY],
            fn (string $key): bool => $this->setting($key) === null,
        ));
    }

    public function connectionSettingFields(): array
    {
        [$profiles, $profilesError] = $this->readShopList(EtsyEndpoints::SHIPPING_PROFILES);
        [$readiness, $readinessError] = $this->readShopList(EtsyEndpoints::READINESS_STATES);

        return [
            new ConnectionSettingField(
                key: self::WHO_MADE_KEY,
                label: __('Ürünleri kim yapıyor?'),
                options: [
                    ['value' => 'i_did', 'label' => __('Ben yaptım'), 'usable' => true],
                    ['value' => 'collective', 'label' => __('Ekibimden biri yaptı'), 'usable' => true],
                    ['value' => 'someone_else', 'label' => __('Başka bir firma ya da kişi yaptı'), 'usable' => true],
                ],
                value: $this->settingIn(self::WHO_MADE_KEY, self::WHO_MADE_VALUES),
                hint: __('Etsy\'nin yasal beyanı; her yeni ilana yazılır. Etsy kuralları gereği doğru beyan senin sorumluluğunda.'),
            ),
            new ConnectionSettingField(
                key: self::WHEN_MADE_KEY,
                label: __('Ürünler ne zaman yapıldı?'),
                options: array_map(fn (string $value): array => [
                    'value' => $value,
                    'label' => $this->whenMadeLabel($value),
                    'usable' => true,
                ], self::WHEN_MADE_VALUES),
                value: $this->settingIn(self::WHEN_MADE_KEY, self::WHEN_MADE_VALUES),
            ),
            new ConnectionSettingField(
                key: self::SHIPPING_PROFILE_KEY,
                label: __('Kargo profili'),
                options: array_map(fn (array $profile): array => $this->shippingProfileOption($profile), $profiles),
                value: $this->setting(self::SHIPPING_PROFILE_KEY),
                hint: __('Yeni ilanlar bu profille açılır. Profilleri Etsy\'de Ayarlar → Kargo ayarları\'ndan düzenleyebilirsin.'),
                optionsError: $profilesError,
            ),
            new ConnectionSettingField(
                key: self::READINESS_KEY,
                label: __('Hazırlık süresi'),
                options: array_map(fn (array $state): array => [
                    'value' => (string) ($state['readiness_state_id'] ?? ''),
                    'label' => $this->readinessLabel($state),
                    'usable' => isset($state['readiness_state_id']),
                ], $readiness),
                value: $this->setting(self::READINESS_KEY),
                required: false,
                hint: __('Boş bırakırsan mağazanda tek hazırlık profili varsa o kullanılır.'),
                optionsError: $readinessError,
            ),
        ];
    }

    /** Kayıtlı değer hâlâ geçerli kümede mi — değilse null (yeniden seçilir). */
    private function settingIn(string $key, array $allowed): ?string
    {
        $value = $this->setting($key);

        return $value !== null && in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * Mağaza düzeyindeki bir listeyi okur: [sonuçlar, hata metni].
     *
     * ⚠️ HATA BOŞ LİSTEYE DÖNÜŞMEZ: "mağazada profil yok" ile "Etsy'ye
     * ulaşılamadı" ayrı şeylerdir ve satıcı farkı görmelidir.
     *
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    private function readShopList(string $template): array
    {
        try {
            $response = $this->client->get(
                EtsyEndpoints::url($template, ['shop_id' => $this->requireShopId()]),
                headers: $this->apiKeyHeader(),
            );

            $response->throw();
        } catch (Throwable $e) {
            Log::warning('etsy.settings_options_unavailable', [
                'connection' => $this->connection->id,
                'endpoint' => $template,
                'error' => $e->getMessage(),
            ]);

            return [[], __('Etsy\'den okunamadı. Bağlantının izinli olduğundan emin ol ve sayfayı yenile.')];
        }

        /** @var list<array<string, mixed>> $results */
        $results = array_values(array_filter((array) ($response->json('results') ?? []), 'is_array'));

        return [$results, null];
    }

    /**
     * Kargo profili seçeneği — Etsy'nin YAYINLAMA kuralıyla.
     *
     * ⚠️ TASLAK AÇILIR AMA YAYINLANAMAZ: eksik profille Etsy ilanı taslak
     * olarak kabul eder, `state=active` anında 400 verir ("Postal Code is
     * required … You must provide either a carrier and mail class or
     * min/max delivery days", canlıda 7 Eki — Printful profilleri). Hata
     * ancak satıcı yayınlamaya kalkınca çıkardı; burada baştan söylenir.
     *
     * @param  array<string, mixed>  $profile
     * @return array{value: string, label: string, usable: bool, note: string|null}
     */
    private function shippingProfileOption(array $profile): array
    {
        $problems = [];

        if (trim((string) ($profile['origin_postal_code'] ?? '')) === '') {
            $problems[] = __('çıkış posta kodu yok');
        }

        foreach ((array) ($profile['shipping_profile_destinations'] ?? []) as $destination) {
            $hasCarrier = (int) ($destination['shipping_carrier_id'] ?? 0) > 0
                && trim((string) ($destination['mail_class'] ?? '')) !== '';
            $hasDays = isset($destination['min_delivery_days'], $destination['max_delivery_days']);

            if (! $hasCarrier && ! $hasDays) {
                $problems[] = __('teslim süresi ya da kargo firması eksik');

                break;
            }
        }

        $origin = trim((string) ($profile['origin_country_iso'] ?? ''));

        return [
            'value' => (string) ($profile['shipping_profile_id'] ?? ''),
            'label' => trim((string) ($profile['title'] ?? '')).($origin !== '' ? " · {$origin}" : ''),
            'usable' => $problems === [] && isset($profile['shipping_profile_id']),
            'note' => $problems === []
                ? null
                : __('Etsy bu profille ilan yayınlamaz: :problems', ['problems' => implode(', ', $problems)]),
        ];
    }

    /** @param  array<string, mixed>  $state */
    private function readinessLabel(array $state): string
    {
        $kind = ($state['readiness_state'] ?? null) === 'made_to_order'
            ? __('Siparişe göre üretim')
            : __('Hazır ürün');
        $days = trim((string) ($state['processing_days_display_label'] ?? ''));

        return $days !== '' ? "{$kind} · {$days}" : $kind;
    }

    private function whenMadeLabel(string $value): string
    {
        return match (true) {
            $value === 'made_to_order' => __('Siparişe göre yapılıyor'),
            str_starts_with($value, 'before_') => __(':year öncesi', ['year' => substr($value, 7)]),
            // "1900s" ON YILDIR (listede "1910s" de var); yalnız "1800s" ve
            // "1700s" yüzyıldır.
            preg_match('/^(\d{4})s$/', $value, $m) === 1 => $m[1].'–'.((int) $m[1] + (in_array($value, ['1800s', '1700s'], true) ? 99 : 9)),
            default => str_replace('_', '–', $value),
        };
    }

    /**
     * Daha önce GÖNDERİLMEMİŞ görselleri ilana yükler (A15 · Etsy adımı).
     *
     * ⚠️ ETSY ADRESTEN ALMAZ, DOSYA İSTER: görsel indirilir (iç ağ korumalı,
     * kimliksiz — `ChannelHttpClient::download`) ve multipart yüklenir.
     *
     * ⚠️ GÖRSEL İLANDADIR, VARYANTTA DEĞİL. Kardeş varyantların listing
     * satırları aynı Etsy ilanını gösterir; her biri kendi listesine bakarak
     * yükleseydi üç varyantlı ilan her görseli ÜÇ KEZ alırdı. Gönderilenler
     * aynı ilana bağlı BÜTÜN satırların `pushed_image_urls` birleşimidir.
     *
     * ⚠️ YALNIZCA EKLER — Shopify kuralı: satıcının Etsy'deki kendi
     * görselleri silinmez, bizde silinen görsel Etsy'den silinmez.
     *
     * ⚠️ 10 SINIRI İLANIN MEVCUT GÖRSELLERİYLE BİRLİKTE sayılır: satıcının
     * 8 görseli varsa en fazla 2 yüklenir; sınır aşılınca Etsy hata verir.
     *
     * GÖRSEL HATASI ÜRÜNÜ GERİ SAYMAZ: ilan yazıldı, kimliği saklanmalı.
     * Yüklenemeyen adres listeye EKLENMEZ, sonraki turda yeniden denenir.
     *
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    private function withNewImages(ListingPayload $payload, string $listingId, array $identity): array
    {
        $variant = $payload->listing->variant;

        if ($variant === null || $listingId === '') {
            return $identity;
        }

        $own = (array) ($payload->listing->channel_metadata['pushed_image_urls'] ?? []);
        $pushed = [...$own, ...$this->pushedForListing($listingId)];

        $new = array_values(array_diff(ChannelImages::urlsFor($variant, 'etsy', $this->connection->id), $pushed));

        if ($new === []) {
            return $identity;
        }

        $uploaded = [];

        try {
            $current = $this->client->get(
                EtsyEndpoints::url(EtsyEndpoints::LISTING_IMAGES, ['listing_id' => $listingId]),
                headers: $this->apiKeyHeader(),
            );

            $current->throw();

            $count = (int) ($current->json('count') ?? count((array) $current->json('results')));
            $room = max(0, $this->maxImages() - $count);

            foreach (array_slice($new, 0, $room) as $index => $url) {
                try {
                    $this->client->upload(
                        EtsyEndpoints::url(EtsyEndpoints::SHOP_LISTING_IMAGES, [
                            'shop_id' => $this->requireShopId(),
                            'listing_id' => $listingId,
                        ]),
                        ['rank' => $count + $index + 1],
                        'image',
                        $this->client->download($url),
                        self::imageFilename($url, $index),
                        headers: $this->apiKeyHeader(),
                    )->throw();

                    $uploaded[] = $url;
                } catch (Throwable $e) {
                    Log::warning('etsy.image_upload_failed', [
                        'connection' => $this->connection->id,
                        'listing' => $listingId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (count($new) > $room) {
                Log::info('etsy.image_limit_reached', [
                    'connection' => $this->connection->id,
                    'listing' => $listingId,
                    'skipped' => count($new) - $room,
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('etsy.image_listing_read_failed', [
                'connection' => $this->connection->id,
                'listing' => $listingId,
                'error' => $e->getMessage(),
            ]);
        }

        if ($uploaded === []) {
            return $identity;
        }

        return [
            ...$identity,
            'channel_metadata' => [
                ...($identity['channel_metadata'] ?? []),
                'pushed_image_urls' => array_values(array_unique([...$own, ...$uploaded])),
            ],
        ];
    }

    /**
     * Aynı Etsy ilanına bağlı bütün listing satırlarının gönderdiği görseller.
     *
     * @return list<string>
     */
    private function pushedForListing(string $listingId): array
    {
        $rows = TenantContext::runAsSystem(fn () => Listing::query()
            ->where('channel_connection_id', $this->connection->id)
            ->where('external_parent_id', $listingId)
            ->pluck('channel_metadata'));

        $urls = [];

        foreach ($rows as $metadata) {
            foreach ((array) ((is_array($metadata) ? $metadata : [])['pushed_image_urls'] ?? []) as $url) {
                $urls[] = (string) $url;
            }
        }

        return $urls;
    }

    /** Yüklenen dosyanın adı — adresin son parçası, yoksa sıra numarası. */
    private static function imageFilename(string $url, int $index): string
    {
        $name = basename((string) parse_url($url, PHP_URL_PATH));

        return preg_match('/^[\w.-]+\.(jpe?g|png|gif|webp)$/i', $name) === 1 ? $name : 'gorsel-'.($index + 1).'.jpg';
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
            EtsyEndpoints::url(EtsyEndpoints::SHOP_LISTING, ['shop_id' => $this->requireShopId(), 'listing_id' => $listingId]),
            body: ['state' => 'inactive'],
            headers: $this->apiKeyHeader(),
            asForm: true,
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

        // ⚠️ `includes=Inventory` BU UÇTA GEÇERSİZDİR. Mağaza listesi
        // (`/shops/{id}/listings`) kabul eder, tekil ilan kabul ETMEZ:
        // canlıda 400 "Invalid value (inventory) for enum(images, shop,
        // user, translations, videos, personalization, buyerprice)"
        // döndü (7 Eki) ve mutabakat her Etsy ilanında çöküyordu.
        // Envanter ayrı uçtan okunur.
        $response = $this->client->get(
            EtsyEndpoints::url(EtsyEndpoints::LISTING, ['listing_id' => $listingId]),
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

        $body['inventory'] = ['products' => $this->readInventoryProducts((string) $listingId)];

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
            // ⚠️ İLAN KİMLİĞİ BENİMSEME İÇİN döner: `updateListing` hedefi
            // `listing_id`'dir (`external_parent_id`). Alınmasaydı satıcının
            // Etsy'de zaten açtığı her ilan "ilan bilinmiyor" ile kalıcı
            // hataya düşerdi.
            parentExternalId: isset($listing['listing_id']) ? (string) $listing['listing_id'] : null,
        );
    }

    // ------------------------------------------------------------ içe aktarma

    /**
     * Mağazanın ilanları — `GET /shops/{id}/listings?includes=Images,Inventory`.
     *
     * Etsy'nin İLANI bizim ürünümüz, envanterdeki her PRODUCT bir varyant:
     * her varyant ayrı `RemoteProduct` olur, ilan kimliği
     * `external_parent_id`'ye yazılır (panel varyantları onunla gruplar;
     * stok/fiyat yazımı da onu hedefler — `toIdentityResult` ile aynı düzen).
     *
     * ⚠️ `state` TEK DEĞER ALIR ve varsayılanı yalnız `active`'tir. Yalnız
     * aktifler çekilseydi stoğu bitmiş (`sold_out`) ilanlar — satıcının
     * kataloğunun gerçek parçası — 34Pazar'a hiç gelmez, stok gelince de
     * kanala bağlı görünmezdi. Sıra: active → sold_out → inactive → expired.
     *
     * SÜRESİ DOLMUŞ (`expired`) DA ALINIR (7 Eki 2026, ilk gerçek mağaza:
     * 0 aktif, 14 süresi dolmuş ilan → içe aktarma "0 ürün" diyordu). Süresi
     * dolan ilan satıcının ürünüdür, yenilenince satışa döner; içe aktarma
     * onu YENİLEMEZ (Etsy'de ücretli). Taslak (`draft`) ALINMAZ: yarım
     * bırakılmış denemeler olabilir.
     *
     * Silinmiş product ve kapalı offering ALINMAZ: Etsy'de o seçenek
     * satılmıyor; alınsaydı panelde kanalda karşılığı olmayan varyant
     * görünürdü.
     *
     * İmleç `{durum sırası}:{offset}`.
     */
    public function fetchProductPage(?string $cursor = null): RemoteProductPage
    {
        [$stateIndex, $offset] = $cursor !== null && preg_match('/^(\d+):(\d+)$/', $cursor, $m) === 1
            ? [(int) $m[1], (int) $m[2]]
            : [0, 0];

        if (! isset(self::IMPORT_STATES[$stateIndex])) {
            return new RemoteProductPage(products: []);
        }

        $response = $this->client->get(
            EtsyEndpoints::url(EtsyEndpoints::SHOP_LISTINGS, ['shop_id' => $this->requireShopId()]),
            query: [
                'state' => self::IMPORT_STATES[$stateIndex],
                'limit' => self::IMPORT_PAGE_SIZE,
                'offset' => $offset,
                'includes' => 'Images,Inventory',
            ],
            headers: $this->apiKeyHeader(),
        );

        $response->throw();

        /** @var list<mixed> $results */
        $results = (array) ($response->json('results') ?? []);
        $products = [];

        foreach ($results as $listing) {
            if (is_array($listing) && isset($listing['listing_id'])) {
                array_push($products, ...EtsyProductMapper::toRemoteProducts($listing));
            }
        }

        $total = (int) ($response->json('count') ?? 0);
        $next = $offset + self::IMPORT_PAGE_SIZE;

        if ($results !== [] && $next < $total) {
            return new RemoteProductPage(products: $products, nextCursor: "{$stateIndex}:{$next}", hasMore: true);
        }

        $nextState = $stateIndex + 1;
        $hasMore = isset(self::IMPORT_STATES[$nextState]);

        return new RemoteProductPage(
            products: $products,
            nextCursor: $hasMore ? "{$nextState}:0" : null,
            hasMore: $hasMore,
        );
    }

    /**
     * Tur başına en fazla 100 sayfa — 100'lük sayfayla 10.000 ilan. Sınır
     * emniyettir (bozuk kanal turu sonsuza dek sürmesin); sınıra takılan tur
     * kullanıcıya söylenir, kalanlar sonraki turda gelir.
     */
    public function maxImportPages(): int
    {
        return 100;
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
            $quantityByProductId = [];
            $keys = [];

            foreach ($items as $item) {
                $sku = (string) $item['sku'];
                $productId = (string) ($item['external_id'] ?? '');

                if ($sku !== '') {
                    $quantityBySku[$sku] = (int) $item['quantity'];
                }

                if ($productId !== '') {
                    $quantityByProductId[$productId] = (int) $item['quantity'];
                }

                $keys[] = ['product_id' => $productId === '' ? null : $productId, 'sku' => $sku];
            }

            $unmatched = $this->writeInventory(
                (string) $listingId,
                quantityBySku: $quantityBySku,
                quantityByProductId: $quantityByProductId,
                items: $keys,
            );

            $missing = [...$missing, ...$unmatched];
            $pushed += count($items) - count($unmatched);
        }

        if ($pushed === 0) {
            // Hiçbir kalem kanaldaki bir varyantla eşleşmedi: istek
            // atılmadı ya da hiçbir şey değiştirmedi. Başarı dönülmez.
            return AdapterResult::failure(
                ErrorClass::VALIDATION,
                'Etsy stok yükündeki kalemler ilandaki hiçbir varyantla eşleşmedi: '
                .implode(', ', $missing),
            );
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
     * Stok turunda envanterde karşılığı olmayan kalemlerin SKU'larını döner;
     * ilanın HİÇBİR kalemi eşleşmediyse yazma yapılmaz (değişmeyecek gövdeyi
     * geri yazmak yalnızca kota harcar).
     *
     * @param  array<string, int>  $quantityBySku  Stok turunda dolu
     * @param  array<string, string>  $priceByProductId  Fiyat turunda dolu
     * @param  array<string, int>  $quantityByProductId  Stok turunda dolu (öncelikli)
     * @param  list<array{product_id: string|null, sku: string}>  $items  Stok kalemlerinin kimlikleri
     * @return list<string>
     */
    private function writeInventory(
        string $listingId,
        array $quantityBySku = [],
        array $priceByProductId = [],
        array $quantityByProductId = [],
        array $items = [],
    ): array {
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
        $unmatched = EtsyInventoryMerger::unmatchedItems($products, $items);

        if ($items !== [] && count($unmatched) === count($items)) {
            return $unmatched;
        }

        $merged = EtsyInventoryMerger::merge($products, $quantityBySku, $priceByProductId, $quantityByProductId);

        // ③ YAZ — TAM gövde.
        $write = $this->client->request(
            'PUT',
            EtsyEndpoints::url(EtsyEndpoints::LISTING_INVENTORY, ['listing_id' => $listingId]),
            body: ['products' => $merged],
            headers: $this->apiKeyHeader(),
        );

        $write->throw();

        return $unmatched;
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

    // ------------------------------------------------------------ sipariş

    /**
     * Siparişleri YOKLAR — Etsy webhook SUNMAZ (§11.4).
     *
     * ⚠️ HAM GÖVDE DÖNER: ayrıştırma `parseOrderEvent()` ile SONRA
     * yapılır. Sıra bilinçlidir — ayrıştırma hatası siparişin
     * kaybolmasına değil, inbox satırının hata durumuna düşmesine yol
     * açar ve satır yeniden işlenebilir.
     *
     * ⚠️ BAŞARISIZ YANIT YÜKSELTİLİR. `json()` bir 500 gövdesinde de dizi
     * döndürür ve boş sayfa "yeni sipariş yok" diye okunurdu; imleç
     * ilerler ve o penceredeki siparişler bir daha HİÇ sorulmazdı
     * (Trendyol'daki kuralın aynısı).
     *
     * ⚠️ `min_created` GÖNDERİLMEZSE TÜM GEÇMİŞ ÇEKİLİR ve Etsy'nin
     * GÜNLÜK kotası (§21: 10.000 istek/gün) tek turda yanardı.
     *
     * ⚠️ İMLEÇ `offset`'TİR ve OPAKTIR. `hasMore`, `nextCursor !== null`
     * ile AYNI ŞEY DEĞİLDİR: turu durduran `hasMore`'dur ve o toplam
     * sayıdan hesaplanır (`OrderPage` sözleşmesi).
     */
    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage
    {
        $offset = $cursor === null ? 0 : max(0, (int) $cursor);

        $response = $this->client->get(
            EtsyEndpoints::url(EtsyEndpoints::SHOP_RECEIPTS, ['shop_id' => $this->requireShopId()]),
            query: [
                // SANİYE epoch — Trendyol milisaniye ister, Etsy saniye.
                // Karıştırılsaydı pencere 1970'e düşer ve her tur TÜM
                // geçmişi çekerdi.
                //
                // ⚠️ PENCERE SON DEĞİŞİKLİĞE GÖREDİR, OLUŞTURMAYA GÖRE DEĞİL.
                // `min_created` ile sipariş yalnız oluştuğu turda görülür;
                // sonradan gelen "Paid", "Completed" ve özellikle
                // "Canceled" pencere dışında kalır — iptal edilen
                // siparişin stoğu HİÇ geri eklenmezdi. Aynı durumun
                // tekrar okunması zararsızdır: olay kimliği
                // `{receipt_id}:{status}` ve inbox tekilleştirir.
                'min_last_modified' => $since->getTimestamp(),
                'limit' => self::ORDER_PAGE_SIZE,
                'offset' => $offset,
                // Eskiden yeniye: tur yarıda kalırsa imleç en eski
                // işlenmemiş siparişin gerisinde kalır ve hiçbir şey
                // atlanmaz.
                'sort_on' => 'updated',
                'sort_order' => 'asc',
            ],
            headers: $this->apiKeyHeader(),
        );

        // Sessizce boş sayfaya düşme — yükselt.
        $response->throw();

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        $receipts = array_values(array_filter(
            (array) ($body['results'] ?? []),
            'is_array',
        ));

        $total = (int) ($body['count'] ?? count($receipts));
        $hasMore = $offset + count($receipts) < $total && $receipts !== [];

        return new OrderPage(
            orders: $receipts,
            nextCursor: $hasMore ? (string) ($offset + self::ORDER_PAGE_SIZE) : null,
            hasMore: $hasMore,
        );
    }

    /**
     * Yoklanan siparişin olay kimliği — `{receipt_id}:{status}` (§11.4 · P0).
     *
     * ═════════════════════════════════════════════════════════════════
     * ⚠️ KİMLİK DURUMU TAŞIMAK ZORUNDADIR
     * ═════════════════════════════════════════════════════════════════
     * Yalnızca `receipt_id`'ye bağlansaydı aynı siparişin sonraki İPTALİ
     * birincil tekillik indeksine (`channel_connection_id`,
     * `external_event_id`) takılır ve `insertOrIgnore` tarafından
     * SESSİZCE YUTULURDU — iptal hiç işlenmez, satılmış stok geri
     * EKLENMEZ ve bakiye kalıcı olarak eksik kalırdı. §1 · Karar 24'ün
     * açıkça uyardığı hata biçimi budur.
     *
     * ⚠️ ALAN ADI `receipt_id`'DİR — `orderNumber` ya da `id` DEĞİL.
     * Kimlik üretimi ÇEKİRDEKTE tutulsaydı Trendyol'un alan adını okur,
     * Etsy'de `null` dönerdi; tekilleştirme saatlik hash yoluna düşer ve
     * korumanın kendisi sessizce zayıflardı.
     *
     * @param  array<string, mixed>  $order
     */
    public function pollingEventIdFor(array $order): ?string
    {
        $receiptId = $order['receipt_id'] ?? null;

        if ($receiptId === null || (string) $receiptId === '') {
            return null;
        }

        $status = (string) ($order['status'] ?? '');

        return $status === '' ? (string) $receiptId : "{$receiptId}:{$status}";
    }

    /**
     * Ham Etsy receipt'ini kanonik olaya çevirir — TİP dahil.
     *
     * ⚠️ TİP AYRIMI (§1 · Karar 24): created / updated / cancelled /
     * returned AYRI yollara gider. Tek yola sokulsaydı iptal siparişin
     * yeniden yaratılması gibi işlenir ve stok İKİ KEZ düşerdi.
     *
     * ⚠️ İADE İÇİN AYRI UÇ NOKTA YOKTUR ve `returned` HİÇ ÜRETİLMEZ
     * (§11.4 · dürüst sınır). Satıcı iadeyi Etsy panelinden işler ve
     * `receipt` durumu değişir; yoklama bunu `updated` görür ve stok
     * hareketi ÜRETMEZ. `returned` sayılsaydı SATILMIŞ stok geri eklenir
     * ve bakiye bozulurdu. Gerçek iade panelden elle girilir.
     *
     * ⚠️ BİLİNMEYEN DURUM `updated` SAYILIR. Etsy durum listesini
     * genişletebilir; `created` saymak var olan siparişi yeniden
     * yaratmayı denerdi, `cancelled` saymak satılmış stoğu geri eklerdi.
     * İkisi de bakiyeyi bozar.
     */
    public function parseOrderEvent(InboxMessage $message): ?NormalizedOrderEvent
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($message->payload) ? $message->payload : [];

        $receiptId = $payload['receipt_id'] ?? null;

        if ($receiptId === null || (string) $receiptId === '') {
            // Kimliksiz gövdeden sipariş yaratılamaz; satır hata durumuna
            // düşer ve elle incelenir — sessizce yutulmaz.
            return null;
        }

        $receiptId = (string) $receiptId;
        $status = (string) ($payload['status'] ?? '');
        $type = self::STATUS_TO_TYPE[self::statusKey($status)] ?? 'updated';

        return new NormalizedOrderEvent(
            type: $type,
            externalOrderId: $receiptId,
            // Çıpa DURUMU taşır — `pollingEventIdFor()` ile AYNI biçim.
            // Ayrışsalardı inbox satırı ile `order_events` satırı farklı
            // kimliklere bağlanırdı.
            externalRef: $message->external_event_id ?? "{$receiptId}:{$status}",
            payload: $this->toCanonicalOrderPayload($payload, $type, $receiptId),
            occurredAt: $this->receiptDate($payload),
        );
    }

    /** "Payment Processing" → "payment_processing" (`STATUS_TO_TYPE` anahtarı). */
    private static function statusKey(string $status): string
    {
        return str_replace([' ', '-'], '_', strtolower(trim($status)));
    }

    /**
     * Etsy gövdesini `OrderPayloadMapper`'ın beklediği biçime çevirir.
     *
     * ⚠️ PARA OKUMADA NESNEDİR — burada da (§11.3'ün fiyat kuralı).
     * Ham `amount` okunsaydı 19.90 TL kanonik siparişte **1990 TL**
     * görünür ve sipariş toplamları tamamen yanlış olurdu.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function toCanonicalOrderPayload(array $payload, string $type, string $receiptId): array
    {
        $grandTotal = $this->money($payload['grandtotal'] ?? null)
            ?? $this->money($payload['total_price'] ?? null)
            ?? '0';

        return [
            'type' => $type,
            'external_number' => $receiptId,
            'status' => (string) ($payload['status'] ?? 'pending'),
            // İade AYRI bir tip üretmez ama finansal durum GÖRÜNÜR kalır:
            // satıcı panelde neyin iade edildiğini görebilmelidir.
            'financial_status' => in_array(self::statusKey((string) ($payload['status'] ?? '')), ['refunded', 'fully_refunded'], true)
                ? 'refunded'
                : null,
            // ⚠️ RECEIPT'İN ÜST DÜZEYİNDE `currency_code` YOKTUR; para
            // birimi para nesnelerinin içindedir. Yalnız üst düzeye
            // bakılsaydı USD mağazanın $5.95'lik siparişi panelde 5,95 TL
            // görünürdü (canlıda 7 Eki oldu).
            'currency' => (string) ($payload['grandtotal']['currency_code']
                ?? $payload['total_price']['currency_code']
                ?? $payload['currency_code']
                ?? 'TRY'),
            'subtotal' => $this->money($payload['total_price'] ?? null) ?? '0',
            'shipping_total' => $this->money($payload['total_shipping_cost'] ?? null) ?? '0',
            'tax_total' => $this->money($payload['total_tax_cost'] ?? null) ?? '0',
            'grand_total' => $grandTotal,
            'lines' => $this->orderLines($payload),
            // Kişisel veri taşınmaz; yalnızca referans.
            'customer_ref' => array_filter([
                'external_customer_id' => isset($payload['buyer_user_id'])
                    ? (string) $payload['buyer_user_id']
                    : null,
            ]),
        ];
    }

    /**
     * Sipariş kalemleri — `transactions` dizisinden (§11.4).
     *
     * ⚠️ KALEM KİMLİĞİ `transaction_id`, SKU `transactions[].sku`.
     * SKU eşleşmezse `order_lines.variant_id` NULL kalır, satır PENDING
     * olur ve SİPARİŞ KAYBEDİLMEZ (Karar 24) — sipariş kaybetmek stok
     * tutarsızlığından kötüdür.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function orderLines(array $payload): array
    {
        $lines = [];

        foreach ((array) ($payload['transactions'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $quantity = (int) ($item['quantity'] ?? 0);
            $unitPrice = $this->money($item['price'] ?? null) ?? '0';

            $lines[] = [
                'external_line_id' => (string) ($item['transaction_id'] ?? ''),
                'sku' => (string) ($item['sku'] ?? ''),
                // ⚠️ SKU YEDEĞİ: Etsy kalemi SKU'yu çoğu zaman BOŞ getirir
                // (canlıda 7 Eki: ilanda SKU varken kalemde ""). Kalem
                // `product_id` taşır ve o bizim `listings.external_id`'mizdir;
                // `OrderPayloadMapper` SKU tutmazsa onu bu bağlantının
                // listing'lerinde arar. Gönderilmeseydi satır eşleşmez, stok
                // DÜŞMEZ ve sonraki mutabakat Etsy'nin düştüğü stoğu eski
                // değerle EZERDİ — fazla satış.
                'external_variant_id' => isset($item['product_id']) ? (string) $item['product_id'] : null,
                'title' => (string) ($item['title'] ?? $item['sku'] ?? ''),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                // Etsy kalem TOPLAMI vermez; birim fiyat × adet.
                // Para KURUŞ ölçeğinde tam sayıyla çarpılır — float
                // çarpımı kuruş kayması üretirdi (§7).
                'line_total' => number_format(
                    ((int) round(((float) $unitPrice) * 100) * $quantity) / 100,
                    2,
                    '.',
                    '',
                ),
            ];
        }

        return $lines;
    }

    /**
     * Etsy para nesnesi → string.
     *
     * Dönüşüm `EtsyProductMapper::money()`'dedir ve YENİDEN YAZILMAZ;
     * burada yalnızca "nesne değilse dokunma" kapısı vardır.
     */
    private function money(mixed $money): ?string
    {
        return is_array($money) ? EtsyProductMapper::money($money) : null;
    }

    /**
     * ⚠️ SANİYE EPOCH — Trendyol MİLİSANİYE gönderir.
     *
     * Karıştırılsaydı sipariş tarihi 1970'e ya da 55.000 yılına düşer ve
     * panelde hiçbir sipariş doğru sıralanmazdı.
     *
     * @param  array<string, mixed>  $payload
     */
    private function receiptDate(array $payload): ?DateTimeImmutable
    {
        $raw = $payload['created_timestamp'] ?? $payload['create_timestamp'] ?? null;

        if (! is_numeric($raw)) {
            return null;
        }

        return (new DateTimeImmutable)->setTimestamp((int) $raw);
    }

    /**
     * ⚠️ ONAY ADIMI YOKTUR ama İSTİSNA DA FIRLATILMAZ.
     *
     * Etsy'de satıcının siparişi "üstlenmesi" diye bir kavram yoktur
     * (Woo ve Shopify ile aynı); pazaryerlerinde (Trendyol,
     * Hepsiburada) bu adım gerçektir. İstisna fırlatılsaydı çağıran
     * sonsuza kadar hata alırdı — oysa yapacak bir şey YOKTUR ve bu
     * eksiklik değil kanalın şeklidir. Sonuç verisi NO-OP olduğunu
     * GÖRÜNÜR kılar.
     */
    public function acknowledgeOrder(Order $order): AdapterResult
    {
        return AdapterResult::success(['acknowledged' => true]);
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
            EtsyAuth::refreshRequest(EtsyApp::keystring(), $refreshToken),
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
            // Yalnız YENİ refresh token geldiyse ömür baştan başlar; eskisi
            // korunduysa kayıttaki bitiş tarihi de korunur (null ezmez).
            refreshExpiresAt: is_string($newRefresh) && $newRefresh !== ''
                ? now()->addDays(EtsyAuth::REFRESH_TOKEN_LIFETIME_DAYS)->toDateTimeImmutable()
                : null,
        );
    }

    /**
     * Süre dolmadan 40 dakika önce yenile.
     *
     * ⚠️ PAY TARAMA SIKLIĞININ (15 dk, §20) KATI OLMALIDIR, EŞİTİ DEĞİL.
     * Eski pay 900 sn'ydi ve "üç deneme hakkı" sanılıyordu; gerçekte TEK
     * deneme veriyordu: yenileme turun birkaç saniye geçesinde olur, yeni
     * token bir sonraki saatin aynı saniyesinde dolar ve önceki tur onu
     * "15 dk 4 sn kaldı" diye ATLAR. Geriye dolmasına 4 saniye kala koşan
     * tek tur kalır. 7 Eki 09:00'da o tur deploy yüzünden kaçtı, token
     * öldü ve sipariş/stok çağrıları 401 aldı.
     *
     * 40 dakika :30, :45 ve :00 turlarına ÜÇ gerçek deneme verir; bedeli
     * saatte iki yenilemedir (günde ~48 çağrı, 10.000'lik kotada önemsiz).
     */
    public function refreshLeadSeconds(): int
    {
        return 2400;
    }

    // ──────────────────────────────────────────────── kota ve ölçüm (§25)

    /**
     * Günlük istek tavanı — 10.000, HESAP başına (§21).
     *
     * ⚠️ BU GERÇEK BİR TAVANDIR, teorik bir sayı değil. Envanter yazma
     * ilan başına ayrı çağrı gerektirdiği için (§11.3) 5.000+ ürünlü
     * mağazalarda AŞILIR ve §21 bunu açıkça hesaplayarak kaydeder:
     * 1.000 ürün · günde 3 değişim ≈ 3.900 istek (sığar); 5.000 ürün
     * ≈ 15.000 istek (AŞAR).
     *
     * `ChannelRateLimiter` bu sayıyı GÖRMEZ ve görmemelidir: kova
     * SANİYELİKTİR ve günlük kotayı temsil edemez. Burada yalnızca
     * ÖLÇÜLÜR (§25) — tavana dayanıldığında yapılacak iş stok itme
     * sıklığını düşürmektir (§21 · P2) ve o bir insan kararıdır.
     */
    public function dailyRequestQuota(): ?int
    {
        return 10_000;
    }

    /**
     * Token yenileme uç noktası — `token_refresh_failures` bunu süzer.
     *
     * Etsy'de token alma ve YENİLEME aynı uç noktadır (§11.2), bu yüzden
     * tek bir parça ikisini de tanır. Yenileme hatası ile ilk
     * yetkilendirme hatası ayırt edilmez ve bu DOĞRUDUR: ikisi de
     * "satıcı yeniden yetkilendirmeli" demektir.
     */
    public function tokenEndpointFragment(): ?string
    {
        return EtsyEndpoints::TOKEN;
    }

    // ────────────────────────────────────────────────────────── yardımcılar

    /**
     * `x-api-key` başlığı — UYGULAMANIN kimliği: `keystring:shared_secret`
     * (`EtsyApp`). Bağlantı başına değil, 34Pazar'ın tek Etsy uygulaması.
     *
     * ⚠️ ANAHTAR YOKSA İSTEK HİÇ ATILMAZ (`EtsyApp` fırlatır). Boş ya da
     * eksik başlıkla giden istek 401 alır, `AUTHENTICATION` KALICI sayılır
     * ve listing "anahtarın yanlış" diyerek ölür — oysa sorun sunucu
     * ayarıdır. Hepsiburada'nın "satıcı kimliği yoksa istek atılmaz"
     * kuralının aynısı.
     *
     * @return array<string, string>
     */
    private function apiKeyHeader(): array
    {
        return ['x-api-key' => EtsyApp::apiKey()];
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
