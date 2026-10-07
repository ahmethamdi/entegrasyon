<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Hepsiburada;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresChannelCurrency;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
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
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use App\Domain\Sync\Support\OrderPage;
use App\Domain\Sync\Support\PricePushBatch;
use App\Domain\Sync\Support\RemoteInventorySnapshot;
use App\Domain\Sync\Support\RemotePriceSnapshot;
use App\Domain\Sync\Support\RemoteProduct;
use App\Domain\Sync\Support\RemoteProductPage;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Hepsiburada kanal adapter'ı — MPOP / listing-external REST API.
 *
 * Mimari Karar Dokümanı v2.2 · §7 (Adapter Architecture).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ DOKÜMAN BU KANALI KAPSAM DIŞI BIRAKIYOR — KULLANICI KARARIYLA AÇILDI
 * ─────────────────────────────────────────────────────────────────────
 * §16: "468 saatte dört kanal yüzeysel çalışır; iki kanal kusursuz
 * çalışır... Hepsiburada v2'de Faz 5'te 'belki' idi; v2.1'de kapsam
 * dışına alındı." Kapsam dışı tablosu da "Ay 7" diyor.
 *
 * Faz 4 bittiği (90/90 sa) ve 468 saatlik planın sonuna gelindiği için
 * bu madde kullanıcının açık kararıyla ele alındı. Doküman ihlali
 * DEĞİL — dokümanın kendi zaman çizelgesinin dışına çıkış.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UÇ NOKTALAR VE KİMLİK RESMÎ DOKÜMANDAN DOĞRULANDI (6 Eki 2026)
 * ─────────────────────────────────────────────────────────────────────
 * `docs/HEPSIBURADA-API-NOTLARI.md` + `docs/hepsiburada-openapi/`. Gerçek
 * hesapla henüz sınanmadı; **kanal `is_active = false`** kalır.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ÜÇ KRİTİK FARK — TRENDYOL'A BENZİYOR AMA AYNI DEĞİL
 * ─────────────────────────────────────────────────────────────────────
 *
 1. **KİMLİK = `merchantId` + Servis Anahtarı, `User-Agent` = ENTEGRATÖR
 *    KULLANICI ADI.** Basic auth kullanıcı adı satıcının `merchantId`'si
 *    (GUID), şifresi panelden alınan 12 karakterlik Servis Anahtarıdır
 *    (eski kullanıcı/şifre 15 Ağu 2024'te kapandı). `User-Agent` zorunlu
 *    ve HB'ye kayıtlı entegratör kullanıcı adını taşır; eksik ya da
 *    yanlışsa anahtar DOĞRU olsa bile reddedilir (`97a7eb7`'nin kardeşi).
 *    Önceki `{merchantId} - Entegrasyon` biçimi ikincil kaynaktandı.
 *
 * 2. **STOK VE FİYAT AYNI YÜKTE GİDER — TRENDYOL'UN TERSİ.** Trendyol'da
 *    "stok yükü fiyat alanı TAŞIMAZ" katı bir kuraldı çünkü orada biri
 *    diğerini SESSİZCE ezerdi. Hepsiburada'nın uç noktası ikisini
 *    birlikte bekliyor ve eksik alanı **sıfır** sayabiliyor — kanal
 *    "stok 0 veya fiyat 0 = satışa kapat" diye yorumluyor. Yani burada
 *    ayırmak, Trendyol'da birleştirmek kadar tehlikelidir.
 *
 *    Bu yüzden `pushInventory` ve `pushPrices` **mevcut değeri okuyup
 *    yükü tamamlamak zorundadır**; §7'nin "mutlak değer gönderilir"
 *    kuralı burada iki alana birden uygulanır.
 *
 3. **WEBHOOK VAR AMA İMZA YOK** — Trendyol'un aksine webhook var, Woo'nun
 *    aksine HMAC yok. Güvenlik Basic auth'tur: satıcı HB'ye bizim
 *    verdiğimiz kullanıcı adı/şifreyi bildirir, HB her bildirimde onu
 *    `Authorization` başlığında gönderir. Önceki `X-HB-Signature`
 *    doğrulaması MEŞRU her bildirimi reddederdi.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KAPSAM — BU TUR SADECE İSTEMCİ KATMANI
 * ─────────────────────────────────────────────────────────────────────
 * Yazılan: kimlik/başlık katmanı, sağlık kontrolü, hata sınıflandırma,
 * hız sınırı profili, webhook imza doğrulaması.
 *
 * YAZILMAYAN ve AÇIKÇA İSTİSNA FIRLATAN: stok/fiyat itme, sipariş
 * yoklama, katalog aktarımı, taksonomi. §7'nin açık yasağı gereği
 * **SESSİZCE BAŞARILI DÖNMEZLER**: `AdapterResult::success()` dönseydi
 * operasyon tamamlandı sanılır, `synced_version` ilerler ve satır
 * kanalda hiçbir şey değişmemişken "senkron" görünürdü.
 *
 * `SupportsCatalog` ve `SupportsTaxonomy` bu turda UYGULANMADI: yetenek
 * `instanceof` ile okunur ve ilan edilen ama çalışmayan bir yetenek,
 * panelde çalışmayan bir sekme demektir.
 */
final class HepsiburadaAdapter implements ChannelAdapter, DeclaresChannelCurrency, SupportsCatalogImport, SupportsInventory, SupportsOrders, SupportsPricing
{
    /** Türk pazaryeri — fiyatlar yalnızca TL. */
    public function channelCurrency(): ?string
    {
        return 'TRY';
    }

    use DeclaresRequestQuota;

    /** Satıcı kimliğinin (GUID) `settings` içindeki yeri. */
    public const MERCHANT_ID_KEY = 'merchant_id';

    /** HB'ye kayıtlı entegratör kullanıcı adı — `User-Agent`. SIR DEĞİL. */
    public const INTEGRATOR_KEY = 'integrator_username';

    /** Ortam: `test` (SIT) ya da `canli`. Yoksa canlı. */
    public const ENVIRONMENT_KEY = 'environment';

    public const ENVIRONMENT_TEST = 'test';

    public const ENVIRONMENT_LIVE = 'canli';

    /** Kasadaki Servis Anahtarı. */
    public const SERVICE_KEY_SECRET = 'service_key';

    /**
     * Toplu stok güncellemesinde tek istekteki üst sınır.
     *
     * İkincil kaynak 4000 diyor; **bilinçli olarak 1000'de tutuluyor**.
     * Gerekçe: sınır doğrulanmadı ve aşımın bedeli ağır — kanal isteği
     * kısmen işlerse hangi satırın gittiği bilinmez. Doğrulandığında
     * artırılabilir; küçük parti yalnızca daha çok istek demektir,
     * yanlış sonuç değil.
     */
    private const MAX_INVENTORY_BATCH = 1000;

    /**
     * İçe aktarma sayfası. Küçük tutulur: her ilan için katalogdan ayrıca
     * ad/görsel okunur (ilan listesi bunları taşımaz), yani sayfa başına
     * 1 + N istek atılır.
     */
    private const IMPORT_PAGE_SIZE = 50;

    /** Sipariş listesi sayfası. */
    private const ORDER_PAGE_SIZE = 100;

    /** Paket listesi sayfası — belgeli üst sınır 10. */
    private const PACKAGE_PAGE_SIZE = 10;

    /** Paket sorgusunun tarih aralığı — belgeli üst sınır 24 saat. */
    private const PACKAGE_WINDOW_HOURS = 24;

    /** İade talepleri için geriye bakış — talep açılışından onaya kadar geçen süreyi kapsar. */
    private const CLAIM_LOOKBACK_DAYS = 30;

    /** Saat dilimi belgelenmediği için sorgu penceresinin genişletilmesi. */
    private const ORDER_DATE_SLACK_HOURS = 3;

    /** Ofsetsiz kanal tarihleri için varsayılan dilim (DOĞRULANMADI). */
    private const CHANNEL_TIMEZONE = 'Europe/Istanbul';

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
     * Satıcının listeleri okunur ve gecikme ölçülür.
     *
     * Sağlık kontrolü geçmeden bağlantı `active` OLMAZ (§13 · faz 1.4):
     * aktif ama çalışmayan bağlantı en pahalı hata biçimidir.
     *
     * ÖZELLİKLE BU KANALDA DEĞERLİ: eksik `User-Agent` 401 üretir ve
     * sağlık kontrolü bunu bağlantı kurulurken yakalar — ilk gerçek
     * senkronda değil.
     */
    public function healthCheck(): HealthResult
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->client->get(
                endpoint: $this->listingPath(HepsiburadaEndpoints::LISTING_LIST),
                query: ['offset' => 0, 'limit' => 1],
                headers: $this->defaultHeaders(),
            );

            $latency = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            return $response->successful()
                ? HealthResult::healthy(latencyMs: $latency)
                : HealthResult::unhealthy("HTTP {$response->status()}");
        } catch (Throwable $e) {
            return HealthResult::unhealthy($e->getMessage());
        }
    }

    // ------------------------------------------------------------ hız sınırı

    /**
     * Sabit profil — Trendyol'un AKSİNE dinamik öğrenme YOK.
     *
     * Hepsiburada sınırı yanıt başlığında bildirmiyor (ikincil kaynak:
     * listing ~30 istek/sn, sipariş ~10 istek/sn). Öğrenilecek bir
     * başlık yokken "öğrenme" kodu yazmak, hiç çalışmayan ve hiç
     * sınanamayan bir yol bırakırdı.
     *
     * **EN DÜŞÜK SINIR SEÇİLİR** (sipariş uç noktası): kova bağlantı
     * başınadır ve tek bir kova iki farklı sınırı ayrı ayrı temsil
     * edemez. Yüksek sınırı seçmek, sipariş çağrılarını sürekli 429'a
     * sokardı; düşük sınırın bedeli yalnızca listing çağrılarının
     * olabileceğinden yavaş gitmesidir.
     */
    public function rateLimitProfile(): RateLimitProfile
    {
        $profile = $this->connection->channelType?->rate_limit_profile;

        return is_array($profile) && $profile !== []
            ? RateLimitProfile::fromArray($profile)
            : new RateLimitProfile(requestsPerSecond: 10, burstCapacity: 20);
    }

    // -------------------------------------------------------- sınıflandırma

    /**
     * Hepsiburada hatasını çekirdeğin anladığı sınıfa çevirir.
     *
     * SINIFLANDIRMA BURADA, KARAR ÇEKİRDEKTE (`RetryPolicy`).
     * `VALIDATION` ve `AUTHENTICATION` KALICIDIR.
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
     * BASIC AUTH — HB imza göndermez (resmî doküman, webhook bölümü).
     *
     * Satıcı HB'ye kullanıcı adı olarak kendi `merchantId`'sini, şifre
     * olarak kasadaki `webhook_secret`'ı bildirir; HB her bildirimde
     * `Authorization: Basic …` gönderir. Gövde imzalanmadığı için ham gövde
     * burada kullanılmaz.
     *
     * SABİT ZAMANLI KARŞILAŞTIRMA (`hash_equals`): `===` ilk farklı
     * baytta döner ve karşılaştırma süresi doğru ön ek uzunluğunu
     * SIZDIRIR. Zamanlama saldırısı işlevsel testte görünmez; kuralı
     * koruyan şey test değil bu yorumdur.
     *
     * KİRACI BAĞLAMI BEKLENMEZ: webhook anonim gelir ve kiracı ancak
     * bağlantı bulunduktan sonra bilinir. Kimlik bilgisi bu yüzden
     * `runAsSystem()` ile okunur — bağlam beklenirse MEŞRU HER WEBHOOK
     * sessizce reddedilir ve kanal sonsuza kadar yeniden gönderir
     * (§13 · faz 1.4'te yaşanmış hata).
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function verifyWebhookSignature(string $raw, array $headers): bool
    {
        $provided = $this->header($headers, 'authorization');

        if ($provided === null || ! str_starts_with(strtolower($provided), 'basic ')) {
            return false;
        }

        $secret = $this->webhookSecret();

        if ($secret === null || $secret === '') {
            // Şifre tanımlı değilse doğrulama YAPILAMAZ ve "geçti" denemez.
            // Güvenli taraf REDDETMEKTİR: kabul etmek, kimliksiz sipariş
            // enjeksiyonuna kapı açardı.
            return false;
        }

        try {
            $expected = 'basic '.base64_encode($this->merchantId().':'.$secret);
        } catch (Throwable) {
            return false;
        }

        // Şema adı harf duyarsız; kimlik kısmı duyarlı.
        return hash_equals($expected, 'basic '.substr($provided, 6));
    }

    /**
     * Olay kimliği — tekilleştirmenin BİRİNCİL çıpası (§4).
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function extractEventId(array $headers): ?string
    {
        return $this->header($headers, 'x-hb-event-id');
    }

    /** @param array<string, array<int, string|null>> $headers */
    public function extractEventType(array $headers): string
    {
        return $this->header($headers, 'x-hb-event-type') ?? 'unknown';
    }

    // ------------------------------------------------------------ içe aktarma

    /**
     * Satıcının Hepsiburada ilanları — `GET /Listings` (offset/limit).
     *
     * KİMLİK `hepsiburadaSku`'dur (HB'nin katalog kodu, kanal genelinde
     * tekil); SKU satıcının `merchantSku`'su. İkisi de stok/fiyat
     * gönderiminde gerekir; `merchantSku` `channel_metadata`'da saklanır.
     *
     * İLAN LİSTESİ AD, MARKA, GÖRSEL TAŞIMAZ. Her ilan katalog servisinden
     * (`all-products-of-merchant?hbSku=`) zenginleştirilir. Katalog yanıt
     * vermezse — ya da ilan başka satıcının açtığı bir katalog ürününe
     * bağlıysa ve kayıt dönmüyorsa (DOĞRULANMADI) — ilan YİNE alınır, ad
     * yerine SKU yazılır: ilanı düşürmek, satıcının satıştaki ürününü
     * 34Pazar'da görünmez yapardı.
     *
     * İmleç `offset`tir.
     */
    public function fetchProductPage(?string $cursor = null): RemoteProductPage
    {
        $offset = $cursor === null ? 0 : max(0, (int) $cursor);

        $response = $this->client->get(
            endpoint: $this->listingPath(HepsiburadaEndpoints::LISTING_LIST),
            query: ['offset' => $offset, 'limit' => self::IMPORT_PAGE_SIZE],
            headers: $this->defaultHeaders(),
        );

        $response->throw();

        $products = [];

        foreach ((array) ($response->json('listings') ?? []) as $listing) {
            if (! is_array($listing)) {
                continue;
            }

            $hbSku = trim((string) ($listing['hepsiburadaSku'] ?? ''));

            if ($hbSku === '') {
                continue;
            }

            $products[] = $this->toRemoteProduct($listing, $this->catalogProduct($hbSku));
        }

        $total = (int) ($response->json('totalCount') ?? 0);
        $next = $offset + self::IMPORT_PAGE_SIZE;
        $hasMore = $next < $total;

        return new RemoteProductPage(
            products: $products,
            nextCursor: $hasMore ? (string) $next : null,
            hasMore: $hasMore,
        );
    }

    /** Tur başına en fazla 100 sayfa — 50'lik sayfayla 5.000 ilan. */
    public function maxImportPages(): int
    {
        return 100;
    }

    /**
     * İlanın katalog bilgisi; bulunamazsa ya da servis hata verirse null.
     *
     * Hata YUTULUR ama günlüğe yazılır: zenginleştirme isteğe bağlıdır,
     * ilanın kendisi kaybolmamalı.
     *
     * @return array<string, mixed>|null
     */
    private function catalogProduct(string $hbSku): ?array
    {
        try {
            $response = $this->client->get(
                endpoint: HepsiburadaEndpoints::host(HepsiburadaEndpoints::SERVICE_CATALOG, $this->isTest())
                    .HepsiburadaEndpoints::path(
                        HepsiburadaEndpoints::CATALOG_MERCHANT_PRODUCTS,
                        ['merchantId' => $this->merchantId()],
                    ),
                query: ['hbSku' => $hbSku, 'page' => 0, 'size' => 1],
                headers: $this->defaultHeaders(),
            );
        } catch (Throwable $e) {
            Log::warning('hepsiburada.catalog_lookup_failed', ['hb_sku' => $hbSku, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('hepsiburada.catalog_lookup_failed', ['hb_sku' => $hbSku, 'status' => $response->status()]);

            return null;
        }

        $first = $response->json('data.0');

        return is_array($first) ? $first : null;
    }

    /**
     * @param  array<string, mixed>  $listing
     * @param  array<string, mixed>|null  $catalog
     */
    private function toRemoteProduct(array $listing, ?array $catalog): RemoteProduct
    {
        $hbSku = trim((string) $listing['hepsiburadaSku']);
        $merchantSku = trim((string) ($listing['merchantSku'] ?? ''));
        $text = static function (mixed $value): ?string {
            $value = is_scalar($value) ? trim((string) $value) : '';

            return $value === '' ? null : $value;
        };

        $images = [];

        foreach ((array) ($catalog['images'] ?? []) as $image) {
            $url = is_array($image) ? (string) ($image['url'] ?? '') : (string) $image;

            if (str_starts_with($url, 'https://')) {
                $images[] = $url;
            }
        }

        $salable = ($listing['isSalable'] ?? false) === true;
        $locked = ($listing['isLocked'] ?? false) === true;

        return new RemoteProduct(
            externalId: $hbSku,
            sku: $merchantSku !== '' ? $merchantSku : $hbSku,
            // Katalog adı yoksa SKU: adsız ürün panelde boş satır olurdu.
            title: $text($catalog['productName'] ?? null) ?? ($merchantSku !== '' ? $merchantSku : $hbSku),
            // Fiyat STRING kalır — float dönüşümü kuruş kayması üretir.
            price: isset($listing['price']) && is_numeric($listing['price']) ? (string) $listing['price'] : null,
            quantity: (int) ($listing['availableStock'] ?? 0),
            description: $text($catalog['description'] ?? null),
            brand: $text($catalog['brand'] ?? null),
            barcode: $text($catalog['barcode'] ?? null),
            status: $locked ? 'locked' : ($salable ? 'on_sale' : 'not_on_sale'),
            images: $images,
            raw: $listing,
            listingIdentity: [
                'external_id' => $hbSku,
                'channel_metadata' => array_filter([
                    'merchant_sku' => $merchantSku,
                    'listing_id' => $text($listing['listingId'] ?? null),
                ], static fn (?string $v): bool => $v !== null && $v !== ''),
            ],
            currency: 'TRY',
        );
    }

    // ------------------------------------------------------------- yetenekler

    /**
     * Stoğu MUTLAK değer olarak iter — `POST /Listings/.../stock-uploads`.
     *
     * AYRI UÇ: toplu `inventory-uploads` stok ve fiyatı birlikte alır ve tek
     * alan gönderilince ötekinin sıfırlanıp sıfırlanmadığı BELGELENMEMİŞ —
     * sıfırlanırsa satış kapanır. `stock-uploads` yalnız stoğu taşır.
     *
     * Kimlik `hepsiburadaSku` (listing `external_id`) + `merchantSku`
     * (varyant SKU'su). ASENKRON: yanıttaki `id` sonuçta taşınır; gerçekten
     * uygulandığını mutabakat (`fetchInventory`) doğrular.
     */
    public function pushInventory(InventoryPushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            return AdapterResult::success(['pushed' => 0]);
        }

        $items = array_map(
            static fn (array $item): array => array_filter([
                'hepsiburadaSku' => (string) $item['external_id'],
                'merchantSku' => isset($item['sku']) ? strtoupper((string) $item['sku']) : null,
                'availableStock' => $item['quantity'],
            ], static fn (mixed $v): bool => $v !== null && $v !== ''),
            $batch->toArray(),
        );

        return $this->upload(HepsiburadaEndpoints::STOCK_UPLOAD, $items, $batch->count());
    }

    public function maxInventoryBatchSize(): int
    {
        return self::MAX_INVENTORY_BATCH;
    }

    /**
     * Uzak stoğu okur — `GET /Listings?hbSkuList=` (mutabakat girdisi).
     *
     * @param  list<Listing>  $listings
     */
    public function fetchInventory(array $listings): RemoteInventorySnapshot
    {
        $quantities = [];

        foreach ($this->remoteListings($listings) as $hbSku => $row) {
            $quantities[$hbSku] = (int) ($row['availableStock'] ?? 0);
        }

        return new RemoteInventorySnapshot($quantities, new DateTimeImmutable);
    }

    /**
     * Fiyatı MUTLAK değer olarak iter — `POST /Listings/.../price-uploads`.
     *
     * Simetrik kural: fiyat yükü stok taşımaz. HB'nin fiyat bandı kuralı
     * (`OutOfPriceRange`) yüklemenin SONUCUNDA döner, yanıtta değil.
     */
    public function pushPrices(PricePushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            return AdapterResult::success(['pushed' => 0]);
        }

        $merchantSkus = $this->merchantSkusFor(array_column($batch->items, 'listing_id'));

        $items = array_map(
            static fn (array $item): array => array_filter([
                'hepsiburadaSku' => (string) $item['external_id'],
                'merchantSku' => $merchantSkus[$item['listing_id']] ?? null,
                // Para STRING'den sayıya burada, kanal sınırında çevrilir.
                'price' => round((float) $item['price'], 2),
            ], static fn (mixed $v): bool => $v !== null && $v !== ''),
            $batch->items,
        );

        return $this->upload(HepsiburadaEndpoints::PRICE_UPLOAD, $items, $batch->count());
    }

    public function maxPriceBatchSize(): int
    {
        return self::MAX_INVENTORY_BATCH;
    }

    /**
     * Uzak fiyatı okur. Fiyat STRING taşınır (kuruş kayması olmasın).
     *
     * @param  list<Listing>  $listings
     */
    public function fetchPrices(array $listings): RemotePriceSnapshot
    {
        $prices = [];

        foreach ($this->remoteListings($listings) as $hbSku => $row) {
            if (isset($row['price']) && is_numeric($row['price'])) {
                $prices[$hbSku] = (string) $row['price'];
            }
        }

        return new RemotePriceSnapshot($prices, new DateTimeImmutable);
    }

    /**
     * Yükleme isteği — başarısızlık İSTİSNA olarak yükselir (§7).
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function upload(string $template, array $items, int $count): AdapterResult
    {
        $response = $this->client->post(
            endpoint: $this->listingPath($template),
            body: $items,
            headers: $this->defaultHeaders(),
        );

        $response->throw();

        return AdapterResult::success([
            'pushed' => $count,
            // Asenkron işin kimliği — sonuç `…-uploads/id/{id}`.
            'upload_id' => $response->json('id'),
        ]);
    }

    /**
     * Uzak ilan satırları `hepsiburadaSku` ile — stok ve fiyat okuması ORTAK.
     *
     * Kimliksiz listing sorulmaz; hiç kimlik yoksa çağrı YAPILMAZ (filtresiz
     * istek bütün kataloğu getirirdi). Başarısız yanıt yükseltilir: boş
     * sonuç mutabakatta "kanalda ürün yok" sanılırdı.
     *
     * @param  list<Listing>  $listings
     * @return array<string, array<string, mixed>>
     */
    private function remoteListings(array $listings): array
    {
        $hbSkus = array_values(array_unique(array_filter(array_map(
            static fn (Listing $listing): string => (string) ($listing->external_id ?? ''),
            $listings,
        ))));

        $rows = [];

        foreach (array_chunk($hbSkus, 100) as $chunk) {
            $response = $this->client->get(
                endpoint: $this->listingPath(HepsiburadaEndpoints::LISTING_LIST),
                query: ['offset' => 0, 'limit' => count($chunk), 'hbSkuList' => implode(',', $chunk)],
                headers: $this->defaultHeaders(),
            );

            $response->throw();

            foreach ((array) ($response->json('listings') ?? []) as $row) {
                if (is_array($row) && isset($row['hepsiburadaSku'])) {
                    $rows[(string) $row['hepsiburadaSku']] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Listing kimliğinden `merchantSku` — içe aktarmanın `channel_metadata`'ya
     * yazdığı değer, yoksa varyant SKU'su.
     *
     * @param  list<string>  $listingIds
     * @return array<string, string>
     */
    private function merchantSkusFor(array $listingIds): array
    {
        $out = [];

        foreach (Listing::query()->with('variant')->whereIn('id', $listingIds)->get() as $listing) {
            $sku = $listing->channel_metadata['merchant_sku'] ?? $listing->variant?->sku;

            if (is_string($sku) && $sku !== '') {
                $out[$listing->id] = strtoupper($sku);
            }
        }

        return $out;
    }

    /**
     * Sipariş yoklaması — dört liste sırayla: açık (paketlenmemiş) kalemler,
     * paketler, iptaller, onaylanmış iadeler. İmleç `open:{offset}` ·
     * `packages:{pencere}:{offset}` · `cancelled:{offset}` · `claims:{offset}`.
     *
     * SİPARİŞ BİRİMİ `orderNumber`, kalem `id` (lineItemId). Görülen her YENİ
     * sipariş tek "created" olayı olur ve kalemleri sipariş DETAYINDAN
     * kurulur (`completeOrder`).
     *
     * ⚠️ NEDEN PAKETLER: `/orders` yalnız PAKETLENMEMİŞ kalemleri verir.
     * Satıcı siparişi iki tur arasında paketlerse sipariş o listeden düşer ve
     * stoğu HİÇ düşmezdi. Paket listesi o siparişleri yakalar.
     *
     * ⚠️ SAAT DİLİMİ BELGELENMEMİŞ (`begindate` örneği `2023-04-02 00:00`).
     * Pencere 3 saat geriye GENİŞLETİLİR: dilim UTC de olsa TR de olsa
     * sipariş kaçmaz; tekrar gelen kayıt olay kimliğiyle elenir.
     */
    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage
    {
        [$list, $window, $offset] = $this->orderCursor($cursor);

        return match ($list) {
            'open' => $this->fetchOpenLines($since, $offset),
            'packages' => $this->fetchPackages($since, $window, $offset),
            'cancelled' => $this->fetchCancelledLines($since, $offset),
            'claims' => $this->fetchAcceptedClaims($offset),
        };
    }

    /**
     * Açık kalemler — siparişe göre gruplanır.
     *
     * ⚠️ SAYFA SINIRINDA YARIM SİPARİŞ ERTELENİR. Detay okunamazsa (404)
     * sipariş listedeki kalemlerle kurulur; o durumda kalemleri iki sayfaya
     * bölünmüş sipariş ikinci parçasını "zaten alınmış" diye kaybederdi.
     * Sayfanın son siparişi, devamı varsa bir sonraki sayfaya bırakılır.
     */
    private function fetchOpenLines(CarbonInterface $since, int $offset): OrderPage
    {
        $response = $this->orderList(HepsiburadaEndpoints::ORDERS, $since, $offset);

        $items = array_values(array_filter((array) ($response->json('items') ?? []), 'is_array'));
        $total = (int) ($response->json('totalCount') ?? 0);
        $more = $offset + count($items) < $total && $items !== [];

        [$groups, $consumed] = self::groupOpenLines($items, $more);

        // Açık liste bitti → paketlere geç.
        return new OrderPage(
            orders: $this->completeOrders($groups),
            nextCursor: $more ? 'open:'.($offset + $consumed) : 'packages:0:0',
            hasMore: true,
        );
    }

    /**
     * Paketler — `GET /packages` (yalnız "open" = gönderime hazır paketler).
     *
     * ⚠️ ÜÇ BELGELİ KISIT:
     *   - `begindate`–`enddate` ≤24 saat; uzunsa `enddate` SESSİZCE yok
     *     sayılır ve yalnız ilk 24 saat döner → pencere 24 saatlik
     *     dilimlerle yürünür, imleç dilimin sırasını taşır.
     *   - `limit` ≤10.
     *   - Sayfalama bilgisi GÖVDEDE değil yanıt BAŞLIĞINDA (adı belgesiz) →
     *     başlık okunabilirse o, okunamazsa "sayfa dolu geldiyse devamı var".
     */
    private function fetchPackages(CarbonInterface $since, int $window, int $offset): OrderPage
    {
        $begin = $since->copy()->subHours(self::ORDER_DATE_SLACK_HOURS);
        $end = now()->addHours(self::ORDER_DATE_SLACK_HOURS);
        $from = $begin->copy()->addHours($window * self::PACKAGE_WINDOW_HOURS);

        if ($from->greaterThanOrEqualTo($end)) {
            return new OrderPage(orders: [], nextCursor: 'cancelled:0', hasMore: true);
        }

        $to = $from->copy()->addHours(self::PACKAGE_WINDOW_HOURS);
        $to = $to->greaterThan($end) ? $end : $to;

        $response = $this->client->get(
            endpoint: $this->orderPath(HepsiburadaEndpoints::PACKAGES),
            query: [
                'offset' => $offset,
                'limit' => self::PACKAGE_PAGE_SIZE,
                'begindate' => $from->copy()->setTimezone(self::CHANNEL_TIMEZONE)->format('Y-m-d H:i'),
                'enddate' => $to->copy()->setTimezone(self::CHANNEL_TIMEZONE)->format('Y-m-d H:i'),
            ],
            headers: $this->defaultHeaders(),
        );

        // Boş listeye 200 `[]` mı 404 mü döndüğü BELGESİZ. 404 dilimi boş
        // sayar ama GÜNLÜĞE yazar: tur düşseydi açık sipariş ve iptaller de
        // okunmazdı; sessiz geçseydi yanlış yol hiç fark edilmezdi.
        if ($response->status() === 404) {
            Log::warning('hepsiburada.packages_not_found', ['connection' => $this->connection->id, 'window' => $window]);

            return new OrderPage(orders: [], nextCursor: 'packages:'.($window + 1).':0', hasMore: true);
        }

        $response->throw();

        $body = $response->json();
        $packages = array_is_list((array) $body) ? (array) $body : (array) ($body['items'] ?? []);
        $packages = array_values(array_filter($packages, 'is_array'));

        $groups = [];

        foreach ($packages as $package) {
            foreach ((array) ($package['items'] ?? []) as $line) {
                $number = is_array($line) ? trim((string) ($line['orderNumber'] ?? '')) : '';

                if ($number !== '') {
                    $groups[$number][] = self::packageLineAsOrderLine($line);
                }
            }
        }

        $total = $this->headerTotal($response->headers());
        $more = $total !== null
            ? $offset + count($packages) < $total && $packages !== []
            : count($packages) >= self::PACKAGE_PAGE_SIZE;

        return new OrderPage(
            orders: $this->completeOrders($groups),
            nextCursor: $more ? "packages:{$window}:".($offset + count($packages)) : 'packages:'.($window + 1).':0',
            hasMore: true,
        );
    }

    /** Kalem iptalleri — son 1 ay (belgeli). */
    private function fetchCancelledLines(CarbonInterface $since, int $offset): OrderPage
    {
        $response = $this->orderList(HepsiburadaEndpoints::ORDERS_CANCELLED, $since, $offset);

        $items = array_values(array_filter((array) ($response->json('items') ?? []), 'is_array'));
        $total = (int) ($response->json('totalCount') ?? 0);
        $more = $offset + count($items) < $total && $items !== [];

        // İptaller bitti → iadeler.
        return new OrderPage(
            orders: array_map(static fn (array $line): array => ['_kind' => 'cancelled', ...$line], $items),
            nextCursor: $more ? 'cancelled:'.($offset + count($items)) : 'claims:0',
            hasMore: true,
        );
    }

    /**
     * Onaylanmış iade talepleri — `GET /claims` (talep-iade servisi).
     *
     * ⚠️ YALNIZ `Accepted` STOĞA DÖNER: satıcı ürünü teslim alıp iadeyi
     * onaylamıştır. `Refunded` tek başına ürünün döndüğünü söylemez (ürün
     * gelmeden para iadesi olabilir); `NewRequest`/`AwaitingAction` henüz
     * yolda. Erken stoğa eklenseydi gelmeyen ürün satılırdı.
     *
     * ⚠️ TARİH SÜZGECİ TALEBİN AÇILIŞINA bakar, onayına değil: talep günler
     * sonra onaylanır. Bu yüzden pencere yoklama imlecinden değil, son
     * {@see CLAIM_LOOKBACK_DAYS} günden kurulur; aynı talep her turda yine
     * gelir ve olay kimliğiyle (`{no}:return:{talep no}`) elenir.
     *
     * ⚠️ TALEPTEKİ `sku` HB'NİN KODUDUR (HBV…), sipariş satırı ise satıcının
     * SKU'sunu taşır — doğrudan eşlenseydi iade hiçbir satırla tutmaz ve
     * stok dönmezdi. Kod, ilanın `merchant_sku`'suna çevrilir.
     */
    private function fetchAcceptedClaims(int $offset): OrderPage
    {
        $response = $this->client->get(
            endpoint: $this->orderPath(HepsiburadaEndpoints::CLAIMS),
            query: [
                'offset' => $offset,
                'limit' => self::ORDER_PAGE_SIZE,
                'beginDate' => now()->subDays(self::CLAIM_LOOKBACK_DAYS)->setTimezone(self::CHANNEL_TIMEZONE)->format('Y-m-d H:i'),
                'endDate' => now()->addHours(self::ORDER_DATE_SLACK_HOURS)->setTimezone(self::CHANNEL_TIMEZONE)->format('Y-m-d H:i'),
            ],
            headers: $this->defaultHeaders(),
        );

        // Paket listesiyle aynı karar: 404 turu düşürmez (açık sipariş ve
        // iptaller yine işlensin) ama günlüğe yazılır.
        if ($response->status() === 404) {
            Log::warning('hepsiburada.claims_not_found', ['connection' => $this->connection->id]);

            return new OrderPage(orders: [], nextCursor: null, hasMore: false);
        }

        $response->throw();

        $body = $response->json();
        $claims = array_values(array_filter(array_is_list((array) $body) ? (array) $body : (array) ($body['items'] ?? []), 'is_array'));
        $accepted = array_values(array_filter($claims, static fn (array $claim): bool => ($claim['status'] ?? null) === 'Accepted'));
        $skus = $this->merchantSkusByHbSku(array_map(static fn (array $c): string => trim((string) ($c['sku'] ?? '')), $accepted));

        $orders = [];

        foreach ($accepted as $claim) {
            $hbSku = trim((string) ($claim['sku'] ?? ''));

            $orders[] = [
                '_kind' => 'returned',
                'orderNumber' => (string) ($claim['orderNumber'] ?? ''),
                'claimNumber' => (string) ($claim['number'] ?? $claim['id'] ?? ''),
                'sku' => $skus[$hbSku] ?? strtoupper($hbSku),
                'quantity' => (int) ($claim['quantity'] ?? 0),
                'claimType' => $claim['claimType'] ?? null,
                'claimDate' => $claim['claimDate'] ?? null,
            ];
        }

        $more = count($claims) >= self::ORDER_PAGE_SIZE;

        return new OrderPage(
            orders: $orders,
            nextCursor: $more ? 'claims:'.($offset + count($claims)) : null,
            hasMore: $more,
        );
    }

    /**
     * HB kodu → satıcı SKU'su (büyük harf), bu bağlantının ilanlarından.
     *
     * @param  list<string>  $hbSkus
     * @return array<string, string>
     */
    private function merchantSkusByHbSku(array $hbSkus): array
    {
        $hbSkus = array_values(array_unique(array_filter($hbSkus)));

        if ($hbSkus === []) {
            return [];
        }

        $listings = TenantContext::runAsSystem(fn () => Listing::query()
            ->with('variant')
            ->where('channel_connection_id', $this->connection->id)
            ->whereIn('external_id', $hbSkus)
            ->get());

        $out = [];

        foreach ($listings as $listing) {
            $sku = $listing->channel_metadata['merchant_sku'] ?? $listing->variant?->sku;

            if (is_string($sku) && $sku !== '') {
                $out[(string) $listing->external_id] = strtoupper($sku);
            }
        }

        return $out;
    }

    /** `{items, totalCount}` biçimli sipariş listesi (açık / iptal). */
    private function orderList(string $template, CarbonInterface $since, int $offset): Response
    {
        $response = $this->client->get(
            endpoint: $this->orderPath($template),
            query: [
                'offset' => $offset,
                'limit' => self::ORDER_PAGE_SIZE,
                'begindate' => $since->copy()->subHours(self::ORDER_DATE_SLACK_HOURS)
                    ->setTimezone(self::CHANNEL_TIMEZONE)->format('Y-m-d H:i'),
                'enddate' => now()->addHours(self::ORDER_DATE_SLACK_HOURS)
                    ->setTimezone(self::CHANNEL_TIMEZONE)->format('Y-m-d H:i'),
            ],
            headers: $this->defaultHeaders(),
        );

        $response->throw();

        return $response;
    }

    /**
     * Gruplanmış siparişleri "created" kayıtlarına çevirir — kalemler DETAYDAN.
     *
     * ⚠️ KISMİ PAKETLEME: A ve B kalemli siparişte yalnız A paketlenirse açık
     * listede B, paket listesinde A görünür. Sipariş hangisinden önce
     * kurulursa kursun ikincisi aynı olay kimliğiyle (`{no}:created`)
     * tekilleştirmede YUTULUR ve o kalemin stoğu hiç düşmezdi. Detay
     * siparişin bütün kalemlerini verir; iptal edilmiş kalem alınmaz.
     *
     * Daha önce alınmış sipariş için detay İSTENMEZ: liste aynı siparişi her
     * turda yeniden döndürür, her seferinde detay okumak kotayı boşa harcardı.
     *
     * @param  array<string, list<array<string, mixed>>>  $groups  sipariş no → listedeki kalemler
     * @return list<array<string, mixed>>
     */
    private function completeOrders(array $groups): array
    {
        $orders = [];

        foreach ($groups as $number => $lines) {
            $number = (string) $number;

            if ($this->alreadyIngested($number)) {
                continue;
            }

            $orders[] = ['_kind' => 'created', 'orderNumber' => $number, 'items' => $this->orderDetailLines($number) ?? $lines];
        }

        return $orders;
    }

    /**
     * Siparişin iptal edilmemiş kalemleri; sipariş detayda yoksa (404) null.
     *
     * 404 dışındaki hata YÜKSELTİLİR: tur başarısız sayılır, imleç ilerlemez
     * ve sipariş bir sonraki turda yeniden sorulur. Listedeki kalemlerle
     * yetinmek kısmi paketlemede kalem kaybettirebilirdi.
     *
     * @return list<array<string, mixed>>|null
     */
    private function orderDetailLines(string $number): ?array
    {
        $response = $this->client->get(
            endpoint: HepsiburadaEndpoints::host(HepsiburadaEndpoints::SERVICE_ORDER, $this->isTest())
                .HepsiburadaEndpoints::path(
                    HepsiburadaEndpoints::ORDER_DETAIL,
                    ['merchantId' => $this->merchantId(), 'orderNumber' => $number],
                ),
            headers: $this->defaultHeaders(),
        );

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        $lines = array_values(array_filter(
            (array) ($response->json('items') ?? []),
            static fn (mixed $line): bool => is_array($line)
                && ! str_contains(strtolower((string) ($line['status'] ?? '')), 'cancel')
                // Başka siparişin kalemi karışmasın; numara yoksa kabul.
                && (string) ($line['orderNumber'] ?? $number) === $number,
        ));

        return $lines === [] ? null : $lines;
    }

    /** Bu sipariş daha önce inbox'a "created" olarak yazıldı mı? */
    private function alreadyIngested(string $number): bool
    {
        return TenantContext::runAsSystem(fn (): bool => InboxMessage::query()
            ->where('channel_connection_id', $this->connection->id)
            ->where('external_event_id', "{$number}:created")
            ->exists());
    }

    /**
     * Paket kalemini açık sipariş kalemi biçimine çevirir — detay okunamazsa
     * `parseOrderEvent` aynı alan adlarıyla çalışsın.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private static function packageLineAsOrderLine(array $line): array
    {
        return array_filter([
            'id' => $line['lineItemId'] ?? null,
            'orderNumber' => $line['orderNumber'] ?? null,
            'orderDate' => $line['orderDate'] ?? null,
            'merchantSKU' => $line['merchantSku'] ?? null,
            'sku' => $line['hbSku'] ?? null,
            'name' => $line['productName'] ?? null,
            'quantity' => $line['quantity'] ?? null,
            'unitPrice' => $line['price'] ?? null,
            'totalPrice' => $line['totalPrice'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * Paket listesinin toplam kaydı — başlık adı belgesiz, bilinen adaylar
     * harf duyarsız denenir. Bulunamazsa null.
     *
     * @param  array<string, array<int, string>>  $headers
     */
    private function headerTotal(array $headers): ?int
    {
        foreach (['totalcount', 'x-total-count', 'x-totalcount'] as $name) {
            $value = $this->header($headers, $name);

            if ($value !== null && is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function orderPath(string $template): string
    {
        return HepsiburadaEndpoints::host(HepsiburadaEndpoints::SERVICE_ORDER, $this->isTest())
            .HepsiburadaEndpoints::path($template, ['merchantId' => $this->merchantId()]);
    }

    /**
     * Açık kalemleri `orderNumber`'a göre gruplar. Devamı olan sayfanın SON
     * siparişi ertelenir (sınıf notu); tek sipariş bütün sayfayı
     * dolduruyorsa sonsuz döngü olmasın diye alınır.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{0: array<string, list<array<string, mixed>>>, 1: int} [sipariş no → kalemler, tüketilen kalem]
     */
    private static function groupOpenLines(array $items, bool $more): array
    {
        $groups = [];

        foreach ($items as $index => $line) {
            $number = (string) ($line['orderNumber'] ?? '');

            if ($number === '') {
                continue;
            }

            $groups[$number] ??= ['first' => $index, 'lines' => []];
            $groups[$number]['lines'][] = $line;
        }

        $consumed = count($items);

        if ($more && count($groups) > 1) {
            $last = array_key_last($groups);
            $consumed = $groups[$last]['first'];
            unset($groups[$last]);
        }

        return [array_map(static fn (array $group): array => $group['lines'], $groups), $consumed];
    }

    /** @return array{0: 'open'|'packages'|'cancelled'|'claims', 1: int, 2: int} [liste, pencere, offset] */
    private function orderCursor(?string $cursor): array
    {
        if ($cursor !== null && preg_match('/^packages:(\d+):(\d+)$/', $cursor, $m) === 1) {
            return ['packages', (int) $m[1], (int) $m[2]];
        }

        if ($cursor !== null && preg_match('/^(open|cancelled|claims):(\d+)$/', $cursor, $m) === 1) {
            return [$m[1], 0, (int) $m[2]];
        }

        return ['open', 0, 0];
    }

    /**
     * Olay kimliği — açık sipariş `{no}:created`, iptal `{no}:cancel:{kalem}`.
     *
     * İptal KALEM başınadır: kısmi iptaller birbirini ezmemeli; sipariş
     * numarasına bağlansaydı ikinci kalemin iptali tekillikte yutulur ve
     * stoğu geri eklenmezdi.
     *
     * @param  array<string, mixed>  $order
     */
    public function pollingEventIdFor(array $order): ?string
    {
        $number = trim((string) ($order['orderNumber'] ?? ''));

        if ($number === '') {
            return null;
        }

        return match ($order['_kind'] ?? null) {
            'created' => "{$number}:created",
            'cancelled' => isset($order['lineItemId']) && (string) $order['lineItemId'] !== ''
                ? "{$number}:cancel:{$order['lineItemId']}"
                : null,
            // İade TALEP başınadır: aynı siparişin iki kalemi ayrı taleplerle
            // dönebilir; sipariş numarasına bağlansaydı ikincisi yutulurdu.
            'returned' => ($order['claimNumber'] ?? '') !== ''
                ? "{$number}:return:{$order['claimNumber']}"
                : null,
            default => null,
        };
    }

    /**
     * Yoklanan kaydı kanonik olaya çevirir.
     *
     * Kalem SKU'su `merchantSKU` (satıcının kodu, içe aktarmada varyant
     * SKU'su odur); yoksa HB SKU'su. Kişisel veri (ad, adres) TAŞINMAZ.
     */
    public function parseOrderEvent(InboxMessage $message): ?NormalizedOrderEvent
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($message->payload) ? $message->payload : [];
        $number = trim((string) ($payload['orderNumber'] ?? ''));

        if ($number === '') {
            return null;
        }

        $ref = $message->external_event_id ?? $this->pollingEventIdFor($payload);

        if (($payload['_kind'] ?? null) === 'returned') {
            return new NormalizedOrderEvent(
                type: 'returned',
                externalOrderId: $number,
                externalRef: $ref,
                // Talep kalem kimliği taşımaz; satır SKU ile eşlenir. BAŞLIK
                // durumu yazılmaz — tek kalemin iadesi siparişi "iade" yapmaz.
                payload: ['lines' => [[
                    'sku' => (string) ($payload['sku'] ?? ''),
                    'quantity' => (int) ($payload['quantity'] ?? 0),
                ]], 'claim_type' => $payload['claimType'] ?? null],
                occurredAt: self::channelDate($payload['claimDate'] ?? null),
            );
        }

        if (($payload['_kind'] ?? null) === 'cancelled') {
            return new NormalizedOrderEvent(
                type: 'cancelled',
                externalOrderId: $number,
                externalRef: $ref,
                // Kalem iptalidir; BAŞLIK durumu yazılmaz — tek kalemin
                // iptali bütün siparişi "iptal" göstermemeli.
                payload: ['lines' => [array_filter([
                    'external_line_id' => isset($payload['lineItemId']) ? (string) $payload['lineItemId'] : null,
                    'sku' => self::lineSku($payload['merchantSku'] ?? null, $payload['sku'] ?? null),
                    'quantity' => (int) ($payload['quantity'] ?? 0),
                ], static fn (mixed $v): bool => $v !== null && $v !== '')]],
                occurredAt: self::channelDate($payload['cancelDate'] ?? null),
            );
        }

        $lines = [];
        $total = 0.0;
        $currency = 'TRY';
        $placedAt = null;
        $customerId = null;

        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $lineTotal = (float) ($item['totalPrice']['amount'] ?? 0);
            $total += $lineTotal;
            $currency = (string) ($item['unitPrice']['currency'] ?? $currency);
            $placedAt ??= self::channelDate($item['orderDate'] ?? null);
            $customerId ??= isset($item['customerId']) ? (string) $item['customerId'] : null;

            $lines[] = [
                'external_line_id' => (string) ($item['id'] ?? ''),
                'sku' => self::lineSku($item['merchantSKU'] ?? null, $item['sku'] ?? null),
                'title' => (string) ($item['name'] ?? ''),
                'quantity' => (int) ($item['quantity'] ?? 0),
                'unit_price' => (string) ($item['unitPrice']['amount'] ?? '0'),
                'line_total' => (string) ($item['totalPrice']['amount'] ?? '0'),
            ];
        }

        return new NormalizedOrderEvent(
            type: 'created',
            externalOrderId: $number,
            externalRef: $ref,
            payload: [
                'type' => 'created',
                'external_number' => $number,
                'status' => 'Open',
                'currency' => $currency,
                'subtotal' => (string) $total,
                'grand_total' => (string) $total,
                'lines' => $lines,
                'customer_ref' => array_filter(['external_customer_id' => $customerId]),
            ],
            occurredAt: $placedAt,
            placedAt: $placedAt,
        );
    }

    /** Satıcının kodu (büyük harf), yoksa HB SKU'su. */
    private static function lineSku(mixed $merchantSku, mixed $hbSku): string
    {
        $merchant = is_scalar($merchantSku) ? trim((string) $merchantSku) : '';

        if ($merchant !== '') {
            return strtoupper($merchant);
        }

        return is_scalar($hbSku) ? trim((string) $hbSku) : '';
    }

    /**
     * Kanal tarihi. Ofset taşıyorsa (`…Z`) o kullanılır; taşımıyorsa
     * Türkiye saati varsayılır — DOĞRULANMADI, ilk gerçek siparişte
     * panelle karşılaştırılacak (Trendyol `orderDate`'i +3 kaydırıyordu).
     */
    private static function channelDate(mixed $raw): ?DateTimeImmutable
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            $hasZone = preg_match('/(Z|[+-]\d{2}:?\d{2})$/', trim($raw)) === 1;

            return new DateTimeImmutable(trim($raw), $hasZone ? null : new \DateTimeZone(self::CHANNEL_TIMEZONE));
        } catch (Throwable) {
            return null;
        }
    }

    public function acknowledgeOrder(Order $order): AdapterResult
    {
        throw new RuntimeException(
            'Hepsiburada sipariş onaylama KAPSAM DIŞI — kargo akışının parçası.'
        );
    }

    // ------------------------------------------------------------------- iç

    /**
     * Her isteğe eklenen başlıklar.
     *
     * `User-Agent` KİMLİK DOĞRULAMANIN PARÇASIDIR ve eksikse kanal 401
     * döner (sınıf başlığındaki gerekçe). Biçim: `{merchantId} - {AppName}`.
     *
     * @return array<string, string>
     */
    private function defaultHeaders(): array
    {
        // Bu bağlantının KENDİ kasası; kiracı bağlamı beklenmez (sağlık
        // kontrolü ve kuyruk işi bağlamsız çağırabilir — webhook ile aynı).
        $serviceKey = TenantContext::runAsSystem(fn (): array => $this->readSecrets())[self::SERVICE_KEY_SECRET] ?? null;

        if (! is_string($serviceKey) || $serviceKey === '') {
            throw new RuntimeException(
                'Hepsiburada Servis Anahtarı kasada yok — istek kimliksiz gider ve reddedilir.'
            );
        }

        return [
            // Adapter kendi `Authorization`'ını verir: kullanıcı adı kasada
            // değil bağlantıdadır (merchantId) ve istemcinin bilinen anahtar
            // çiftleri bu biçimi taşımaz.
            'Authorization' => 'Basic '.base64_encode($this->merchantId().':'.$serviceKey),
            'User-Agent' => $this->integratorUsername(),
        ];
    }

    /**
     * HB'ye kayıtlı entegratör kullanıcı adı — `User-Agent` değeri.
     *
     * Boşsa istek atılmaz: başlıksız ya da yanlış başlıklı istek reddedilir
     * ve sebep "anahtar yanlış" diye görünürdü.
     */
    private function integratorUsername(): string
    {
        $name = $this->connection->settings[self::INTEGRATOR_KEY] ?? null;

        if (! is_string($name) || trim($name) === '') {
            throw new RuntimeException(
                'Hepsiburada entegratör kullanıcı adı tanımsız — User-Agent kurulamaz.'
            );
        }

        return trim($name);
    }

    /** Test (SIT) ortamı mı? Tanımsızsa canlı. */
    private function isTest(): bool
    {
        return ($this->connection->settings[self::ENVIRONMENT_KEY] ?? null) === self::ENVIRONMENT_TEST;
    }

    /**
     * Satıcı kimliği — HESABIN kimliğidir, mağaza adresi DEĞİL.
     *
     * Trendyol'un `supplierId` kuralıyla aynı: Hepsiburada'da tek API
     * adresi vardır ve tüm satıcılar onu paylaşır. Alan adı kimlik
     * sayılsaydı her satıcı aynı `external_account_id` ile çakışır ve
     * `(tenant, type, account)` tekilliği ikincisini reddederdi.
     *
     * `external_account_id` BİRİNCİL kaynaktır; `settings` yalnızca
     * geri düşüştür. İkisi de yoksa istisna: sessizce boş bir kimlikle
     * istek atmak, `User-Agent`'ı bozar ve 401'e yol açar — üstelik
     * sebebi görünmez.
     */
    private function merchantId(): string
    {
        $id = $this->connection->external_account_id
            ?: ($this->connection->settings[self::MERCHANT_ID_KEY] ?? null);

        if (! is_string($id) || $id === '') {
            throw new RuntimeException(
                'Hepsiburada satıcı kimliği (merchantId) tanımsız — '.
                'kimlik doğrulama kurulamaz ve kanal 401 döner.'
            );
        }

        return $id;
    }

    /** Webhook sırrı kasadan okunur; bağlam beklenmez (sınıf başlığı). */
    private function webhookSecret(): ?string
    {
        $secrets = TenantContext::runAsSystem(
            fn (): array => $this->readSecrets(),
        );

        $secret = $secrets['webhook_secret'] ?? null;

        return is_string($secret) ? $secret : null;
    }

    /** @return array<string, mixed> */
    private function readSecrets(): array
    {
        try {
            return app(CredentialVault::class)
                ->read($this->connection);
        } catch (Throwable) {
            return [];
        }
    }

    /** Listing hostundaki tam adres. */
    private function listingPath(string $template): string
    {
        return HepsiburadaEndpoints::host(HepsiburadaEndpoints::SERVICE_LISTING, $this->isTest()).HepsiburadaEndpoints::path(
            $template,
            ['merchantId' => $this->merchantId()],
        );
    }

    /**
     * Başlık okuma — ad BÜYÜK/KÜÇÜK HARFTEN bağımsızdır.
     *
     * HTTP başlık adları büyük/küçük harf duyarsızdır ve vekil sunucular
     * onları yeniden yazar. Tam eşleşme aransaydı `X-HB-Signature`
     * gönderen bir kanal `x-hb-signature` aranırken bulunamaz ve MEŞRU
     * webhook reddedilirdi.
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
