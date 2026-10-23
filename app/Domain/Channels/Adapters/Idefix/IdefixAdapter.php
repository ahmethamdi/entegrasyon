<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Idefix;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresChannelCurrency;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\MapsOrderState;
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
use App\Domain\Orders\Enums\OrderState;
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
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * İdefix (D&R ailesi) satıcı API'si — `merchantapi.idefix.com`.
 *
 * Kaynak: developer.idefix.com (herkese açık, 24 Eki 2026 okundu; 55 sayfa).
 * Notlar: `docs/IDEFIX-API-NOTLARI.md`. BETA kanal — gerçek satıcı hesabıyla
 * SINANMADI (21 Eki kararı: ilk müşteriyle birlikte doğrulanır).
 *
 * KİMLİK: `X-API-KEY: base64(ApiKey:ApiSecret)` + yoldaki satıcı kimliği
 * (`{vendorId}`). Sır adları `api_key`/`api_secret` DEĞİL: o çift
 * `ChannelHttpClient`'ta Basic auth'a çevrilirdi.
 *
 * MODEL: ürün = barkod (`pim/pool` havuzu); stok VE fiyat aynı uçtan
 * (`inventory-upload`, asenkron `batchRequestId`, sonuç `inventory-result`).
 * Sipariş = "shipment" (satıcı başına sevkiyat); kalemler adet başına ayrı
 * satır (her kalem 1 adet — SSS) → barkoda göre toplanır.
 *
 * BİLEREK YAZILMAYANLAR:
 *   - Ürün açma (`pool/create`): S10 kapsamında değil; İdefix'te API ürün
 *     GÜNCELLEMEYİ desteklemiyor (SSS), yalnız stok-fiyat.
 *   - Kargo bildirimi (`update-tracking-number`): belge bu ucun siparişi
 *     platform anlaşmasından SATICININ KENDİ anlaşmasına geçirdiğini
 *     söylüyor. Platform anlaşmalı sevkiyata gönderilseydi kargo bedeli
 *     sessizce satıcıya kayardı; takip adresinin kargo profiliyle uyumu da
 *     doğrulanamıyor. Anlaşmalı kargoda takip numarasını İdefix kendisi
 *     atar.
 *   - Sipariş onayı (`picking`): müşterinin iptal hakkını kapatır; satıcı
 *     paneldeki akışa bırakıldı (Pazarama kararıyla aynı).
 *
 * ⚠️ DOĞRULANMADI (ilk gerçek hesapta bakılacak):
 *   - Yalnız stok içeren `inventory-upload` kaleminin kabul edilip
 *     edilmediği (belgede yalnız `barcode` zorunlu; `NO_PRICE` reddi fiyatı
 *     hiç olmayan ürün için olabilir).
 *   - Sayfa boyutu üst sınırı (belgede yok) — boş sayfa gelene kadar okunur.
 *   - `lastUpdatedAt` saat dilimi (örnekler +03:00; Türkiye saati gönderilir).
 *   - Kalem düzeyi iptal (`itemStatus` değerleri belgede yok) — yalnız
 *     sevkiyat düzeyi iptal okunur.
 *   - `inventory-result`'ın saklama süresi.
 */
final class IdefixAdapter implements ChannelAdapter, DeclaresChannelCurrency, MapsOrderState, SupportsBatchStatus, SupportsCatalogImport, SupportsInventory, SupportsOrders, SupportsPricing
{
    use DeclaresRequestQuota;

    public const BASE_URL = 'https://merchantapi.idefix.com';

    public const API_KEY_SECRET = 'idefix_api_key';

    public const API_SECRET_SECRET = 'idefix_api_secret';

    public const VENDOR_ID_KEY = 'idefix_vendor_id';

    /** SSS: bir istekte en çok 200 kalem. */
    private const MAX_ITEMS_PER_REQUEST = 200;

    private const PAGE_SIZE = 100;

    /** Belgede yok — batch kimliği bir gün yoklanır (DOĞRULANMADI). */
    private const BATCH_RETENTION_SECONDS = 86_400;

    /** Satışa açık / açılabilir ürün statüleri (batch-result "Ürün Statüleri"). */
    private const SELLABLE_STATUSES = ['ready_for_sale', 'auto_matched', 'manual_matched'];

