<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Ciceksepeti;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresChannelCurrency;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\SupportsBatchStatus;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
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
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Çiçeksepeti kanal adapter'ı — REST/JSON (`apis.ciceksepeti.com/api/v1`),
 * kimlik YALNIZ `x-api-key` başlığı.
 *
 * Araştırma: `docs/YENI-KANALLAR-API-NOTLARI.md` §5 (ciceksepeti.dev'in 27
 * sayfası gerçek Chrome ile okundu). Gerçek mağazayla HENÜZ SINANMADI →
 * kanal `is_active = false`. Ürün açma (`POST /Products`) bu sürümde YOK.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ ALMANYA'DAN ERİŞİM YOK — HTML 403 = ERİŞİM ENGELİ, ANAHTAR HATASI DEĞİL
 * ─────────────────────────────────────────────────────────────────────
 * 8 Eki 2026'da Almanya IP'sinden `apis` ve `sandbox-apis` altındaki HER
 * yol (robots.txt dahil, anahtarlı/anahtarsız, her user-agent) HTTP 403 ve
 * HTML gövde ("Page Not Available") döndü; istek kimlik kontrolüne hiç
 * ulaşmıyor (§5 · Tuzaklar 1). 34Pazar'ın sunucusu da Almanya'da (IONOS);
 * oradan ölçülmedi. Bu yüzden HTML 403 ayrı tanınır ({@see isAccessBlocked}):
 * kalıcı AUTHENTICATION sayılır (devre süresiz açılır, boşuna denenmez) ama
 * mesaj "anahtarın yanlış" DEMEZ — {@see ACCESS_BLOCKED_MESSAGE}. Aksi hâlde
 * satıcı doğru anahtarı defalarca yeniden girerdi. Çözüm Türkiye çıkışlı
 * vekil ya da Çiçeksepeti'nin IP'yi açması; ikisi de koddan yapılamaz.
 * Coğrafi engel mi WAF kuralı mı DOĞRULANMADI. JSON gövdeli 403/401 normal
 * kimlik hatasıdır.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KİMLİK: `x-api-key` + `user-agent: <SatıcıId>-34Pazar`
 * ─────────────────────────────────────────────────────────────────────
 * Anahtar destek talebiyle gelir (satıcı paneli → Çiçeksepeti Destek Ekibi
 * → "API Entegrasyon Süreçleri"); sonra Hesap Yönetimi → Entegrasyon
 * Bilgilerim'de görünür. Kasada {@see API_KEY_SECRET} adıyla durur:
 * `api_key` SEÇİLMEDİ — `ChannelHttpClient::BASIC_AUTH_KEY_PAIRS` çiftinin
 * yarısıdır ve ileride bir `api_secret` eklenirse istek fazladan Basic auth
 * taşırdı. Satıcı ID'si sır değildir (`settings`); `user-agent`'a girer.
 * Doküman başlığı "zorunlu değil, yalnız uyarı" diyor ama girilmesini
 * istiyor; satıcı panelde Entegratör Adı'na `34Pazar` yazmalı.
 * Test (sandbox) ayrı host ve AYRI anahtardır ({@see ENVIRONMENT_KEY}).
 *
 * ─────────────────────────────────────────────────────────────────────
 * HIZ SINIRLARI — İKİ AYRI KURAL
 * ─────────────────────────────────────────────────────────────────────
 * 1. FARKLI istek aralığı: ürün listesi ve sipariş/iade listesi 5 sn'de 1,
 *    stok-fiyat sn'de 1. Çekirdeğin kovası saniyede 1'in altını ifade
 *    edemez ve yoklama/içe aktarma döngüleri sayfalar arasında beklemez;
 *    aralık burada, bağlantı + uç nokta başına önbellek damgasıyla tutulur
 *    ve gerekirse BEKLENİR ({@see pace}). Bedeli duvar saati: yoklama turu
 *    bağlantı başına ~10 sn, içe aktarma sayfa başına 5 sn.
 * 2. AYNI istek (aynı gövde/sorgu): stok-fiyat 30 dk, ürün listesi 10 dk,
 *    sipariş listesi 1 dk. Stok-fiyatta {@see claimBody} uygular (aşağıda).
 *    Okumalarda gövdeler tarih/sayfa taşıdığı için pratikte tekrarlanmaz;
 *    sağlık kontrolü sorguyu `SortMethod` ile döndürür.
 * Sınır aşılınca dönen kod belgesiz (429 beklenir) — DOĞRULANMADI.
 *
 * ─────────────────────────────────────────────────────────────────────
 * STOK / FİYAT: `PUT /Products/price-and-stock` — ORTAK UÇ, ASENKRON
 * ─────────────────────────────────────────────────────────────────────
 * Anahtar `stockCode` (satıcı varyant kodu) → listing kimliği de o. Uç
 * KISMİ güncelleme destekler: "Gönderilmeyen alan değişmez", kalemde
 * `stockCode` + (`stockQuantity` VEYA `salesPrice`) yeter (§5).
 *   - Stok yükü YALNIZ `stockCode` + `stockQuantity` (fiyat yazılsaydı
 *     panelde değişmiş fiyat eski değerle ezilirdi).
 *   - Fiyat yükü YALNIZ `stockCode` + `salesPrice` (+ koşullu `listPrice`);
 *     stok TAŞINMAZ (bayat bakiye satılmış ürünü yeniden satışa açardı).
 *     `listPrice` yalnız indirim varsa ve fark %1–%80 aralığındaysa gider:
 *     "İndirim yoksa listPrice hiç gönderilmemeli", aralık dışı kalem
 *     reddedilir. DOĞRULANMADI: `listPrice` gönderilmeyince eski üstü çizili
 *     fiyatın kalıp kalmadığı.
 *   - İstek başına ≤200 kalem. Yanıt `batchId` — "sıraya alındı" demektir,
 *     uygulandı DEĞİL (4 saate kadar `Pending`); sonuçta `batch_id` taşınır.
 *     Kalem sonucu `GET /Products/batch-status/{id}`'de; bu sürüm OKUMAZ,
 *     uygulandığını mutabakat doğrular.
 *   - %50'den büyük tek adımlık indirim ve kategori asgari fiyatı Çiçeksepeti
 *     tarafında kalem bazında reddedilir; burada bilinemez (mevcut fiyat
 *     okunmadan).
 *   - KDV alanı yok; fiyat tüketici fiyatı = KDV dahil sayıldı (DOĞRULANMADI).
 *
 * ⚠️ "AYNI GÖVDE 30 DK'DA 1" — KARAR: GÖNDERMEDEN `RATE_LIMITED`, BAŞARI DEĞİL.
 * Stok 5 → 4 → 5 dönerse üçüncü yük birincinin AYNISIDIR. "Gövde aynıysa
 * kanaldaki durum zaten o" varsayımı YANLIŞ: arada "4" gitti, kanal 4'te.
 * Başarı denseydi kanal 30 dk değil KALICI olarak 4'te kalırdı (sessiz veri
 * kaybı; operasyon "tamamlandı" olur, kimse yeniden göndermez). Göndermek de
 * güvenli değil: Çiçeksepeti'nin aynı gövdeyi nasıl reddettiği belgesiz
 * (DOĞRULANMADI) — 200 + `batchId` dönüp sessizce yok sayarsa aynı kayıp.
 * Bu yüzden gövdenin özeti bağlantı başına 30 dk tutulur; tekrar gelirse
 * İSTEK ATILMAZ, kalan süreyle `RATE_LIMITED` döner ve çekirdek o kadar
 * sonra yeniden dener. Bu arada değer değişirse eski operasyon yerini
 * yenisine bırakır ve FARKLI gövde hemen gider. Bilinen bedel: 4 → 5 → 4'te
 * kanal en çok 30 dk fazla stok gösterir. Özet istekten ÖNCE yazılır
 * (eşzamanlı iki kopya da yakalanır); yalnız 429'da silinir (kanal kabul
 * etmedi, kural işlemedi). Teknik `Failed` kalemleri mutabakat yeniden
 * gönderir — 30 dk sonra, farklı ya da aynı gövdeyle.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SİPARİŞ: `POST /Order/GetOrders` — ALT SİPARİŞ (orderItem) SATIRLARI
 * ─────────────────────────────────────────────────────────────────────
 * - Her satır bir alt sipariş (`orderItemId`, kendi statüsü, `quantity`);
 *   34Pazar siparişi ana sipariştir (`orderId`), satır kimliği `orderItemId`.
 *   Satırın SKU'su `code` (= `stockCode`).
 * - TARİH PENCERESİ EN ÇOK 2 HAFTA, aşan pencere HATA verir → pencere
 *   {@see ORDER_WINDOW_DAYS} günlük dilimlere bölünür. İstekte ISO/UTC
 *   gönderilir; sunucunun TR saati mi UTC mi okuduğu DOĞRULANMADI → iki
 *   uçtan {@see ORDER_DATE_SLACK_HOURS} saat pay; tekrar olay kimliğiyle
 *   elenir. Süzgecin oluşturma mı güncellenme tarihine mi uygulandığı
 *   DOĞRULANMADI.
 * - Sayfa 0'dan, en çok 100. Bir siparişin satırları sayfa sınırında
 *   bölünebilir: ilk kez görülen sipariş DOLU sayfadaysa bütün aktif
 *   satırları `orderNo` ile yeniden okunur (N11 kuralı); aksi hâlde eksik
 *   satırın stoğu hiç düşmezdi. Dolu olmayan sayfada pencereye giren bütün
 *   satırlar zaten oradadır.
 * - Tur üç aşamadır, imleç `a|p|r:{dilim}:{sayfa}`:
 *   a) aktif satırlar (`isOrderStatusActive: true`) → "created" / durum;
 *   b) PASİF satırlar (`isOrderStatusActive: false`) → iptal. İptal olan
 *      satır varsayılan listede GÖRÜNMEZ (tuzak 7); bu aşama olmasa stok
 *      hiç geri gelmezdi. En az {@see CANCEL_LOOKBACK_DAYS} gün geriye
 *      bakılır (iptal yalnız kargodan önce mümkün);
 *   c) `POST /Order/getcanceledorders`, İPTAL/İADE TARİHİNE göre
 *      (`cancellationStartDate/EndDate`, ≤1 ay) → iade. İade kaydı aylarca
 *      sürmez ama onay günler sonra gelir → {@see RETURN_LOOKBACK_DAYS} gün.
 * - İPTAL/İADE KURALI (N11/Pazarama'yla aynı): sipariş ve satır daha önce
 *   "created"e GİRMEDİYSE iptali de iadesi de hiçbir şey üretmez. İlk kez
 *   görülen siparişin zaten iptal olmuş satırları "created"e hiç girmez
 *   (aktif listede yoklar) → net sıfır. Miktar "created" kaydından okunur
 *   (iade satırında adet yok — tuzak 8).
 * - İade yalnız ÜRÜN DÖNDÜYSE stoğa eklenir: `cancelType = 2` ve satıcı
 *   onayı (`Bayi Onay` — yalnız "İade Tedarikçide"de verilebilir) ya da
 *   "Müşteri Haklı" + ürün tedarikçide. Değişim (`cancelReasonId = 2`),
 *   "müşteriye geri gönderilecek" (`cancelType = 3`) ve bayi reddi stoğa
 *   dokunmaz. Yanıt tablosunda karar ve statü METİN; sayısal alanlar da
 *   okunur. DOĞRULANMADI: iade adedinin alt siparişin tamamı olduğu.
 *   `getcanceledorders`'taki `cancelType = 1` (iptal) BURADA işlenmez —
 *   iptalin kaynağı pasif satırdır (bekleyen iptal talebi stoğu geri
 *   getirmesin; "İptal/İknadan Geri Çekildi" de var).
 * - Değişim onaylanınca satır "Yeni"ye döner (tuzak 8): "created" sipariş
 *   başına bir kez (olay kimliği) → stok ikinci kez düşmez.
 * - Kişisel veri inbox'a girmez: alıcı/gönderici adı, adresi, telefonu,
 *   vergi no, fatura e-postası, kart notu, video mesajı, kişiselleştirme
 *   metinleri, müşteri kimliği. Kalem beyaz listeyle seçilir; iade
 *   satırından yalnız karar alanları alınır.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ SİPARİŞ ONAYI VE KARGO — BİLİNÇLİ OLARAK YAZILMADI
 * ─────────────────────────────────────────────────────────────────────
 * Ayrı bir "onayla" ucu yok. Kargo iki modelden biridir ve satıcıya göre
 * değişir (`cargoModelType`): 1 = Çiçeksepeti entegrasyonu
 * (`readyforcargowithcsintegration`, yalnız "Yeni"de, `partialNumber`
 * üretir), 2 = satıcının kendi kargosu (`statusupdatewithsupplierintegration`;
 * Teslim Edildi dahil bütün statülerden SATICI sorumlu, Yurtiçi istisnası,
 * aynı gün teslimatta "Arabaya Verildi"). Yanlış modelin ucu hata döner ve
 * otomatik bir adım satıcının kendi akışını bozardı. `acknowledgeOrder`
 * istek atmaz. Satıcı tarafı iptal/ret ucu da yok (panelden).
 */
final class CiceksepetiAdapter implements ChannelAdapter, DeclaresChannelCurrency, SupportsBatchStatus, SupportsCatalogImport, SupportsInventory, SupportsOrders, SupportsPricing
{
    use DeclaresRequestQuota;

    /** Canlı API. */
    public const LIVE_BASE_URL = 'https://apis.ciceksepeti.com/api/v1';

    /** Test API — ayrı anahtar ister. */
    public const SANDBOX_BASE_URL = 'https://sandbox-apis.ciceksepeti.com/api/v1';

    /** Kasadaki anahtarın adı (sınıf notu: `api_key` DEĞİL). */
    public const API_KEY_SECRET = 'ciceksepeti_api_key';

    /** Satıcı ID — `user-agent`'a girer. SIR DEĞİL. */
    public const SELLER_ID_KEY = 'ciceksepeti_seller_id';

    /** `test` ise sandbox; boş/`canli` canlı. SIR DEĞİL. */
    public const ENVIRONMENT_KEY = 'ciceksepeti_environment';

    public const ENVIRONMENT_SANDBOX = 'test';

    /** `user-agent`'ın ikinci yarısı — satıcı panelinde Entegratör Adı. */
    public const INTEGRATOR = '34Pazar';

    /** HTML 403'ün satıcıya söylediği (sınıf notu). */
    public const ACCESS_BLOCKED_MESSAGE = "Çiçeksepeti erişimi IP'yi engelliyor — sunucu IP'sini Çiçeksepeti'ye bildir.";

    /** Ürün listesi sayfası en çok 60; sayfa 1'den. */
    private const PRODUCT_PAGE_SIZE = 60;

    /** Stok-fiyat isteği başına en çok 200 kalem. */
    private const MAX_ITEMS_PER_REQUEST = 200;

    /** Aynı stok-fiyat gövdesi arası (resmi: 30 dk). */
    public const SAME_BODY_SECONDS = 1800;

    /** Farklı istekler arası (resmi), saniye. */
    private const PRODUCTS_INTERVAL = 5;

    private const ORDERS_INTERVAL = 5;

    private const RETURNS_INTERVAL = 5;

    private const STOCK_PRICE_INTERVAL = 1;

    /**
     * Stok/fiyat işi "en geç 4 saatte" biter (API notları §5); sonuç bu
     * süre içinde okunur, sonra yoklama bırakılır.
     */
    private const BATCH_RETENTION_SECONDS = 4 * 3600;

    /** Tek seferde beklenebilecek en uzun süre; fazlası RATE_LIMITED. */
    private const MAX_INLINE_WAIT_SECONDS = 30;

    /** Mutabakatta tek tek `StockCode` sorgusu üst sınırı (her biri 5 sn). */
    private const PER_CODE_LOOKUP_LIMIT = 10;

    /** Sipariş sayfası en çok 100; sayfa 0'dan. */
    private const ORDER_PAGE_SIZE = 100;

    /** İade listesi sayfası — üst sınır belgesiz (DOĞRULANMADI). */
    private const RETURN_PAGE_SIZE = 100;

    /** "2 hafta" sınırı; kapsayıcı mı belgesiz, bir gün pay. */
    private const ORDER_WINDOW_DAYS = 13;

    /** Saat dilimi belirsizliği payı (sınıf notu). */
    private const ORDER_DATE_SLACK_HOURS = 3;

    /**
     * Pasif (iptal) satırlar için her turda en az bu kadar geriye — pay
     * dahil TEK dilime (≤13 gün) sığsın diye 12.
     */
    private const CANCEL_LOOKBACK_DAYS = 12;

    /** İade için her turda en az bu kadar geriye. */
    private const RETURN_LOOKBACK_DAYS = 27;

    /** İade listesi dilimi "en çok 1 ay" — Şubat dahil aşılmaz; 27 gün + pay tek dilim. */
    private const RETURN_WINDOW_DAYS = 28;

    private const CHANNEL_TIMEZONE = 'Europe/Istanbul';

    /**
     * Alt sipariş statüleri (§5) → panelin tanıdığı durum sözcüğü
     * (`Order` izin listesi, `format.js`). Tanınmayan olduğu gibi yazılır.
     */
    private const STATUS_WORDS = [
        1 => 'Created',             // Yeni
        2 => 'Picking',             // Hazırlanıyor
        11 => 'Picking',            // Kargoya Verilecek (henüz kargoda değil)
        3 => 'Shipped',             // Arabaya Verildi (servis aracı)
        5 => 'Shipped',             // Kargoya Verildi
        7 => 'Delivered',           // Teslim Edildi
        18 => 'ReturnedToSeller',   // Firmaya İade Edildi
        20 => 'ReturnRequested',    // İade Süreci Başladı
        21 => 'ReturnInTransit',    // İade Kargoda
        22 => 'ReturnAtSeller',     // İade Tedarikçide
        23 => 'ReturnAwaitingSeller', // İade Tedarikçi Onayı Bekliyor
    ];

    /** İade kaydı türü: 2 = iade (1 iptal, 3 müşteriye geri gönderilecek). */
    private const CANCEL_TYPE_RETURN = 2;

    /** İade nedeni türü: 2 = değişim. */
    private const CANCEL_REASON_EXCHANGE = 2;

    /** Karar kodları (resmi). */
    private const DECISION_CUSTOMER_RIGHT = 1;

    private const DECISION_SELLER_APPROVED = 4;

    /** İade Tedarikçide — ürün satıcıya ulaştı. */
    private const RETURN_AT_SELLER_STATUS = 22;

    /**
     * Bu turda "created" kaydı ÜRETİLEN siparişlerin kalemleri
     * (`orderItemId` → kalem).
     *
     * @var array<string, array<string, array<string, mixed>>>
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

    /** Türk pazaryeri; fiyatlar TL. */
    public function channelCurrency(): ?string
    {
        return 'TRY';
    }

    // ---------------------------------------------------------------- sağlık

    /**
     * `GET /Products?PageSize=1&Page=1` — anahtarı doğrular, yanıt küçük
     * (§5 · Sağlık). AYNI sorgu 10 dk'da bir atılabilir: saatlik tarama,
     * bağlanma ve elle "yeniden dene" üst üste gelebilir → `SortMethod` her
     * çağrıda 1–8 arasında döner (dokümanın önerisi). JSON `products`
     * taşımayan 200 sağlıklı SAYILMAZ.
     */
    public function healthCheck(): HealthResult
    {
        $startedAt = hrtime(true);

        try {
            $sequence = (int) Cache::increment('ciceksepeti:health-seq:'.$this->connection->id);
            $this->productQuery(['PageSize' => 1, 'Page' => 1, 'SortMethod' => (max(1, $sequence) - 1) % 8 + 1]);
        } catch (Throwable $e) {
            return HealthResult::unhealthy($e->getMessage());
        }

        return HealthResult::healthy((int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    /**
     * Kova saniyede 1'in altını ifade edemez; en sıkı hâl bildirilir. Asıl
     * 5 sn / 30 dk kuralları adapter içinde (sınıf notu).
     */
    public function rateLimitProfile(): RateLimitProfile
    {
        $profile = $this->connection->channelType?->rate_limit_profile;

        return is_array($profile) && $profile !== []
            ? RateLimitProfile::fromArray($profile)
            : new RateLimitProfile(requestsPerSecond: 1, burstCapacity: 1, maxConcurrent: 1);
    }

    /**
     * - HTML 403 → AUTHENTICATION (erişim engeli, sınıf notu).
     * - Yanlış anahtar: yalnız fatura ucunda "Geçersiz API Key" metni belgeli;
     *   HTTP kodu ve biçimi DOĞRULANMADI (ölçülemedi, istek Cloudflare'de
     *   takılıyor) → metin de taranır, 401/403 de kimlik hatasıdır.
     */
    public function classifyError(Throwable $e): ErrorClass
    {
        if ($e instanceof CiceksepetiFault) {
            return $e->errorClass;
        }

        if ($e instanceof ConnectionException || ! $e instanceof RequestException) {
            return ErrorClass::NETWORK;
        }

        if (self::isAccessBlocked($e->response)) {
            return ErrorClass::AUTHENTICATION;
        }

        $body = json_decode($e->response->body(), true);
        $text = mb_strtolower($e->response->body().' '.(is_array($body) ? (string) (self::pick($body, 'message') ?? '') : ''));

        if (str_contains($text, 'geçersiz api key') || str_contains($text, 'invalid api key')) {
            return ErrorClass::AUTHENTICATION;
        }

        $status = $e->response->status();

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

    /** Webhook yok (27 sayfanın hiçbirinde) — imzasız gövde ASLA kabul edilmez. */
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

    // ------------------------------------------------------------ içe aktarma

    /**
     * `GET /Products` — her satır BİR VARYANT. Kimlik `stockCode` (bütün
     * yazımlar ona göre), üst kimlik `mainProductCode`.
     *
     * Statü süzgeci YOK: "Stoğu Tükenen" ve "Satışa Kapalı" ürünler de
     * yönetilmeli (yalnız "Satışta" alınsaydı stoğu biten ürün bir daha hiç
     * stok alamazdı). İmleç sayfa numarasıdır (1'den). Alan adları örnek ile
     * tablo arasında tutarsız (tuzak 4) → harf duyarsız, birden çok ad.
     */
    public function fetchProductPage(?string $cursor = null): RemoteProductPage
    {
        $page = $cursor === null ? 1 : max(1, (int) $cursor);

        $body = $this->productQuery(['PageSize' => self::PRODUCT_PAGE_SIZE, 'Page' => $page]);
        $rows = self::rows(self::pick($body, 'products'));
        $total = self::pick($body, 'totalCount');

        $products = [];
        $withoutCode = 0;

        foreach ($rows as $row) {
            if (trim((string) self::pick($row, 'stockCode')) === '') {
                $withoutCode++;

                continue;
            }

            $products[] = $this->toRemoteProduct($row);
        }

        if ($withoutCode > 0) {
            Log::warning('ciceksepeti.product_without_stock_code', ['connection' => $this->connection->id, 'page' => $page, 'count' => $withoutCode]);
        }

        $hasMore = $rows !== [] && (is_numeric($total)
            ? $page * self::PRODUCT_PAGE_SIZE < (int) $total
            : count($rows) >= self::PRODUCT_PAGE_SIZE);

        return new RemoteProductPage(
            products: $products,
            nextCursor: $hasMore ? (string) ($page + 1) : null,
            hasMore: $hasMore,
        );
    }

    /**
     * Tur başına 50 sayfa × 60 = 3000 varyant. Sayfa arası 5 sn zorunlu ve
     * içe aktarma işi 300 sn'de kesilir (`ImportProductsFromChannelJob`) —
     * daha büyük katalog tek turda gelmez (rapor: çekirdekte devam imleci).
     */
    public function maxImportPages(): int
    {
        return 50;
    }

    /** @param array<string, mixed> $row */
    private function toRemoteProduct(array $row): RemoteProduct
    {
        $stockCode = trim((string) self::pick($row, 'stockCode'));
        $mainCode = trim((string) self::pick($row, 'mainProductCode'));
        $barcode = trim((string) self::pick($row, 'barcode'));
        $productCode = trim((string) self::pick($row, 'productCode'));
        $description = trim((string) self::pick($row, 'description'));
        $price = self::pick($row, 'salesPrice', 'totalPrice');
        $tracked = self::pick($row, 'isUseStockQuantity');
        $active = self::pick($row, 'isActive');

        $images = [];

        foreach ((array) (self::pick($row, 'images') ?? []) as $image) {
            $url = is_array($image) ? (string) (self::pick($image, 'url', 'imageUrl') ?? '') : (string) $image;

            if (str_starts_with($url, 'https://')) {
                $images[] = $url;
            }
        }

        return new RemoteProduct(
            externalId: $stockCode,
            sku: $stockCode,
            title: trim((string) self::pick($row, 'productName')) ?: $stockCode,
            price: is_numeric($price) ? self::money($price) : null,
            quantity: (int) (self::pick($row, 'stockQuantity', 'stock') ?? 0),
            description: $description !== '' ? $description : null,
            // Marka alanı yok (§5 · Ürün açma) — özellik olarak geçip geçmediği DOĞRULANMADI.
            brand: null,
            barcode: $barcode !== '' ? $barcode : null,
            status: self::productStatus(self::pick($row, 'productStatusType')),
            images: $images,
            raw: $row,
            listingIdentity: array_filter([
                // Kimlik METİN — `(int)` yok.
                'external_id' => $stockCode,
                'external_parent_id' => $mainCode !== '' ? $mainCode : null,
                'channel_metadata' => array_filter([
                    'product_code' => $productCode !== '' ? $productCode : null,
                    'category_id' => is_numeric(self::pick($row, 'categoryId')) ? (int) self::pick($row, 'categoryId') : null,
                    // false = stoksuz satış: kanal stok adedine bakmıyor.
                    'stock_tracked' => is_bool($tracked) ? $tracked : null,
                    'is_active' => is_bool($active) ? $active : null,
                ], static fn (mixed $v): bool => $v !== null),
            ], static fn (mixed $v): bool => $v !== null && $v !== []),
            currency: 'TRY',
        );
    }

    /**
     * `productStatusType` tabloda sayı, örnekte metin (`"YAYINDA"`) —
     * tuzak 4. Sayı yanıt tablosundaki anlamla, metin küçük harfle.
     */
    private static function productStatus(mixed $raw): ?string
    {
        if (is_numeric($raw)) {
            return match ((int) $raw) {
                1 => 'draft',
                2 => 'pending_approval',
                3 => 'published',
                4 => 'rejected',
                5 => 'passive',
                7 => 'published_pending_approval',
                8 => 'out_of_stock',
                default => (string) $raw,
            };
        }

        $text = mb_strtolower(trim((string) $raw));

        return $text !== '' ? $text : null;
    }

    // ---------------------------------------------------------------- stok

    /** MUTLAK stok — YALNIZ `stockCode` + `stockQuantity` (sınıf notu). */
    public function pushInventory(InventoryPushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            return AdapterResult::success(['pushed' => 0]);
        }

        $items = array_map(static fn (array $item): array => [
            'stockCode' => (string) $item['external_id'],
            'stockQuantity' => max(0, (int) $item['quantity']),
        ], $batch->toArray());

        return $this->pushStockPrice($items);
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
            $quantities[$code] = (int) (self::pick($row, 'stockQuantity', 'stock') ?? 0);
        }

        return new RemoteInventorySnapshot($quantities, new DateTimeImmutable);
    }

    // --------------------------------------------------------------- fiyat

    /**
     * MUTLAK fiyat — `stockCode` + `salesPrice`, koşullu `listPrice`; stok
     * alanı TAŞINMAZ (sınıf notu). Sıfır/negatif fiyat gönderilmez, kalem
     * bazında reddedilir.
     */
    public function pushPrices(PricePushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            return AdapterResult::success(['pushed' => 0]);
        }

        $items = [];
        $failed = [];

        foreach ($batch->items as $index => $item) {
            $price = round((float) $item['price'], 2);

            if ($price <= 0) {
                $failed[$index] = 'Çiçeksepeti sıfır ya da negatif satış fiyatı kabul etmez.';

                continue;
            }

            $compare = isset($item['compare_at_price']) && is_numeric($item['compare_at_price'])
                ? round((float) $item['compare_at_price'], 2)
                : null;

            $line = ['stockCode' => (string) $item['external_id'], 'salesPrice' => $price];

            // Üstü çizili fiyat yalnız gerçek indirimde ve fark %1–%80 iken.
            if ($compare !== null && $compare > $price) {
                $discount = ($compare - $price) / $compare;

                if ($discount >= 0.01 && $discount <= 0.80) {
                    $line['listPrice'] = $compare;
                }
            }

            $items[] = $line;
        }

        if ($items === []) {
            return AdapterResult::failure(ErrorClass::VALIDATION, (string) reset($failed));
        }

        $result = $this->pushStockPrice($items);

        if ($failed === [] || $result->failed()) {
            return $result;
        }

        $byOperation = [];

        foreach ($failed as $index => $message) {
            $operation = $batch->operations()[$index] ?? null;

            if ($operation === null) {
                return AdapterResult::failure(ErrorClass::VALIDATION, $message);
            }

            $byOperation[$operation->id] = $message;
        }

        return AdapterResult::partial(failedOperations: $byOperation, data: $result->data, errorClass: ErrorClass::VALIDATION);
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
            $price = self::pick($row, 'salesPrice', 'totalPrice');

            if (is_numeric($price)) {
                $prices[$code] = self::money($price);
            }
        }

        return new RemotePriceSnapshot($prices, new DateTimeImmutable);
    }

    /**
     * Stok ya da fiyat isteği — aynı-gövde kapısından ve 1 sn aralıktan
     * geçer (sınıf notu). Yanıt `{"batchId": "…"}`; kimliksiz 2xx belirsizdir
     * ve İSTİSNA olur ("sıraya alındı" denmeden uygulandı sayılamaz).
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function pushStockPrice(array $items): AdapterResult
    {
        $payload = ['items' => $items];
        $bodyKey = 'ciceksepeti:same-body:'.$this->connection->id.':'.sha1((string) json_encode($payload));
        $wait = $this->claimBody($bodyKey);

        if ($wait !== null) {
            return AdapterResult::failure(
                ErrorClass::RATE_LIMITED,
                'Çiçeksepeti aynı stok-fiyat isteğini 30 dakikada bir kabul eder; '.(int) ceil($wait / 60).' dk sonra yeniden denenecek.',
                $wait,
            );
        }

        $this->pace('stock-price', self::STOCK_PRICE_INTERVAL);

        try {
            $response = $this->api('PUT', '/Products/price-and-stock', body: $payload);
        } catch (RequestException $e) {
            // 429 = kanal kabul etmedi; kapı tutulsaydı yeniden deneme 30 dk
            // boşuna beklerdi.
            if ($e->response->status() === 429) {
                Cache::forget($bodyKey);
            }

            throw $e;
        }

        $batchId = self::pick(self::jsonBody($response), 'batchId');

        if (! is_string($batchId) || trim($batchId) === '') {
            throw new RuntimeException('Çiçeksepeti işlem kimliği (batchId) dönmedi: '.mb_substr(trim($response->body()), 0, 120));
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
     * İşlem sonucu — `GET /Products/batch-status/{batchId}` (API notları §5).
     *
     * Yanıt `{batchId, itemCount, items[{data{stockCode,…}, itemId, status,
     * failureReasons[{message, code}], lastModificationDate}]}`. Statüler
     * `Pending` · `Processing` · `Success` · `Failed` · `Warning`.
     *
     * - Tek bir satır bile `Pending`/`Processing` ise iş SÜRÜYOR.
     * - `Warning` = "başarılı ama kontrol edilmeli" → başarılı (fiyat yazıldı;
     *   ör. üstü çizili fiyat 30 günün en düşüğünden yüksek uyarısı).
     * - `Failed`: belge "teknik hata, aynı kalem yeniden gönderilmeli" diyor
     *   AMA örnekteki tek hata bilinmeyen stockCode (`code: 4000`). Bu yüzden
     *   4xxx kodlu sebep VALIDATION (kalıcı, satıcı düzeltir), kodsuz ya da
     *   başka kodlu sebep SERVER_ERROR (geçici, mutabakat yeniden gönderir).
     *   DOĞRULANMADI: kod aralığının anlamı.
     * - Alan adları harf duyarsız okunur (tuzak 4: `stockCode`/`StockCode`).
     * - Aynı iş dakikada bir sorgulanabilir — çekirdek turu buna uyar.
     */
    public function fetchBatchStatus(string $batchId, SyncDomain $domain): BatchStatus
    {
        try {
            $response = $this->api('GET', '/Products/batch-status/'.rawurlencode($batchId));
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                return BatchStatus::expired();
            }

            throw $e;
        }

        $items = self::rows(self::pick(self::jsonBody($response), 'items'));

        if ($items === []) {
            return BatchStatus::pending();
        }

        foreach ($items as $item) {
            if (in_array(strtolower((string) self::pick($item, 'status')), ['pending', 'processing'], true)) {
                return BatchStatus::pending();
            }
        }

        $failed = [];
        $succeeded = [];
        $unmatched = [];

        foreach ($items as $item) {
            $data = self::pick($item, 'data');
            $code = is_array($data) ? trim((string) self::pick($data, 'stockCode')) : '';
            $status = strtolower((string) self::pick($item, 'status'));

            if (in_array($status, ['success', 'warning'], true)) {
                if ($code !== '') {
                    $succeeded[] = $code;
                }

                continue;
            }

            if ($status !== 'failed') {
                continue;
            }

            $failure = self::batchFailure(self::rows(self::pick($item, 'failureReasons')));

            if ($code === '') {
                $unmatched[] = $failure->reason;

                continue;
            }

            $failed[$code] = $failure;
        }

        return BatchStatus::completed(failed: $failed, succeeded: $succeeded, unmatched: $unmatched);
    }

    /**
     * `failureReasons[{message, code}]` → tek sebep ve sınıf (bkz.
     * `fetchBatchStatus` notu: 4xxx kodu kalıcı, gerisi geçici).
     *
     * @param  list<array<string, mixed>>  $reasons
     */
    private static function batchFailure(array $reasons): BatchItemFailure
    {
        $texts = [];
        $validation = false;

        foreach ($reasons as $reason) {
            $message = trim((string) (self::pick($reason, 'message') ?? ''));
            $code = self::pick($reason, 'code');

            if ($message !== '') {
                $texts[] = is_scalar($code) && (string) $code !== '' ? "{$message} ({$code})" : $message;
            }

            if (is_numeric($code) && (int) $code >= 4000 && (int) $code < 5000) {
                $validation = true;
            }
        }

        return new BatchItemFailure(
            $texts === [] ? 'Çiçeksepeti satırı işleyemedi (sebep belirtilmedi).' : implode(' · ', array_unique($texts)),
            $validation ? ErrorClass::VALIDATION : ErrorClass::SERVER_ERROR,
        );
    }

    /**
     * Aynı gövde kapısı — atomik `add`: özet varsa kalan saniye, yoksa
     * alınır ve null. Değer kapının AÇILACAĞI an; önbellek süresi ondan geç
     * dolsa da karar bu ana göre verilir.
     */
    private function claimBody(string $key): ?int
    {
        $now = now()->getTimestamp();
        $until = $now + self::SAME_BODY_SECONDS;

        if (Cache::add($key, $until, self::SAME_BODY_SECONDS)) {
            return null;
        }

        $held = Cache::get($key);

        if (is_numeric($held) && (int) $held <= $now) {
            Cache::put($key, $until, self::SAME_BODY_SECONDS);

            return null;
        }

        return max(1, (is_numeric($held) ? (int) $held : $until) - $now);
    }

    /**
     * Uzak satırlar `stockCode` ile — stok ve fiyat ORTAK.
     *
     * KİMLİKSİZ LISTING SORULMAZ; hiç kimlik yoksa çağrı YAPILMAZ (süzgeçsiz
     * sorgu bütün kataloğu getirirdi). Az listing için `StockCode` süzgeciyle
     * tek tek (dönen satırın kodu yine karşılaştırılır — süzgecin tam eşleşme
     * yaptığı DOĞRULANMADI), çoksa tam tarama. Başarısız yanıt yükselir: boş
     * sonuç "kanalda yok" sanılırdı.
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
                $body = $this->productQuery(['StockCode' => (string) $code, 'PageSize' => self::PRODUCT_PAGE_SIZE, 'Page' => 1]);

                foreach (self::rows(self::pick($body, 'products')) as $row) {
                    if (trim((string) self::pick($row, 'stockCode')) === (string) $code) {
                        $found[(string) $code] = $row;
                    }
                }
            }

            return $found;
        }

        for ($page = 1; $page <= $this->maxImportPages(); $page++) {
            $body = $this->productQuery(['PageSize' => self::PRODUCT_PAGE_SIZE, 'Page' => $page]);
            $rows = self::rows(self::pick($body, 'products'));

            foreach ($rows as $row) {
                $code = trim((string) self::pick($row, 'stockCode'));

                if (isset($codes[$code])) {
                    $found[$code] = $row;
                }
            }

            if (count($rows) < self::PRODUCT_PAGE_SIZE || count($found) === count($codes)) {
                break;
            }
        }

        return $found;
    }

    /**
     * `GET /Products` — 5 sn aralığından geçer, gövde `products` taşımalı.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function productQuery(array $query): array
    {
        $this->pace('products', self::PRODUCTS_INTERVAL);

        return self::jsonBody($this->api('GET', '/Products', query: $query), 'products');
    }

    // ------------------------------------------------------------- siparişler

    /**
     * Yoklama: aktif dilimler → pasif dilimler → iade dilimleri, her biri
     * sayfalı. İmleç `{a|p|r}:{dilim}:{sayfa}` (sınıf notu).
     */
    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage
    {
        [$stage, $slice, $page] = $cursor !== null && preg_match('/^([apr]):(\d+):(\d+)$/', $cursor, $m) === 1
            ? [$m[1], (int) $m[2], (int) $m[3]]
            : ['a', 0, 0];

        $now = CarbonImmutable::now();
        $slack = self::ORDER_DATE_SLACK_HOURS;
        $from = CarbonImmutable::instance($since);
        $to = $now->addHours($slack);

        $windows = match ($stage) {
            'a' => self::windows($from->subHours($slack), $to, self::ORDER_WINDOW_DAYS),
            'p' => self::windows(self::earliest($from, $now->subDays(self::CANCEL_LOOKBACK_DAYS))->subHours($slack), $to, self::ORDER_WINDOW_DAYS),
            default => self::windows(self::earliest($from, $now->subDays(self::RETURN_LOOKBACK_DAYS)), $to, self::RETURN_WINDOW_DAYS),
        };

        if (! isset($windows[$slice])) {
            return match ($stage) {
                'a' => $this->fetchOrders($since, 'p:0:0'),
                'p' => $this->fetchOrders($since, 'r:0:0'),
                default => new OrderPage(orders: []),
            };
        }

        [$start, $end] = $windows[$slice];

        if ($stage === 'r') {
            [$rows, $full] = $this->returnList($start, $end, $page);
            $records = $this->returnRecords($rows);
        } else {
            [$rows, $full] = $this->orderList([
                'startDate' => $start,
                'endDate' => $end,
                'pageSize' => self::ORDER_PAGE_SIZE,
                'page' => $page,
                'isOrderStatusActive' => $stage === 'a',
            ]);
            $records = $stage === 'a' ? $this->activeRecords($rows, $full) : $this->cancelRecords($rows);
        }

        $next = match (true) {
            $full => "{$stage}:{$slice}:".($page + 1),
            isset($windows[$slice + 1]) => "{$stage}:".($slice + 1).':0',
            $stage === 'a' => 'p:0:0',
            $stage === 'p' => 'r:0:0',
            default => null,
        };

        return new OrderPage(orders: $records, nextCursor: $next, hasMore: $next !== null);
    }

    /**
     * Aralıksız dilimler, eskiden yeniye (her dilimin sonu bir sonrakinin
     * başı), ISO/UTC.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function windows(CarbonImmutable $from, CarbonImmutable $to, int $days): array
    {
        $windows = [];

        for ($start = $from; $start->lessThan($to); $start = $start->addDays($days)) {
            $end = $start->addDays($days);
            $windows[] = [self::isoUtc($start), self::isoUtc($end->greaterThan($to) ? $to : $end)];
        }

        return $windows;
    }

    private static function earliest(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a->lessThan($b) ? $a : $b;
    }

    private static function isoUtc(CarbonImmutable $at): string
    {
        return $at->setTimezone('UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * `GetOrders` sayfası — satırlar ve "sayfa dolu, devamı var mı".
     *
     * @param  array<string, mixed>  $body
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function orderList(array $body): array
    {
        $this->pace('orders', self::ORDERS_INTERVAL);

        $data = self::jsonBody($this->api('POST', '/Order/GetOrders', body: $body), 'supplierOrderListWithBranch');
        $rows = self::rows(self::pick($data, 'supplierOrderListWithBranch'));
        $count = self::pick($data, 'orderListCount');
        $page = (int) ($body['page'] ?? 0);

        $full = count($rows) >= self::ORDER_PAGE_SIZE
            && (! is_numeric($count) || ($page + 1) * self::ORDER_PAGE_SIZE < (int) $count);

        return [$rows, $full];
    }

    /**
     * `getcanceledorders` sayfası — İPTAL/İADE TARİHİNE göre (sınıf notu).
     *
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function returnList(string $start, string $end, int $page): array
    {
        $this->pace('returns', self::RETURNS_INTERVAL);

        $data = self::jsonBody($this->api('POST', '/Order/getcanceledorders', body: [
            'pageSize' => self::RETURN_PAGE_SIZE,
            'page' => $page,
            'cancellationStartDate' => $start,
            'cancellationEndDate' => $end,
        ]), 'orderItemList');

        $rows = self::rows(self::pick($data, 'orderItemList'));

        return [$rows, count($rows) >= self::RETURN_PAGE_SIZE];
    }

    /**
     * Aktif satırlar → ilk kez görülen siparişe "created", alınmış siparişe
     * durum anlık görüntüsü ("updated", stok hareketi yok).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function activeRecords(array $rows, bool $pageFull): array
    {
        $records = [];

        foreach (self::groupByOrder($rows) as $orderId => $group) {
            // Sayısal anahtar PHP'de tamsayıya döner; kimlik METİN kalmalı.
            $orderId = (string) $orderId;

            if ($this->createdKnown($orderId)) {
                $records[] = self::snapshot($orderId, self::items($group));

                continue;
            }

            // Dolu sayfada siparişin satırları sonraki sayfaya taşmış olabilir.
            if ($pageFull && ctype_digit($orderId)) {
                [$all] = $this->orderList(['orderNo' => (int) $orderId, 'pageSize' => self::ORDER_PAGE_SIZE, 'page' => 0, 'isOrderStatusActive' => true]);
                $group = [...$group, ...array_filter($all, static fn (array $row): bool => trim((string) self::pick($row, 'orderId')) === $orderId)];
            }

            $items = [];

            foreach (self::items($group) as $item) {
                // Pasif satır "created"e GİRMEZ (net sıfır, sınıf notu).
                if ($item['isOrderStatusActive'] !== false) {
                    $items[$item['orderItemId']] = $item;
                }
            }

            if ($items === []) {
                continue;
            }

            $this->createdThisRun[$orderId] = $items;
            $first = reset($items);

            $records[] = [
                '_kind' => 'created',
                'orderId' => $orderId,
                'orderCreateDate' => $first['orderCreateDate'],
                'orderCreateTime' => $first['orderCreateTime'],
                'items' => array_values($items),
            ];
        }

        return $records;
    }

    /**
     * Pasif satırlar → iptal kaydı; YALNIZ "created"e girmiş ve
     * `isOrderStatusActive: false` taşıyan satır için.
     * Başlık, siparişin "created"deki bütün satırları iptalse "Cancelled".
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function cancelRecords(array $rows): array
    {
        $records = [];

        foreach (self::groupByOrder($rows) as $orderId => $group) {
            $orderId = (string) $orderId;
            $created = $this->createdItems($orderId);

            if ($created === null) {
                continue;
            }

            $cancelled = [];

            foreach (self::items($group) as $item) {
                // Yalnız AÇIKÇA pasif satır: süzgeç yok sayılıp aktif satır
                // dönseydi satılmış ürün stoğa geri eklenirdi.
                if ($item['isOrderStatusActive'] === false && isset($created[$item['orderItemId']])) {
                    $cancelled[$item['orderItemId']] = $item;
                }
            }

            if ($cancelled === []) {
                continue;
            }

            $all = array_diff_key($created, $cancelled, array_flip($this->cancelledBefore($orderId))) === [];

            foreach ($cancelled as $itemId => $item) {
                $itemId = (string) $itemId;
                $records[] = [
                    '_kind' => 'cancelled',
                    'orderId' => $orderId,
                    'orderCancelled' => $all,
                    // Miktar "created"deki satırdan (stoğun düştüğü adet).
                    'item' => [...$created[$itemId], 'orderModifyDate' => $item['orderModifyDate'], 'orderModifyTime' => $item['orderModifyTime']],
                ];
            }
        }

        return $records;
    }

    /**
     * İade satırları → yalnız ürün döndüyse ve satır "created"e girmişse
     * (sınıf notu). Satırdan yalnız karar alanları alınır; müşteri adı ve
     * kişiselleştirme metni alınmaz.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function returnRecords(array $rows): array
    {
        $records = [];

        foreach ($rows as $row) {
            $orderId = trim((string) self::pick($row, 'orderId'));
            $itemId = trim((string) self::pick($row, 'orderItemId'));

            if ($orderId === '' || $itemId === ''
                || (int) self::pick($row, 'cancelType') !== self::CANCEL_TYPE_RETURN
                || (int) self::pick($row, 'cancelReasonId') === self::CANCEL_REASON_EXCHANGE
                || ! self::productCameBack($row)) {
                continue;
            }

            $created = $this->createdItems($orderId);

            if ($created === null) {
                continue;
            }

            if (! isset($created[$itemId])) {
                Log::warning('ciceksepeti.return_without_created_item', ['connection' => $this->connection->id, 'order' => $orderId, 'item' => $itemId]);

                continue;
            }

            $records[] = [
                '_kind' => 'returned',
                'orderId' => $orderId,
                'decision' => trim((string) self::pick($row, 'orderItemCancelStatus')),
                'item' => $created[$itemId],
            ];
        }

        return $records;
    }

    /**
     * Ürün satıcıya döndü mü: satıcı onayı ya da "Müşteri Haklı" + ürün
     * tedarikçide. Sayısal kod varsa o, yoksa metin (sınıf notu).
     *
     * @param  array<string, mixed>  $row
     */
    private static function productCameBack(array $row): bool
    {
        $decisionId = self::pick($row, 'orderItemCancelStatusId');
        $decision = mb_strtolower(trim((string) self::pick($row, 'orderItemCancelStatus')));
        $statusId = self::pick($row, 'orderItemStatusId');
        $status = mb_strtolower(trim((string) self::pick($row, 'orderItemStatus')));

        $sellerApproved = is_numeric($decisionId) ? (int) $decisionId === self::DECISION_SELLER_APPROVED : str_contains($decision, 'bayi onay');
        $customerRight = is_numeric($decisionId) ? (int) $decisionId === self::DECISION_CUSTOMER_RIGHT : str_contains($decision, 'müşteri haklı');
        $atSeller = is_numeric($statusId) ? (int) $statusId === self::RETURN_AT_SELLER_STATUS : str_contains($status, 'tedarikçide');

        return $sellerApproved || ($customerRight && $atSeller);
    }

    /**
     * Durum anlık görüntüsü — kimlik satır statülerinin imzası; statü
     * değişmedikçe aynı kayıt yeniden yazılmaz.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private static function snapshot(string $orderId, array $items): array
    {
        $statuses = [];
        $tracking = null;
        $carrier = null;
        $changedAt = null;

        foreach ($items as $item) {
            $statuses[(string) $item['orderItemId']] = (int) ($item['orderItemStatusId'] ?? 0);
            $tracking ??= trim((string) ($item['cargoNumber'] ?? '')) ?: null;
            $carrier ??= trim((string) ($item['cargoCompany'] ?? '')) ?: null;
            $at = self::orderDate($item['orderModifyDate'] ?? null, $item['orderModifyTime'] ?? null);
            $changedAt = $at !== null && ($changedAt === null || $at > $changedAt) ? $at : $changedAt;
        }

        ksort($statuses);
        $counts = array_count_values($statuses);
        arsort($counts);
        $status = (int) array_key_first($counts);

        return [
            '_kind' => 'updated',
            'orderId' => $orderId,
            'signature' => substr(sha1((string) json_encode($statuses)), 0, 16),
            'status' => self::STATUS_WORDS[$status] ?? (string) $status,
            'trackingNumber' => $tracking,
            'carrier' => $carrier,
            'changedAt' => $changedAt?->format(DATE_ATOM),
        ];
    }

    /**
     * `{orderId}:created` · `{orderId}:cancel:{orderItemId}` ·
     * `{orderId}:return:{orderItemId}` · `{orderId}:status:{imza}`.
     *
     * İptal/iade kimliği SATIRA bağlı: aynı siparişin ikinci kısmi iptali
     * sipariş düzeyindeki kimlikle YUTULURDU.
     *
     * @param  array<string, mixed>  $order
     */
    public function pollingEventIdFor(array $order): ?string
    {
        $orderId = trim((string) ($order['orderId'] ?? ''));

        if ($orderId === '') {
            return null;
        }

        $itemId = trim((string) ($order['item']['orderItemId'] ?? ''));

        return match ($order['_kind'] ?? null) {
            'created' => "{$orderId}:created",
            'cancelled' => $itemId !== '' ? "{$orderId}:cancel:{$itemId}" : null,
            'returned' => $itemId !== '' ? "{$orderId}:return:{$itemId}" : null,
            'updated' => isset($order['signature']) ? "{$orderId}:status:{$order['signature']}" : null,
            default => null,
        };
    }

    public function parseOrderEvent(InboxMessage $message): ?NormalizedOrderEvent
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($message->payload) ? $message->payload : [];
        $orderId = trim((string) ($payload['orderId'] ?? ''));
        $kind = $payload['_kind'] ?? null;

        if ($orderId === '' || ! in_array($kind, ['created', 'cancelled', 'returned', 'updated'], true)) {
            return null;
        }

        $ref = $message->external_event_id ?? $this->pollingEventIdFor($payload);

        if ($kind === 'cancelled' || $kind === 'returned') {
            $item = is_array($payload['item'] ?? null) ? $payload['item'] : [];

            return new NormalizedOrderEvent(
                type: $kind,
                externalOrderId: $orderId,
                externalRef: $ref,
                payload: array_filter([
                    'status' => $kind === 'cancelled' && ($payload['orderCancelled'] ?? false) === true ? 'Cancelled' : null,
                    'lines' => [array_filter([
                        'external_line_id' => (string) ($item['orderItemId'] ?? ''),
                        'sku' => trim((string) ($item['code'] ?? '')),
                        'quantity' => (int) ($item['quantity'] ?? 0),
                    ], static fn (mixed $v): bool => $v !== null && $v !== '')],
                ], static fn (mixed $v): bool => $v !== null),
                occurredAt: self::orderDate($item['orderModifyDate'] ?? null, $item['orderModifyTime'] ?? null),
            );
        }

        if ($kind === 'updated') {
            return new NormalizedOrderEvent(
                type: 'updated',
                externalOrderId: $orderId,
                externalRef: $ref,
                payload: array_filter([
                    'status' => (string) ($payload['status'] ?? ''),
                    'tracking_number' => $payload['trackingNumber'] ?? null,
                    'carrier' => $payload['carrier'] ?? null,
                ], static fn (mixed $v): bool => $v !== null && $v !== ''),
                occurredAt: is_string($payload['changedAt'] ?? null) ? new DateTimeImmutable($payload['changedAt']) : null,
            );
        }

        $lines = [];
        $subtotal = 0.0;
        $shipping = 0.0;
        $statuses = [];

        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $quantity = (int) ($item['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            // ⚠️ `totalPrice` ("Sipariş tutarı") satır tutarı sayıldı; yoksa
            // `itemPrice` ("Ürünün satış fiyatı") × adet. Resmi örnekte ikisi
            // tutarsız — DOĞRULANMADI. KDV dahil sayıldı.
            $total = is_numeric($item['totalPrice'] ?? null)
                ? (float) $item['totalPrice']
                : (float) ($item['itemPrice'] ?? 0) * $quantity;
            $subtotal += $total;
            // Kargo ücreti satırda; sipariş başına mı satır başına mı
            // belgesiz — toplanırsa çift sayılabilirdi, en büyüğü alınır.
            $shipping = max($shipping, (float) ($item['cargoPrice'] ?? 0));
            $statuses[] = (int) ($item['orderItemStatusId'] ?? 1);
            $code = trim((string) ($item['code'] ?? ''));

            $lines[] = [
                'external_line_id' => (string) $item['orderItemId'],
                'sku' => $code,
                // Listing kimliği de `stockCode` (içe aktarma).
                'external_variant_id' => $code !== '' ? $code : null,
                'title' => trim((string) ($item['name'] ?? '')) ?: (string) $item['orderItemId'],
                'quantity' => $quantity,
                'unit_price' => self::money($total / $quantity),
                'line_total' => self::money($total),
            ];
        }

        $placedAt = self::orderDate($payload['orderCreateDate'] ?? null, $payload['orderCreateTime'] ?? null);
        $status = $statuses === [] ? 1 : min($statuses);

        return new NormalizedOrderEvent(
            type: 'created',
            externalOrderId: $orderId,
            externalRef: $ref,
            payload: [
                'type' => 'created',
                'external_number' => $orderId,
                'status' => self::STATUS_WORDS[$status] ?? (string) $status,
                'currency' => 'TRY',
                'subtotal' => self::money($subtotal),
                'shipping_total' => self::money($shipping),
                'grand_total' => self::money($subtotal + $shipping),
                'lines' => $lines,
                // Müşteri kimliği de kişisel veri (§5) — taşınmaz.
                'customer_ref' => [],
            ],
            occurredAt: $placedAt,
            placedAt: $placedAt,
        );
    }

    /**
     * İSTEK ATMAZ — Çiçeksepeti'nde ayrı onay ucu yok, kargo adımı satıcının
     * modeline bağlı (sınıf notu).
     */
    public function acknowledgeOrder(Order $order): AdapterResult
    {
        return AdapterResult::success(['acknowledged' => false]);
    }

    // ------------------------------------------------------------------- iç

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private static function groupByOrder(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $orderId = trim((string) self::pick($row, 'orderId'));

            if ($orderId !== '') {
                $groups[$orderId][] = $row;
            }
        }

        return $groups;
    }

    /**
     * Satırlar, kişisel veriden arındırılmış (beyaz liste); kimliksiz satır
     * alınmaz, aynı satır bir kez.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function items(array $rows): array
    {
        $items = [];

        foreach ($rows as $row) {
            $id = trim((string) self::pick($row, 'orderItemId'));

            if ($id === '' || isset($items[$id])) {
                continue;
            }

            $active = self::pick($row, 'isOrderStatusActive');
            $history = [];

            foreach (self::rows(self::pick($row, 'orderItemStatusHistoryList')) as $entry) {
                $history[] = [
                    'orderItemStatusId' => self::pick($entry, 'orderItemStatusId'),
                    'transactionTime' => self::pick($entry, 'transactionTime'),
                ];
            }

            $items[$id] = [
                'orderItemId' => $id,
                'orderItemStatusId' => is_numeric(self::pick($row, 'orderItemStatusId')) ? (int) self::pick($row, 'orderItemStatusId') : null,
                // Metin "false" da gelebilir (tablo `string` diyor).
                'isOrderStatusActive' => is_bool($active) ? $active : (is_string($active) ? strtolower($active) !== 'false' : null),
                'code' => trim((string) self::pick($row, 'code')),
                'productCode' => self::pick($row, 'productCode'),
                'productId' => self::pick($row, 'productId'),
                'barcode' => self::pick($row, 'barcode'),
                'name' => self::pick($row, 'name'),
                'quantity' => (int) (self::pick($row, 'quantity') ?? 0),
                'quantityUnit' => self::pick($row, 'quantityUnit'),
                'itemPrice' => self::pick($row, 'itemPrice'),
                'totalPrice' => self::pick($row, 'totalPrice'),
                'discount' => self::pick($row, 'discount'),
                'tax' => self::pick($row, 'tax'),
                'cargoPrice' => self::pick($row, 'cargoPrice'),
                'cargoCompany' => self::pick($row, 'cargoCompany'),
                'cargoNumber' => self::pick($row, 'cargoNumber'),
                'cargoModelType' => self::pick($row, 'cargoModelType'),
                'deliveryType' => self::pick($row, 'deliveryType'),
                'cancellationResult' => self::pick($row, 'cancellationResult'),
                'orderCreateDate' => self::pick($row, 'orderCreateDate'),
                'orderCreateTime' => self::pick($row, 'orderCreateTime'),
                'orderModifyDate' => self::pick($row, 'orderModifyDate'),
                'orderModifyTime' => self::pick($row, 'orderModifyTime'),
                'history' => $history,
            ];
        }

        return array_values($items);
    }

    /** Sipariş daha önce alındı mı — önceki turda inbox'a ya da bu turda kayda. */
    private function createdKnown(string $orderId): bool
    {
        return isset($this->createdThisRun[$orderId]) || TenantContext::runAsSystem(fn (): bool => InboxMessage::query()
            ->where('channel_connection_id', $this->connection->id)
            ->where('external_event_id', "{$orderId}:created")
            ->exists());
    }

    /**
     * "created"e giren satırlar (`orderItemId` → satır); sipariş hiç
     * alınmadıysa null.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private function createdItems(string $orderId): ?array
    {
        if (isset($this->createdThisRun[$orderId])) {
            return $this->createdThisRun[$orderId];
        }

        $payload = TenantContext::runAsSystem(fn (): mixed => InboxMessage::query()
            ->where('channel_connection_id', $this->connection->id)
            ->where('external_event_id', "{$orderId}:created")
            ->first()?->payload);

        if (! is_array($payload)) {
            return null;
        }

        $items = [];

        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (is_array($item) && isset($item['orderItemId'])) {
                $items[(string) $item['orderItemId']] = $item;
            }
        }

        return $items;
    }

    /**
     * Önceki turlarda iptal kaydı yazılmış satırlar.
     *
     * @return list<string>
     */
    private function cancelledBefore(string $orderId): array
    {
        $prefix = "{$orderId}:cancel:";

        return TenantContext::runAsSystem(fn (): array => InboxMessage::query()
            ->where('channel_connection_id', $this->connection->id)
            ->where('external_event_id', 'like', $prefix.'%')
            ->pluck('external_event_id')
            ->map(static fn (string $id): string => substr($id, strlen($prefix)))
            ->all());
    }

    /**
     * Kimlikli API isteği.
     *
     * Anahtar YOKSA istek ATILMAZ: kimliksiz istek reddedilir ve "anahtarın
     * yanlış" diye kalıcı hataya düşerdi. HTML 403 erişim engelidir
     * ({@see CiceksepetiFault}); öteki hatalar `RequestException`.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function api(string $method, string $path, array $query = [], ?array $body = null): Response
    {
        $key = $this->readSecrets()[self::API_KEY_SECRET] ?? null;

        if (! is_string($key) || trim($key) === '') {
            throw new RuntimeException('Çiçeksepeti API anahtarı kasada yok — anahtarla yeniden bağlan.');
        }

        $sellerId = $this->setting(self::SELLER_ID_KEY);

        $response = $this->client->request(
            $method,
            $this->baseUrl().$path,
            body: $body,
            query: $query,
            headers: [
                'x-api-key' => trim($key),
                // Resmi biçim "<SatıcıId>-<EntegratörAdı>".
                'User-Agent' => $sellerId !== null ? $sellerId.'-'.self::INTEGRATOR : self::INTEGRATOR,
            ],
        );

        if (self::isAccessBlocked($response)) {
            throw new CiceksepetiFault(self::ACCESS_BLOCKED_MESSAGE, ErrorClass::AUTHENTICATION);
        }

        $response->throw();

        return $response;
    }

    /** HTML gövdeli 403 — kimlik kontrolüne ulaşmayan erişim engeli (sınıf notu). */
    private static function isAccessBlocked(Response $response): bool
    {
        if ($response->status() !== 403) {
            return false;
        }

        return str_contains(strtolower((string) $response->header('Content-Type')), 'text/html')
            || str_starts_with(ltrim($response->body()), '<');
    }

    private function baseUrl(): string
    {
        return strtolower((string) $this->setting(self::ENVIRONMENT_KEY)) === self::ENVIRONMENT_SANDBOX
            ? self::SANDBOX_BASE_URL
            : self::LIVE_BASE_URL;
    }

    /**
     * Uç nokta başına "farklı istek" aralığı — bağlantı başına önbellek
     * damgası; erken gelen istek kalan süre kadar BEKLER (sınıf notu).
     * Bekleme {@see MAX_INLINE_WAIT_SECONDS}'ı aşarsa (saat kayması, bozuk
     * damga) istek atılmaz, RATE_LIMITED yükselir.
     */
    private function pace(string $group, int $seconds): void
    {
        $key = "ciceksepeti:pace:{$group}:{$this->connection->id}";
        $now = (int) now()->getTimestampMs();
        $next = Cache::get($key);

        if (is_numeric($next) && (int) $next > $now) {
            $wait = (int) $next - $now;

            if ($wait > self::MAX_INLINE_WAIT_SECONDS * 1000) {
                throw new CiceksepetiFault('Çiçeksepeti istek aralığı dolmadı.', ErrorClass::RATE_LIMITED, (int) ceil($wait / 1000));
            }

            Sleep::for($wait)->milliseconds();
            $now = max((int) now()->getTimestampMs(), (int) $next);
        }

        Cache::put($key, $now + $seconds * 1000, $seconds + 60);
    }

    /**
     * Gövde JSON nesnesi olmalı (ve istenirse anahtarı taşımalı). Düz metin
     * ya da HTML 200 "hiç kayıt yok" sanılırdı — imleç ilerler, pencere bir
     * daha sorulmazdı.
     *
     * @return array<string, mixed>
     */
    private static function jsonBody(Response $response, ?string $requiredKey = null): array
    {
        $body = json_decode($response->body(), true);

        if (! is_array($body) || array_is_list($body) && $body !== []
            || ($requiredKey !== null && ! array_key_exists(strtolower($requiredKey), array_change_key_case($body, CASE_LOWER)))) {
            throw new RuntimeException('Çiçeksepeti beklenmeyen yanıt döndü: '.mb_substr(trim($response->body()), 0, 120));
        }

        return $body;
    }

    /**
     * İlk dolu alan — önce tam ad, sonra harf duyarsız (tuzak 4: `StockCode`
     * / `stockCode`, `StockQuantity` / `stock`).
     *
     * @param  array<string, mixed>  $row
     */
    private static function pick(array $row, string ...$names): mixed
    {
        $lower = null;

        foreach ($names as $name) {
            if (isset($row[$name])) {
                return $row[$name];
            }

            $lower ??= array_change_key_case($row, CASE_LOWER);

            if (isset($lower[strtolower($name)])) {
                return $lower[strtolower($name)];
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private static function money(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    /**
     * Sipariş tarihi `dd/MM/yyyy` + `HH:mm` (ayrı alanlar), Türkiye saati
     * sayıldı — DOĞRULANMADI (tuzak 6). `deliveryDate` örneğindeki
     * `20-01-2020` gün-önce biçimini doğruluyor.
     */
    private static function orderDate(mixed $date, mixed $time): ?DateTimeImmutable
    {
        if (! is_string($date) || preg_match('#^(\d{2})[/.-](\d{2})[/.-](\d{4})$#', trim($date), $m) !== 1) {
            return null;
        }

        $clock = is_string($time) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', trim($time)) === 1 ? trim($time) : '00:00';

        try {
            return new DateTimeImmutable("{$m[3]}-{$m[2]}-{$m[1]} {$clock}", new DateTimeZone(self::CHANNEL_TIMEZONE));
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
