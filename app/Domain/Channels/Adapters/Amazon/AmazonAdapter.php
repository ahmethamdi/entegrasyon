<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Amazon;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresChannelCurrency;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\MapsOrderState;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\RefreshedCredentials;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Contracts\SupportsFulfillment;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Contracts\SupportsTokenRefresh;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Enums\OrderState;
use App\Domain\Orders\Models\Fulfillment;
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
use RuntimeException;
use Throwable;

/**
 * Amazon Selling Partner API adapter'ı (Avrupa uç noktası, DE + TR pazarı).
 *
 * BETA — geliştirici kaydı YOK, gerçek hesapla SINANMADI. İstek biçimleri
 * resmî SP-API belgelerinden (Listings Items 2021-08-01, Orders v0). Notlar
 * ve başvuru metinleri: `docs/AMAZON-BASVURU.md`.
 *
 * KİMLİK: LWA. Satıcının yenileme anahtarı kasada; erişim anahtarı (1 sa)
 * `refreshCredentials()` ile yenilenir ve `x-amz-access-token` başlığında
 * gider. 2023'ten beri AWS SigV4 imzası GEREKMEZ.
 *
 * KİŞİSEL VERİ: alıcı adı/adresi (RDT gerektiren alanlar) İSTENMEZ. Sipariş
 * yalnız tutar, kalem ve durumla alınır (PersonalDataMask ilkesi).
 *
 * ⚠️ DOĞRULANMADI: `searchListingsItems` yanıtındaki `offers`/
 * `fulfillmentAvailability` alan biçimi, `productType: PRODUCT` ile fiyat
 * yamasının her kategoride kabulü, getOrderItems hız sınırı (0,5/sn) altında
 * çok kalemli turun süresi.
 */
final class AmazonAdapter implements ChannelAdapter, DeclaresChannelCurrency, MapsOrderState, SupportsCatalogImport, SupportsFulfillment, SupportsInventory, SupportsOrders, SupportsPricing, SupportsTokenRefresh
{
    use DeclaresRequestQuota;

    public const SELLER_ID_KEY = 'amazon_selling_partner_id';

    public const MARKETPLACE_KEY = 'amazon_marketplace';

    /**
     * LWA erişim anahtarının kasadaki adı. `access_token` DEĞİL: o ad
     * `ChannelHttpClient`'ta otomatik `Authorization: Bearer` olurdu; SP-API
     * anahtarı yalnız `x-amz-access-token` başlığında bekler.
     */
    public const ACCESS_TOKEN_SECRET = 'lwa_access_token';

    private const LISTINGS = '/listings/2021-08-01/items';

    private const MAX_BATCH = 20;

