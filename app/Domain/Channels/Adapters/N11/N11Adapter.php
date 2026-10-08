<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\N11;

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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * N11 kanal adapter'ı — REST (`api.n11.com`), iade için tek SOAP çağrısı.
 *
 * Araştırma: `docs/YENI-KANALLAR-API-NOTLARI.md` §4. SOAP şeması canlı
 * WSDL'den (`N11Soap` başlığı). Gerçek mağazayla HENÜZ SINANMADI → kanal
 * `is_active = false`. Ürün açma (`product-create`) bu sürümde YOK.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KİMLİK: başlıkta `appKey` + `appSecret`, Authorization YOK
 * ─────────────────────────────────────────────────────────────────────
 * Satıcı anahtarı Satıcı Ofisi → Hesabım → API Hesapları'ndan üretir;
 * secret e-postasına gelir. Sırlar kasada `app_key` / `app_secret` adıyla
 * durur — `api_key`/`api_secret` DEĞİL: o adlar `ChannelHttpClient`'ta
 * Basic auth çiftidir ve istek fazladan yanlış bir Authorization taşırdı.
 * Sırlar yoksa istek ATILMAZ. Hesap kimliği satıcının yazdığı n11 mağaza
 * adıdır (API satıcı kimliği istemez; tek host bütün satıcılarındır).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ `sender` HER İSTEKTE AÇIKÇA `SELLER` — n11depom BİLİNÇLİ DIŞARIDA
 * ─────────────────────────────────────────────────────────────────────
 * `sender` gönderilmezse N11 yalnız mağaza (SELLER) kayıtlarını döndürür;
 * n11depom kullanan satıcının depo ürünleri ve siparişleri SESSİZCE eksik
 * gelir (tuzak 11). Burada bu bir karar: n11 deposundaki stok satıcının
 * deposunda DEĞİLDİR. Depo siparişi 34Pazar stoğunu düşürseydi aynı mal
 * (depoya gönderilirken bir kez çıkmış) ikinci kez düşerdi. Varsayılana
 * dayanılmaz, değer her istekte yazılır; kalem düzeyinde `sender = N11`
 * gelen satır da stoğa dokunmaz. DOĞRULANMADI: n11depom kullanan gerçek
 * satıcıda karışık paket görülüp görülmediği.
 *
 * ─────────────────────────────────────────────────────────────────────
 * STOK / FİYAT: `price-stock-update` görevi — asenkron, ≤1000 SKU
 * ─────────────────────────────────────────────────────────────────────
 * Kimlik `stockCode`'dur (metin, sayıya çevrilmez). Yanıt bir görev
 * kimliği döner (`IN_QUEUE`); kimlik sonuçta `task_id` olarak taşınır,
 * uygulandığını mutabakat doğrular. Gövde bütünüyle reddedilirse
 * `status = REJECT` → VALIDATION.
 *   - Stok yükü YALNIZ `stockCode` + `quantity` taşır: gönderilmeyen alan
 *     değişmez. Fiyat da yazılsaydı panelde değişmiş ama henüz gitmemiş
 *     fiyat eski değerle ezilirdi (Trendyol kuralı).
 *   - Fiyat yükü YALNIZ `stockCode` + `listPrice` + `salePrice` taşır,
 *     ikisi BİRLİKTE, `listPrice ≥ salePrice`, noktadan sonra TAM 2 hane
 *     (aksi FAIL). `json_encode` 2000.00'ı `2000.0` yazdığı için gövde
 *     metin olarak kurulur. `currencyType` GÖNDERİLMEZ: ürünün para
 *     birimini değiştirebilirdi; TL dışı ürüne fiyat yazılmaz.
 *   - KDV: fiyatın KDV dahil olduğu belgede açık değil; tüketici fiyatı
 *     olduğu için dahil sayıldı (DOĞRULANMADI).
 *
 * ─────────────────────────────────────────────────────────────────────
 * SİPARİŞ: paket bazlı yoklama, ANAHTAR `orderNumber` / `orderLineId`
 * ─────────────────────────────────────────────────────────────────────
 * - Paket `id` kalıcı değildir (bölme ve kısmi iptal yeni paket açar,
 *   "Konuma Özel Teslimat"ta null gelir) → sipariş kimliği `orderNumber`.
 * - TARİH PENCERESİ EN ÇOK 15 GÜN ve aşan pencere HATA VERMEDEN kırpılır
 *   (yalnız son 15 gün gelir — sessiz veri kaybı, tuzak 3). Pencere
 *   {@see ORDER_WINDOW_DAYS} günlük dilimlere bölünür.
 * - `status` istek başına TEK değer alır (resmi sayfa) → her statü ayrı
 *   sorgulanır. `Unpacked` (bölünmüş ana paket) yok sayılır: kalemleri
 *   yeni paketlerde yeniden gelir.
 * - "GMT+3 timestamp" belirsiz (tuzak 4) → pencere iki uçtan
 *   {@see ORDER_DATE_SLACK_HOURS} saat genişletilir; tekrar gelen kayıt olay
 *   kimliğiyle elenir. DOĞRULANMADI: ilk gerçek siparişte ölçülecek.
 * - İPTAL/İADE KURALI (Ticimax'la aynı): sipariş daha önce "created"
 *   olarak ALINMADIYSA iptali de iadesi de hiçbir şey üretmez. İki tur
 *   arasında verilip iptal edilen sipariş net sıfırdır; "created" atlanıp
 *   iptal işlenseydi hiç düşülmemiş stok GERİ EKLENİRDİ.
 * - Yeni sipariş ilk görüldüğünde AYNI siparişin bütün paketleri
 *   `orderNumber` ile okunur: sipariş birden çok pakete bölünmüş olabilir
 *   ve yalnız görülen paketin kalemleri alınsaydı ötekilerin stoğu hiç
 *   düşmezdi. Bu durumda iptal edilmiş paketlerin kalemleri de "created"e
 *   girer ve hemen ardından iptal kaydıyla geri eklenir (ikas kuralı).
 *   DOĞRULANMADI: `orderNumber` sorgusu tarih/statü süzgeci olmadan bütün
 *   paketleri döndürüyor mu — boş dönerse eldeki paketle yetinilir.
 * - İade REST'te `Delivered` görünür (tuzak 5) → yoklamanın son adımı SOAP
 *   `ClaimReturnList`, yalnız `APPROVED` (satıcı iadeyi onayladı = ürün
 *   döndü). Kalemde `stockCode` yok; `productId` alınmış siparişin
 *   kalemiyle eşlenir. Liste talep tarihine göre süzülür, onay günler
 *   sonra gelebilir → her turda {@see RETURN_LOOKBACK_DAYS} gün geriye
 *   bakılır. DOĞRULANMADI: `MANUAL_REFUND` ürünün döndüğünü mü söylüyor.
 * - Kişisel veri inbox'a girmez: adresler (gsm, tcId), müşteri adı,
 *   e-postası, kimliği, vergi bilgisi, kalemdeki müşteriye özel metin.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ SİPARİŞ ONAYI (`Picking`) — YAZILDI AMA HİÇBİR AKIŞA BAĞLI DEĞİL
 * ─────────────────────────────────────────────────────────────────────
 * N11'de onay zorunludur: `Created` kalemi satıcı `Picking`'e çekmezse
 * sipariş "Kargo Yapılması Gecikmiş" olur. `acknowledgeOrder` bunu yapar
 * (`PUT /rest/order/v1/update`) ama çekirdek bugün onu ÇAĞIRMIYOR ve
 * otomatiğe bağlanmadan önce düşünülmeli: onaylanan kalem artık panelden
 * "Reddet"lenemez, iptal yalnız paket bölme + iptal nedeniyle yapılır.
 * Satıcı siparişi kendi panelinden ya da başka bir araçtan onaylıyorsa
 * otomatik onay onun akışını BOZAR (stoğu olmayan siparişi reddetme şansı
 * kalmaz). Satıcı onayı N11'e otomatiğe de bağlatabilir
 * (`sellerintegration@n11.com`).
 */
final class N11Adapter implements ChannelAdapter, DeclaresChannelCurrency, SupportsBatchStatus, SupportsCatalogImport, SupportsInventory, SupportsOrders, SupportsPricing
{
    use DeclaresRequestQuota;

    /** Tek host — bütün satıcılar paylaşır. */
    public const BASE_URL = 'https://api.n11.com';

    /** Satıcının n11 mağaza adı — hesap kimliği. SIR DEĞİL. */
    public const SELLER_NAME_KEY = 'n11_seller_name';

    /** Kasadaki anahtar çifti — başlık adları `appKey` / `appSecret`. */
    public const APP_KEY_SECRET = 'app_key';

    public const APP_SECRET_SECRET = 'app_secret';

    /** Görev gövdelerindeki zorunlu `integrator` — "her gönderimde aynı değer". */
    public const INTEGRATOR = '34Pazar';

    /** `sender` her istekte açıkça (sınıf notu). */
    private const SENDER = 'SELLER';

    /** Ürün sorgusu sayfa üst sınırı 250. */
    private const PRODUCT_PAGE_SIZE = 250;

    /** Görev başına en çok 1000 SKU. */
    private const MAX_SKUS_PER_TASK = 1000;

    /**
     * Görev sonucunun sorgulanabildiği süre — DOĞRULANMADI: belgede
     * saklama süresi yok, 24 saat varsayıldı. Kanal unutursa 404 döner ve
     * yoklama zaten bırakılır.
     */
    private const TASK_RETENTION_SECONDS = 24 * 3600;

    /** `task-details` sayfa sınırı — görev ≤1000 SKU olduğu için tek sayfa yeter. */
    private const TASK_DETAIL_MAX_PAGES = 5;

    /** Stok üst sınırı (ürün yükleme dokümanı). */
    private const MAX_QUANTITY = 999_999;

    /**
     * Mutabakatta tek tek `stockCode` sorgusunun üst sınırı. `stockCode`
     * süzgeci TEK değer alır (tuzak 10); daha çok listing için tam tarama.
     */
    private const PER_CODE_LOOKUP_LIMIT = 20;

    /** Sipariş sayfa üst sınırı 100. */
    private const ORDER_PAGE_SIZE = 100;

    /**
     * Sipariş tarih penceresinin dilimi. Kanal sınırı 15 gün ve aşan
     * pencereyi SESSİZCE kırpar; sınırın kapsayıcı olup olmadığı belgesiz,
     * bir gün pay bırakıldı.
     */
    private const ORDER_WINDOW_DAYS = 14;

    /** "GMT+3 timestamp" belirsizliği için iki uçtan pay (sınıf notu). */
    private const ORDER_DATE_SLACK_HOURS = 3;

    /** Her biri ayrı sorgulanır — `status` tek değer alır. */
    private const ORDER_STATUSES = ['Created', 'Picking', 'Shipped', 'Delivered', 'Cancelled', 'UnSupplied'];

    /** Stoğu düşmüş (ya da düşecek) paket durumları — küçük harf, kırpılmış. */
    private const LIVE_STATUSES = ['created', 'picking', 'shipped', 'delivered'];

    /** İptal: müşteri iptali + satıcının tedarik edemediği kısım. */
    private const CANCELLED_STATUSES = ['cancelled', 'canceled', 'unsupplied'];

    /** Bölünmüş ana paket — kalemleri yeni paketlerde. */
    private const UNPACKED_STATUS = 'unpacked';

    /** İade onayı = ürün satıcıya döndü (sınıf notu). */
    private const RETURN_STATUS = 'APPROVED';

    private const RETURN_LOOKBACK_DAYS = 30;

    private const CHANNEL_TIMEZONE = 'Europe/Istanbul';

    /**
     * Bu yoklama turunda "created" kaydı ÜRETİLEN siparişler. Aynı sayfada
     * aynı siparişin ikinci paketi yeniden okuma yaptırmasın ve aynı turdaki
     * iptal kaydı alınmış sayılsın.
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

    /** Türk pazaryeri; TL dışı ürünlere fiyat yazılmaz (`pushPrices`). */
    public function channelCurrency(): ?string
    {
        return 'TRY';
    }

    // ---------------------------------------------------------------- sağlık

    /**
     * `product-query?page=0&size=1` — key ve secret'ı birlikte doğrular,
     * yanıt küçük (§4 · Sağlık kontrolü). JSON `content` taşımayan 200 de
     * sağlıklı SAYILMAZ.
     */
    public function healthCheck(): HealthResult
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->get('/ms/product-query', ['page' => 0, 'size' => 1, 'sender' => self::SENDER]);
            $response->throw();
            self::jsonBody($response, 'content');
        } catch (Throwable $e) {
            return HealthResult::unhealthy($e->getMessage());
        }

        return HealthResult::healthy((int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    /**
     * Belgeli sınır yalnız sipariş listelemede (dakikada 1000). Öteki uçlar
     * belgesiz → tutucu: saniyede 5.
     */
    public function rateLimitProfile(): RateLimitProfile
    {
        $profile = $this->connection->channelType?->rate_limit_profile;

        return is_array($profile) && $profile !== []
            ? RateLimitProfile::fromArray($profile)
            : new RateLimitProfile(requestsPerSecond: 5, burstCapacity: 10);
    }

    /**
     * Yanlış anahtar üç biçimde döner (8 Eki 2026 ölçümü):
     *   - `/ms/` → 401 JSON (`SellerApiUserUnauthorizedException`);
     *   - `/rest/` ve `/ws/orderService/` → 403, `text/plain`
     *     "Authentication failed" — JSON DEĞİL (tuzak 9);
     *   - SOAP → HTTP 200 + `SELLER_API.authenticationFailed` (`N11SoapFault`).
     * Gövde metni de taranır: düz metin ret başka bir durum koduyla gelse
     * de kalıcı kimlik hatasıdır, yeniden denenmemeli.
     */
    public function classifyError(Throwable $e): ErrorClass
    {
        if ($e instanceof N11SoapFault) {
            return match (true) {
                $e->isAuthentication() => ErrorClass::AUTHENTICATION,
                in_array($e->errorCode, ['NoXml', 'NoResult', 'Fault'], true) => ErrorClass::SERVER_ERROR,
                default => ErrorClass::VALIDATION,
            };
        }

        if ($e instanceof ConnectionException || ! $e instanceof RequestException) {
            return ErrorClass::NETWORK;
        }

        if (str_contains(mb_strtolower($e->response->body()), 'authentication failed')) {
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

    /** Webhook yok (developer.n11.com'un 34 sayfasının hiçbirinde) — imzasız gövde ASLA kabul edilmez. */
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
     * `product-query` — her satır BİR SKU. Kimlik `stockCode` (bütün stok ve
     * fiyat yazımları ona göre), üst kimlik `productMainId` (satıcının
     * model/grup kodu; varyantlar aynı değeri taşır).
     *
     * İmleç sayfa numarasıdır (0'dan). Boş `content` son sayfadır (resmi
     * not). `stockCode`'suz satır içe alınmaz — stok yazılamaz — ama
     * görünür kalsın diye günlüğe düşer.
     */
    public function fetchProductPage(?string $cursor = null): RemoteProductPage
    {
        $page = $cursor === null ? 0 : max(0, (int) $cursor);

        $body = $this->productQuery(['page' => $page, 'size' => self::PRODUCT_PAGE_SIZE]);
        $rows = self::rows($body['content'] ?? null);

        $products = [];
        $withoutCode = 0;

        foreach ($rows as $row) {
            if (trim((string) ($row['stockCode'] ?? '')) === '') {
                $withoutCode++;

                continue;
            }

            $products[] = $this->toRemoteProduct($row);
        }

        if ($withoutCode > 0) {
            Log::warning('n11.product_without_stock_code', ['connection' => $this->connection->id, 'page' => $page, 'count' => $withoutCode]);
        }

        $hasMore = $rows !== [] && ($body['last'] ?? false) !== true && $page + 1 < (int) ($body['totalPages'] ?? 0);

        return new RemoteProductPage(
            products: $products,
            nextCursor: $hasMore ? (string) ($page + 1) : null,
            hasMore: $hasMore,
        );
    }

    /** Tur başına 100 sayfa × 250 = 25.000 SKU. */
    public function maxImportPages(): int
    {
        return 100;
    }

    /** @param array<string, mixed> $row */
    private function toRemoteProduct(array $row): RemoteProduct
    {
        $stockCode = trim((string) $row['stockCode']);
        $mainId = trim((string) ($row['productMainId'] ?? ''));
        $barcode = trim((string) ($row['barcode'] ?? ''));
        $description = trim((string) ($row['description'] ?? ''));
        $currencyType = strtoupper(trim((string) ($row['currencyType'] ?? '')));

        $brand = null;
        foreach ((array) ($row['attributes'] ?? []) as $attribute) {
            // Marka ayrı servis değil, özellik `id: 1` (§4 · Ürün açma).
            if (is_array($attribute) && ((int) ($attribute['attributeId'] ?? 0) === 1 || ($attribute['attributeName'] ?? null) === 'Marka')) {
                $brand = trim((string) ($attribute['attributeValue'] ?? '')) ?: null;
            }
        }

        return new RemoteProduct(
            externalId: $stockCode,
            sku: $stockCode,
            title: trim((string) ($row['title'] ?? '')) ?: $stockCode,
            price: isset($row['salePrice']) ? self::money($row['salePrice']) : null,
            quantity: (int) ($row['quantity'] ?? 0),
            description: $description !== '' ? $description : null,
            brand: $brand,
            barcode: $barcode !== '' ? $barcode : null,
            status: strtolower(trim((string) ($row['status'] ?? ''))) ?: null,
            images: array_values(array_filter(
                array_map('strval', (array) ($row['imageUrls'] ?? [])),
                static fn (string $url): bool => str_starts_with($url, 'https://'),
            )),
            raw: $row,
            listingIdentity: array_filter([
                // Kimlik METİN — `(int)` yok.
                'external_id' => $stockCode,
                'external_parent_id' => $mainId !== '' ? $mainId : null,
                'channel_metadata' => array_filter([
                    // 9→10 hane büyüyor (tuzak 7) — metin.
                    'n11_product_id' => isset($row['n11ProductId']) ? (string) $row['n11ProductId'] : null,
                    'currency_type' => $currencyType !== '' ? $currencyType : null,
                    'vat_rate' => isset($row['vatRate']) ? (int) $row['vatRate'] : null,
                ], static fn (mixed $v): bool => $v !== null),
            ], static fn (mixed $v): bool => $v !== null && $v !== []),
            currency: self::isoCurrency($currencyType),
        );
    }

    // ---------------------------------------------------------------- stok

    /**
     * MUTLAK stok — `price-stock-update`, YALNIZ `stockCode` + `quantity`
     * (sınıf notu: fiyat alanı TAŞINMAZ).
     */
    public function pushInventory(InventoryPushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            return AdapterResult::success(['pushed' => 0]);
        }

        $skus = array_map(static fn (array $item): array => [
            'stockCode' => (string) $item['external_id'],
            'quantity' => max(0, min(self::MAX_QUANTITY, (int) $item['quantity'])),
        ], $batch->toArray());

        $response = $this->client->post(
            self::BASE_URL.'/ms/product/tasks/price-stock-update',
            ['payload' => ['integrator' => self::INTEGRATOR, 'skus' => $skus]],
            headers: $this->authHeaders(),
        );

        $response->throw();

        return $this->taskResult($response, $batch->count());
    }

    public function maxInventoryBatchSize(): int
    {
        return self::MAX_SKUS_PER_TASK;
    }

    /** @param list<Listing> $listings */
    public function fetchInventory(array $listings): RemoteInventorySnapshot
    {
        $quantities = [];

        foreach ($this->remoteRows($listings) as $code => $row) {
            $quantities[$code] = (int) ($row['quantity'] ?? 0);
        }

        return new RemoteInventorySnapshot($quantities, new DateTimeImmutable);
    }

    // --------------------------------------------------------------- fiyat

    /**
     * MUTLAK fiyat — `price-stock-update`, YALNIZ `stockCode` + `listPrice`
     * + `salePrice` (stok alanı TAŞINMAZ: bayat bakiye satılmış ürünü
     * yeniden satışa açardı).
     *
     * Karşılaştırma fiyatı satıştan yüksekse üstü çizili (`listPrice`),
     * değilse iki alan aynı değer — tek alan gönderilemez (tuzak 8).
     * TL dışı ürüne yazılmaz: rakam olduğu gibi giderse 100 TL'lik fiyat
     * 100 USD olurdu.
     */
    public function pushPrices(PricePushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            return AdapterResult::success(['pushed' => 0]);
        }

        $metadata = TenantContext::runAsSystem(fn (): array => Listing::query()
            ->where('channel_connection_id', $this->connection->id)
            ->whereIn('id', array_column($batch->items, 'listing_id'))
            ->pluck('channel_metadata', 'id')
            ->all());

        $skus = [];
        $failed = [];

        foreach ($batch->items as $index => $item) {
            $meta = $metadata[$item['listing_id']] ?? [];
            $currency = strtoupper((string) (is_array($meta) ? ($meta['currency_type'] ?? '') : ''));

            if ($currency !== '' && ! in_array($currency, ['TL', 'TRY'], true)) {
                $failed[$index] = "N11'deki ürün {$currency} fiyatlı; 34Pazar fiyatı TRY. Para birimini N11'de TL yap.";

                continue;
            }

            $price = round((float) $item['price'], 2);
            $compare = isset($item['compare_at_price']) && is_numeric($item['compare_at_price'])
                ? round((float) $item['compare_at_price'], 2)
                : null;

            $skus[] = [
                'stockCode' => (string) $item['external_id'],
                'listPrice' => self::money($compare !== null && $compare > $price ? $compare : $price),
                'salePrice' => self::money($price),
            ];
        }

        if ($skus === []) {
            return AdapterResult::failure(ErrorClass::VALIDATION, (string) reset($failed));
        }

        $response = $this->client->postRaw(
            self::BASE_URL.'/ms/product/tasks/price-stock-update',
            self::jsonWithTwoDecimals(['payload' => ['integrator' => self::INTEGRATOR, 'skus' => $skus]]),
            'application/json',
            $this->authHeaders(),
        );

        $response->throw();

        $result = $this->taskResult($response, count($skus));

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
        return self::MAX_SKUS_PER_TASK;
    }

    /** @param list<Listing> $listings */
    public function fetchPrices(array $listings): RemotePriceSnapshot
    {
        $prices = [];

        foreach ($this->remoteRows($listings) as $code => $row) {
            if (isset($row['salePrice'])) {
                $prices[$code] = self::money($row['salePrice']);
            }
        }

        return new RemotePriceSnapshot($prices, new DateTimeImmutable);
    }

    /**
     * Görev yanıtı → sonuç. `REJECT` gövdenin bütünüyle reddedildiğidir
     * (VALIDATION, kalıcı); kimliksiz 2xx belirsizdir ve İSTİSNA olur.
     * `IN_QUEUE` "uygulandı" DEMEK DEĞİL — satır sonucu task-details'te.
     */
    private function taskResult(Response $response, int $count): AdapterResult
    {
        $body = self::jsonBody($response);
        $status = strtoupper((string) ($body['status'] ?? ''));
        $reasons = implode(' ', array_map('strval', (array) ($body['reasons'] ?? [])));

        if ($status === 'REJECT') {
            return AdapterResult::failure(ErrorClass::VALIDATION, 'N11 görevi reddetti: '.($reasons !== '' ? $reasons : 'neden yok'));
        }

        if (! isset($body['id'])) {
            throw new RuntimeException('N11 görev kimliği dönmedi.');
        }

        return AdapterResult::success([
            'pushed' => $count,
            'task_id' => (string) $body['id'],
            'task_status' => $status,
        ]);
    }

    // ------------------------------------------------------- toplu iş sonucu

    public function batchIdFrom(AdapterResult $result): ?string
    {
        $id = $result->data['task_id'] ?? null;

        return is_scalar($id) && trim((string) $id) !== '' ? (string) $id : null;
    }

    public function batchRetentionSeconds(SyncDomain $domain): int
    {
        return self::TASK_RETENTION_SECONDS;
    }

    /**
     * Görev sonucu — `POST /ms/product/task-details/page-query`
     * (API notları §4), gövde `{"taskId": N, "pageable": {"page", "size"}}`.
     *
     * Görev `status`: `IN_QUEUE` sürüyor · `PROCESSED` bitti · `REJECT`
     * işlenmedi (TÜM satırlar aynı sebeple başarısız). Satırlar
     * `skus.content[]`: `itemCode` (= gönderdiğimiz `stockCode`),
     * `status` `SUCCESS`/`FAIL`, `reasons[]`. Bir görevde başarılı ve
     * başarısız satır birlikte olabilir.
     *
     * DOĞRULANMADI: sayfa alanı adı (`skus.totalPages`, Spring sayfası
     * varsayıldı) · `taskId`'nin sayı olarak beklendiği (resmi örnek sayı) ·
     * saklama süresi · bilinmeyen görevde dönen kod (404 varsayıldı).
     */
    public function fetchBatchStatus(string $batchId, SyncDomain $domain): BatchStatus
    {
        $taskId = ctype_digit($batchId) ? (int) $batchId : $batchId;
        $failed = [];
        $succeeded = [];

        for ($page = 0; $page < self::TASK_DETAIL_MAX_PAGES; $page++) {
            $response = $this->client->post(
                self::BASE_URL.'/ms/product/task-details/page-query',
                ['taskId' => $taskId, 'pageable' => ['page' => $page, 'size' => self::MAX_SKUS_PER_TASK]],
                headers: $this->authHeaders(),
            );

            if ($response->status() === 404) {
                return BatchStatus::expired();
            }

            $response->throw();

            $body = self::jsonBody($response);
            $status = strtoupper(trim((string) ($body['status'] ?? '')));

            if ($status === 'REJECT') {
                $reasons = implode(' ', array_map('strval', (array) ($body['reasons'] ?? [])));

                return BatchStatus::rejected(new BatchItemFailure(
                    'N11 görevi reddetti: '.($reasons !== '' ? $reasons : 'neden yok'),
                ));
            }

            if ($status !== 'PROCESSED') {
                return BatchStatus::pending();
            }

            $skus = is_array($body['skus'] ?? null) ? $body['skus'] : [];

            foreach (self::rows($skus['content'] ?? []) as $row) {
                $code = trim((string) ($row['itemCode'] ?? ''));

                if ($code === '') {
                    continue;
                }

                $rowStatus = strtoupper((string) ($row['status'] ?? ''));

                if ($rowStatus === 'SUCCESS') {
                    $succeeded[] = $code;
                } elseif ($rowStatus === 'FAIL') {
                    $reasons = implode(' · ', array_filter(array_map(
                        static fn (mixed $r): string => is_scalar($r) ? trim((string) $r) : '',
                        (array) ($row['reasons'] ?? []),
                    )));

                    $failed[$code] = new BatchItemFailure($reasons !== '' ? $reasons : 'N11 satırı reddetti (sebep belirtilmedi).');
                }
            }

            $totalPages = (int) ($skus['totalPages'] ?? 1);

            if ($page + 1 >= $totalPages) {
                break;
            }
        }

        return BatchStatus::completed(failed: $failed, succeeded: $succeeded);
    }

    /**
     * Uzak satırlar `stockCode` ile — stok ve fiyat ORTAK.
     *
     * KİMLİKSİZ LISTING SORULMAZ; hiç kimlik yoksa çağrı YAPILMAZ (süzgeçsiz
     * sorgu bütün kataloğu getirirdi). `stockCode` süzgeci tek değer alır:
     * az listing için tek tek, çoksa tam tarama (sorulanlar bulununca durur).
     * Başarısız yanıt yükselir: boş sonuç "kanalda yok" sanılırdı.
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
                foreach (self::rows($this->productQuery(['stockCode' => (string) $code, 'page' => 0, 'size' => 20])['content'] ?? null) as $row) {
                    if ((string) ($row['stockCode'] ?? '') === (string) $code) {
                        $found[(string) $code] = $row;
                    }
                }
            }

            return $found;
        }

        for ($page = 0; $page < $this->maxImportPages(); $page++) {
            $body = $this->productQuery(['page' => $page, 'size' => self::PRODUCT_PAGE_SIZE]);
            $rows = self::rows($body['content'] ?? null);

            foreach ($rows as $row) {
                $code = (string) ($row['stockCode'] ?? '');

                if (isset($codes[$code])) {
                    $found[$code] = $row;
                }
            }

            if ($rows === [] || count($found) === count($codes) || ($body['last'] ?? false) === true) {
                break;
            }
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function productQuery(array $query): array
    {
        $response = $this->get('/ms/product-query', [...$query, 'sender' => self::SENDER]);

        $response->throw();

        return self::jsonBody($response, 'content');
    }

    // ------------------------------------------------------------- siparişler

    /**
     * Yoklama: tarih dilimi × statü × sayfa, sonra iade (SOAP) sayfaları.
     *
     * İmleç `r:{dilim}:{statü}:{sayfa}` ya da `c:{sayfa}`. Pencere
     * `since − pay` … `şimdi + pay`; 14 günden uzunsa dilimlenir (sınıf
     * notu — aşan pencere SESSİZCE kırpılırdı).
     */
    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage
    {
        if ($cursor !== null && preg_match('/^c:(\d+)$/', $cursor, $m) === 1) {
            return $this->returnPage((int) $m[1]);
        }

        [$slice, $statusIndex, $page] = $cursor !== null && preg_match('/^r:(\d+):(\d+):(\d+)$/', $cursor, $m) === 1
            ? [(int) $m[1], (int) $m[2], (int) $m[3]]
            : [0, 0, 0];

        $slackMs = self::ORDER_DATE_SLACK_HOURS * 3_600_000;
        $windows = self::windows($since->getTimestampMs() - $slackMs, CarbonImmutable::now()->getTimestampMs() + $slackMs);

        if (! isset($windows[$slice], self::ORDER_STATUSES[$statusIndex])) {
            return $this->returnPage(0);
        }

        [$start, $end] = $windows[$slice];

        $body = $this->shipmentPackages([
            'startDate' => $start,
            'endDate' => $end,
            'status' => self::ORDER_STATUSES[$statusIndex],
            // Pencere paketin SON DEĞİŞİKLİĞİNE göre: iptal de görünür.
            'orderByField' => 'true',
            'orderByDirection' => 'ASC',
            'page' => $page,
            'size' => self::ORDER_PAGE_SIZE,
        ]);

        $packages = self::rows($body['content'] ?? null);
        $records = [];

        foreach ($packages as $package) {
            array_push($records, ...$this->recordsFor($package));
        }

        $next = match (true) {
            $packages !== [] && $page + 1 < (int) ($body['totalPages'] ?? 0) => "r:{$slice}:{$statusIndex}:".($page + 1),
            isset(self::ORDER_STATUSES[$statusIndex + 1]) => "r:{$slice}:".($statusIndex + 1).':0',
            isset($windows[$slice + 1]) => 'r:'.($slice + 1).':0:0',
            default => 'c:0',
        };

        return new OrderPage(orders: $records, nextCursor: $next, hasMore: true);
    }

    /**
     * Pencereyi en çok {@see ORDER_WINDOW_DAYS} günlük, ARALIKSIZ dilimlere
     * böler (her dilimin sonu bir sonrakinin başı).
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function windows(int $fromMs, int $toMs): array
    {
        $width = self::ORDER_WINDOW_DAYS * 86_400_000;
        $windows = [];

        for ($start = $fromMs; $start < $toMs; $start += $width) {
            $windows[] = [$start, min($start + $width, $toMs)];
        }

        return $windows === [] ? [[$fromMs, $toMs]] : $windows;
    }

    /**
     * Paketin kayıtları.
     *
     * - `Unpacked` hiçbir şey üretmez (kalemleri yeni paketlerde).
     * - Canlı statü (Created/Picking/Shipped/Delivered): sipariş daha önce
     *   alınmadıysa "created" (bütün paketleriyle, sınıf notu); alındıysa
     *   durum anlık görüntüsü ("updated", stok hareketi yok).
     * - İptal (Cancelled/UnSupplied): YALNIZ sipariş alındıysa, kalem başına.
     *
     * Statü `trim` + küçük harfle karşılaştırılır: yazım tutarsız
     * (`Cancelled`/`Canceled`, `" Shipped"` — tuzak 2).
     *
     * @param  array<string, mixed>  $package
     * @return list<array<string, mixed>>
     */
    private function recordsFor(array $package): array
    {
        $orderNumber = trim((string) ($package['orderNumber'] ?? ''));
        $status = self::status($package);

        if ($orderNumber === '' || $status === self::UNPACKED_STATUS) {
            return [];
        }

        if (in_array($status, self::CANCELLED_STATUSES, true)) {
            return $this->createdKnown($orderNumber) ? self::cancellationsFor($orderNumber, $package) : [];
        }

        if (! in_array($status, self::LIVE_STATUSES, true)) {
            Log::warning('n11.unknown_package_status', ['connection' => $this->connection->id, 'status' => $status]);

            return [];
        }

        if (! $this->createdKnown($orderNumber)) {
            return $this->createdFor($orderNumber, $package);
        }

        $packageId = trim((string) ($package['id'] ?? ''));

        // "Konuma Özel Teslimat" paketinde `id` null: kimliksiz anlık
        // görüntü olay kimliği üretemez; stok etkisi yok, atlanır.
        return $packageId === '' ? [] : [[
            '_kind' => 'updated',
            'orderNumber' => $orderNumber,
            'packageId' => $packageId,
            'status' => trim((string) ($package['shipmentPackageStatus'] ?? '')),
            'lastModifiedDate' => $package['lastModifiedDate'] ?? null,
            'cargoTrackingNumber' => $package['cargoTrackingNumber'] ?? null,
            'cargoSenderNumber' => $package['cargoSenderNumber'] ?? null,
            'cargoProviderName' => $package['cargoProviderName'] ?? null,
        ]];
    }

    /**
     * İlk kez görülen sipariş: bütün paketleri okunur, `Unpacked` dışındaki
     * paketlerin satıcı kalemleri "created"e girer; iptal edilmiş paketlerin
     * kalemleri ardından iptal kaydıyla geri eklenir.
     *
     * @param  array<string, mixed>  $package
     * @return list<array<string, mixed>>
     */
    private function createdFor(string $orderNumber, array $package): array
    {
        $packages = [...self::rows($this->shipmentPackages([
            'orderNumber' => $orderNumber,
            'page' => 0,
            'size' => self::ORDER_PAGE_SIZE,
        ])['content'] ?? null), $package];

        $lines = [];
        $cancelled = [];
        $placedAt = null;

        foreach ($packages as $candidate) {
            $status = self::status($candidate);
            $isCancelled = in_array($status, self::CANCELLED_STATUSES, true);

            if (! $isCancelled && ! in_array($status, self::LIVE_STATUSES, true)) {
                continue;
            }

            foreach (self::sellerLines($candidate) as $line) {
                $lineId = (string) $line['orderLineId'];

                if (isset($lines[$lineId])) {
                    continue;
                }

                $lines[$lineId] = $line;

                if ($isCancelled) {
                    $cancelled[$lineId] = $candidate;
                }
            }

            $placed = self::placedAtMs($candidate);
            $placedAt = $placed !== null && ($placedAt === null || $placed < $placedAt) ? $placed : $placedAt;
        }

        if ($lines === []) {
            return [];
        }

        $this->createdThisRun[$orderNumber] = true;

        $records = [[
            '_kind' => 'created',
            'orderNumber' => $orderNumber,
            'status' => trim((string) ($package['shipmentPackageStatus'] ?? '')),
            'placedAt' => $placedAt,
            'lines' => array_values($lines),
        ]];

        foreach ($cancelled as $lineId => $candidate) {
            $records[] = self::cancellationRecord($orderNumber, $candidate, $lines[$lineId]);
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return list<array<string, mixed>>
     */
    private static function cancellationsFor(string $orderNumber, array $package): array
    {
        return array_map(
            static fn (array $line): array => self::cancellationRecord($orderNumber, $package, $line),
            self::sellerLines($package),
        );
    }

    /**
     * @param  array<string, mixed>  $package
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private static function cancellationRecord(string $orderNumber, array $package, array $line): array
    {
        return [
            '_kind' => 'cancelled',
            'orderNumber' => $orderNumber,
            'status' => trim((string) ($package['shipmentPackageStatus'] ?? '')),
            'lastModifiedDate' => $package['lastModifiedDate'] ?? null,
            'line' => $line,
        ];
    }

    /**
     * İade sayfası — SOAP `ClaimReturnList`, yalnız `APPROVED`.
     *
     * Kayıt yalnız sipariş daha önce alındıysa ve `productId` o siparişin
     * kalemiyle eşleşiyorsa üretilir. Eşleşmeyen iade sessizce yutulmaz,
     * günlüğe düşer.
     */
    private function returnPage(int $page): OrderPage
    {
        $today = CarbonImmutable::now(self::CHANNEL_TIMEZONE);
        [$key, $secret] = $this->credentials();

        $response = $this->client->postRaw(
            N11Soap::RETURN_SERVICE_URL,
            N11Soap::claimReturnList(
                $key,
                $secret,
                self::RETURN_STATUS,
                $today->subDays(self::RETURN_LOOKBACK_DAYS)->format('d/m/Y'),
                $today->format('d/m/Y'),
                self::SENDER,
                $page,
            ),
            'text/xml; charset=utf-8',
            ['SOAPAction' => '""', 'Accept' => 'text/xml'],
        );

        // Fault/`failure` gövdesi HTTP 200 ile de gelir — önce gövde okunur.
        $result = N11Soap::parseClaimReturnList($response->body());
        $response->throw();

        $records = [];

        foreach ($result['claims'] as $claim) {
            $record = $this->returnRecordFor($claim);

            if ($record !== null) {
                $records[] = $record;
            }
        }

        $more = $page + 1 < $result['pageCount'];

        return new OrderPage(orders: $records, nextCursor: $more ? 'c:'.($page + 1) : null, hasMore: $more);
    }

    /**
     * @param  array<string, string>  $claim
     * @return array<string, mixed>|null
     */
    private function returnRecordFor(array $claim): ?array
    {
        $orderNumber = trim($claim['orderNumber'] ?? '');
        $claimId = trim($claim['claimReturnId'] ?? '');
        $quantity = (int) ($claim['quantity'] ?? 0);

        if ($orderNumber === '' || $claimId === '' || $quantity <= 0
            || strtoupper(trim($claim['status'] ?? self::RETURN_STATUS)) !== self::RETURN_STATUS
            || strtoupper(trim($claim['sender'] ?? self::SENDER)) === 'N11') {
            return null;
        }

        // Hiç alınmamış siparişin iadesi stoğa DOKUNMAZ (sınıf notu).
        $created = $this->ingestedCreated($orderNumber);

        if ($created === null) {
            return null;
        }

        foreach ((array) ($created['lines'] ?? []) as $line) {
            if (is_array($line) && (string) ($line['productId'] ?? '') === trim($claim['productId'] ?? '')) {
                return [
                    '_kind' => 'returned',
                    'orderNumber' => $orderNumber,
                    'claimReturnId' => $claimId,
                    'quantity' => $quantity,
                    'approvedDate' => $claim['approvedDate'] ?? null,
                    'line' => $line,
                ];
            }
        }

        Log::warning('n11.return_without_matching_line', [
            'connection' => $this->connection->id,
            'order' => $orderNumber,
            'claim' => $claimId,
        ]);

        return null;
    }

    /**
     * `{orderNumber}:created` · `{orderNumber}:cancel:{orderLineId}` ·
     * `{orderNumber}:return:{claimReturnId}` · `{orderNumber}:{paket}:{statü}`.
     *
     * İptal kimliği KALEME bağlı: aynı siparişin ikinci kısmi iptali
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

        $lineId = trim((string) ($order['line']['orderLineId'] ?? ''));

        return match ($order['_kind'] ?? null) {
            'created' => "{$orderNumber}:created",
            'cancelled' => $lineId !== '' ? "{$orderNumber}:cancel:{$lineId}" : null,
            'returned' => isset($order['claimReturnId']) ? "{$orderNumber}:return:{$order['claimReturnId']}" : null,
            'updated' => isset($order['packageId']) && $order['packageId'] !== ''
                ? "{$orderNumber}:{$order['packageId']}:".strtolower((string) ($order['status'] ?? ''))
                : null,
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
            $line = is_array($payload['line'] ?? null) ? $payload['line'] : [];

            return new NormalizedOrderEvent(
                type: $kind,
                externalOrderId: $orderNumber,
                externalRef: $ref,
                payload: array_filter([
                    // Başlık yalnız müşteri iptalinde "Cancelled"; tedarik
                    // edilemeyen KISIM (`UnSupplied`) siparişi iptal göstermez.
                    'status' => $kind === 'cancelled' && strtolower((string) ($payload['status'] ?? '')) === 'cancelled' ? 'Cancelled' : null,
                    'lines' => [array_filter([
                        // Bölmeden sonra kalem kimliği değişebilir; eşleşmezse SKU.
                        'external_line_id' => isset($line['orderLineId']) ? (string) $line['orderLineId'] : null,
                        'sku' => trim((string) ($line['stockCode'] ?? '')),
                        'quantity' => $kind === 'returned' ? (int) ($payload['quantity'] ?? 0) : (int) ($line['quantity'] ?? 0),
                    ], static fn (mixed $v): bool => $v !== null && $v !== '')],
                ], static fn (mixed $v): bool => $v !== null),
                occurredAt: $kind === 'returned'
                    ? self::claimDate($payload['approvedDate'] ?? null)
                    : self::epochMs($payload['lastModifiedDate'] ?? null),
            );
        }

        if ($kind === 'updated') {
            return new NormalizedOrderEvent(
                type: 'updated',
                externalOrderId: $orderNumber,
                externalRef: $ref,
                payload: array_filter([
                    'status' => (string) ($payload['status'] ?? ''),
                    'tracking_number' => $payload['cargoSenderNumber'] ?? null,
                    'carrier' => $payload['cargoProviderName'] ?? null,
                ], static fn (mixed $v): bool => $v !== null && $v !== ''),
                occurredAt: self::epochMs($payload['lastModifiedDate'] ?? null),
            );
        }

        $lines = [];
        $total = 0.0;

        foreach ((array) ($payload['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $quantity = (int) ($line['quantity'] ?? 0);
            $unit = (float) ($line['price'] ?? 0);
            // Mağaza fatura tutarı = birim × adet − mağaza indirimi (resmi formül).
            $lineTotal = isset($line['sellerInvoiceAmount']) ? (float) $line['sellerInvoiceAmount'] : $unit * $quantity;
            $total += $lineTotal;
            $stockCode = trim((string) ($line['stockCode'] ?? ''));

            $lines[] = [
                'external_line_id' => (string) ($line['orderLineId'] ?? ''),
                'sku' => $stockCode,
                // Listing kimliği de `stockCode` (içe aktarma).
                'external_variant_id' => $stockCode !== '' ? $stockCode : null,
                'title' => (string) ($line['productName'] ?? $stockCode),
                'quantity' => $quantity,
                // ⚠️ `price` birim fiyat, indirimler HARİÇ (resmi); KDV dahil
                // sayıldı — DOĞRULANMADI.
                'unit_price' => self::money($unit),
                'line_total' => self::money($lineTotal),
            ];
        }

        $placedAt = self::epochMs($payload['placedAt'] ?? null);

        return new NormalizedOrderEvent(
            type: 'created',
            externalOrderId: $orderNumber,
            externalRef: $ref,
            payload: [
                'type' => 'created',
                'external_number' => $orderNumber,
                'status' => (string) ($payload['status'] ?? 'Created'),
                'currency' => 'TRY',
                'subtotal' => self::money($total),
                'shipping_total' => '0.00',
                'grand_total' => self::money($total),
                'lines' => $lines,
                // Müşteri kimliği de kişisel veri listesinde (§4) — taşınmaz.
                'customer_ref' => [],
            ],
            occurredAt: $placedAt,
            placedAt: $placedAt,
        );
    }

    /**
     * Kalemleri `Picking`'e çeker — `PUT /rest/order/v1/update`.
     *
     * ⚠️ Sınıf notu: çekirdek bunu bugün ÇAĞIRMIYOR; otomatik onay
     * satıcının "Reddet" seçeneğini kaldırır. Hatalı kalem HTTP 200 içinde
     * `content[].status != SUCCESS` ile döner — başarı SAYILMAZ.
     */
    public function acknowledgeOrder(Order $order): AdapterResult
    {
        $lineIds = TenantContext::runAsSystem(fn (): array => $order->lines()
            ->pluck('external_line_id')
            ->map(static fn (mixed $id): string => trim((string) $id))
            ->filter(static fn (string $id): bool => ctype_digit($id))
            ->values()
            ->all());

        if ($lineIds === []) {
            return AdapterResult::failure(ErrorClass::VALIDATION, 'N11 kalem kimliği (orderLineId) yok; sipariş onaylanamaz.');
        }

        $response = $this->client->put(
            self::BASE_URL.'/rest/order/v1/update',
            ['lines' => array_map(static fn (string $id): array => ['lineId' => (int) $id], $lineIds), 'status' => 'Picking'],
            headers: $this->authHeaders(),
        );

        $response->throw();

        $rejected = [];

        foreach (self::rows(self::jsonBody($response)['content'] ?? null) as $row) {
            if (strtoupper((string) ($row['status'] ?? '')) !== 'SUCCESS') {
                $rejected[] = ($row['lineId'] ?? '?').': '.(is_array($row['reasons'] ?? null) ? implode(' ', $row['reasons']) : (string) ($row['reasons'] ?? ''));
            }
        }

        if ($rejected !== []) {
            return AdapterResult::failure(ErrorClass::VALIDATION, 'N11 şu kalemleri onaylamadı: '.implode('; ', $rejected));
        }

        return AdapterResult::success(['acknowledged' => true, 'lines' => count($lineIds)]);
    }

    // ------------------------------------------------------------------- iç

    /**
     * `shipmentPackages` — `sender` her istekte açıkça (sınıf notu).
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function shipmentPackages(array $query): array
    {
        $response = $this->get('/rest/delivery/v1/shipmentPackages', [...$query, 'sender' => self::SENDER]);

        $response->throw();

        return self::jsonBody($response, 'content');
    }

    /**
     * Satıcının gönderdiği kalemler, kişisel veriden arındırılmış.
     * `sender = N11` (n11depom) kalemi stoğa dokunmaz (sınıf notu);
     * `customTextOptionValues` müşteriye özel metin (isim baskısı vb.)
     * taşıyabilir — alınmaz.
     *
     * @param  array<string, mixed>  $package
     * @return list<array<string, mixed>>
     */
    private static function sellerLines(array $package): array
    {
        $keep = ['orderLineId', 'stockCode', 'productId', 'productName', 'barcode', 'quantity', 'price',
            'sellerInvoiceAmount', 'totalSellerDiscountPrice', 'vatRate', 'orderItemLineItemStatusName', 'sender'];

        $lines = [];

        foreach (self::rows($package['lines'] ?? null) as $line) {
            if (! isset($line['orderLineId']) || strtoupper(trim((string) ($line['sender'] ?? self::SENDER))) === 'N11') {
                continue;
            }

            $lines[] = array_intersect_key($line, array_flip($keep));
        }

        return $lines;
    }

    /** Sipariş daha önce alındı mı — önceki turda inbox'a ya da bu turda kayda. */
    private function createdKnown(string $orderNumber): bool
    {
        return isset($this->createdThisRun[$orderNumber]) || $this->alreadyIngested("{$orderNumber}:created");
    }

    private function alreadyIngested(string $eventId): bool
    {
        return TenantContext::runAsSystem(fn (): bool => InboxMessage::query()
            ->where('channel_connection_id', $this->connection->id)
            ->where('external_event_id', $eventId)
            ->exists());
    }

    /** @return array<string, mixed>|null */
    private function ingestedCreated(string $orderNumber): ?array
    {
        $payload = TenantContext::runAsSystem(fn (): mixed => InboxMessage::query()
            ->where('channel_connection_id', $this->connection->id)
            ->where('external_event_id', "{$orderNumber}:created")
            ->first()?->payload);

        return is_array($payload) ? $payload : null;
    }

    /** @param array<string, mixed> $package */
    private static function status(array $package): string
    {
        return strtolower(trim((string) ($package['shipmentPackageStatus'] ?? '')));
    }

    /**
     * Sipariş anı: geçmişteki ilk `Created` kaydı; yoksa en eski kayıt, o da
     * yoksa son değişiklik. Pakette `orderDate` alanı yok (resmi örnek).
     *
     * @param  array<string, mixed>  $package
     */
    private static function placedAtMs(array $package): ?int
    {
        $created = null;
        $earliest = null;

        foreach (self::rows($package['packageHistories'] ?? null) as $history) {
            if (! is_numeric($history['createdDate'] ?? null)) {
                continue;
            }

            $at = (int) $history['createdDate'];
            $earliest = $earliest === null ? $at : min($earliest, $at);

            if (strtolower(trim((string) ($history['status'] ?? ''))) === 'created') {
                $created = $created === null ? $at : min($created, $at);
            }
        }

        return $created ?? $earliest ?? (is_numeric($package['lastModifiedDate'] ?? null) ? (int) $package['lastModifiedDate'] : null);
    }

    /**
     * Gövde JSON nesnesi olmalı (ve istenirse anahtarı taşımalı). Düz metin
     * ya da HTML 200 "hiç kayıt yok" sanılırdı — imleç ilerler, pencere
     * bir daha sorulmazdı.
     *
     * @return array<string, mixed>
     */
    private static function jsonBody(Response $response, ?string $requiredKey = null): array
    {
        $body = json_decode($response->body(), true);

        if (! is_array($body) || ($requiredKey !== null && ! array_key_exists($requiredKey, $body))) {
            throw new RuntimeException('N11 beklenmeyen yanıt döndü: '.mb_substr(trim($response->body()), 0, 120));
        }

        return $body;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * JSON gövde; `listPrice` / `salePrice` TAM 2 ondalıkla ve SAYI olarak
     * (sınıf notu). Değerler önce `"1600.00"` metni olarak yazılır, sonra
     * tırnakları sökülür.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function jsonWithTwoDecimals(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return (string) preg_replace('/"(listPrice|salePrice)":"(-?\d+\.\d{2})"/', '"$1":$2', $json);
    }

    private static function money(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    /** N11 `TL` yazar; çekirdek ISO 4217 bekler. */
    private static function isoCurrency(string $currencyType): string
    {
        return match ($currencyType) {
            'USD', 'EUR' => $currencyType,
            default => 'TRY',
        };
    }

    /** Milisaniye epoch. "GMT+3" kaydırması DOĞRULANMADI (sınıf notu). */
    private static function epochMs(mixed $raw): ?DateTimeImmutable
    {
        return is_numeric($raw) ? new DateTimeImmutable('@'.intdiv((int) $raw, 1000)) : null;
    }

    /** SOAP tarihleri `dd/mm/yyyy`, Türkiye günü. */
    private static function claimDate(mixed $raw): ?DateTimeImmutable
    {
        if (! is_string($raw) || preg_match('#^\d{2}/\d{2}/\d{4}$#', trim($raw)) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!d/m/Y', trim($raw), new \DateTimeZone(self::CHANNEL_TIMEZONE));

        return $date === false ? null : $date;
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        [$key, $secret] = $this->credentials();

        return ['appKey' => $key, 'appSecret' => $secret];
    }

    /**
     * Kasadaki çift. Eksikse istek ATILMAZ — kimliksiz istek 401/403 alır
     * ve "anahtarın yanlış" diye kalıcı hataya düşerdi.
     *
     * @return array{0: string, 1: string}
     */
    private function credentials(): array
    {
        try {
            $secrets = TenantContext::runAsSystem(fn (): array => app(CredentialVault::class)->read($this->connection));
        } catch (Throwable) {
            $secrets = [];
        }

        $key = $secrets[self::APP_KEY_SECRET] ?? null;
        $secret = $secrets[self::APP_SECRET_SECRET] ?? null;

        if (! is_string($key) || $key === '' || ! is_string($secret) || $secret === '') {
            throw new RuntimeException('N11 API anahtarı (appKey/appSecret) kasada yok.');
        }

        return [$key, $secret];
    }

    /** @param array<string, mixed> $query */
    private function get(string $path, array $query): Response
    {
        return $this->client->get(self::BASE_URL.$path, $query, headers: $this->authHeaders());
    }
}
