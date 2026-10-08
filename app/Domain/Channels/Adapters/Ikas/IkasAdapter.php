<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Ikas;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresChannelCurrency;
use App\Domain\Channels\Contracts\DeclaresConnectionSettings;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\RefreshedCredentials;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Contracts\SupportsTokenRefresh;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\ConnectionSettingField;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\SyncOperation;
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
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * ikas kanal adapter'ı — Admin API v2 (GraphQL), Private App kimliği.
 *
 * Araştırma: `docs/YENI-KANALLAR-API-NOTLARI.md` §2. Şema: `IkasQueries`
 * başlığı. Gerçek mağazayla HENÜZ SINANMADI → kanal `is_active = false`.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KİMLİK — SATICININ KENDİ "ÖZEL UYGULAMASI"
 * ─────────────────────────────────────────────────────────────────────
 * Satıcı ikas panelinde (Uygulamalar → Özel Uygulamalar) bir uygulama
 * açar ve `client_id` + bir kez gösterilen `client_secret`'ı 34Pazar'a
 * yapıştırır. Partner onayı gerekmez. Token `client_credentials` ile alınır
 * ve 4 saat yaşar; refresh token YOKTUR, süre dolmadan aynı çiftle yenisi
 * alınır. İlk token bağlanırken (`ConnectChannel` → `TokenRefresher`),
 * sonrakiler `credentials:refresh` taramasıyla gelir — adapter kasaya
 * yazmaz (§7).
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🚨 HATA ORANI KALICI ENGEL GETİRİR — EN ÖNEMLİ KURAL
 * ─────────────────────────────────────────────────────────────────────
 * ikas son 1 saatte hata oranı %25'i geçerse 1 saat, %60 ve üstünde 5
 * günde 9.000 istekte mağazayı KALICI olarak engeller. Bu yüzden:
 *   - kimliksiz istek HİÇ atılmaz (token yoksa istisna, 401 üretilmez);
 *   - GraphQL hatası (200 + `errors`) ve `isSuccess=false` kalem hatası
 *     `VALIDATION`'dır = KALICI, yeniden denenmez;
 *   - lokasyonu bilinmeyen stok yazılmaz, bilinmeyen ürün kimliğiyle
 *     istek kurulmaz.
 *
 * ─────────────────────────────────────────────────────────────────────
 * STOK LOKASYON BAŞINADIR
 * ─────────────────────────────────────────────────────────────────────
 * `saveVariantStocks` `stockLocationId` ister. Satıcı ayar ekranından
 * lokasyon seçer (`STOCK_LOCATION_KEY`); mağazada TEK lokasyon varsa seçim
 * beklenmeden o kullanılır. Birden çok lokasyon varsa ve seçilmemişse stok
 * YAZILMAZ — rastgele birine yazmak, ötekindeki stoğun da satılabilir
 * kalması yüzünden fazla satış demektir.
 *
 * ─────────────────────────────────────────────────────────────────────
 * WEBHOOK YOK (BU SÜRÜMDE) — YOKLAMA
 * ─────────────────────────────────────────────────────────────────────
 * ikas webhook'u 3 denemeden sonra olayı bırakır; asıl kaynak zaten
 * `updatedAt` yoklaması olmalıydı. İmza doğrulaması yine de yazıldı
 * (gövdedeki `data` alanının HMAC-SHA256'sı, anahtar `client_secret` —
 * `@ikas/admin-api-client` `validateIkasWebhookSignature`).
 */
final class IkasAdapter implements ChannelAdapter, DeclaresChannelCurrency, DeclaresConnectionSettings, SupportsCatalogImport, SupportsInventory, SupportsOrders, SupportsPricing, SupportsTokenRefresh
{
    use DeclaresRequestQuota;

    /** Mağaza adı (`{magaza}.myikas.com`) — hesap kimliği. SIR DEĞİL. */
    public const STORE_NAME_KEY = 'ikas_store_name';

    /** Stoğun yazıldığı lokasyon (`settings`). */
    public const STOCK_LOCATION_KEY = 'ikas_stock_location_id';

    /** Mağaza para birimi (`settings`). Yoksa TRY. */
    public const CURRENCY_KEY = 'ikas_currency';

    public const DEFAULT_CURRENCY = 'TRY';

    /** Kasadaki uygulama çifti. */
    public const CLIENT_ID_SECRET = 'client_id';

    public const CLIENT_SECRET_SECRET = 'client_secret';

    /** Şema üst sınırı 200; içe aktarmada tek sayfa. */
    private const PAGE_SIZE = 100;

    /** Toplu yazma partisi — belgede sınır yok, tutucu tutuldu. */
    private const MAX_BATCH = 100;

    /** Mutabakatta tek sorguda sorulan ürün sayısı. */
    private const READ_CHUNK = 50;

    /** Sipariş durumları: henüz gerçek sipariş OLMAYANLAR. */
    private const NOT_YET_ORDERS = ['DRAFT', 'WAITING_UPSELL_ACTION'];