    /** Sipariş durumları (Orders v0 `OrderStatus`). */
    private const STATE_MAP = [
        'Pending' => OrderState::AWAITING_PAYMENT,
        'PendingAvailability' => OrderState::AWAITING_PAYMENT,
        'Unshipped' => OrderState::AWAITING_SHIPMENT,
        'PartiallyShipped' => OrderState::AWAITING_SHIPMENT,
        'Shipped' => OrderState::SHIPPED,
        'InvoiceUnconfirmed' => OrderState::SHIPPED,
        'Canceled' => OrderState::CANCELLED,
    ];

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
        return $this->market()['currency'];
    }

    // ---------------------------------------------------------------- sağlık

    /** `GET /sellers/v1/marketplaceParticipations` — anahtarın geçtiğini kanıtlar. */
    public function healthCheck(): HealthResult
    {
        $startedAt = hrtime(true);

        try {
            $this->api('GET', '/sellers/v1/marketplaceParticipations');
        } catch (Throwable $e) {
            return HealthResult::unhealthy($e->getMessage());
        }

        return HealthResult::healthy((int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    /** Listings Items uçları 5/sn; Orders getOrders 0,0167/sn patlama 20 — en sıkı ortak profil. */
    public function rateLimitProfile(): RateLimitProfile
    {
        $profile = $this->connection->channelType?->rate_limit_profile;

        return is_array($profile) && $profile !== []
            ? RateLimitProfile::fromArray($profile)
            : new RateLimitProfile(requestsPerSecond: 1, burstCapacity: 5, maxConcurrent: 1);
    }

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
            $status >= 500 => ErrorClass::SERVER_ERROR,
            $status >= 400 => ErrorClass::VALIDATION,
            default => ErrorClass::SERVER_ERROR,
        };
    }

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
        return '/auth/o2/token';
    }

    // ------------------------------------------------------- token yenileme

    public function refreshCredentials(): RefreshedCredentials
    {
        $secrets = $this->readSecrets();
        $refresh = $secrets['refresh_token'] ?? null;

        if (! is_string($refresh) || $refresh === '') {
            throw new RuntimeException('Amazon yenileme anahtarı kasada yok — Seller Central yetkisini yenile.');
        }

        $response = $this->client->post(AmazonApp::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
            'client_id' => AmazonApp::clientId(),
            'client_secret' => AmazonApp::clientSecret(),
        ], asForm: true);

        $response->throw();

        $access = $response->json('access_token');

        if (! is_string($access) || $access === '') {
            throw new RuntimeException('Amazon LWA yanıtı access_token taşımıyor.');
        }

        $seconds = (int) ($response->json('expires_in') ?? 3600);

        return new RefreshedCredentials(
            secrets: [...$secrets, self::ACCESS_TOKEN_SECRET => $access],
            expiresAt: new DateTimeImmutable('@'.(time() + max(60, $seconds))),
        );
    }

    public function refreshLeadSeconds(): int
    {
        return 2400;
    }

    // ------------------------------------------------------------ içe aktarma

    /** `searchListingsItems` — her satır bir SKU (listing kimliği SKU). */
    public function fetchProductPage(?string $cursor = null): RemoteProductPage
    {
        $body = $this->json($this->api('GET', self::LISTINGS.'/'.rawurlencode($this->sellerId()), array_filter([
            'marketplaceIds' => $this->market()['marketplace_id'],
            'includedData' => 'summaries,offers,fulfillmentAvailability',
            'pageSize' => 20,
            'pageToken' => $cursor,
        ], static fn (mixed $v): bool => $v !== null && $v !== '')));

        $products = [];

        foreach (self::rows($body['items'] ?? null) as $item) {
            $sku = trim((string) ($item['sku'] ?? ''));

            if ($sku === '') {
                continue;
            }

            $summary = self::rows($item['summaries'] ?? null)[0] ?? [];
            $price = self::rows($item['offers'] ?? null)[0]['price']['amount'] ?? null;
            $quantity = self::rows($item['fulfillmentAvailability'] ?? null)[0]['quantity'] ?? null;
            $image = (string) ($summary['mainImage']['link'] ?? '');

            $products[] = new RemoteProduct(
                externalId: $sku,
                sku: $sku,
                title: trim((string) ($summary['itemName'] ?? '')) ?: $sku,
                price: is_numeric($price) ? number_format((float) $price, 2, '.', '') : null,
                quantity: is_numeric($quantity) ? (int) $quantity : null,
                status: in_array('BUYABLE', (array) ($summary['status'] ?? []), true) ? 'approved' : 'inactive',
                images: str_starts_with($image, 'https://') ? [$image] : [],
                raw: $item,
                listingIdentity: array_filter([
                    'external_id' => $sku,
                    'channel_metadata' => array_filter(['asin' => $summary['asin'] ?? null]),
                ]),
                currency: $this->channelCurrency(),
            );
        }

        $next = $body['pagination']['nextToken'] ?? null;

        return new RemoteProductPage(products: $products, nextCursor: is_string($next) && $next !== '' ? $next : null, hasMore: is_string($next) && $next !== '');
    }

    public function maxImportPages(): int
    {
        return 500;
    }

    // ------------------------------------------------------------ stok/fiyat

    /** `patchListingsItem` — `fulfillment_availability` (satıcı gönderimi, DEFAULT). */
    public function pushInventory(InventoryPushBatch $batch): AdapterResult
    {
        $patches = [];

        foreach ($batch->items as $index => $item) {
            $patches[$index] = [$item->externalId, [
                'op' => 'replace',
                'path' => '/attributes/fulfillment_availability',
                'value' => [['fulfillment_channel_code' => 'DEFAULT', 'quantity' => max(0, $item->quantity)]],
            ]];
        }

        return $this->patchListings($patches, $batch->operations());
    }

    public function maxInventoryBatchSize(): int
    {
        return self::MAX_BATCH;
    }

    /** @param list<Listing> $listings */
    public function fetchInventory(array $listings): RemoteInventorySnapshot
    {
        $quantities = [];

        foreach ($this->readListings($listings) as $sku => $item) {
            $quantity = self::rows($item['fulfillmentAvailability'] ?? null)[0]['quantity'] ?? null;

            if (is_numeric($quantity)) {
                $quantities[$sku] = (int) $quantity;
            }
        }

        return new RemoteInventorySnapshot($quantities, new DateTimeImmutable);
    }

    /** `purchasable_offer.our_price` — KDV dahil satış fiyatı. */
    public function pushPrices(PricePushBatch $batch): AdapterResult
    {
        $market = $this->market();
        $patches = [];

        foreach ($batch->items as $index => $item) {
            $patches[$index] = [(string) $item['external_id'], [
                'op' => 'replace',
                'path' => '/attributes/purchasable_offer',
                'value' => [[
                    'marketplace_id' => $market['marketplace_id'],
                    'currency' => $market['currency'],
                    'our_price' => [['schedule' => [['value_with_tax' => round((float) $item['price'], 2)]]]],
                ]],
            ]];
        }

        return $this->patchListings($patches, $batch->operations());
    }

    public function maxPriceBatchSize(): int
    {
        return self::MAX_BATCH;
    }

    /** @param list<Listing> $listings */
    public function fetchPrices(array $listings): RemotePriceSnapshot
    {
        $prices = [];

        foreach ($this->readListings($listings) as $sku => $item) {
            $amount = self::rows($item['offers'] ?? null)[0]['price']['amount'] ?? null;

            if (is_numeric($amount)) {
                $prices[$sku] = number_format((float) $amount, 2, '.', '');
            }
        }

        return new RemotePriceSnapshot($prices, new DateTimeImmutable);
    }

    /**
     * SKU başına PATCH. `status: INVALID` ya da 4xx kaleme yazılır; 5xx/429/
     * kimlik hatası yükselir (değerler mutlak, tekrar zararsız).
     *
     * @param  array<int, array{0: string, 1: array<string, mixed>}>  $patches
     * @param  list<mixed>  $operations
     */
    private function patchListings(array $patches, array $operations): AdapterResult
    {
        if ($patches === []) {
            return AdapterResult::success(['pushed' => 0]);
        }

        $failed = [];
        $pushed = 0;

        foreach ($patches as $index => [$sku, $patch]) {
            try {
                $response = $this->api(
                    'PATCH',
                    self::LISTINGS.'/'.rawurlencode($this->sellerId()).'/'.rawurlencode($sku),
                    ['marketplaceIds' => $this->market()['marketplace_id']],
                    ['productType' => 'PRODUCT', 'patches' => [$patch]],
                );
            } catch (RequestException $e) {
                $status = $e->response->status();

                if ($status >= 500 || in_array($status, [401, 403, 429], true)) {
                    throw $e;
                }

                $failed[self::operationId($operations, $index)] = "Amazon reddetti ({$status}): ".mb_substr($e->response->body(), 0, 300);

                continue;
            }

            $body = $this->json($response);

            if (($body['status'] ?? null) === 'INVALID') {
                $issues = array_map(static fn (array $i): string => (string) ($i['message'] ?? ''), self::rows($body['issues'] ?? null));
                $failed[self::operationId($operations, $index)] = 'Amazon geçersiz saydı: '.mb_substr(implode(' | ', $issues), 0, 300);

                continue;
            }

            $pushed++;
        }

        unset($failed['']);

        return $failed === []
            ? AdapterResult::success(['pushed' => $pushed])
            : AdapterResult::partial($failed, ['pushed' => $pushed], ErrorClass::VALIDATION);
    }

    /** @param list<mixed> $operations */
    private static function operationId(array $operations, int $index): string
    {
        $operation = $operations[$index] ?? null;

        return is_object($operation) && isset($operation->id) ? (string) $operation->id : '';
    }

    /**
     * @param  list<Listing>  $listings
     * @return array<string, array<string, mixed>>
     */
    private function readListings(array $listings): array
    {
        $found = [];

        foreach ($listings as $listing) {
            $sku = trim((string) $listing->external_id);

            if ($sku === '') {
                continue;
            }

            $found[$sku] = $this->json($this->api('GET', self::LISTINGS.'/'.rawurlencode($this->sellerId()).'/'.rawurlencode($sku), [
                'marketplaceIds' => $this->market()['marketplace_id'],
                'includedData' => 'offers,fulfillmentAvailability',
            ]));
        }

        return $found;
    }

    // ---------------------------------------------------------------- sipariş

    /**
     * `getOrders` (LastUpdatedAfter) + sipariş başına `getOrderItems`.
     * Olay kimliği `{AmazonOrderId}:{OrderStatus}` — iptal yutulmaz.
     * Alıcı ve adres İSTENMEZ (RDT yok).
     */
    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage
    {
        // Amazon `LastUpdatedAfter` en az 2 dk geçmişte olmalı (resmî kural).
        $after = $since->copy()->utc();
        $limit = now()->utc()->subMinutes(2);

        $query = $cursor !== null && $cursor !== ''
            ? ['MarketplaceIds' => $this->market()['marketplace_id'], 'NextToken' => $cursor]
            : ['MarketplaceIds' => $this->market()['marketplace_id'], 'LastUpdatedAfter' => ($after->greaterThan($limit) ? $limit : $after)->format('Y-m-d\TH:i:s\Z')];

        $payload = $this->json($this->api('GET', '/orders/v0/orders', $query))['payload'] ?? [];
        $orders = [];

        foreach (self::rows($payload['Orders'] ?? null) as $order) {
            $id = (string) ($order['AmazonOrderId'] ?? '');

            if ($id === '') {
                continue;
            }

            $items = self::rows($this->json($this->api('GET', '/orders/v0/orders/'.rawurlencode($id).'/orderItems'))['payload']['OrderItems'] ?? null);

            $orders[] = [
                'AmazonOrderId' => $id,
                'OrderStatus' => (string) ($order['OrderStatus'] ?? ''),
                'PurchaseDate' => $order['PurchaseDate'] ?? null,
                'LastUpdateDate' => $order['LastUpdateDate'] ?? null,
                'OrderTotal' => $order['OrderTotal'] ?? null,
                'items' => array_map(static fn (array $i): array => array_intersect_key($i, array_flip([
                    'OrderItemId', 'SellerSKU', 'ASIN', 'Title', 'QuantityOrdered', 'QuantityShipped', 'ItemPrice', 'ShippingPrice',
                ])), $items),
            ];
        }

        $next = $payload['NextToken'] ?? null;

        return new OrderPage(orders: $orders, nextCursor: is_string($next) && $next !== '' ? $next : null, hasMore: is_string($next) && $next !== '');
    }

    /** @param array<string, mixed> $order */
    public function pollingEventIdFor(array $order): ?string
    {
        $id = (string) ($order['AmazonOrderId'] ?? '');

        return $id === '' ? null : $id.':'.($order['OrderStatus'] ?? '');
    }

    public function parseOrderEvent(InboxMessage $message): ?NormalizedOrderEvent
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($message->payload) ? $message->payload : [];
        $id = (string) ($payload['AmazonOrderId'] ?? '');

        if ($id === '') {
            return null;
        }

        $status = (string) ($payload['OrderStatus'] ?? '');
        $type = match ($status) {
            'Canceled' => 'cancelled',
            'Unshipped', 'Pending', 'PendingAvailability' => 'created',
            default => 'updated',
        };

        $lines = [];
        $subtotal = 0;
        $shipping = 0;

        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $quantity = (int) ($item['QuantityOrdered'] ?? 0);
            // `ItemPrice` kalem TOPLAMIDIR (adet × birim, resmî tanım).
            $cents = (int) round(((float) ($item['ItemPrice']['Amount'] ?? 0)) * 100);
            $subtotal += $cents;
            $shipping += (int) round(((float) ($item['ShippingPrice']['Amount'] ?? 0)) * 100);

            $lines[] = [
                'external_line_id' => (string) ($item['OrderItemId'] ?? ''),
                'sku' => (string) ($item['SellerSKU'] ?? ''),
                'external_variant_id' => isset($item['SellerSKU']) ? (string) $item['SellerSKU'] : null,
                'title' => (string) ($item['Title'] ?? $item['SellerSKU'] ?? ''),
                'quantity' => $quantity,
                'unit_price' => number_format(($quantity > 0 ? intdiv($cents, $quantity) : $cents) / 100, 2, '.', ''),
                'line_total' => number_format($cents / 100, 2, '.', ''),
            ];
        }

        $placedAt = self::date($payload['PurchaseDate'] ?? null);

        return new NormalizedOrderEvent(
            type: $type,
            externalOrderId: $id,
            externalRef: $message->external_event_id ?? $this->pollingEventIdFor($payload),
            payload: [
                'type' => $type,
                'external_number' => $id,
                'status' => $status,
                'currency' => (string) ($payload['OrderTotal']['CurrencyCode'] ?? $this->channelCurrency()),
                'subtotal' => number_format($subtotal / 100, 2, '.', ''),
                'shipping_total' => number_format($shipping / 100, 2, '.', ''),
                'grand_total' => is_numeric($payload['OrderTotal']['Amount'] ?? null)
                    ? number_format((float) $payload['OrderTotal']['Amount'], 2, '.', '')
                    : number_format(($subtotal + $shipping) / 100, 2, '.', ''),
                'lines' => $lines,
                'customer_ref' => [],
            ],
            occurredAt: self::date($payload['LastUpdateDate'] ?? null) ?? $placedAt,
            placedAt: $placedAt,
        );
    }

    public function orderStateFor(NormalizedOrderEvent $event): ?OrderState
    {
        return self::STATE_MAP[(string) ($event->payload['status'] ?? '')] ?? null;
    }

    public function acknowledgeOrder(Order $order): AdapterResult
    {
        return AdapterResult::success(['acknowledged' => true]);
    }

    // ---------------------------------------------------------------- kargo

    /** `confirmShipment` — siparişin gönderilmemiş kalemleri tek pakette. */
    public function pushFulfillment(Fulfillment $fulfillment): AdapterResult
    {
        $orderId = (string) ($fulfillment->order?->external_id ?? '');

        if ($orderId === '') {
            return AdapterResult::failure(ErrorClass::VALIDATION, 'Kargo bildirimi için Amazon sipariş numarası yok.');
        }

        $items = [];

        foreach (self::rows($this->json($this->api('GET', '/orders/v0/orders/'.rawurlencode($orderId).'/orderItems'))['payload']['OrderItems'] ?? null) as $item) {
            $open = (int) ($item['QuantityOrdered'] ?? 0) - (int) ($item['QuantityShipped'] ?? 0);

            if ($open > 0 && isset($item['OrderItemId'])) {
                $items[] = ['orderItemId' => (string) $item['OrderItemId'], 'quantity' => $open];
            }
        }

        if ($items === []) {
            return AdapterResult::success(['already_fulfilled' => true]);
        }

        $this->api('POST', '/orders/v0/orders/'.rawurlencode($orderId).'/shipmentConfirmation', [], [
            'marketplaceId' => $this->market()['marketplace_id'],
            'packageDetail' => [
                'packageReferenceId' => '1',
                'carrierCode' => trim((string) $fulfillment->carrier) !== '' ? trim((string) $fulfillment->carrier) : 'Other',
                'trackingNumber' => trim((string) $fulfillment->tracking_number),
                'shipDate' => ($fulfillment->shipped_at ?? now())->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'orderItems' => $items,
            ],
        ]);

        return AdapterResult::success(['external_id' => trim((string) $fulfillment->tracking_number)]);
    }

    /** Amazon serbest taşıyıcı adı kabul eder; sık kullanılanlar. */
    public function fetchCarriers(): array
    {
        return ['DHL' => 'DHL', 'DPD' => 'DPD', 'GLS' => 'GLS', 'Hermes' => 'Hermes', 'UPS' => 'UPS', 'Yurtici' => 'Yurtiçi Kargo', 'Aras' => 'Aras Kargo', 'Other' => 'Diğer'];
    }

    // ------------------------------------------------------------------- iç

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function api(string $method, string $path, array $query = [], ?array $body = null): Response
    {
        $token = $this->readSecrets()[self::ACCESS_TOKEN_SECRET] ?? null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Amazon erişim anahtarı yok — Seller Central yetkisini yenile.');
        }

        $response = $this->client->request(
            $method,
            AmazonApp::EU_ENDPOINT.$path,
            body: $body,
            query: $query,
            headers: ['x-amz-access-token' => $token],
        );

        $response->throw();

        return $response;
    }

    /** @return array{marketplace_id: string, currency: string, seller_central: string} */
    private function market(): array
    {
        $key = strtolower((string) ($this->connection->settings[self::MARKETPLACE_KEY] ?? 'de'));

        return AmazonApp::MARKETPLACES[$key] ?? AmazonApp::MARKETPLACES['de'];
    }

    private function sellerId(): string
    {
        $id = trim((string) ($this->connection->settings[self::SELLER_ID_KEY] ?? ''));

        if ($id === '') {
            throw new RuntimeException('Amazon satıcı kimliği (selling_partner_id) yok — yetkilendirmeyi tamamla.');
        }

        return $id;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $body = json_decode($response->body(), true);

        return is_array($body) ? $body : [];
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
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
