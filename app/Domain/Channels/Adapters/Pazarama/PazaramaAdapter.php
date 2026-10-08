<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Pazarama;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresChannelCurrency;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\RefreshedCredentials;
use App\Domain\Channels\Contracts\SupportsBatchStatus;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Contracts\SupportsTokenRefresh;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\BatchItemFailure;
use App\Domain\Sync\Support\BatchStatus;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use App\Domain\Sync\Support\OrderPage;
use App\Domain\Sync\Support\PricePushBatch;
use App\Domain\Sync\Support\RemoteInventorySnapshot;
use App\Domain\Sync\Support\RemotePriceSnapshot;
use App\Domain\Sync\Support\RemoteProduct;
use App\Domain\Sync\Support\RemoteProductPage;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Pazarama kanal adapter'ı — REST/JSON (`isortagimapi.pazarama.com`),
 * kimlik OAuth2 `client_credentials`.
 *
 * Araştırma: `docs/YENI-KANALLAR-API-NOTLARI.md` §6 (53 resmi sayfanın
 * CMS kopyası okundu). Gerçek mağazayla HENÜZ SINANMADI → kanal
 * `is_active = false`. Ürün açma (`product/create`) bu sürümde YOK.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KİMLİK: clientId + clientSecret → 1 SAATLİK ERİŞİM ANAHTARI
 * ─────────────────────────────────────────────────────────────────────
 * Satıcı anahtarı isortagim.pazarama.com → Hesabım → Hesap Bilgileri →
 * Entegrasyon Bilgileri → "Yeni API Key Üret" ile kendisi üretir; partner
 * onayı yok. Çift kasada `client_id` / `client_secret` adıyla durur
 * (ikas'la aynı ad). Bu adlar `ChannelHttpClient::BASIC_AUTH_KEY_PAIRS`
 * içinde YOK — `api_key`/`api_secret` seçilseydi her API isteği kasadan
 * Basic auth alır, Bearer hiç gitmezdi. eBay aynı adları kullanır ama Basic
 * başlığını kendisi verir; istemci listesine bakmaz.
 *
 * Token `POST isortagimgiris.pazarama.com/connect/token`, form gövdesi
 * `grant_type=client_credentials&scope=merchantgatewayapi.fullaccess`,
 * HTTP Basic (clientId:clientSecret). Başlığı ADAPTER verir: kasada
 * `access_token` varken istemci ölü token'ı Bearer olarak eklerdi.
 * İlk token bağlanırken (`ConnectChannel` → `TokenRefresher`), sonrakiler
 * `credentials:refresh` taramasıyla gelir — adapter kasaya yazmaz.
 *
 * DOĞRULANMADI: başarılı token cevabının biçimi (dokümanda yalnız ekran
 * görüntüsü). Hata cevabı Pazarama zarfında geldiği için hem standart
 * `access_token` hem zarflı `data.accessToken` okunur. Süre gelmezse
 * dokümandaki 1 saat kullanılır (UYDURMA değil, resmi değer); yazılmasaydı
 * `expires_at` NULL kalır, tarama token'ı hiç yenilemez ve bağlantı bir
 * saat sonra ölürdü.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ SECRET 365 GÜNDE ÖLÜR — ROZETE BAĞLANDI
 * ─────────────────────────────────────────────────────────────────────
 * Secret üretildiği günden en çok 365 gün yaşar, süresi dolan otomatik
 * sıfırlanır; yeni anahtar üretilince eskisi ANINDA iptal olur. Satıcı
 * bağlanırken üretim tarihini (isteğe bağlı) girerse
 * {@see SECRET_CREATED_AT_KEY} + 365 gün `refresh_expires_at` olarak
 * döner; `/channels` rozeti (`TokenStatus`) ve `token_expiring_soon`
 * metriği 14 gün kala sarıya döner — Pazarama'nın kendi e-postasıyla aynı
 * pencere. Tarih girilmezse rozet erişim anahtarının 1 saatlik ömrünü
 * gösterir (ikas'taki gibi); bu bilinen bir sınırdır.
 *
 * ─────────────────────────────────────────────────────────────────────
 * STOK / FİYAT: `updateStock-v2` / `updatePrice-v2` — TOPLU, ASENKRON
 * ─────────────────────────────────────────────────────────────────────
 * Anahtar BARKOD (`code`); `stockCode` yazmalarda anahtar DEĞİL (tuzak 3).
 * Listing kimliği bu yüzden `code`'dur. Yanıt bir `dataId` (GUID) döner,
 * sonuçta `batch_id` olarak taşınır; "sıraya alındı" uygulandı DEMEK
 * DEĞİL (sonuç `lake-projections`'ta, 4 saate kadar "İşleniyor").
 *   - Stok yükü YALNIZ `code` + `stockCount` (fiyat yazılsaydı panelde
 *     değişmiş fiyat eski değerle ezilirdi).
 *   - Fiyat yükü YALNIZ `code` + `listPrice` + `salePrice`; ikisi de
 *     zorunlu (`decimal(18,2)`). Üstü çizili fiyat satıştan büyükse
 *     `listPrice`, değilse iki alan eşit (resmi örnekte eşit).
 *   - İstek başına en çok 3000 ürün; satıcı başına İKİ STOK-FİYAT İSTEĞİ
 *     ARASINDA 10 SN. Stok ve fiyat ortak sayaç sayıldı (DOĞRULANMADI,
 *     doküman "stok-fiyat isteği" diyor). Çekirdeğin jeton kovası saniyede
 *     1'in altına inemediği için ({@see RateLimitProfile}) kural burada,
 *     bağlantı başına önbellek kilidiyle uygulanır ({@see claimStockPriceSlot}):
 *     10 sn dolmadan gelen yazım İSTEK ATMADAN `RATE_LIMITED` + kalan süre
 *     döner, iş o kadar sonra yeniden kuyruğa girer. Bilinçli istisna:
 *     adapter veritabanına yazmaz ama bu kilidi önbelleğe yazar (Trendyol
 *     marka önbelleği emsali).
 *   - KDV: fiyatın KDV dahil olduğu ürün dokümanında yazmıyor; sipariş
 *     kaleminde `taxIncluded: true` → dahil sayıldı (DOĞRULANMADI).
 *   - %70 indirimli fiyat onaya düşer ("Onaya gönderildi"); bu sonuç
 *     yalnız `lake-projections`'ta görünür, burada okunmaz (DOĞRULANMADI:
 *     eşik).
 *
 * ─────────────────────────────────────────────────────────────────────
 * SİPARİŞ: `getOrdersForApiV2` — ANAHTAR `orderNumber` / `orderItemId`
 * ─────────────────────────────────────────────────────────────────────
 * - V2 aynı üründen her adedi ayrı `orderItemId` + `quantity: 1` ile verir;
 *   kısmi iptal/iade kalem bazında izlenebilir. Sipariş satırında aynı
 *   barkodun kalemleri TOPLANIR (satır kimliği barkod): üç ayrı "1 adet"
 *   satırı yerine tek "3 adet" satırı. İptal/iade kaydı da aynı satır
 *   kimliğini taşır, olay kimliği ise kaleme bağlıdır. DOĞRULANMADI: V2'nin
 *   bölme kullanmayan satıcıda da açık olduğu ve yanıtın V1 ile aynı
 *   biçimde geldiği (dokümanda V2 yanıt örneği yok).
 * - TARİH ARALIĞI EN ÇOK 1 AY ve `endDate` O GÜNÜ KAPSAMAZ (tuzak 7):
 *   istek gün biçiminde (`YYYY-AA-GG`, Türkiye günü), son gün yarındır.
 *   Pencere {@see ORDER_WINDOW_DAYS} günlük dilimlere bölünür — en kısa ay
 *   bile aşılmaz. Dilimler YENİDEN ESKİYE okunur: sayfa tavanına (çekirdek,
 *   50 sayfa) takılan dev bir birikimde önce yeni siparişlerin stoğu düşer.
 * - GÜNCELLENME TARİHİ FİLTRESİ YOK (tuzak 8): iptal ve iade, sipariş
 *   tarihine göre yeniden taranarak görülür. Her turda en az
 *   {@see LOOKBACK_DAYS} gün geriye bakılır; tekrar gelen kayıt olay
 *   kimliğiyle elenir.
 * - İPTAL/İADE KURALI (N11/Ticimax'la aynı): sipariş daha önce "created"
 *   olarak ALINMADIYSA iptali de iadesi de hiçbir şey üretmez. İlk kez
 *   görülen siparişin iptal/iade edilmiş kalemleri "created"e girer ve
 *   hemen ardından iptal/iade kaydıyla geri eklenir (net sıfır); kalemlerin
 *   hepsi iptalse sipariş hiç alınmaz.
 * - İptal: kalem `6` (İptal Edildi) ve `13` (Tedarik Edilemedi). `18`
 *   (İptal Süreci Başlatıldı) yalnız taleptir, stoğa dokunmaz.
 * - İade: kalem `8` (İade Onaylandı = satıcı/backoffice onayı, ürün döndü).
 *   `10` (İade Edildi) YALNIZ geçmişinde `8` varsa sayılır: `10` para
 *   iadesi olabilir ve ürünsüz iadede stoğa eklenseydi gelmeyen ürün
 *   satılırdı (ikas `REFUNDED` kuralı). `7`/`9` stoğa dokunmaz.
 *   DOĞRULANMADI: otomatik onaylanan iadenin (`getRefund` 6) sipariş
 *   tarafında hangi statüyle göründüğü.
 * - Kişisel veri inbox'a girmez: müşteri adı, e-postası, kimliği, iki
 *   adres (telefon, TCKN, vergi bilgisi dahil) alınmaz; kalemden yalnız
 *   bilinen alanlar beyaz listeyle seçilir.
 * - Sayfa sonu: yanıtta toplam/sayfa bilgisi YOK (resmi örnek) → sayfa
 *   {@see ORDER_PAGE_SIZE}'dan az satır dönünce dilim biter. DOĞRULANMADI:
 *   `pageSize` üst sınırı (resmi örnek 500).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ SİPARİŞ ONAYI (3 → 12) — YAZILDI AMA HİÇBİR AKIŞA BAĞLI DEĞİL
 * ─────────────────────────────────────────────────────────────────────
 * Pazarama'da onay zorunludur: yeni kalem `3` gelir ve kargo dahil her
 * adımdan önce `12`'ye (Hazırlanıyor) çekilmelidir. `acknowledgeOrder`
 * bunu yapar (`PUT /order/updateOrderStatusList`) ama çekirdek bugün onu
 * ÇAĞIRMIYOR ve otomatiğe bağlanmadan önce düşünülmeli (N11 gerekçesi):
 *   - `12`'ye alınıp kargoya verilmeyen sipariş için OTOMATİK İADE süreci
 *     başlar ve SATICI PUANI DÜŞER — stoğu olmayan siparişi otomatik onay
 *     cezaya çevirir;
 *   - `3`'te müşteri doğrudan iptal edebilir, `12`'de yalnız talep açar;
 *     erken onay müşterinin iptal yolunu daraltır;
 *   - satıcı siparişi panelden ya da başka araçtan yönetiyorsa otomatik
 *     onay onun akışını BOZAR.
 */
final class PazaramaAdapter implements ChannelAdapter, DeclaresChannelCurrency, SupportsBatchStatus, SupportsCatalogImport, SupportsInventory, SupportsOrders, SupportsPricing, SupportsTokenRefresh
{
    use DeclaresRequestQuota;

    /** API — bütün satıcılar paylaşır. */
    public const BASE_URL = 'https://isortagimapi.pazarama.com';

    /** Token ayrı hostta (IdentityServer). */
    public const TOKEN_URL = 'https://isortagimgiris.pazarama.com/connect/token';

    public const TOKEN_PATH = '/connect/token';

    /** Dokümandaki scope; `.read`/`.write` ayrımı DOĞRULANMADI. */
    public const SCOPE = 'merchantgatewayapi.fullaccess';

    /** Satıcının Pazarama mağaza adı — hesap kimliği. SIR DEĞİL. */
    public const SELLER_NAME_KEY = 'pazarama_seller_name';

    /** API secret'ın üretildiği gün (`YYYY-AA-GG`, isteğe bağlı). SIR DEĞİL. */
    public const SECRET_CREATED_AT_KEY = 'pazarama_secret_created_at';

    /** Kasadaki çift — ikas'la aynı adlar (sınıf notu). */
    public const CLIENT_ID_SECRET = 'client_id';

    public const CLIENT_SECRET_SECRET = 'client_secret';

    /** Secret ömrü (resmi: "en fazla 1 yıl (365 gün)"). */
    public const SECRET_LIFETIME_DAYS = 365;

    /** Erişim anahtarı ömrü (resmi: 1 saat) — yanıtta süre yoksa. */
    private const DEFAULT_TOKEN_SECONDS = 3600;

    /** Onaylı ürün listesi `Size` üst sınırı 100. */
    private const PRODUCT_PAGE_SIZE = 100;

    /** Stok-fiyat isteği başına en çok 3000 ürün. */
    private const MAX_ITEMS_PER_REQUEST = 3000;

    /** Satıcı başına iki stok-fiyat isteği arası (resmi). */
    public const STOCK_PRICE_INTERVAL_SECONDS = 10;

    /**
     * "İşleniyor" dönen istek en geç 4 saatte sonuçlanır ve batch kimliği 4
     * saat sonra sorgulanamaz (API notları §6, tuzak 5).
     */
    private const BATCH_RETENTION_SECONDS = 4 * 3600;

    /** `lake-projections` satır durumları (API notları §6). */
    private const LAKE_SUCCESS = 0;

    private const LAKE_PROCESSING = 3;

    private const LAKE_SENT_TO_APPROVAL = 5;

    /** Mutabakatta tek tek `Code` sorgusunun üst sınırı; fazlası tam tarama. */
    private const PER_CODE_LOOKUP_LIMIT = 20;

    /** Sipariş sayfası — resmi örnekteki değer (üst sınır DOĞRULANMADI). */
    private const ORDER_PAGE_SIZE = 500;

    /** Dilim genişliği (gün, `endDate` hariç) — "1 ayı geçemez", Şubat dahil. */
    private const ORDER_WINDOW_DAYS = 27;

    /** İptal/iade için her turda geriye bakılan gün (sınıf notu). */
    private const LOOKBACK_DAYS = 30;

    private const CHANNEL_TIMEZONE = 'Europe/Istanbul';

    /**
     * Kalem statüleri (resmi tam liste) → panelin tanıdığı durum sözcüğü.
     *
     * Sayılar Türkçe adlarıyla değil bu sözcüklerle yazılır: panel rozeti ve
     * "kargo bekliyor" sayacı (`Order` izin listesi, `format.js`) `created`,
     * `picking`, `shipped`, `delivered`, `cancelled` sözcüklerini tanır.
     */
    private const STATUS_WORDS = [
        3 => 'Created',            // Siparişiniz Alındı
        12 => 'Picking',           // Siparişiniz Hazırlanıyor
        5 => 'Shipped',            // Siparişiniz Kargoya Verildi
        16 => 'AtCollectionPoint', // Siparişiniz Mağazada
        19 => 'AtCollectionPoint', // Siparişiniz Teslimat Noktasında
        11 => 'Delivered',         // Teslim Edildi
        14 => 'UnDelivered',       // Teslim Edilemedi
        6 => 'Cancelled',          // Siparişiniz İptal Edildi
        18 => 'CancelRequested',   // İptal Süreci Başlatıldı
        13 => 'UnSupplied',        // Tedarik Edilemedi
        7 => 'ReturnRequested',    // İade Süreci Başlatıldı
        8 => 'Returned',           // İade Onaylandı
        9 => 'ReturnRejected',     // İade Reddedildi
        10 => 'Returned',          // İade Edildi
    ];

    /** Stoğu geri getiren iptal: müşteri iptali + satıcının tedarik edemediği. */
    private const CANCELLED_STATUSES = [6, 13];

    /** İade onaylandı — ürün döndü. */
    private const RETURN_APPROVED_STATUS = 8;

    /** İade edildi — yalnız geçmişte `8` varsa (sınıf notu). */
    private const RETURN_REFUNDED_STATUS = 10;

    /** Onay: Hazırlanıyor. */
    private const ACK_STATUS = 12;

    /**
     * Bu yoklama turunda "created" kaydı ÜRETİLEN siparişler.
     *
     * @var array<string, true>
     */
    private array $createdThisRun = [];

    public function __construct(
        private readonly ChannelConnection $connection,
        private readonly ChannelHttpClient $client,
    ) {}

    public function connection(): ChannelConnection
    {
        return $this->connection;
    }

    /** Türk pazaryeri; fiyatlar TL (sipariş `currency: "TL"`). */
    public function channelCurrency(): ?string
    {
        return 'TRY';
    }

    // ---------------------------------------------------------------- sağlık

    /**
     * `product/products/approved?Size=1` — token'ın API'de geçtiğini
     * doğrular, yanıt küçük (§6 · Sağlık kontrolü). Zarfında `success`
     * true olmayan 200 sağlıklı SAYILMAZ.
     */
    public function healthCheck(): HealthResult
    {
        $startedAt = hrtime(true);

        try {
            $this->api('GET', '/product/products/approved', query: ['Size' => 1]);
        } catch (Throwable $e) {
            return HealthResult::unhealthy($e->getMessage());
        }

        return HealthResult::healthy((int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    /**
     * Çekirdeğin kovası saniyede 1'in altını ifade edemez (`max(rps, 1)`);
     * en sıkı hâli (1/sn, patlama 1, tek eşzamanlı) bildirilir. Asıl 10 sn
     * kuralı stok-fiyat yazımında adapter içinde (sınıf notu). Sipariş ve
     * ürün listeleme için sınır yazmıyor (DOĞRULANMADI).
     */
    public function rateLimitProfile(): RateLimitProfile
    {
        $profile = $this->connection->channelType?->rate_limit_profile;

        return is_array($profile) && $profile !== []
            ? RateLimitProfile::fromArray($profile)
            : new RateLimitProfile(requestsPerSecond: 1, burstCapacity: 1, maxConcurrent: 1);
    }

    /**
     * Yanlış kimlik iki biçimde döner (8 Eki 2026 ölçümü):
     *   - token: HTTP 400, JSON zarf `messageCode: "invalid_token"` —
     *     standart OAuth `invalid_client` DEĞİL;
     *   - API: HTTP 401, BOŞ GÖVDE (`WWW-Authenticate: Bearer …`).
     * Boş/JSON olmayan gövde çözümleyiciyi patlatmaz. 400 yalnız bilinen
     * kimlik kodlarıyla AUTHENTICATION olur; geri kalan 400 girdi hatasıdır.
     */
    public function classifyError(Throwable $e): ErrorClass
    {
        if ($e instanceof ConnectionException || ! $e instanceof RequestException) {
            return ErrorClass::NETWORK;
        }

        $status = $e->response->status();
        $body = json_decode($e->response->body(), true);
        $code = is_array($body) ? strtolower((string) ($body['messageCode'] ?? $body['error'] ?? '')) : '';

        if (in_array($code, ['invalid_token', 'invalid_client', 'unauthorized_client', 'invalid_grant'], true)) {
            return ErrorClass::AUTHENTICATION;
        }

        return match (true) {
            $status === 429 => ErrorClass::RATE_LIMITED,
            $status === 401, $status === 403 => ErrorClass::AUTHENTICATION,
            $status === 404 => ErrorClass::NOT_FOUND,
            $status === 408 => ErrorClass::TIMEOUT,
            $status === 409 => ErrorClass::CONFLICT,
            $status >= 500 => ErrorClass::SERVER_ERROR,
            $status >= 400 => ErrorClass::VALIDATION,
            default => ErrorClass::SERVER_ERROR,
        };
    }

    /** Webhook yok (portalın 53 sayfasının hiçbirinde) — imzasız gövde ASLA kabul edilmez. */
    public function verifyWebhookSignature(string $raw, array $headers): bool
    {
        return false;
    }

    public function extractEventId(array $headers): ?string
    {
        return null;
    }

    public function extractEventType(array $headers): string
    {
        return 'order.polled';
    }

    // ------------------------------------------------------- token yenileme

    /**
     * `client_credentials` ile yeni erişim anahtarı — KASAYA YAZMAZ.
     *
     * Çift korunur, yalnız `access_token` değişir. Çift yoksa istek
     * atılmaz. Secret'ın bitişi bağlantıdaki üretim tarihinden hesaplanır
     * (sınıf notu); tarih yoksa `null` = "değişmedi".
     */
    public function refreshCredentials(): RefreshedCredentials
    {
        $secrets = $this->readSecrets();

        $clientId = $secrets[self::CLIENT_ID_SECRET] ?? null;
        $clientSecret = $secrets[self::CLIENT_SECRET_SECRET] ?? null;

        if (! is_string($clientId) || $clientId === '' || ! is_string($clientSecret) || $clientSecret === '') {
            throw new RuntimeException('Pazarama API bilgileri (clientId / clientSecret) kasada yok.');
        }

        $response = $this->client->post(
            self::TOKEN_URL,
            ['grant_type' => 'client_credentials', 'scope' => self::SCOPE],
            headers: ['Authorization' => 'Basic '.base64_encode($clientId.':'.$clientSecret)],
            asForm: true,
        );

        $response->throw();

        $body = json_decode($response->body(), true);
        $body = is_array($body) ? $body : [];
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        // İki biçim de okunur (sınıf notu — DOĞRULANMADI).
        $access = $body['access_token'] ?? $data['accessToken'] ?? $data['access_token'] ?? null;

        if (! is_string($access) || $access === '' || ($body['success'] ?? true) === false) {
            throw new RuntimeException('Pazarama token yanıtı erişim anahtarı taşımıyor.');
        }

        $seconds = $body['expires_in'] ?? $data['expiresIn'] ?? $data['expires_in'] ?? null;
        $seconds = is_numeric($seconds) && (int) $seconds > 0 ? (int) $seconds : self::DEFAULT_TOKEN_SECONDS;

        return new RefreshedCredentials(
            secrets: [...$secrets, 'access_token' => $access],
            expiresAt: new DateTimeImmutable('@'.(time() + $seconds)),
            refreshExpiresAt: $this->secretExpiresAt(),
        );
    }

    /**
     * 1 saatlik anahtar, 15 dakikalık tarama: bitişe 40 dk kala aday olur,
     * ölmeden önce en az iki deneme hakkı kalır (Etsy'nin payı).
     */
    public function refreshLeadSeconds(): int
    {
        return 2400;
    }

    public function tokenEndpointFragment(): ?string
    {
        return self::TOKEN_PATH;
    }

    /** Üretim tarihi + 365 gün; tarih yoksa ya da bozuksa null. */
    private function secretExpiresAt(): ?DateTimeImmutable
    {
        $raw = $this->setting(self::SECRET_CREATED_AT_KEY);

        if ($raw === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }

        $created = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new DateTimeZone(self::CHANNEL_TIMEZONE));

        return $created === false ? null : $created->modify('+'.self::SECRET_LIFETIME_DAYS.' days');
    }

    // ------------------------------------------------------------ içe aktarma

    /**
     * Onaylı (satıştaki) ürünler — her satır BİR BARKOD. Kimlik `code`
     * (bütün yazımlar ona göre), üst kimlik `groupCode` (varyant grubu).
     *
     * İmleç Pazarama'nın `nextCursor`'ıdır, olduğu gibi geri verilir; null
     * dönünce bitti. Onaysız (onayda/reddedilen) ürünler ALINMAZ: satışta
     * değiller ve stok yazımının onlara etkisi DOĞRULANMADI. Barkodsuz satır
     * içe alınmaz (yazılamaz) ama günlüğe düşer.
     */
    public function fetchProductPage(?string $cursor = null): RemoteProductPage
    {
        $data = $this->approvedProducts(array_filter([
            'Size' => self::PRODUCT_PAGE_SIZE,
            'Cursor' => $cursor !== null && $cursor !== '' ? $cursor : null,
        ], static fn (mixed $v): bool => $v !== null));

        $products = [];
        $withoutCode = 0;

        foreach (self::rows($data['sellerProducts'] ?? null) as $row) {
            if (trim((string) ($row['code'] ?? '')) === '') {
                $withoutCode++;

                continue;
            }

            $products[] = $this->toRemoteProduct($row);
        }

        if ($withoutCode > 0) {
            Log::warning('pazarama.product_without_barcode', ['connection' => $this->connection->id, 'count' => $withoutCode]);
        }

        $next = is_string($data['nextCursor'] ?? null) && $data['nextCursor'] !== '' ? $data['nextCursor'] : null;

        return new RemoteProductPage(products: $products, nextCursor: $next, hasMore: $next !== null);
    }

    /** Tur başına 200 sayfa × 100 = 20.000 barkod. */
    public function maxImportPages(): int
    {
        return 200;
    }

    /** @param array<string, mixed> $row */
    private function toRemoteProduct(array $row): RemoteProduct
    {
        $code = trim((string) $row['code']);
        $stockCode = trim((string) ($row['stockCode'] ?? ''));
        $groupCode = trim((string) ($row['groupCode'] ?? ''));
        $description = trim((string) ($row['description'] ?? ''));
        $brand = trim((string) ($row['brandName'] ?? ''));

        $images = self::rows($row['images'] ?? null);
        usort($images, static fn (array $a, array $b): int => (int) ($a['sortOrder'] ?? 0) <=> (int) ($b['sortOrder'] ?? 0));

        return new RemoteProduct(
            externalId: $code,
            // Satıcının stok kodu SKU'dur; yoksa barkod (yazım anahtarı zaten barkod).
            sku: $stockCode !== '' ? $stockCode : $code,
            title: trim((string) ($row['displayName'] ?? $row['name'] ?? '')) ?: $code,
            price: isset($row['salePrice']) && is_numeric($row['salePrice']) ? self::money($row['salePrice']) : null,
            quantity: (int) ($row['stockCount'] ?? 0),
            description: $description !== '' ? $description : null,
            brand: $brand !== '' ? $brand : null,
            barcode: $code,
            // `state` sayıları belgesiz (yalnız 3 = Onaylandı); onaylı listeden geldi.
            status: 'approved',
            images: array_values(array_filter(
                array_map(static fn (array $image): string => (string) ($image['imageUrl'] ?? ''), $images),
                static fn (string $url): bool => str_starts_with($url, 'https://'),
            )),
            raw: $row,
            listingIdentity: array_filter([
                // Kimlik METİN — `(int)` yok (baştaki sıfır korunur).
                'external_id' => $code,
                'external_parent_id' => $groupCode !== '' ? $groupCode : null,
                'channel_metadata' => array_filter([
                    'stock_code' => $stockCode !== '' ? $stockCode : null,
                    'vat_rate' => isset($row['vatRate']) && is_numeric($row['vatRate']) ? (int) $row['vatRate'] : null,
                ], static fn (mixed $v): bool => $v !== null),
            ], static fn (mixed $v): bool => $v !== null && $v !== []),
            currency: 'TRY',
        );
    }

    // ---------------------------------------------------------------- stok

    /** MUTLAK stok — `updateStock-v2`, YALNIZ `code` + `stockCount`. */
    public function pushInventory(InventoryPushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            return AdapterResult::success(['pushed' => 0]);
        }

        $items = array_map(static fn (array $item): array => [
            'code' => (string) $item['external_id'],
            'stockCount' => max(0, (int) $item['quantity']),
        ], $batch->toArray());

        return $this->pushStockPrice('/product/updateStock-v2', $items);
    }

    public function maxInventoryBatchSize(): int
    {
        return self::MAX_ITEMS_PER_REQUEST;
    }

    /** @param list<Listing> $listings */
    public function fetchInventory(array $listings): RemoteInventorySnapshot
    {
        $quantities = [];

        foreach ($this->remoteRows($listings) as $code => $row) {
            $quantities[$code] = (int) ($row['stockCount'] ?? 0);
        }

        return new RemoteInventorySnapshot($quantities, new DateTimeImmutable);
    }

    // --------------------------------------------------------------- fiyat

    /**
     * MUTLAK fiyat — `updatePrice-v2`, YALNIZ `code` + `listPrice` +
     * `salePrice` (stok alanı TAŞINMAZ: bayat bakiye satılmış ürünü yeniden
     * satışa açardı).
     */
    public function pushPrices(PricePushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            return AdapterResult::success(['pushed' => 0]);
        }

        $items = [];

        foreach ($batch->items as $item) {
            $price = round((float) $item['price'], 2);
            $compare = isset($item['compare_at_price']) && is_numeric($item['compare_at_price'])
                ? round((float) $item['compare_at_price'], 2)
                : null;

            $items[] = [
                'code' => (string) $item['external_id'],
                'listPrice' => $compare !== null && $compare > $price ? $compare : $price,
                'salePrice' => $price,
            ];
        }

        return $this->pushStockPrice('/product/updatePrice-v2', $items);
    }

    public function maxPriceBatchSize(): int
    {
        return self::MAX_ITEMS_PER_REQUEST;
    }

    /** @param list<Listing> $listings */
    public function fetchPrices(array $listings): RemotePriceSnapshot
    {
        $prices = [];

        foreach ($this->remoteRows($listings) as $code => $row) {
            if (isset($row['salePrice']) && is_numeric($row['salePrice'])) {
                $prices[$code] = self::money($row['salePrice']);
            }
        }

        return new RemotePriceSnapshot($prices, new DateTimeImmutable);
    }

    /**
     * Stok ya da fiyat isteği — 10 sn kapısından geçer.
     *
     * Yanıt `{"data": "<GUID>", "success": true, …}`. `success: false`
     * gövdenin reddidir (VALIDATION); GUID'siz başarı belirsizdir ve
     * İSTİSNA olur ("sıraya alındı" denmeden uygulandı sayılamaz).
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function pushStockPrice(string $path, array $items): AdapterResult
    {
        $wait = $this->claimStockPriceSlot();

        if ($wait !== null) {
            return AdapterResult::failure(
                ErrorClass::RATE_LIMITED,
                "Pazarama iki stok-fiyat isteği arasında 10 sn ister; {$wait} sn sonra yeniden denenecek.",
                $wait,
            );
        }

        $response = $this->api('POST', $path, body: ['items' => $items], requireSuccess: false);
        $body = self::jsonBody($response);

        if (($body['success'] ?? null) !== true) {
            return AdapterResult::failure(ErrorClass::VALIDATION, 'Pazarama isteği reddetti: '.self::message($body));
        }

        $batchId = $body['data'] ?? null;

        if (! is_string($batchId) || trim($batchId) === '') {
            throw new RuntimeException('Pazarama işlem kimliği (dataId) dönmedi.');
        }

        return AdapterResult::success([
            'pushed' => count($items),
            'batch_id' => $batchId,
        ]);
    }

    // ------------------------------------------------------- toplu iş sonucu

    public function batchIdFrom(AdapterResult $result): ?string
    {
        $id = $result->data['batch_id'] ?? null;

        return is_scalar($id) && trim((string) $id) !== '' ? (string) $id : null;
    }

    public function batchRetentionSeconds(SyncDomain $domain): int
    {
        return self::BATCH_RETENTION_SECONDS;
    }

    /**
     * İşlem sonucu — `GET /listing-state/batch-id/{dataId}/lake-projections
     * ?page=1&pageSize=3000` (API notları §6, "dataId sorgulama servisi").
     *
     * Satır: `code` (barkod — gönderdiğimiz anahtar), alana göre
     * `stock{status, operationDetail, …}` ya da `price{…}`,
     * `operationStatusText`. Durumlar: `0` Başarılı · `1` Tamamlanamadı ·
     * `2` Hata oluştu · `3` İşleniyor · `5` Onaya gönderildi.
     *
     * - Tek satır `3` ise iş SÜRÜYOR.
     * - `5` (%70 indirim fiyat onayı) BAŞARI DEĞİLDİR (tuzak 6): satış eski
     *   fiyattan sürer → `awaitingApproval`, panelde "Bekliyor".
     * - `1`/`2` → VALIDATION (sebep `operationDetail`). DOĞRULANMADI: `2`
     *   "Hata oluştu"nun geçici bir teknik hata olup olmadığı.
     * - DOĞRULANMADI: satır listesinin zarftaki yeri (`data` dizi mi,
     *   `data.items`/`data.data`/`data.content` mı) — dördü de denenir.
     *   Satırın alt nesnesi yoksa üst düzey `status` okunur.
     * - Sayfa 3000 = istek başına üst sınır; tek sayfa yeter.
     */
    public function fetchBatchStatus(string $batchId, SyncDomain $domain): BatchStatus
    {
        try {
            $response = $this->api(
                'GET',
                '/listing-state/batch-id/'.rawurlencode($batchId).'/lake-projections',
                query: ['page' => 1, 'pageSize' => self::MAX_ITEMS_PER_REQUEST],
            );
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                return BatchStatus::expired();
            }

            throw $e;
        }

        $data = self::jsonBody($response)['data'] ?? null;
        $rows = is_array($data) && array_is_list($data)
            ? self::rows($data)
            : self::rows(is_array($data) ? ($data['items'] ?? $data['data'] ?? $data['content'] ?? []) : []);

        if ($rows === []) {
            return BatchStatus::pending();
        }

        $section = $domain === SyncDomain::PRICE ? 'price' : 'stock';
        $parsed = [];

        foreach ($rows as $row) {
            $part = is_array($row[$section] ?? null) ? $row[$section] : [];
            $status = $part['status'] ?? $row['status'] ?? null;

            if (! is_numeric($status)) {
                continue;
            }

            if ((int) $status === self::LAKE_PROCESSING) {
                return BatchStatus::pending();
            }

            $reason = trim((string) ($part['operationDetail'] ?? $row['operationDetail'] ?? $row['operationStatusText'] ?? ''));
            $parsed[] = [trim((string) ($row['code'] ?? '')), (int) $status, $reason];
        }

        $failed = [];
        $succeeded = [];
        $awaiting = [];
        $unmatched = [];

        foreach ($parsed as [$code, $status, $reason]) {
            if ($status === self::LAKE_SUCCESS) {
                if ($code !== '') {
                    $succeeded[] = $code;
                }

                continue;
            }

            if ($status === self::LAKE_SENT_TO_APPROVAL) {
                if ($code !== '') {
                    $awaiting[$code] = $reason !== ''
                        ? $reason
                        : 'Pazarama fiyatı onaya gönderdi; onaylanana kadar eski fiyat geçerli.';
                }

                continue;
            }

            $text = $reason !== '' ? $reason : 'Pazarama satırı işleyemedi (sebep belirtilmedi).';

            if ($code === '') {
                $unmatched[] = $text;

                continue;
            }

            $failed[$code] = new BatchItemFailure($text);
        }

        return BatchStatus::completed(
            failed: $failed,
            succeeded: $succeeded,
            awaitingApproval: $awaiting,
            unmatched: $unmatched,
        );
    }

    /**
     * Satıcı başına 10 sn kapısı — atomik `add`: kilit varsa kalan saniye,
     * yoksa kilit alınır ve null. Kilit BİLİNÇLİ OLARAK bırakılmaz; süresi
     * dolunca açılır = "iki istek arası en az 10 sn".
     */
    private function claimStockPriceSlot(): ?int
    {
        $key = 'pazarama:stock-price-gate:'.$this->connection->id;
        $now = now()->getTimestamp();
        $until = $now + self::STOCK_PRICE_INTERVAL_SECONDS;

        if (Cache::add($key, $until, self::STOCK_PRICE_INTERVAL_SECONDS)) {
            return null;
        }

        $held = Cache::get($key);

        // Değer kilidin AÇILACAĞI an; önbellek süresi (saniye yuvarlaması,
        // saat kayması) ondan geç dolsa da karar bu ana göre verilir.
        if (is_numeric($held) && (int) $held <= $now) {
            Cache::put($key, $until, self::STOCK_PRICE_INTERVAL_SECONDS);

            return null;
        }

        return max(1, (is_numeric($held) ? (int) $held : $until) - $now);
    }

    /**
     * Uzak satırlar barkodla — stok ve fiyat ORTAK.
     *
     * KİMLİKSİZ LISTING SORULMAZ; hiç kimlik yoksa çağrı YAPILMAZ. Az
     * listing için `Code` süzgeciyle tek tek (dönen satırın barkodu yine
     * karşılaştırılır — süzgecin tam eşleşme yaptığı DOĞRULANMADI), çoksa
     * imleçle tam tarama. Başarısız yanıt yükselir: boş sonuç "kanalda yok"
     * sanılırdı.
     *
     * @param  list<Listing>  $listings
     * @return array<string, array<string, mixed>>
     */
    private function remoteRows(array $listings): array
    {
        $codes = [];

        foreach ($listings as $listing) {
            $code = trim((string) ($listing->external_id ?? ''));

            if ($code !== '') {
                $codes[$code] = true;
            }
        }

        if ($codes === []) {
            return [];
        }

        $found = [];

        if (count($codes) <= self::PER_CODE_LOOKUP_LIMIT) {
            foreach (array_keys($codes) as $code) {
                foreach (self::rows($this->approvedProducts(['Code' => (string) $code, 'Size' => self::PRODUCT_PAGE_SIZE])['sellerProducts'] ?? null) as $row) {
                    if ((string) ($row['code'] ?? '') === (string) $code) {
                        $found[(string) $code] = $row;
                    }
                }
            }

            return $found;
        }

        $cursor = null;

        for ($page = 0; $page < $this->maxImportPages(); $page++) {
            $data = $this->approvedProducts(array_filter(
                ['Size' => self::PRODUCT_PAGE_SIZE, 'Cursor' => $cursor],
                static fn (mixed $v): bool => $v !== null,
            ));

            foreach (self::rows($data['sellerProducts'] ?? null) as $row) {
                $code = (string) ($row['code'] ?? '');

                if (isset($codes[$code])) {
                    $found[$code] = $row;
                }
            }

            $cursor = is_string($data['nextCursor'] ?? null) && $data['nextCursor'] !== '' ? $data['nextCursor'] : null;

            if ($cursor === null || count($found) === count($codes)) {
                break;
            }
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function approvedProducts(array $query): array
    {
        $data = self::jsonBody($this->api('GET', '/product/products/approved', query: $query))['data'] ?? null;

        if (! is_array($data)) {
            throw new RuntimeException('Pazarama ürün listesi veri taşımıyor.');
        }

        return $data;
    }

    // ------------------------------------------------------------- siparişler

    /**
     * Yoklama: tarih dilimi × sayfa. İmleç `o:{dilim}:{sayfa}`.
     *
     * Pencere `min(since, bugün − LOOKBACK_DAYS)` günü … yarın (hariç);
     * {@see ORDER_WINDOW_DAYS} günlük dilimler, YENİDEN ESKİYE (sınıf notu).
     */
    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage
    {
        [$slice, $page] = $cursor !== null && preg_match('/^o:(\d+):(\d+)$/', $cursor, $m) === 1
            ? [(int) $m[1], (int) $m[2]]
            : [0, 1];

        $windows = self::windows($since);

        if (! isset($windows[$slice])) {
            return new OrderPage(orders: []);
        }

        [$start, $end] = $windows[$slice];

        $response = $this->api('POST', '/order/getOrdersForApiV2', body: [
            'pageSize' => self::ORDER_PAGE_SIZE,
            'pageNumber' => max(1, $page),
            'startDate' => $start,
            'endDate' => $end,
        ]);

        $orders = self::rows(self::jsonBody($response)['data'] ?? null);
        $records = [];

        foreach ($orders as $order) {
            array_push($records, ...$this->recordsFor($order));
        }

        $next = match (true) {
            count($orders) >= self::ORDER_PAGE_SIZE => "o:{$slice}:".($page + 1),
            isset($windows[$slice + 1]) => 'o:'.($slice + 1).':1',
            default => null,
        };

        return new OrderPage(orders: $records, nextCursor: $next, hasMore: $next !== null);
    }

    /**
     * Gün dilimleri (`YYYY-AA-GG`, Türkiye günü, `endDate` HARİÇ), yeniden
     * eskiye; her dilimin başı bir sonrakinin (daha eskinin) sonudur.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function windows(CarbonInterface $since): array
    {
        $today = CarbonImmutable::now(self::CHANNEL_TIMEZONE)->startOfDay();
        $from = CarbonImmutable::instance($since)->setTimezone(self::CHANNEL_TIMEZONE)->startOfDay();
        $lookback = $today->subDays(self::LOOKBACK_DAYS);
        $from = $from->lessThan($lookback) ? $from : $lookback;

        // `endDate` o günü kapsamaz → bugünün siparişleri için yarın.
        $end = $today->addDay();
        $windows = [];

        while ($end->greaterThan($from)) {
            $start = $end->subDays(self::ORDER_WINDOW_DAYS);
            $start = $start->lessThan($from) ? $from : $start;
            $windows[] = [$start->format('Y-m-d'), $end->format('Y-m-d')];
            $end = $start;
        }

        return $windows;
    }

    /**
     * Siparişin kayıtları.
     *
     * - Alınmamış sipariş: kalemlerin hepsi iptalse HİÇBİR ŞEY; değilse
     *   bütün kalemlerle "created", ardından iptal/iade edilmiş kalemlerin
     *   kayıtları (net sıfır).
     * - Alınmış sipariş: iptal/iade edilmiş her kalem için kayıt (olay
     *   kimliği tekrarı eler) ve durum anlık görüntüsü ("updated", stok
     *   hareketi yok).
     *
     * @param  array<string, mixed>  $order
     * @return list<array<string, mixed>>
     */
    private function recordsFor(array $order): array
    {
        $orderNumber = trim((string) ($order['orderNumber'] ?? ''));
        $items = self::items($order);

        if ($orderNumber === '' || $items === []) {
            return [];
        }

        $allCancelled = array_filter($items, static fn (array $item): bool => ! self::isCancelled($item)) === [];
        $records = [];
        $isNew = ! $this->createdKnown($orderNumber);

        if ($isNew) {
            if ($allCancelled) {
                return [];
            }

            $this->createdThisRun[$orderNumber] = true;

            $records[] = [
                '_kind' => 'created',
                'orderNumber' => $orderNumber,
                'orderId' => isset($order['orderId']) ? (string) $order['orderId'] : null,
                'orderDate' => $order['orderDate'] ?? null,
                'orderStatus' => $order['orderStatus'] ?? null,
                'orderAmount' => self::amount($order['orderAmount'] ?? null),
                'shipmentAmount' => self::amount($order['shipmentAmount'] ?? null),
                'items' => $items,
            ];
        }

        foreach ($items as $item) {
            if (self::isCancelled($item)) {
                $records[] = ['_kind' => 'cancelled', 'orderNumber' => $orderNumber, 'orderCancelled' => $allCancelled, 'item' => $item];
            } elseif ($this->isReturned($orderNumber, $item)) {
                $records[] = ['_kind' => 'returned', 'orderNumber' => $orderNumber, 'item' => $item];
            }
        }

        if (! $isNew) {
            $records[] = self::snapshot($orderNumber, $items);
        }

        return $records;
    }

    /**
     * Durum anlık görüntüsü — kimlik kalem statülerinin imzasıdır; statü
     * değişmedikçe aynı kayıt yeniden yazılmaz.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private static function snapshot(string $orderNumber, array $items): array
    {
        $statuses = [];
        $tracking = null;
        $carrier = null;
        $changedAt = null;

        foreach ($items as $item) {
            $statuses[(string) $item['orderItemId']] = (int) ($item['orderItemStatus'] ?? 0);
            $tracking ??= trim((string) ($item['cargo']['trackingNumber'] ?? $item['shipmentCode'] ?? '')) ?: null;
            $carrier ??= trim((string) ($item['cargo']['companyName'] ?? '')) ?: null;

            foreach ((array) ($item['history'] ?? []) as $history) {
                $at = $history['historyCreatedDate'] ?? null;
                $changedAt = is_string($at) && ($changedAt === null || $at > $changedAt) ? $at : $changedAt;
            }
        }

        ksort($statuses);

        // Başlıkta en çok kalemin statüsü (kalem bazlı statü sipariş geneline indirgenir).
        // Bütün kalemler iptal/tedarik edilemedi ise sipariş iptaldir.
        $counts = array_count_values($statuses);
        arsort($counts);
        $status = array_diff($statuses, self::CANCELLED_STATUSES) === [] ? 6 : (int) array_key_first($counts);

        return [
            '_kind' => 'updated',
            'orderNumber' => $orderNumber,
            'signature' => substr(sha1((string) json_encode($statuses)), 0, 16),
            'status' => self::STATUS_WORDS[$status] ?? (string) $status,
            'trackingNumber' => $tracking,
            'carrier' => $carrier,
            'changedAt' => $changedAt,
        ];
    }

    /**
     * `{orderNumber}:created` · `{orderNumber}:cancel:{orderItemId}` ·
     * `{orderNumber}:return:{orderItemId}` · `{orderNumber}:status:{imza}`.
     *
     * İptal/iade kimliği KALEME bağlı: aynı siparişin ikinci kısmi iptali
     * sipariş düzeyindeki kimlikle YUTULURDU.
     *
     * @param  array<string, mixed>  $order
     */
    public function pollingEventIdFor(array $order): ?string
    {
        $orderNumber = trim((string) ($order['orderNumber'] ?? ''));

        if ($orderNumber === '') {
            return null;
        }

        $itemId = trim((string) ($order['item']['orderItemId'] ?? ''));

        return match ($order['_kind'] ?? null) {
            'created' => "{$orderNumber}:created",
            'cancelled' => $itemId !== '' ? "{$orderNumber}:cancel:{$itemId}" : null,
            'returned' => $itemId !== '' ? "{$orderNumber}:return:{$itemId}" : null,
            'updated' => isset($order['signature']) ? "{$orderNumber}:status:{$order['signature']}" : null,
            default => null,
        };
    }

    public function parseOrderEvent(InboxMessage $message): ?NormalizedOrderEvent
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($message->payload) ? $message->payload : [];
        $orderNumber = trim((string) ($payload['orderNumber'] ?? ''));
        $kind = $payload['_kind'] ?? null;

        if ($orderNumber === '' || ! in_array($kind, ['created', 'cancelled', 'returned', 'updated'], true)) {
            return null;
        }

        $ref = $message->external_event_id ?? $this->pollingEventIdFor($payload);

        if ($kind === 'cancelled' || $kind === 'returned') {
            $item = is_array($payload['item'] ?? null) ? $payload['item'] : [];

            return new NormalizedOrderEvent(
                type: $kind,
                externalOrderId: $orderNumber,
                externalRef: $ref,
                payload: array_filter([
                    // Başlık yalnız bütün kalemler iptalse "Cancelled".
                    'status' => $kind === 'cancelled' && ($payload['orderCancelled'] ?? false) === true ? 'Cancelled' : null,
                    'lines' => [array_filter([
                        // Satır kimliği BARKOD: "created" aynı barkodu topladı.
                        'external_line_id' => self::lineKey($item),
                        'sku' => self::lineSku($item),
                        'quantity' => (int) ($item['quantity'] ?? 0),
                    ], static fn (mixed $v): bool => $v !== null && $v !== '')],
                ], static fn (mixed $v): bool => $v !== null),
                occurredAt: self::lastChange($item),
            );
        }

        if ($kind === 'updated') {
            return new NormalizedOrderEvent(
                type: 'updated',
                externalOrderId: $orderNumber,
                externalRef: $ref,
                payload: array_filter([
                    'status' => (string) ($payload['status'] ?? ''),
                    'tracking_number' => $payload['trackingNumber'] ?? null,
                    'carrier' => $payload['carrier'] ?? null,
                ], static fn (mixed $v): bool => $v !== null && $v !== ''),
                occurredAt: self::date($payload['changedAt'] ?? null),
            );
        }

        // Aynı barkodun kalemleri TEK satırda toplanır (V2: adet başına kalem).
        $lines = [];

        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $quantity = (int) ($item['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            // ⚠️ `totalPrice` kalem tutarı sayıldı ("siparişin toplam tutarı"
            // diye tanımlı, örnekte 1 adet = satış fiyatı) — DOĞRULANMADI;
            // yoksa satış fiyatı × adet. KDV dahil (`taxIncluded`).
            $total = isset($item['totalPrice']) ? (float) $item['totalPrice'] : (float) ($item['salePrice'] ?? 0) * $quantity;
            $key = self::lineKey($item);

            if (! isset($lines[$key])) {
                $code = trim((string) ($item['product']['code'] ?? ''));
                $variant = trim((string) ($item['product']['variantOptionDisplay'] ?? ''));
                $name = trim((string) ($item['product']['name'] ?? ''));

                $lines[$key] = [
                    'external_line_id' => $key,
                    'sku' => self::lineSku($item),
                    // Listing kimliği barkod (içe aktarma).
                    'external_variant_id' => $code !== '' ? $code : null,
                    'title' => trim($name.($variant !== '' ? ' — '.$variant : '')) ?: $key,
                    'quantity' => 0,
                    'total' => 0.0,
                ];
            }

            $lines[$key]['quantity'] += $quantity;
            $lines[$key]['total'] += $total;
        }

        $subtotal = 0.0;
        $normalizedLines = [];

        foreach ($lines as $line) {
            $subtotal += $line['total'];

            $normalizedLines[] = [
                'external_line_id' => $line['external_line_id'],
                'sku' => $line['sku'],
                'external_variant_id' => $line['external_variant_id'],
                'title' => $line['title'],
                'quantity' => $line['quantity'],
                'unit_price' => self::money($line['total'] / $line['quantity']),
                'line_total' => self::money($line['total']),
            ];
        }

        $shipping = (float) ($payload['shipmentAmount'] ?? 0);
        $placedAt = self::date($payload['orderDate'] ?? null);
        $status = is_numeric($payload['orderStatus'] ?? null) ? (int) $payload['orderStatus'] : 3;

        return new NormalizedOrderEvent(
            type: 'created',
            externalOrderId: $orderNumber,
            externalRef: $ref,
            payload: [
                'type' => 'created',
                'external_number' => $orderNumber,
                'status' => self::STATUS_WORDS[$status] ?? (string) $status,
                'currency' => 'TRY',
                'subtotal' => self::money($subtotal),
                'shipping_total' => self::money($shipping),
                // `orderAmount`'ın kargoyu içerip içermediği DOĞRULANMADI.
                'grand_total' => self::money(is_numeric($payload['orderAmount'] ?? null) ? $payload['orderAmount'] : $subtotal + $shipping),
                'lines' => $normalizedLines,
                // Müşteri kimliği de kişisel veri (§6) — taşınmaz.
                'customer_ref' => [],
            ],
            occurredAt: $placedAt,
            placedAt: $placedAt,
        );
    }

    /**
     * Siparişin bütün kalemlerini `12`'ye (Hazırlanıyor) çeker —
     * `PUT /order/updateOrderStatusList`.
     *
     * ⚠️ Sınıf notu: çekirdek bunu bugün ÇAĞIRMIYOR; `12`'de kargolanmayan
     * sipariş otomatik iadeye düşer ve satıcı puanı düşer. Zarfında
     * `success` true olmayan 200 başarı SAYILMAZ.
     */
    public function acknowledgeOrder(Order $order): AdapterResult
    {
        $orderNumber = trim((string) $order->external_id);

        if (! ctype_digit($orderNumber)) {
            return AdapterResult::failure(ErrorClass::VALIDATION, 'Pazarama sipariş numarası (orderNumber) yok; sipariş onaylanamaz.');
        }

        $response = $this->api('PUT', '/order/updateOrderStatusList', body: [
            // Resmi örnekte SAYI.
            'orderNumber' => (int) $orderNumber,
            'status' => self::ACK_STATUS,
        ], requireSuccess: false);

        $body = self::jsonBody($response);

        if (($body['success'] ?? null) !== true) {
            return AdapterResult::failure(ErrorClass::VALIDATION, 'Pazarama siparişi onaylamadı: '.self::message($body));
        }

        return AdapterResult::success(['acknowledged' => true]);
    }

    // ------------------------------------------------------------------- iç

    /**
     * Kalemler, kişisel veriden arındırılmış (beyaz liste). Para alanları
     * nesne (`{"value": 7.00, …}`) gelir, sayıya indirilir; kimliksiz kalem
     * alınmaz.
     *
     * @param  array<string, mixed>  $order
     * @return list<array<string, mixed>>
     */
    private static function items(array $order): array
    {
        $items = [];

        foreach (self::rows($order['items'] ?? null) as $item) {
            $id = trim((string) ($item['orderItemId'] ?? ''));

            if ($id === '') {
                continue;
            }

            $product = is_array($item['product'] ?? null) ? $item['product'] : [];
            $cargo = is_array($item['cargo'] ?? null) ? $item['cargo'] : [];

            $items[] = [
                'orderItemId' => $id,
                'orderItemStatus' => is_numeric($item['orderItemStatus'] ?? null) ? (int) $item['orderItemStatus'] : null,
                'quantity' => (int) ($item['quantity'] ?? 0),
                'salePrice' => self::amount($item['salePrice'] ?? null),
                'totalPrice' => self::amount($item['totalPrice'] ?? null),
                'discountAmount' => self::amount($item['discountAmount'] ?? null),
                'taxIncluded' => $item['taxIncluded'] ?? null,
                'deliveryType' => $item['deliveryType'] ?? null,
                'shipmentCode' => $item['shipmentCode'] ?? null,
                'product' => array_intersect_key($product, array_flip(['productId', 'name', 'code', 'stockCode', 'variantOptionDisplay', 'vatRate'])),
                'cargo' => array_intersect_key($cargo, array_flip(['companyId', 'companyName', 'trackingNumber', 'trackingUrl'])),
                'history' => array_map(
                    static fn (array $h): array => array_intersect_key($h, array_flip(['historyStatus', 'historyCreatedDate'])),
                    self::rows($item['orderItemStatusHistory'] ?? null),
                ),
            ];
        }

        return $items;
    }

    /** @param array<string, mixed> $item */
    private static function isCancelled(array $item): bool
    {
        return in_array($item['orderItemStatus'] ?? null, self::CANCELLED_STATUSES, true);
    }

    /**
     * Ürün döndü mü (sınıf notu): `8`; `10` yalnız geçmişinde `8` varsa.
     * Yalnız `10` görülürse ürünsüz iade olabilir — stoğa eklenmez, günlüğe
     * düşer.
     *
     * @param  array<string, mixed>  $item
     */
    private function isReturned(string $orderNumber, array $item): bool
    {
        $status = $item['orderItemStatus'] ?? null;

        if ($status === self::RETURN_APPROVED_STATUS) {
            return true;
        }

        if ($status !== self::RETURN_REFUNDED_STATUS) {
            return false;
        }

        foreach ((array) ($item['history'] ?? []) as $history) {
            if ((int) ($history['historyStatus'] ?? 0) === self::RETURN_APPROVED_STATUS) {
                return true;
            }
        }

        Log::warning('pazarama.refund_without_approved_return', [
            'connection' => $this->connection->id,
            'order' => $orderNumber,
            'item' => $item['orderItemId'] ?? null,
        ]);

        return false;
    }

    /**
     * Satır kimliği: barkod; barkodsuz kalemde kalem kimliği.
     *
     * @param  array<string, mixed>  $item
     */
    private static function lineKey(array $item): string
    {
        $code = trim((string) ($item['product']['code'] ?? ''));

        return $code !== '' ? $code : (string) ($item['orderItemId'] ?? '');
    }

    /**
     * SKU: satıcının stok kodu; yoksa barkod (içe aktarmayla aynı kural).
     *
     * @param  array<string, mixed>  $item
     */
    private static function lineSku(array $item): string
    {
        $stockCode = trim((string) ($item['product']['stockCode'] ?? ''));

        return $stockCode !== '' ? $stockCode : trim((string) ($item['product']['code'] ?? ''));
    }

    /** @param array<string, mixed> $item */
    private static function lastChange(array $item): ?DateTimeImmutable
    {
        $latest = null;

        foreach ((array) ($item['history'] ?? []) as $history) {
            $at = self::date($history['historyCreatedDate'] ?? null);
            $latest = $at !== null && ($latest === null || $at > $latest) ? $at : $latest;
        }

        return $latest;
    }

    /** Sipariş daha önce alındı mı — önceki turda inbox'a ya da bu turda kayda. */
    private function createdKnown(string $orderNumber): bool
    {
        return isset($this->createdThisRun[$orderNumber]) || TenantContext::runAsSystem(fn (): bool => InboxMessage::query()
            ->where('channel_connection_id', $this->connection->id)
            ->where('external_event_id', "{$orderNumber}:created")
            ->exists());
    }

    /**
     * Kimlikli API isteği.
     *
     * Erişim anahtarı YOKSA istek ATILMAZ: kimliksiz istek 401 alır ve
     * "anahtarın yanlış" diye kalıcı hataya düşerdi. Bearer başlığı açıkça
     * verilir (kasa onu ezmez). `requireSuccess` ise zarfın `success`'i
     * true değilse istisna: boş `data` "kayıt yok" sanılırdı.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function api(string $method, string $path, array $query = [], ?array $body = null, bool $requireSuccess = true): Response
    {
        $token = $this->readSecrets()['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Pazarama erişim anahtarı henüz alınmadı — API bilgileriyle yeniden bağlan.');
        }

        $response = $this->client->request(
            $method,
            self::BASE_URL.$path,
            body: $body,
            query: $query,
            headers: ['Authorization' => 'Bearer '.$token],
        );

        $response->throw();

        if ($requireSuccess) {
            $envelope = self::jsonBody($response);

            if (($envelope['success'] ?? null) !== true) {
                throw new RuntimeException('Pazarama isteği başarısız: '.self::message($envelope));
            }
        }

        return $response;
    }

    /**
     * Gövde JSON nesnesi olmalı. Düz metin/HTML 200 "hiç kayıt yok"
     * sanılırdı — imleç ilerler, pencere bir daha sorulmazdı.
     *
     * @return array<string, mixed>
     */
    private static function jsonBody(Response $response): array
    {
        $body = json_decode($response->body(), true);

        if (! is_array($body)) {
            throw new RuntimeException('Pazarama beklenmeyen yanıt döndü: '.mb_substr(trim($response->body()), 0, 120));
        }

        return $body;
    }

    /** @param array<string, mixed> $body */
    private static function message(array $body): string
    {
        $text = trim((string) ($body['userMessage'] ?? $body['message'] ?? $body['messageCode'] ?? ''));

        return $text !== '' ? $text : 'neden yok';
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /** Para alanı: nesne (`value`) ya da sayı. */
    private static function amount(mixed $value): ?float
    {
        if (is_array($value)) {
            $value = $value['value'] ?? null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private static function money(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    /**
     * Sipariş tarihi `2026-02-17 17:23` (bölgesiz, Türkiye saati) ya da
     * geçmişteki `…+03:00`. Bölgesiz değer Türkiye saati sayılır
     * (DOĞRULANMADI).
     */
    private static function date(mixed $raw): ?DateTimeImmutable
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable(trim($raw), new DateTimeZone(self::CHANNEL_TIMEZONE));
        } catch (Throwable) {
            return null;
        }
    }

    private function setting(string $key): ?string
    {
        $value = $this->connection->settings[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /** @return array<string, mixed> */
    private function readSecrets(): array
    {
        try {
            return TenantContext::runAsSystem(fn (): array => app(CredentialVault::class)->read($this->connection));
        } catch (Throwable) {
            return [];
        }
    }
}