    /**
     * Stok düşmüş siparişi geri alan sevkiyat statüleri.
     *
     * `shipment_split` İPTAL SAYILIR: bölünen sevkiyatın yerine aynı
     * kalemlerle YENİ sevkiyatlar gelir ve onlar ayrıca "created" olur. Eski
     * sevkiyat açık bırakılsaydı stok İKİ KEZ düşerdi (Trendyol `UnPacked`
     * kararının aynısı: net sıfır).
     */
    private const CANCELLED_STATUSES = ['shipment_cancelled', 'shipment_unsupplied', 'shipment_split'];

    private const SHIPPED_STATUSES = ['shipment_in_cargo', 'shipment_undeliver'];

    private const DELIVERED_STATUSES = ['shipment_delivered', 'shipment_approved'];

    /** @var array<string, true> */
    private array $createdThisRun = [];

    /** @var array<string, list<array<string, mixed>>> orderNumber → sevkiyatlar (iade eşlemesi) */
    private array $shipmentsByOrderNumber = [];

    public function __construct(
        private readonly ChannelConnection $connection,
        private readonly ChannelHttpClient $client,
    ) {}

    public function connection(): ChannelConnection
    {
        return $this->connection;
    }

    public function channelCurrency(): ?string
    {
        return 'TRY';
    }

    // ---------------------------------------------------------------- sağlık