    /**
     * Ürün satıcıya GERİ DÖNMÜŞ demek olan kalem durumu.
     *
     * Yalnız `REFUND_DELIVERED` (iade paketi satıcıya ulaştı). `REFUNDED`
     * para iadesidir ve ürünün döndüğünü SÖYLEMEZ (ürünsüz iade olabilir);
     * stoğa eklenseydi gelmeyen ürün satılırdı. Hepsiburada'nın `Accepted`
     * kuralının aynısı. DOĞRULANMADI: ikas satıcı akışında bu durumun hep
     * görünüp görünmediği ilk gerçek iadede bakılacak.
     */
    private const RETURNED_LINE_STATUS = 'REFUND_DELIVERED';

    /** Kanal kayıtlarında olmayan durum için bellekte tutulan lokasyon. */
    private ?string $resolvedLocation = null;

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
     * `getMerchant` — ve DÖNEN MAĞAZA BAĞLANAN MAĞAZA MI?
     *
     * Satıcı başka mağazanın uygulama çiftini yapıştırırsa token alınır ve
     * her çağrı 200 döner; stok YANLIŞ mağazaya yazılırdı. Mağaza adı
     * eşleşmiyorsa bağlantı sağlıklı SAYILMAZ.
     */
    public function healthCheck(): HealthResult
    {
        $startedAt = hrtime(true);

        try {
            $merchant = $this->graphql(IkasQueries::MERCHANT)['getMerchant'] ?? null;
        } catch (Throwable $e) {
            return HealthResult::unhealthy($e->getMessage());
        }

        if (! is_array($merchant)) {
            return HealthResult::unhealthy('ikas mağaza bilgisi dönmedi.');
        }

        $expected = $this->storeName();
        $actual = strtolower(trim((string) ($merchant['storeName'] ?? '')));

        if ($expected !== null && $actual !== '' && $actual !== $expected) {
            return HealthResult::unhealthy(
                "Bu uygulama bilgileri \"{$actual}\" mağazasına ait, \"{$expected}\" değil."
            );
        }

        return HealthResult::healthy((int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    /**
     * 10 saniyede 50 istek = 5/sn. Kova bağlantı başınadır; patlama
     * payı küçük tutulur — 429 da hata oranına sayılır.
     */
    public function rateLimitProfile(): RateLimitProfile
    {
        $profile = $this->connection->channelType?->rate_limit_profile;

        return is_array($profile) && $profile !== []
            ? RateLimitProfile::fromArray($profile)
            : new RateLimitProfile(requestsPerSecond: 4, burstCapacity: 8);
    }

    /**
     * GraphQL hatası 200 içinde gelir ve `IkasGraphQLException` olur.
     *
     * Kimlik hatası (`UNAUTHENTICATED`/`FORBIDDEN`) dışındaki GraphQL hatası
     * KALICIDIR: sorgu ya da girdi yanlıştır ve aynı istek aynı hatayı verir
     * — yeniden denemek yalnızca hata oranını büyütür (sınıf notu).
     */
    public function classifyError(Throwable $e): ErrorClass
    {
        if ($e instanceof IkasGraphQLException) {
            return match ($e->errorCode) {
                'UNAUTHENTICATED', 'FORBIDDEN', 'UNAUTHORIZED' => ErrorClass::AUTHENTICATION,
                'INTERNAL_SERVER_ERROR' => ErrorClass::SERVER_ERROR,
                default => ErrorClass::VALIDATION,
            };
        }

        if ($e instanceof ConnectionException || ! $e instanceof RequestException) {
            return ErrorClass::NETWORK;
        }

        $status = $e->response->status();

        return match (true) {
            $status === 429 => ErrorClass::RATE_LIMITED,
            $status === 401, $status === 403 => ErrorClass::AUTHENTICATION,
            $status === 404 => ErrorClass::NOT_FOUND,
            $status === 408 => ErrorClass::TIMEOUT,
            $status >= 500 => ErrorClass::SERVER_ERROR,
            $status >= 400 => ErrorClass::VALIDATION,
            default => ErrorClass::SERVER_ERROR,
        };
    }

    // ------------------------------------------------------------- webhook

    /**
     * İmza BAŞLIKTA DEĞİL GÖVDEDEDİR: `{"data": "<json metni>", "signature":
     * hex(hmac_sha256(data, client_secret))}`. `data` bir METİNDİR ve
     * imza o metnin baytları üzerinden alınır — dış zarfı ayrıştırmak
     * imzalı baytları değiştirmez.
     *
     * Sır yoksa REDDEDİLİR (kabul etmek imzasız sipariş enjeksiyonu olurdu).
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function verifyWebhookSignature(string $raw, array $headers): bool
    {
        $envelope = json_decode($raw, true);

        if (! is_array($envelope) || ! is_string($envelope['data'] ?? null) || ! is_string($envelope['signature'] ?? null)) {
            return false;
        }

        $secret = TenantContext::runAsSystem(fn (): array => $this->readSecrets())[self::CLIENT_SECRET_SECRET] ?? null;

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $envelope['data'], $secret), strtolower($envelope['signature']));
    }

    /** Webhook kapalı (sınıf notu); kimlik başlıkta taşınmaz. */
    public function extractEventId(array $headers): ?string
    {
        return null;
    }

    public function extractEventType(array $headers): string
    {
        return 'unknown';
    }

    // ------------------------------------------------------- token yenileme

    /**
     * `client_credentials` ile yeni token — KASAYA YAZMAZ (§7).
     *
     * Refresh token yoktur; uygulama çifti korunur, yalnız `access_token`
     * değişir. Çift yoksa istek atılmaz.
     */
    public function refreshCredentials(): RefreshedCredentials
    {
        $secrets = TenantContext::runAsSystem(
            fn (): array => app(CredentialVault::class)->read($this->connection)
        );

        $clientId = $secrets[self::CLIENT_ID_SECRET] ?? null;
        $clientSecret = $secrets[self::CLIENT_SECRET_SECRET] ?? null;

        if (! is_string($clientId) || $clientId === '' || ! is_string($clientSecret) || $clientSecret === '') {
            throw new RuntimeException('ikas uygulama bilgileri (client_id / client_secret) kasada yok.');
        }

        $response = $this->client->post(
            IkasQueries::TOKEN_URL,
            [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ],
            asForm: true,
        );

        $response->throw();

        $access = $response->json('access_token');

        if (! is_string($access) || $access === '') {
            throw new RuntimeException('ikas token yanıtı access token taşımıyor.');
        }

        $seconds = $response->json('expires_in');

        return new RefreshedCredentials(
            secrets: [...$secrets, 'access_token' => $access],
            // Süre yoksa UYDURULMAZ (Etsy `expiryFrom` kuralı).
            expiresAt: is_numeric($seconds) ? new DateTimeImmutable('@'.(time() + (int) $seconds)) : null,
        );
    }

    /**
     * Süre dolmadan 1 saat önce: 4 saatlik token'a tarama (15 dk) en az
     * dört deneme hakkı verir. Hata oranı yüzünden pay geniş tutuldu —
     * ölü token'la atılan her istek bir hata daha demektir.
     */
    public function refreshLeadSeconds(): int
    {
        return 3600;
    }

    public function tokenEndpointFragment(): ?string
    {
        return IkasQueries::TOKEN_PATH;
    }

    // ------------------------------------------------------------- ayarlar

    public function missingConnectionSettings(): array
    {
        // Tek lokasyonlu mağazada seçim gerekmez (sınıf notu) — ama bunu
        // bilmek ağ çağrısı ister ve bu metot ağa çıkmaz. Seçilmemişse
        // eksik GÖSTERİLİR; satıcı ekranda tek seçeneği görür.
        return $this->setting(self::STOCK_LOCATION_KEY) === null ? [self::STOCK_LOCATION_KEY] : [];
    }

    public function connectionSettingFields(): array
    {
        $error = null;
        $locations = [];

        try {
            $locations = $this->stockLocations();
        } catch (Throwable $e) {
            Log::warning('ikas.settings_options_unavailable', ['connection' => $this->connection->id, 'error' => $e->getMessage()]);
            $error = __('ikas\'tan okunamadı. Bağlantının sağlıklı olduğundan emin ol ve sayfayı yenile.');
        }

        return [
            new ConnectionSettingField(
                key: self::STOCK_LOCATION_KEY,
                label: __('Stok lokasyonu'),
                options: array_map(static fn (array $location): array => [
                    'value' => (string) $location['id'],
                    'label' => (string) ($location['name'] ?? $location['id']),
                    'usable' => true,
                ], $locations),
                value: $this->setting(self::STOCK_LOCATION_KEY),
                hint: __('34Pazar stoğu ikas\'ta bu lokasyona yazar. Birden çok lokasyonun varsa ötekilerdeki stok ayrıca satılabilir kalır; satışı tek lokasyondan yap.'),
                optionsError: $error,
            ),
        ];
    }

    public function channelCurrency(): ?string
    {
        return $this->setting(self::CURRENCY_KEY) !== null
            ? strtoupper((string) $this->setting(self::CURRENCY_KEY))
            : self::DEFAULT_CURRENCY;
    }

    // ------------------------------------------------------------ içe aktarma

    /**
     * Ürün sayfası — HER VARYANT ayrı ürün (Etsy/Trendyol ile aynı model).
     *
     * Kimlik varyant `id`'si; ürün `id`'si `external_parent_id` olur —
     * stok ve fiyat yazımı İKİSİNİ BİRDEN ister. İmleç sayfa numarasıdır.
     *
     * Silinmiş ürün/varyant ATLANIR. Varyant adı seçenek değerleriyle
     * zenginleşir ("Tişört — Kırmızı / M"); yoksa her varyant aynı adla
     * gelir ve panelde ayırt edilemez.
     *
     * ⚠️ SKU'SUZ VARYANT: ikas kimliği UUID'dir, sonu rakam değildir ve
     * çekirdeğin `autoSku()`'su onu üretemez. `IKAS-{kimlik}` SKU'su
     * verilir; sipariş satırı yine `external_variant_id` ile eşlenir.
     *
     * Görsel ALINMAZ: şema yalnız `imageId` verir, adres biçimi belgede yok.
     */
    public function fetchProductPage(?string $cursor = null): RemoteProductPage
    {
        $page = $cursor === null ? 1 : max(1, (int) $cursor);

        $list = $this->graphql(IkasQueries::PRODUCTS, [
            'pagination' => ['page' => $page, 'limit' => self::PAGE_SIZE],
        ])['listProduct'] ?? [];

        $location = $this->setting(self::STOCK_LOCATION_KEY);
        $products = [];

        foreach ((array) ($list['data'] ?? []) as $product) {
            if (! is_array($product) || ($product['deleted'] ?? false) === true) {
                continue;
            }

            foreach ((array) ($product['variants'] ?? []) as $variant) {
                if (is_array($variant) && ($variant['deleted'] ?? false) !== true && isset($variant['id'])) {
                    $products[] = $this->toRemoteProduct($product, $variant, $location);
                }
            }
        }

        $hasMore = ($list['hasNext'] ?? false) === true;

        return new RemoteProductPage(
            products: $products,
            nextCursor: $hasMore ? (string) ($page + 1) : null,
            hasMore: $hasMore,
        );
    }

    /** 100'lük sayfayla 10.000 ürün. */
    public function maxImportPages(): int
    {
        return 100;
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>  $variant
     */
    private function toRemoteProduct(array $product, array $variant, ?string $location): RemoteProduct
    {
        $variantId = (string) $variant['id'];
        $productId = (string) ($product['id'] ?? '');
        $sku = trim((string) ($variant['sku'] ?? ''));
        $price = self::defaultPrice($variant);

        $values = array_filter(array_map(
            static fn (mixed $v): string => is_array($v) ? trim((string) ($v['variantValueName'] ?? '')) : '',
            (array) ($variant['variantValues'] ?? []),
        ));
        $title = trim((string) ($product['name'] ?? ''));
        $title = $values === [] ? $title : trim($title.' — '.implode(' / ', $values));

        $barcode = collect((array) ($variant['barcodeList'] ?? []))->first(static fn (mixed $b): bool => is_string($b) && trim($b) !== '');

        return new RemoteProduct(
            externalId: $variantId,
            sku: $sku !== '' ? $sku : 'IKAS-'.strtoupper(str_replace('-', '', $variantId)),
            title: $title !== '' ? $title : ($sku !== '' ? $sku : $variantId),
            price: $price === null ? null : self::money(self::effectivePrice($price)),
            quantity: self::stockOf($variant, $location),
            description: is_string($product['description'] ?? null) && trim($product['description']) !== '' ? $product['description'] : null,
            brand: is_string($product['brand']['name'] ?? null) ? $product['brand']['name'] : null,
            barcode: is_string($barcode) ? trim($barcode) : null,
            status: ($variant['isActive'] ?? false) === true ? 'active' : 'inactive',
            images: [],
            raw: ['product_id' => $productId, 'variant' => $variant],
            listingIdentity: [
                'external_id' => $variantId,
                'external_parent_id' => $productId,
            ],
            currency: is_string($price['currency'] ?? null) && $price['currency'] !== '' ? strtoupper($price['currency']) : $this->channelCurrency(),
        );
    }

    // ---------------------------------------------------------------- stok

    /**
     * MUTLAK stok — `saveVariantStocks`, seçili (ya da tek) lokasyona.
     *
     * Ürün kimliği (`external_parent_id`) olmayan kalem GÖNDERİLMEZ ve
     * kalem hatası olarak döner: eksik kimlikle kurulan istek ikas'ta
     * hata sayılır.
     */
    public function pushInventory(InventoryPushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            return AdapterResult::success(['pushed' => 0]);
        }

        $location = $this->stockLocationForWrite();

        if ($location === null) {
            return AdapterResult::failure(
                ErrorClass::VALIDATION,
                'ikas stok lokasyonu seçilmedi. Kanal ayarlarından stoğun yazılacağı lokasyonu seç.',
            );
        }

        $items = $batch->toArray();
        $parents = $this->parentIdsFor(array_column($items, 'listing_id'));

        $inputs = [];
        $missing = [];

        foreach ($items as $index => $item) {
            $productId = $parents[$item['listing_id']] ?? null;

            if ($productId === null) {
                $missing[$index] = 'ikas ürün kimliği bilinmiyor; ürünü kanaldan yeniden içe aktar.';

                continue;
            }

            $inputs[$index] = [
                'productId' => $productId,
                'variantId' => (string) $item['external_id'],
                'stockLocationId' => $location,
                'stockCount' => (int) $item['quantity'],
                'deleted' => false,
            ];
        }

        $result = $inputs === []
            ? ['isSuccess' => true, 'errorInputs' => []]
            : $this->graphql(IkasQueries::SAVE_STOCKS, ['input' => ['stockInputs' => array_values($inputs)]])['saveVariantStocks'] ?? [];

        return $this->bulkResult($result, $inputs, $missing, $batch->operations(), $batch->count());
    }

    public function maxInventoryBatchSize(): int
    {
        return self::MAX_BATCH;
    }

    /**
     * Uzak stok — ürünler `id` süzgeciyle okunur, seçili lokasyonun sayısı.
     *
     * @param  list<Listing>  $listings
     */
    public function fetchInventory(array $listings): RemoteInventorySnapshot
    {
        $location = $this->setting(self::STOCK_LOCATION_KEY);
        $quantities = [];

        foreach ($this->remoteVariants($listings) as $variantId => $variant) {
            $quantities[$variantId] = self::stockOf($variant, $location);
        }

        return new RemoteInventorySnapshot($quantities, new DateTimeImmutable);
    }

    // --------------------------------------------------------------- fiyat

    /**
     * MUTLAK fiyat — `updateVariantPrices`, varsayılan fiyat listesi.
     *
     * ikas'ta `sellPrice` liste fiyatı, `discountPrice` indirimli satış
     * fiyatıdır. Karşılaştırma fiyatı satış fiyatından yüksekse ikisi
     * birlikte gider; değilse yalnız `sellPrice` — eski indirim kalırsa
     * müşteri eski fiyatı görürdü, bu yüzden `discountPrice` açıkça `null`.
     */
    public function pushPrices(PricePushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            return AdapterResult::success(['pushed' => 0]);
        }

        $parents = $this->parentIdsFor(array_column($batch->items, 'listing_id'));
        $currency = $this->channelCurrency();

        $inputs = [];
        $missing = [];

        foreach ($batch->items as $index => $item) {
            $productId = $parents[$item['listing_id']] ?? null;

            if ($productId === null) {
                $missing[$index] = 'ikas ürün kimliği bilinmiyor; ürünü kanaldan yeniden içe aktar.';

                continue;
            }

            $price = round((float) $item['price'], 2);
            $compare = isset($item['compare_at_price']) && is_numeric($item['compare_at_price'])
                ? round((float) $item['compare_at_price'], 2)
                : null;
            $discounted = $compare !== null && $compare > $price;

            $inputs[$index] = [
                'productId' => $productId,
                'variantId' => (string) $item['external_id'],
                'price' => [
                    'sellPrice' => $discounted ? $compare : $price,
                    'discountPrice' => $discounted ? $price : null,
                    'currency' => $currency,
                ],
                'deleted' => false,
            ];
        }

        $result = $inputs === []
            ? ['isSuccess' => true, 'errorInputs' => []]
            : $this->graphql(IkasQueries::UPDATE_PRICES, ['input' => [
                'priceListId' => null,
                'variantPriceInputs' => array_values($inputs),
            ]])['updateVariantPrices'] ?? [];

        return $this->bulkResult($result, $inputs, $missing, $batch->operations(), $batch->count());
    }

    /** @param list<Listing> $listings */
    public function fetchPrices(array $listings): RemotePriceSnapshot
    {
        $prices = [];

        foreach ($this->remoteVariants($listings) as $variantId => $variant) {
            $price = self::defaultPrice($variant);

            if ($price !== null) {
                $prices[$variantId] = self::money(self::effectivePrice($price));
            }
        }

        return new RemotePriceSnapshot($prices, new DateTimeImmutable);
    }

    public function maxPriceBatchSize(): int
    {
        return self::MAX_BATCH;
    }

    /**
     * Toplu yanıt → sonuç. Kalem hatası KALICIDIR (sınıf notu).
     *
     * Eşleştirme KONUMLA değil `variantId` ile yapılır (eBay kuralı).
     * Hatalı kalemin operasyonu bulunamazsa KISMİ başarı denmez, bütün
     * parti başarısız döner: hatanın sessizce "senkron" damgası yemesinden
     * iyidir.
     *
     * @param  array<string, mixed>  $result
     * @param  array<int, array<string, mixed>>  $inputs  parti sırası → gönderilen girdi
     * @param  array<int, string>  $missing  parti sırası → gönderilmeyen kalemin nedeni
     * @param  list<SyncOperation>  $operations
     */
    private function bulkResult(array $result, array $inputs, array $missing, array $operations, int $count): AdapterResult
    {
        $failed = $missing;

        $indexByVariant = [];
        foreach ($inputs as $index => $input) {
            $indexByVariant[$input['variantId']] = $index;
        }

        foreach ((array) ($result['errorInputs'] ?? []) as $error) {
            $index = is_array($error) ? ($indexByVariant[(string) ($error['variantId'] ?? '')] ?? null) : null;

            if ($index === null) {
                return AdapterResult::failure(ErrorClass::VALIDATION, 'ikas tanımadığımız bir kalemi reddetti.');
            }

            $failed[$index] = 'ikas bu varyantı reddetti (ürün ya da varyant ikas\'ta silinmiş olabilir).';
        }

        if ($failed === [] && ($result['isSuccess'] ?? false) !== true) {
            return AdapterResult::failure(ErrorClass::VALIDATION, 'ikas toplu güncellemeyi kabul etmedi.');
        }

        if ($failed === []) {
            return AdapterResult::success(['pushed' => $count]);
        }

        $byOperation = [];

        foreach ($failed as $index => $message) {
            $operation = $operations[$index] ?? null;

            if ($operation === null) {
                return AdapterResult::failure(ErrorClass::VALIDATION, $message);
            }

            $byOperation[$operation->id] = $message;
        }

        return AdapterResult::partial(
            failedOperations: $byOperation,
            data: ['pushed' => $count - count($byOperation)],
            errorClass: ErrorClass::VALIDATION,
        );
    }

    /**
     * Uzak varyantlar, varyant kimliğiyle. Ürün kimliği bilinmeyen listing
     * sorulmaz; hiç yoksa çağrı YAPILMAZ (süzgeçsiz sorgu bütün kataloğu
     * getirirdi). Başarısız yanıt yükselir: boş sonuç "kanalda yok" sanılırdı.
     *
     * @param  list<Listing>  $listings
     * @return array<string, array<string, mixed>>
     */
    private function remoteVariants(array $listings): array
    {
        $productIds = array_values(array_unique(array_filter(array_map(
            static fn (Listing $listing): string => (string) ($listing->external_parent_id ?? ''),
            $listings,
        ))));

        $variants = [];

        foreach (array_chunk($productIds, self::READ_CHUNK) as $chunk) {
            $list = $this->graphql(IkasQueries::PRODUCTS, [
                'pagination' => ['page' => 1, 'limit' => self::READ_CHUNK],
                'id' => ['in' => $chunk],
            ])['listProduct'] ?? [];

            foreach ((array) ($list['data'] ?? []) as $product) {
                foreach ((array) (is_array($product) ? ($product['variants'] ?? []) : []) as $variant) {
                    if (is_array($variant) && isset($variant['id']) && ($variant['deleted'] ?? false) !== true) {
                        $variants[(string) $variant['id']] = $variant;
                    }
                }
            }
        }

        return $variants;
    }

    /**
     * Listing → ikas ürün kimliği (`external_parent_id`).
     *
     * @param  list<string>  $listingIds
     * @return array<string, string>
     */
    private function parentIdsFor(array $listingIds): array
    {
        return TenantContext::runAsSystem(fn (): array => Listing::query()
            ->where('channel_connection_id', $this->connection->id)
            ->whereIn('id', $listingIds)
            ->whereNotNull('external_parent_id')
            ->pluck('external_parent_id', 'id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all());
    }

    // ------------------------------------------------------------- siparişler

    /**
     * Sipariş yoklaması — `updatedAt ≥ since`, eskiden yeniye, sayfa sayfa.
     *
     * HER SİPARİŞ BİRDEN ÇOK KAYIT ÜRETEBİLİR (Hepsiburada'nın kalem
     * kuralı): bir "created" + her İPTAL edilmiş kalem için bir "cancelled"
     * + her İADESİ TESLİM ALINMIŞ kalem için bir "returned". Kimlik kaleme
     * bağlıdır; sipariş durumuna bağlansaydı ikinci kısmi iptal
     * (`PARTIALLY_CANCELLED` → `PARTIALLY_CANCELLED`) aynı kimlikle YUTULUR
     * ve stoğu geri eklenmezdi.
     *
     * "created" İPTAL EDİLMİŞ KALEMLERİ DE taşır ve iptal kaydı onları geri
     * ekler: sipariş ilk kez zaten kısmen iptal hâlinde görülürse yalnız
     * açık kalemleri saymak, sonradan bölünen kalemin (ikas kısmi iptalde
     * yeni kalem açar, `originalOrderLineItemId`) stoğunu yanlış bırakırdı.
     *
     * Saat MİLİSANİYE epoch'tur (`@ikas/admin-api-client` `parseInt`).
     */
    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage
    {
        $page = $cursor === null ? 1 : max(1, (int) $cursor);

        $list = $this->graphql(IkasQueries::ORDERS, [
            'pagination' => ['page' => $page, 'limit' => self::PAGE_SIZE],
            'updatedAt' => ['gte' => $since->getTimestamp() * 1000],
        ])['listOrder'] ?? [];

        $records = [];

        foreach ((array) ($list['data'] ?? []) as $order) {
            if (! is_array($order) || ! isset($order['id']) || in_array($order['status'] ?? null, self::NOT_YET_ORDERS, true)) {
                continue;
            }

            $records[] = ['_kind' => 'created', ...$order];

            foreach ((array) ($order['orderLineItems'] ?? []) as $line) {
                if (! is_array($line) || ($line['deleted'] ?? false) === true) {
                    continue;
                }

                $kind = match ($line['status'] ?? null) {
                    'CANCELLED' => 'cancelled',
                    self::RETURNED_LINE_STATUS => 'returned',
                    default => null,
                };

                if ($kind !== null) {
                    $records[] = [
                        '_kind' => $kind,
                        'id' => $order['id'],
                        'orderNumber' => $order['orderNumber'] ?? null,
                        'status' => $order['status'] ?? null,
                        'line' => $line,
                    ];
                }
            }
        }

        $hasMore = ($list['hasNext'] ?? false) === true;

        return new OrderPage(
            orders: $records,
            nextCursor: $hasMore ? (string) ($page + 1) : null,
            hasMore: $hasMore,
        );
    }

    /**
     * `{id}:created` · `{id}:cancel:{kalem}` · `{id}:return:{kalem}`.
     *
     * Kimlik sipariş `id`'sidir (`orderNumber` değil): numara mağaza içi
     * sıradır ve satıcı arayüzünde görünür ama değişmez kimlik `id`'dir.
     *
     * @param  array<string, mixed>  $order
     */
    public function pollingEventIdFor(array $order): ?string
    {
        $id = trim((string) ($order['id'] ?? ''));

        if ($id === '') {
            return null;
        }

        $lineId = trim((string) ($order['line']['id'] ?? ''));

        return match ($order['_kind'] ?? null) {
            'created' => "{$id}:created",
            'cancelled' => $lineId !== '' ? "{$id}:cancel:{$lineId}" : null,
            'returned' => $lineId !== '' ? "{$id}:return:{$lineId}" : null,
            default => null,
        };
    }

    /**
     * Kanonik olay. Kişisel veri TAŞINMAZ; müşteri yalnız kimlikle.
     *
     * İptal/iade kalemi kendi kimliğiyle gider, eşleşmezse SKU ile: kısmi
     * iptalde ikas yeni kalem açar ve o kimlik bizde yoktur — SKU, bölünen
     * asıl kaleme düşer.
     */
    public function parseOrderEvent(InboxMessage $message): ?NormalizedOrderEvent
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($message->payload) ? $message->payload : [];
        $id = trim((string) ($payload['id'] ?? ''));

        if ($id === '') {
            return null;
        }

        $ref = $message->external_event_id ?? $this->pollingEventIdFor($payload);
        $kind = $payload['_kind'] ?? null;

        if ($kind === 'cancelled' || $kind === 'returned') {
            $line = is_array($payload['line'] ?? null) ? $payload['line'] : [];

            return new NormalizedOrderEvent(
                type: $kind,
                externalOrderId: $id,
                externalRef: $ref,
                // BAŞLIK DURUMU yalnız bütün sipariş iptal edildiyse yazılır;
                // tek kalemin iptali siparişi "iptal" göstermemeli.
                payload: array_filter([
                    'status' => ($payload['status'] ?? null) === 'CANCELLED' ? 'CANCELLED' : null,
                    'lines' => [array_filter([
                        'external_line_id' => isset($line['id']) ? (string) $line['id'] : null,
                        'sku' => self::lineSku($line),
                        'quantity' => (int) ($line['quantity'] ?? 0),
                    ], static fn (mixed $v): bool => $v !== null && $v !== '')],
                ], static fn (mixed $v): bool => $v !== null),
                occurredAt: self::timestamp($line['statusUpdatedAt'] ?? null),
            );
        }

        $lines = [];

        foreach ((array) ($payload['orderLineItems'] ?? []) as $line) {
            if (! is_array($line) || ($line['deleted'] ?? false) === true) {
                continue;
            }

            $quantity = (int) ($line['quantity'] ?? 0);
            $unit = $line['finalUnitPrice'] ?? $line['unitPrice'] ?? $line['price'] ?? 0;

            $lines[] = [
                'external_line_id' => (string) ($line['id'] ?? ''),
                'sku' => self::lineSku($line),
                'external_variant_id' => isset($line['variant']['id']) ? (string) $line['variant']['id'] : null,
                'title' => (string) ($line['variant']['name'] ?? ''),
                'quantity' => $quantity,
                'unit_price' => self::money($unit),
                'line_total' => self::money($line['finalPrice'] ?? ((float) $unit * $quantity)),
            ];
        }

        $placedAt = self::timestamp($payload['orderedAt'] ?? null);

        return new NormalizedOrderEvent(
            type: 'created',
            externalOrderId: $id,
            externalRef: $ref,
            payload: [
                'type' => 'created',
                'external_number' => (string) ($payload['orderNumber'] ?? $id),
                'status' => (string) ($payload['status'] ?? 'CREATED'),
                'financial_status' => is_string($payload['orderPaymentStatus'] ?? null) ? strtolower($payload['orderPaymentStatus']) : null,
                'currency' => (string) ($payload['currencyCode'] ?? $this->channelCurrency()),
                'subtotal' => self::money($payload['totalPrice'] ?? 0),
                'grand_total' => self::money($payload['totalFinalPrice'] ?? $payload['totalPrice'] ?? 0),
                'lines' => $lines,
                'customer_ref' => array_filter(['external_customer_id' => isset($payload['customerId']) ? (string) $payload['customerId'] : null]),
            ],
            occurredAt: $placedAt,
            placedAt: $placedAt,
        );
    }

    /** Onay adımı yok (Etsy/Shopify gibi) — NO-OP görünür kılınır. */
    public function acknowledgeOrder(Order $order): AdapterResult
    {
        return AdapterResult::success(['acknowledged' => true]);
    }

    // ------------------------------------------------------------------- iç

    /**
     * GraphQL isteği — `data` döner.
     *
     * Token YOKSA istek ATILMAZ (sınıf notu: kimliksiz istek hata oranına
     * yazılır). Taşıma hatası `RequestException`, gövdedeki `errors`
     * `IkasGraphQLException` olarak yükselir; `data` hiç yoksa da
     * yükselir — boş sonuç "kanalda hiçbir şey yok" sanılırdı.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function graphql(string $query, array $variables = []): array
    {
        $token = TenantContext::runAsSystem(fn (): array => $this->readSecrets())['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('ikas erişim anahtarı henüz alınmadı — uygulama bilgileriyle yeniden bağlan.');
        }

        $response = $this->client->post(
            IkasQueries::GRAPHQL_URL,
            $variables === [] ? ['query' => $query] : ['query' => $query, 'variables' => $variables],
        );

        $response->throw();

        $errors = $response->json('errors');

        if (is_array($errors) && $errors !== []) {
            throw IkasGraphQLException::fromErrors($errors);
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            throw new IkasGraphQLException('ikas yanıtı veri taşımıyor.', 'NO_DATA');
        }

        return $data;
    }

    /** @return list<array<string, mixed>> */
    private function stockLocations(): array
    {
        $locations = $this->graphql(IkasQueries::STOCK_LOCATIONS)['listStockLocation'] ?? [];

        return array_values(array_filter(
            (array) $locations,
            static fn (mixed $l): bool => is_array($l) && isset($l['id']) && ($l['deleted'] ?? false) !== true,
        ));
    }

    /**
     * Yazılacak lokasyon: seçili olan; seçilmemişse ve mağazada TEK lokasyon
     * varsa o (bellekte, kalıcı yazılmaz — adapter yan etkisizdir).
     */
    private function stockLocationForWrite(): ?string
    {
        $chosen = $this->setting(self::STOCK_LOCATION_KEY);

        if ($chosen !== null) {
            return $chosen;
        }

        if ($this->resolvedLocation === null) {
            $locations = $this->stockLocations();
            $this->resolvedLocation = count($locations) === 1 ? (string) $locations[0]['id'] : '';
        }

        return $this->resolvedLocation === '' ? null : $this->resolvedLocation;
    }

    /**
     * Varyantın stoğu: seçili lokasyonunki; lokasyon seçilmemişse bütün
     * lokasyonların toplamı (satılabilir olan budur).
     *
     * @param  array<string, mixed>  $variant
     */
    private static function stockOf(array $variant, ?string $location): int
    {
        $total = 0.0;

        foreach ((array) ($variant['stocks'] ?? []) as $stock) {
            if (! is_array($stock) || ($stock['deleted'] ?? false) === true) {
                continue;
            }

            if ($location === null || (string) ($stock['stockLocationId'] ?? '') === $location) {
                $total += (float) ($stock['stockCount'] ?? 0);
            }
        }

        return (int) $total;
    }

    /**
     * Varsayılan fiyat listesinin fiyatı (`priceListId` boş); yoksa ilki.
     *
     * @param  array<string, mixed>  $variant
     * @return array<string, mixed>|null
     */
    private static function defaultPrice(array $variant): ?array
    {
        $prices = array_values(array_filter((array) ($variant['prices'] ?? []), 'is_array'));

        foreach ($prices as $price) {
            if (($price['priceListId'] ?? null) === null || $price['priceListId'] === '') {
                return $price;
            }
        }

        return $prices[0] ?? null;
    }

    /**
     * Müşterinin ödediği fiyat: indirim satış fiyatından düşükse indirim.
     *
     * @param  array<string, mixed>  $price
     */
    private static function effectivePrice(array $price): float
    {
        $sell = (float) ($price['sellPrice'] ?? 0);
        $discount = $price['discountPrice'] ?? null;

        return is_numeric($discount) && (float) $discount > 0 && (float) $discount < $sell ? (float) $discount : $sell;
    }

    /** Sayı → "12.50". Para STRING taşınır (kuruş kayması olmasın). */
    private static function money(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    /** @param array<string, mixed> $line */
    private static function lineSku(array $line): string
    {
        return trim((string) ($line['variant']['sku'] ?? ''));
    }

    /** Milisaniye epoch → an. */
    private static function timestamp(mixed $raw): ?DateTimeImmutable
    {
        if (! is_numeric($raw) || (float) $raw <= 0) {
            return null;
        }

        return (new DateTimeImmutable)->setTimestamp(intdiv((int) $raw, 1000));
    }

    /** Mağaza adı (küçük harf) — bağlanırken girilen hesap kimliği. */
    private function storeName(): ?string
    {
        $name = $this->connection->external_account_id ?: ($this->connection->settings[self::STORE_NAME_KEY] ?? null);

        return is_string($name) && trim($name) !== '' ? strtolower(trim($name)) : null;
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
            return app(CredentialVault::class)->read($this->connection);
        } catch (Throwable) {
            return [];
        }
    }
}