    /** `GET pim/pool/{vendorId}/list?page=1&limit=1` — anahtar ve satıcı kimliği birlikte geçer. */
    public function healthCheck(): HealthResult
    {
        $startedAt = hrtime(true);

        try {
            $this->api('GET', '/pim/pool/'.$this->vendorId().'/list', ['page' => 1, 'limit' => 1]);
        } catch (Throwable $e) {
            return HealthResult::unhealthy($e->getMessage());
        }

        return HealthResult::healthy((int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    public function rateLimitProfile(): RateLimitProfile
    {
        $profile = $this->connection->channelType?->rate_limit_profile;

        return is_array($profile) && $profile !== []
            ? RateLimitProfile::fromArray($profile)
            : new RateLimitProfile(requestsPerSecond: 2, burstCapacity: 2, maxConcurrent: 1);
    }

    /** Yanlış anahtar: 401 + `VENDOR_TOKEN_NOT_EXIST` (belge "Authorization"). */
    public function classifyError(Throwable $e): ErrorClass
    {
        if ($e instanceof ConnectionException || ! $e instanceof RequestException) {
            return ErrorClass::NETWORK;
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

    /** Webhook yok (belgenin hiçbir sayfasında geçmiyor); imzasız gövde ASLA kabul edilmez. */
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

    public function tokenEndpointFragment(): ?string
    {
        return null;
    }

    // ------------------------------------------------------------ içe aktarma

    /**
     * Havuzdaki ürünler — kimlik BARKOD (stok-fiyat ucunun anahtarı).
     *
     * Yalnız satışa açılabilir statüler alınır; incelemedeki/reddedilen ürüne
     * stok gönderimi `PRODUCT_NOT_FOUND` ile düşerdi. Sayfa boyutunun üst
     * sınırı belgede yok: istenenden az satır "son sayfa" sayılmaz (sınır
     * 50 ise 100 isteyip 50 alan okuma kataloğun yarısını sessizce
     * kaybederdi) — BOŞ sayfa gelene kadar okunur.
     */
    public function fetchProductPage(?string $cursor = null): RemoteProductPage
    {
        $page = $cursor === null ? 1 : max(1, (int) $cursor);

        $body = $this->json($this->api('GET', '/pim/pool/'.$this->vendorId().'/list', [
            'page' => $page,
            'limit' => self::PAGE_SIZE,
        ]));

        $rows = self::rows($body['products'] ?? $body['items'] ?? null);
        $products = [];

        foreach ($rows as $row) {
            $barcode = trim((string) ($row['barcode'] ?? ''));

            if ($barcode === '' || ! in_array((string) ($row['status'] ?? ''), self::SELLABLE_STATUSES, true)) {
                continue;
            }

            $products[] = $this->toRemoteProduct($row, $barcode);
        }

        $hasMore = $rows !== [];

        return new RemoteProductPage(
            products: $products,
            nextCursor: $hasMore ? (string) ($page + 1) : null,
            hasMore: $hasMore,
        );
    }

    public function maxImportPages(): int
    {
        return 200;
    }

    /** @param array<string, mixed> $row */
    private function toRemoteProduct(array $row, string $barcode): RemoteProduct
    {
        $stockCode = trim((string) ($row['vendorStockCode'] ?? ''));
        $mainId = trim((string) ($row['productMainId'] ?? ''));
        $images = [];

        foreach ((array) ($row['images'] ?? []) as $image) {
            $url = is_array($image) ? (string) ($image['url'] ?? '') : '';

            if (str_starts_with($url, 'https://')) {
                $images[] = $url;
            }
        }

        return new RemoteProduct(
            externalId: $barcode,
            sku: $stockCode !== '' ? $stockCode : $barcode,
            title: trim((string) ($row['title'] ?? '')) ?: $barcode,
            price: is_numeric($row['price'] ?? null) ? self::money($row['price']) : null,
            quantity: (int) ($row['inventoryQuantity'] ?? 0),
            description: is_string($row['description'] ?? null) ? $row['description'] : null,
            barcode: $barcode,
            status: 'approved',
            images: $images,
            raw: $row,
            listingIdentity: array_filter([
                'external_id' => $barcode,
                'external_parent_id' => $mainId !== '' ? $mainId : null,
                'channel_metadata' => array_filter([
                    'vendor_stock_code' => $stockCode !== '' ? $stockCode : null,
                    'idefix_status' => (string) ($row['status'] ?? ''),
                ]),
            ], static fn (mixed $v): bool => $v !== null && $v !== []),
            currency: 'TRY',
        );
    }

    // ------------------------------------------------------------ stok/fiyat

    /**
     * MUTLAK stok — `inventory-upload`, kalemde YALNIZ `barcode` +
     * `inventoryQuantity`. Fiyat alanı TAŞINMAZ: stok işinin bayat fiyatı
     * kampanyayı ezerdi (fiyat ayrı iş, aynı uç).
     */
    public function pushInventory(InventoryPushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            return AdapterResult::success(['pushed' => 0]);
        }

        $items = array_map(static fn (array $item): array => [
            'barcode' => (string) $item['external_id'],
            'inventoryQuantity' => max(0, (int) $item['quantity']),
        ], $batch->toArray());

        return $this->upload($items);
    }

    public function maxInventoryBatchSize(): int
    {
        return self::MAX_ITEMS_PER_REQUEST;
    }

    /** @param list<Listing> $listings */
    public function fetchInventory(array $listings): RemoteInventorySnapshot
    {
        $quantities = [];

        foreach ($this->remoteRows($listings) as $barcode => $row) {
            $quantities[$barcode] = (int) ($row['inventoryQuantity'] ?? 0);
        }

        return new RemoteInventorySnapshot($quantities, new DateTimeImmutable);
    }

    /**
     * MUTLAK fiyat — `price` + `comparePrice` (stok alanı TAŞINMAZ: bayat
     * bakiye satılmış ürünü yeniden satışa açardı).
     *
     * `comparePrice` belgeye göre "göndermek istemiyorsanız 0"; üstü çizili
     * fiyat satıştan büyük değilse 0 gider.
     */
    public function pushPrices(PricePushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            return AdapterResult::success(['pushed' => 0]);
        }

        $items = [];

        foreach ($batch->items as $item) {
            $price = round((float) $item['price'], 2);

            if ($price <= 0) {
                continue;
            }

            $compare = isset($item['compare_at_price']) && is_numeric($item['compare_at_price'])
                ? round((float) $item['compare_at_price'], 2)
                : 0.0;

            $items[] = [
                'barcode' => (string) $item['external_id'],
                'price' => $price,
                'comparePrice' => $compare > $price ? $compare : 0,
            ];
        }

        return $items === [] ? AdapterResult::success(['pushed' => 0]) : $this->upload($items);
    }

    public function maxPriceBatchSize(): int
    {
        return self::MAX_ITEMS_PER_REQUEST;
    }

    /** @param list<Listing> $listings */
    public function fetchPrices(array $listings): RemotePriceSnapshot
    {
        $prices = [];

        foreach ($this->remoteRows($listings) as $barcode => $row) {
            if (is_numeric($row['price'] ?? null)) {
                $prices[$barcode] = self::money($row['price']);
            }
        }

        return new RemotePriceSnapshot($prices, new DateTimeImmutable);
    }

    /**
     * `POST pim/catalog/{vendorId}/inventory-upload` — yanıtta
     * `batchRequestId`. Kimliksiz 200 belirsizdir ve İSTİSNA olur ("sıraya
     * alındı" denmeden uygulandı sayılamaz).
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function upload(array $items): AdapterResult
    {
        $body = $this->json($this->api('POST', '/pim/catalog/'.$this->vendorId().'/inventory-upload', body: ['items' => $items]));
        $batchId = $body['batchRequestId'] ?? null;

        if (! is_string($batchId) || trim($batchId) === '') {
            throw new RuntimeException('İdefix işlem kimliği (batchRequestId) dönmedi.');
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
     * `GET pim/catalog/{vendorId}/inventory-result/{batchId}`.
     *
     * Kalem `status`: `created` (sürüyor) · `completed` · `decline`
     * (`failureReasons`). Tek kalem `created` ise iş SÜRÜYOR. Başlık
     * `FAILED` ve kalem yoksa işin tamamı reddedilmiştir. `BATCH_NOT_EXIST`
     * / 404 = kanal işi tanımıyor → `expired` (bilinmeyen sonuç red değildir).
     */
    public function fetchBatchStatus(string $batchId, SyncDomain $domain): BatchStatus
    {
        try {
            $body = $this->json($this->api('GET', '/pim/catalog/'.$this->vendorId().'/inventory-result/'.rawurlencode($batchId)));
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                return BatchStatus::expired();
            }

            throw $e;
        }

        $state = strtolower(trim((string) ($body['status'] ?? '')));
        $rows = self::rows($body['items'] ?? null);

        if (in_array($state, ['created', 'running'], true)) {
            return BatchStatus::pending();
        }

        $failed = [];
        $succeeded = [];
        $unmatched = [];

        foreach ($rows as $row) {
            $barcode = trim((string) ($row['barcode'] ?? ''));
            $itemState = strtolower(trim((string) ($row['status'] ?? '')));
            $reason = trim((string) ($row['failureReasons'] ?? ''));

            if ($itemState === 'created') {
                return BatchStatus::pending();
            }

            if ($reason === 'BATCH_NOT_EXIST') {
                return BatchStatus::expired();
            }

            if ($itemState === 'completed') {
                if ($barcode !== '') {
                    $succeeded[] = $barcode;
                }

                continue;
            }

            $text = self::failureText($reason);

            if ($barcode === '') {
                $unmatched[] = $text;

                continue;
            }

            $failed[$barcode] = new BatchItemFailure($text);
        }

        if ($rows === [] && in_array($state, ['failed', 'cancelled'], true)) {
            return BatchStatus::rejected(new BatchItemFailure('İdefix işlemi reddetti (durum: '.$state.').'));
        }

        if ($rows === []) {
            return BatchStatus::pending();
        }

        return BatchStatus::completed(failed: $failed, succeeded: $succeeded, unmatched: $unmatched);
    }

    /** Belgedeki `failureReasons` kodları Türkçe karşılığıyla. */
    private static function failureText(string $code): string
    {
        return match ($code) {
            'PRODUCT_NOT_FOUND' => 'İdefix: barkod ürün havuzunda yok (PRODUCT_NOT_FOUND).',
            'NO_PRICE' => 'İdefix: ürünün fiyatı yok — önce fiyat gönderilmeli (NO_PRICE).',
            'CATALOG_PRICE_LOCKED' => 'İdefix: ürün fiyatı kilitli, İdefix destek ekibiyle görüşülmeli (CATALOG_PRICE_LOCKED).',
            'DATA_PARSE_ERROR' => 'İdefix gönderilen veriyi çözemedi (DATA_PARSE_ERROR).',
            'BATCH_ALREADY_PROCESSED' => 'İdefix: işlem zaten işleniyor (BATCH_ALREADY_PROCESSED).',
            '' => 'İdefix satırı reddetti (sebep belirtilmedi).',
            default => 'İdefix satırı reddetti: '.$code,
        };
    }

    /**
     * Uzak satırlar barkodla — `pim/pool/{vendorId}/list?barcode=…` (belgede
     * barkod süzgeci var). Dönen satırın barkodu yine karşılaştırılır:
     * süzgecin tam eşleşme yaptığı DOĞRULANMADI. Kimliksiz listing sorulmaz.
     *
     * @param  list<Listing>  $listings
     * @return array<string, array<string, mixed>>
     */
    private function remoteRows(array $listings): array
    {
        $rows = [];

        foreach ($listings as $listing) {
            $barcode = trim((string) ($listing->external_id ?? ''));

            if ($barcode === '') {
                continue;
            }

            $body = $this->json($this->api('GET', '/pim/pool/'.$this->vendorId().'/list', [
                'page' => 1,
                'limit' => 10,
                'barcode' => $barcode,
            ]));

            foreach (self::rows($body['products'] ?? $body['items'] ?? null) as $row) {
                if (trim((string) ($row['barcode'] ?? '')) === $barcode) {
                    $rows[$barcode] = $row;
                }
            }
        }

        return $rows;
    }

    // ---------------------------------------------------------------- sipariş

    /**
     * İki aşama, imleç `s:{sayfa}` → `c:{sayfa}`:
     *
     *  (s) `oms/{vendorId}/list` — `lastUpdatedAt` penceresi (iptal sonradan
     *      gelir). Sevkiyat = sipariş birimi (`externalOrderId` = sevkiyat
     *      `id`).
     *  (c) `oms/{vendorId}/claim-list` — onaylanan iade kalemleri. İade
     *      kalemi sevkiyatı DEĞİL ana sipariş numarasını taşır; sevkiyat,
     *      kalemin `orderLineId`'si o numaranın sevkiyatlarında aranarak
     *      bulunur (bulunamazsa iade yazılmaz, uyarı düşer).
     */
    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage
    {
        [$phase, $page] = $cursor !== null && preg_match('/^([sc]):(\d+)$/', $cursor, $m) === 1
            ? [$m[1], max(1, (int) $m[2])]
            : ['s', 1];

        $query = [
            'lastUpdatedAt' => self::trTime($since),
            'page' => $page,
            'limit' => self::PAGE_SIZE,
        ];

        if ($phase === 's') {
            $body = $this->json($this->api('GET', '/oms/'.$this->vendorId().'/list', $query));
            $records = [];

            foreach (self::rows($body['items'] ?? null) as $shipment) {
                array_push($records, ...$this->recordsFor($shipment));
            }

            $next = self::morePages($body, $page) ? 's:'.($page + 1) : 'c:1';

            return new OrderPage(orders: $records, nextCursor: $next, hasMore: true);
        }

        $body = $this->json($this->api('GET', '/oms/'.$this->vendorId().'/claim-list', $query));
        $records = [];

        foreach (self::rows($body['items'] ?? null) as $claim) {
            array_push($records, ...$this->returnRecordsFor($claim));
        }

        $more = self::morePages($body, $page);

        return new OrderPage(orders: $records, nextCursor: $more ? 'c:'.($page + 1) : null, hasMore: $more);
    }

    /**
     * Sevkiyatın kayıtları.
     *
     * - Görülmemiş sevkiyat: iptal/bölünmüşse HİÇBİR ŞEY (stok hiç
     *   düşmedi); değilse bütün kalemlerle "created".
     * - Görülmüş sevkiyat: iptal statüsündeyse "cancelled" (bütün kalemler),
     *   değilse statü anlık görüntüsü ("updated", stok hareketi yok).
     *
     * @param  array<string, mixed>  $shipment
     * @return list<array<string, mixed>>
     */
    private function recordsFor(array $shipment): array
    {
        $id = trim((string) ($shipment['id'] ?? ''));
        $status = (string) ($shipment['status'] ?? '');
        $lines = self::lines($shipment);

        if ($id === '' || $lines === []) {
            return [];
        }

        $cancelled = in_array($status, self::CANCELLED_STATUSES, true);

        if (! $this->createdKnown($id)) {
            if ($cancelled) {
                return [];
            }

            $this->createdThisRun[$id] = true;

            return [[
                '_kind' => 'created',
                'shipmentId' => $id,
                'orderNumber' => (string) ($shipment['orderNumber'] ?? ''),
                'orderDate' => $shipment['orderDate'] ?? $shipment['createdAt'] ?? null,
                'status' => $status,
                'discountedTotalPrice' => $shipment['discountedTotalPrice'] ?? null,
                'lines' => $lines,
            ]];
        }

        if ($cancelled) {
            return [[
                '_kind' => 'cancelled',
                'shipmentId' => $id,
                'status' => $status,
                'changedAt' => $shipment['statusUpdatedAt'] ?? $shipment['updatedAt'] ?? null,
                'lines' => $lines,
            ]];
        }

        return [[
            '_kind' => 'updated',
            'shipmentId' => $id,
            'status' => $status,
            'trackingNumber' => trim((string) ($shipment['cargoTrackingNumber'] ?? '')) ?: null,
            'carrier' => trim((string) ($shipment['cargoCompany'] ?? '')) ?: null,
            'changedAt' => $shipment['statusUpdatedAt'] ?? $shipment['updatedAt'] ?? null,
        ]];
    }

    /**
     * Onaylanan (`approved`) iade kalemleri — kalem başına ayrı kayıt; adet 1
     * (sevkiyatta her kalem 1 adettir, SSS).
     *
     * @param  array<string, mixed>  $claim
     * @return list<array<string, mixed>>
     */
    private function returnRecordsFor(array $claim): array
    {
        $orderNumber = trim((string) ($claim['orderNumber'] ?? ''));
        $records = [];

        foreach (self::rows($claim['items'] ?? null) as $item) {
            if ((string) ($item['state'] ?? '') !== 'approved' || ! isset($item['id'], $item['orderLineId'])) {
                continue;
            }

            $shipmentId = $this->shipmentForLine($orderNumber, (string) $item['orderLineId']);
            $barcode = trim((string) ($item['barcode'] ?? ''));

            if ($shipmentId === null || $barcode === '') {
                Log::warning('idefix.return_without_shipment', [
                    'channel_connection_id' => $this->connection->id,
                    'claim_item_id' => (string) $item['id'],
                ]);

                continue;
            }

            $records[] = [
                '_kind' => 'returned',
                'shipmentId' => $shipmentId,
                'claimItemId' => (string) $item['id'],
                'barcode' => $barcode,
                'changedAt' => $item['approvedAt'] ?? $item['stateUpdatedAt'] ?? null,
            ];
        }

        return $records;
    }

    /** İade kaleminin sevkiyatı — ana sipariş numarasının sevkiyatlarında kalem kimliği aranır. */
    private function shipmentForLine(string $orderNumber, string $lineId): ?string
    {
        if ($orderNumber === '') {
            return null;
        }

        if (! isset($this->shipmentsByOrderNumber[$orderNumber])) {
            $body = $this->json($this->api('GET', '/oms/'.$this->vendorId().'/list', ['orderNumber' => $orderNumber, 'limit' => self::PAGE_SIZE]));
            $this->shipmentsByOrderNumber[$orderNumber] = self::rows($body['items'] ?? null);
        }

        foreach ($this->shipmentsByOrderNumber[$orderNumber] as $shipment) {
            foreach (self::rows($shipment['items'] ?? null) as $item) {
                if ((string) ($item['id'] ?? '') === $lineId && isset($shipment['id'])) {
                    return (string) $shipment['id'];
                }
            }
        }

        return null;
    }

    /**
     * `{sevkiyat}:created` · `{sevkiyat}:cancel` · `{sevkiyat}:return:{iade kalemi}`
     * · `{sevkiyat}:status:{statü}`.
     *
     * @param  array<string, mixed>  $order
     */
    public function pollingEventIdFor(array $order): ?string
    {
        $id = trim((string) ($order['shipmentId'] ?? ''));

        if ($id === '') {
            return null;
        }

        return match ($order['_kind'] ?? null) {
            'created' => "{$id}:created",
            'cancelled' => "{$id}:cancel",
            'returned' => isset($order['claimItemId']) ? "{$id}:return:{$order['claimItemId']}" : null,
            'updated' => ($order['status'] ?? '') !== '' ? "{$id}:status:{$order['status']}" : null,
            default => null,
        };
    }

    public function parseOrderEvent(InboxMessage $message): ?NormalizedOrderEvent
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($message->payload) ? $message->payload : [];
        $id = trim((string) ($payload['shipmentId'] ?? ''));
        $kind = $payload['_kind'] ?? null;

        if ($id === '' || ! in_array($kind, ['created', 'cancelled', 'returned', 'updated'], true)) {
            return null;
        }

        $ref = $message->external_event_id ?? $this->pollingEventIdFor($payload);

        if ($kind === 'cancelled') {
            return new NormalizedOrderEvent(
                type: 'cancelled',
                externalOrderId: $id,
                externalRef: $ref,
                payload: [
                    'status' => 'cancelled',
                    'lines' => array_map(static fn (array $line): array => [
                        'external_line_id' => $line['external_line_id'],
                        'sku' => $line['sku'],
                        'quantity' => $line['quantity'],
                    ], self::rows($payload['lines'] ?? null)),
                ],
                occurredAt: self::date($payload['changedAt'] ?? null),
            );
        }

        if ($kind === 'returned') {
            $barcode = (string) ($payload['barcode'] ?? '');

            return new NormalizedOrderEvent(
                type: 'returned',
                externalOrderId: $id,
                externalRef: $ref,
                payload: ['lines' => [[
                    // Satır kimliği BARKOD: "created" aynı barkodu topladı.
                    'external_line_id' => $barcode,
                    'quantity' => 1,
                ]]],
                occurredAt: self::date($payload['changedAt'] ?? null),
            );
        }

        if ($kind === 'updated') {
            return new NormalizedOrderEvent(
                type: 'updated',
                externalOrderId: $id,
                externalRef: $ref,
                payload: array_filter([
                    'status' => (string) ($payload['status'] ?? ''),
                    'tracking_number' => $payload['trackingNumber'] ?? null,
                    'carrier' => $payload['carrier'] ?? null,
                ], static fn (mixed $v): bool => $v !== null && $v !== ''),
                occurredAt: self::date($payload['changedAt'] ?? null),
            );
        }

        $subtotal = 0.0;
        $lines = [];

        foreach (self::rows($payload['lines'] ?? null) as $line) {
            $subtotal += (float) $line['total'];
            $lines[] = [
                'external_line_id' => $line['external_line_id'],
                'sku' => $line['sku'],
                'external_variant_id' => $line['external_line_id'],
                'title' => $line['title'],
                'quantity' => $line['quantity'],
                'unit_price' => self::money((float) $line['total'] / max(1, (int) $line['quantity'])),
                'line_total' => self::money($line['total']),
            ];
        }

        $placedAt = self::date($payload['orderDate'] ?? null);
        $orderNumber = (string) ($payload['orderNumber'] ?? '');

        return new NormalizedOrderEvent(
            type: 'created',
            externalOrderId: $id,
            externalRef: $ref,
            payload: [
                'type' => 'created',
                'external_number' => $orderNumber !== '' ? $orderNumber : $id,
                'status' => (string) ($payload['status'] ?? ''),
                'currency' => 'TRY',
                'subtotal' => self::money($subtotal),
                'shipping_total' => '0.00',
                'grand_total' => self::money(is_numeric($payload['discountedTotalPrice'] ?? null) ? $payload['discountedTotalPrice'] : $subtotal),
                'lines' => $lines,
                // Alıcı, adres, T.C. kimlik no ALINMAZ (kişisel veri; `lines`).
                'customer_ref' => [],
            ],
            occurredAt: $placedAt,
            placedAt: $placedAt,
        );
    }

    public function orderStateFor(NormalizedOrderEvent $event): ?OrderState
    {
        $status = (string) ($event->payload['status'] ?? '');

        return match (true) {
            in_array($status, ['created', 'shipment_ready', 'shipment_picking', 'shipment_invoiced'], true) => OrderState::AWAITING_SHIPMENT,
            in_array($status, self::SHIPPED_STATUSES, true) => OrderState::SHIPPED,
            in_array($status, self::DELIVERED_STATUSES, true) => OrderState::DELIVERED,
            $status === 'cancelled', in_array($status, self::CANCELLED_STATUSES, true) => OrderState::CANCELLED,
            default => null,
        };
    }

    /** Sınıf notu: `picking` müşterinin iptal hakkını kapatır — çekirdek çağırsa da istek gitmez. */
    public function acknowledgeOrder(Order $order): AdapterResult
    {
        return AdapterResult::success(['acknowledged' => true]);
    }

    // ------------------------------------------------------------------- iç

    /**
     * Sevkiyat kalemleri barkoda göre toplanır; YALNIZ iş alanları (alıcı,
     * adres, kimlik no ALINMAZ). Tutar `discountedTotalPrice` (indirim
     * sonrası, kalem başına); yoksa `price`.
     *
     * @param  array<string, mixed>  $shipment
     * @return list<array{external_line_id: string, sku: string, title: string, quantity: int, total: float}>
     */
    private static function lines(array $shipment): array
    {
        $lines = [];

        foreach (self::rows($shipment['items'] ?? null) as $item) {
            $barcode = trim((string) ($item['barcode'] ?? ''));

            if ($barcode === '') {
                continue;
            }

            $sku = trim((string) ($item['merchantSku'] ?? ''));
            $amount = is_numeric($item['discountedTotalPrice'] ?? null) ? (float) $item['discountedTotalPrice'] : (float) ($item['price'] ?? 0);

            $lines[$barcode] ??= [
                'external_line_id' => $barcode,
                'sku' => $sku !== '' ? $sku : $barcode,
                'title' => trim((string) ($item['productName'] ?? '')) ?: $barcode,
                'quantity' => 0,
                'total' => 0.0,
            ];
            $lines[$barcode]['quantity']++;
            $lines[$barcode]['total'] += $amount;
        }

        return array_values($lines);
    }

    /** @param array<string, mixed> $body */
    private static function morePages(array $body, int $page): bool
    {
        if (isset($body['pageCount']) && is_numeric($body['pageCount'])) {
            return $page < (int) $body['pageCount'];
        }

        // Sayfa bilgisi yoksa boş sayfa gelene kadar.
        return self::rows($body['items'] ?? null) !== [];
    }

    private function createdKnown(string $shipmentId): bool
    {
        return isset($this->createdThisRun[$shipmentId]) || TenantContext::runAsSystem(fn (): bool => InboxMessage::query()
            ->where('channel_connection_id', $this->connection->id)
            ->where('external_event_id', "{$shipmentId}:created")
            ->exists());
    }

    /**
     * Kimlikli istek. Anahtar YOKSA istek ATILMAZ: kimliksiz istek 401 alır
     * ve "anahtarın yanlış" diye kalıcı hataya düşerdi.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function api(string $method, string $path, array $query = [], ?array $body = null): Response
    {
        $secrets = $this->readSecrets();
        $key = trim((string) ($secrets[self::API_KEY_SECRET] ?? ''));
        $secret = trim((string) ($secrets[self::API_SECRET_SECRET] ?? ''));

        if ($key === '' || $secret === '') {
            throw new RuntimeException('İdefix API Key / API Secret kasada yok — bağlantıyı yeniden kur.');
        }

        $response = $this->client->request(
            strtoupper($method),
            self::BASE_URL.$path,
            body: $body,
            query: $query,
            headers: [
                'Accept' => 'application/json',
                'X-API-KEY' => self::vendorToken($key, $secret),
            ],
        );

        $response->throw();

        return $response;
    }

    /** Belge: VENDOR TOKEN = base64_encode(ApiKEY:ApiSecret). */
    public static function vendorToken(string $key, string $secret): string
    {
        return base64_encode($key.':'.$secret);
    }

    private function vendorId(): string
    {
        $id = trim((string) ($this->connection->settings[self::VENDOR_ID_KEY] ?? $this->connection->external_account_id ?? ''));

        if (! ctype_digit($id)) {
            throw new RuntimeException('İdefix satıcı kimliği (Satıcı ID) eksik ya da geçersiz — bağlantıyı yeniden kur.');
        }

        return $id;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $body = json_decode($response->body(), true);

        if (! is_array($body)) {
            throw new RuntimeException('İdefix beklenmeyen yanıt döndü: '.mb_substr(trim($response->body()), 0, 120));
        }

        return $body;
    }

    /** Belgedeki biçim `2022/09/30 23:59:59`, Türkiye saati. */
    private static function trTime(CarbonInterface $at): string
    {
        return $at->copy()->setTimezone('Europe/Istanbul')->format('Y/m/d H:i:s');
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private static function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private static function date(mixed $raw): ?DateTimeImmutable
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable(trim($raw));
        } catch (Throwable) {
            return null;
        }
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
