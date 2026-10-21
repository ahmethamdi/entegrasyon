<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Otto;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresChannelCurrency;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\MapsOrderState;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Enums\OrderState;
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
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * OTTO Market adapter'ı (`api.otto.market`) — Service Partner uygulaması.
 *
 * Kaynak: `api.otto.market/docs/sp-integration` ve birleşik OpenAPI
 * (`api.otto.market/docs/openapi.json`, 21 Eki 2026 indirildi). Notlar:
 * `docs/OTTO-API-NOTLARI.md`. BETA — gerçek hesapla SINANMADI.
 *
 * KİMLİK: satıcı 34Pazar uygulamasını OTTO'da KURAR; kurulum kimliği
 * (`installationId`) bağlantı ayarında durur. Her istekte erişim anahtarı:
 *   1. geliştirici anahtarı: `POST /oauth2/token` client_credentials, scope `developer`
 *   2. kurulum anahtarı: `POST /v1/apps/{appId}/installations/{installationId}/accessToken`
 *      (Bearer geliştirici anahtarı, `scope=…`), ömür 1800 sn → 25 dk önbellek.
 *
 * KAPSAM: içe aktarma (`GET /v5/products`), stok (`POST /v1/availability/quantities`,
 * en çok 200 SKU), fiyat (`POST /v5/products/prices`, asenkron 202), sipariş
 * yoklaması (`GET /v4/orders`, `fromDate` = son değişiklik). Listing kimliği SKU.
 *
 * ⚠️ KARGO BİLDİRİMİ YOK: `POST /v1/shipments` her kalem için İADE takip
 * numarası (`returnTrackingKey`) ve gönderim adresi ister; panelde bu veri
 * yok. Satıcı kargoyu OTTO portalında girer.
 *
 * ⚠️ DOĞRULANMADI: kurulum anahtarı yanıtının alan adı (`access_token`
 * varsayıldı), fiyat görevinin sonucu (202 başarı sayılıyor), `fromDate`
 * ile iptallerin pencereye girdiği (lifecycleChangeDate'e göre).
 */
final class OttoAdapter implements ChannelAdapter, DeclaresChannelCurrency, MapsOrderState, SupportsCatalogImport, SupportsInventory, SupportsOrders, SupportsPricing
{
    use DeclaresRequestQuota;

    public const INSTALLATION_ID_KEY = 'otto_installation_id';

    /** Kurulum anahtarında istenen yetkiler (resmî scope adları). */
    public const SCOPES = 'products orders availability';

    private const TOKEN_TTL_SECONDS = 1500;

    private const QUANTITY_BATCH = 200;

    private const PRICE_BATCH = 200;

    private const PRODUCT_PAGE_SIZE = 100;

    private const ORDER_PAGE_SIZE = 128;

    private const CANCELLED = ['CANCELLED_BY_PARTNER', 'CANCELLED_BY_MARKETPLACE'];

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
        return 'EUR';
    }

    // ---------------------------------------------------------------- sağlık

    public function healthCheck(): HealthResult
    {
        $startedAt = hrtime(true);

        try {
            $this->api('GET', '/v1/availability/quantities', ['limit' => 1]);
        } catch (Throwable $e) {
            return HealthResult::unhealthy($e->getMessage());
        }

        return HealthResult::healthy((int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    /** Availability uçları 10 istek/sn (resmî tablo); temkinli 5. */
    public function rateLimitProfile(): RateLimitProfile
    {
        $profile = $this->connection->channelType?->rate_limit_profile;

        return is_array($profile) && $profile !== []
            ? RateLimitProfile::fromArray($profile)
            : new RateLimitProfile(requestsPerSecond: 5, burstCapacity: 5, maxConcurrent: 2);
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
            $status === 409 => ErrorClass::CONFLICT,
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
        return '/oauth2/token';
    }

    // ------------------------------------------------------------ içe aktarma

    /** `GET /v5/products?page&limit≤100` — her satır bir varyasyon, kimlik SKU. */
    public function fetchProductPage(?string $cursor = null): RemoteProductPage
    {
        $page = $cursor === null ? 0 : max(0, (int) $cursor);
        $body = $this->json($this->api('GET', '/v5/products', ['page' => $page, 'limit' => self::PRODUCT_PAGE_SIZE]));

        $products = [];

        foreach (self::rows($body['productVariations'] ?? null) as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));

            if ($sku === '') {
                continue;
            }

            $price = $row['pricing']['standardPrice']['amount'] ?? null;
            $description = is_array($row['productDescription'] ?? null) ? $row['productDescription'] : [];
            $images = array_values(array_filter(array_map(
                static fn (array $asset): string => (string) ($asset['location'] ?? ''),
                self::rows($row['mediaAssets'] ?? null),
            ), static fn (string $url): bool => str_starts_with($url, 'https://')));

            $products[] = new RemoteProduct(
                externalId: $sku,
                sku: $sku,
                title: trim((string) ($description['productLine'] ?? '')) ?: $sku,
                price: is_numeric($price) ? number_format((float) $price, 2, '.', '') : null,
                description: is_string($description['description'] ?? null) ? $description['description'] : null,
                barcode: is_string($row['ean'] ?? null) ? $row['ean'] : null,
                status: 'approved',
                images: $images,
                raw: $row,
                listingIdentity: array_filter([
                    'external_id' => $sku,
                    'external_parent_id' => is_string($row['productReference'] ?? null) ? $row['productReference'] : null,
                ]),
                currency: 'EUR',
            );
        }

        $hasMore = self::hasNext($body['links'] ?? null);

        return new RemoteProductPage(products: $products, nextCursor: $hasMore ? (string) ($page + 1) : null, hasMore: $hasMore);
    }

    public function maxImportPages(): int
    {
        return 200;
    }

    // ------------------------------------------------------------ stok/fiyat

    /**
     * MUTLAK stok — `[{sku, quantity}]`. 207 yanıtı kalem hatasıdır (bilinmeyen
     * SKU vb.) ve partial'a çevrilir; parti düşmez.
     */
    public function pushInventory(InventoryPushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            return AdapterResult::success(['pushed' => 0]);
        }

        $body = array_map(static fn ($item): array => [
            'sku' => $item->externalId,
            'quantity' => max(0, $item->quantity),
        ], $batch->items);

        $response = $this->api('POST', '/v1/availability/quantities', [], $body);

        if ($response->status() === 207) {
            $failed = [];
            $operations = $batch->operations();
            $skuIndex = array_flip(array_map(static fn ($item): string => $item->externalId, $batch->items));

            // Resmî örnek: `errors[].logref` = hatalı SKU, `detail` = sebep.
            foreach (self::rows($this->json($response)['errors'] ?? null) as $error) {
                $sku = (string) ($error['logref'] ?? $error['sku'] ?? '');
                $operation = isset($skuIndex[$sku]) ? ($operations[$skuIndex[$sku]] ?? null) : null;

                if (is_object($operation) && isset($operation->id)) {
                    $failed[(string) $operation->id] = 'OTTO reddetti: '.mb_substr((string) ($error['detail'] ?? $error['title'] ?? 'neden yok'), 0, 300);
                }
            }

            if ($failed !== []) {
                return AdapterResult::partial($failed, ['pushed' => count($body) - count($failed)], ErrorClass::VALIDATION);
            }
        }

        return AdapterResult::success(['pushed' => count($body)]);
    }

    public function maxInventoryBatchSize(): int
    {
        return self::QUANTITY_BATCH;
    }

    /** @param list<Listing> $listings */
    public function fetchInventory(array $listings): RemoteInventorySnapshot
    {
        $quantities = [];

        foreach ($listings as $listing) {
            $sku = trim((string) $listing->external_id);

            if ($sku === '') {
                continue;
            }

            $body = $this->json($this->api('GET', '/v1/availability/quantities/'.rawurlencode($sku)));
            $row = $body['resources']['variations'][0] ?? $body;

            if (is_numeric($row['quantity'] ?? null)) {
                $quantities[$sku] = (int) $row['quantity'];
            }
        }

        return new RemoteInventorySnapshot($quantities, new DateTimeImmutable);
    }

    /**
     * Standart fiyat — `[{sku, standardPrice:{amount, currency}}]`. OTTO
     * görevi kuyruğa alır (202); kimliği sonuçta durur.
     */
    public function pushPrices(PricePushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            return AdapterResult::success(['pushed' => 0]);
        }

        $body = array_map(static fn (array $item): array => [
            'sku' => (string) $item['external_id'],
            'standardPrice' => ['amount' => round((float) $item['price'], 2), 'currency' => 'EUR'],
        ], $batch->items);

        $response = $this->api('POST', '/v5/products/prices', [], $body);
        $links = $this->json($response)['links'] ?? null;
        $task = null;

        foreach (self::rows($links) as $link) {
            if (($link['rel'] ?? null) === 'self') {
                $task = (string) ($link['href'] ?? '');
            }
        }

        return AdapterResult::success(array_filter(['pushed' => count($body), 'task' => $task]));
    }

    public function maxPriceBatchSize(): int
    {
        return self::PRICE_BATCH;
    }

    /** @param list<Listing> $listings */
    public function fetchPrices(array $listings): RemotePriceSnapshot
    {
        $prices = [];

        foreach ($listings as $listing) {
            $sku = trim((string) $listing->external_id);

            if ($sku === '') {
                continue;
            }

            foreach (self::rows($this->json($this->api('GET', '/v5/products/prices', ['sku' => $sku]))['variationPrices'] ?? null) as $row) {
                $amount = $row['standardPrice']['amount'] ?? null;

                if (($row['sku'] ?? null) === $sku && is_numeric($amount)) {
                    $prices[$sku] = number_format((float) $amount, 2, '.', '');
                }
            }
        }

        return new RemotePriceSnapshot($prices, new DateTimeImmutable);
    }

    // ---------------------------------------------------------------- sipariş

    /**
     * `GET /v4/orders?fromDate&limit≤256` — `nextcursor` bağlantısıyla sayfalanır.
     * Sipariş başına bir `created`; iptal ve iade KALEM başına ayrı olay.
     */
    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage
    {
        $query = $cursor !== null && $cursor !== ''
            ? ['nextcursor' => $cursor, 'limit' => self::ORDER_PAGE_SIZE]
            : ['fromDate' => $since->copy()->utc()->format('Y-m-d\TH:i:s\Z'), 'limit' => self::ORDER_PAGE_SIZE];

        $body = $this->json($this->api('GET', '/v4/orders', $query));
        $events = [];

        foreach (self::rows($body['resources'] ?? null) as $order) {
            $salesOrderId = trim((string) ($order['salesOrderId'] ?? ''));

            if ($salesOrderId === '') {
                continue;
            }

            $items = array_map(self::cleanItem(...), self::rows($order['positionItems'] ?? null));
            $events[] = [
                '_kind' => 'created',
                'salesOrderId' => $salesOrderId,
                'orderNumber' => (string) ($order['orderNumber'] ?? ''),
                'orderDate' => $order['orderDate'] ?? null,
                'items' => $items,
            ];

            foreach ($items as $item) {
                $status = (string) ($item['fulfillmentStatus'] ?? '');

                if (in_array($status, self::CANCELLED, true)) {
                    $events[] = ['_kind' => 'cancelled', 'salesOrderId' => $salesOrderId, 'item' => $item];
                } elseif ($status === 'RETURNED') {
                    $events[] = ['_kind' => 'returned', 'salesOrderId' => $salesOrderId, 'item' => $item];
                }
            }
        }

        $next = self::nextCursor($body['links'] ?? null);

        return new OrderPage(orders: $events, nextCursor: $next, hasMore: $next !== null);
    }

    /** @param array<string, mixed> $order */
    public function pollingEventIdFor(array $order): ?string
    {
        $id = trim((string) ($order['salesOrderId'] ?? ''));
        $item = (string) ($order['item']['positionItemId'] ?? '');

        if ($id === '') {
            return null;
        }

        return match ($order['_kind'] ?? null) {
            'created' => "{$id}:created",
            'cancelled' => $item !== '' ? "{$id}:cancel:{$item}" : null,
            'returned' => $item !== '' ? "{$id}:return:{$item}" : null,
            default => null,
        };
    }

    public function parseOrderEvent(InboxMessage $message): ?NormalizedOrderEvent
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($message->payload) ? $message->payload : [];
        $id = trim((string) ($payload['salesOrderId'] ?? ''));
        $kind = $payload['_kind'] ?? null;

        if ($id === '' || ! in_array($kind, ['created', 'cancelled', 'returned'], true)) {
            return null;
        }

        $ref = $message->external_event_id ?? $this->pollingEventIdFor($payload);

        if ($kind !== 'created') {
            $item = is_array($payload['item'] ?? null) ? $payload['item'] : [];
            $sku = (string) ($item['sku'] ?? '');

            return new NormalizedOrderEvent(
                type: $kind,
                externalOrderId: $id,
                externalRef: $ref,
                payload: ['lines' => [['external_line_id' => $sku !== '' ? $sku : (string) ($item['positionItemId'] ?? ''), 'sku' => $sku, 'quantity' => 1]]],
                occurredAt: self::date($item['cancellationDate'] ?? $item['returnedDate'] ?? null),
            );
        }

        $lines = [];
        $statuses = [];

        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $sku = (string) ($item['sku'] ?? '');
            $key = $sku !== '' ? $sku : (string) ($item['positionItemId'] ?? '');
            $statuses[] = (string) ($item['fulfillmentStatus'] ?? '');
            $lines[$key] ??= ['external_line_id' => $key, 'sku' => $sku, 'external_variant_id' => $sku !== '' ? $sku : null, 'title' => (string) ($item['title'] ?? $key), 'quantity' => 0, 'cents' => 0];
            $lines[$key]['quantity']++;
            $lines[$key]['cents'] += (int) round(((float) ($item['price'] ?? 0)) * 100);
        }

        $subtotal = 0;
        $normalized = [];

        foreach ($lines as $line) {
            $subtotal += $line['cents'];
            $normalized[] = [
                'external_line_id' => $line['external_line_id'],
                'sku' => $line['sku'],
                'external_variant_id' => $line['external_variant_id'],
                'title' => $line['title'],
                'quantity' => $line['quantity'],
                'unit_price' => number_format(intdiv($line['cents'], max(1, $line['quantity'])) / 100, 2, '.', ''),
                'line_total' => number_format($line['cents'] / 100, 2, '.', ''),
            ];
        }

        $placedAt = self::date($payload['orderDate'] ?? null);

        return new NormalizedOrderEvent(
            type: 'created',
            externalOrderId: $id,
            externalRef: $ref,
            payload: [
                'type' => 'created',
                'external_number' => (string) ($payload['orderNumber'] ?? '') ?: $id,
                'status' => self::orderStatus($statuses),
                'currency' => 'EUR',
                'subtotal' => number_format($subtotal / 100, 2, '.', ''),
                'shipping_total' => '0.00',
                'grand_total' => number_format($subtotal / 100, 2, '.', ''),
                'lines' => $normalized,
                'customer_ref' => [],
            ],
            occurredAt: $placedAt,
            placedAt: $placedAt,
        );
    }

    public function orderStateFor(NormalizedOrderEvent $event): ?OrderState
    {
        return match ($event->payload['status'] ?? null) {
            'ANNOUNCED' => OrderState::AWAITING_PAYMENT,
            'PROCESSABLE' => OrderState::AWAITING_SHIPMENT,
            'SENT' => OrderState::SHIPPED,
            'CANCELLED' => OrderState::CANCELLED,
            default => null,
        };
    }

    public function acknowledgeOrder(Order $order): AdapterResult
    {
        return AdapterResult::success(['acknowledged' => true]);
    }

    // ------------------------------------------------------------------- iç

    /**
     * @param  array<string, mixed>  $query
     * @param  list<array<string, mixed>>|null  $body
     */
    private function api(string $method, string $path, array $query = [], ?array $body = null): Response
    {
        $response = $this->client->request(
            $method,
            OttoApp::host().$path,
            body: $body,
            query: $query,
            headers: ['Authorization' => 'Bearer '.$this->installationToken()],
        );

        $response->throw();

        return $response;
    }

    /**
     * Kurulum erişim anahtarı — 25 dk önbellekte (ömür 1800 sn).
     * Kurulum kimliği yoksa istek ATILMAZ: satıcı uygulamayı kurmamıştır.
     */
    private function installationToken(): string
    {
        $installationId = trim((string) ($this->connection->settings[self::INSTALLATION_ID_KEY] ?? ''));

        if ($installationId === '') {
            throw new RuntimeException('OTTO kurulumu tamamlanmadı — panelden "Yetkilendir" ile uygulamayı kur.');
        }

        return Cache::remember("otto-installation-token:{$this->connection->id}", self::TOKEN_TTL_SECONDS, function () use ($installationId): string {
            $developer = $this->client->post(
                OttoApp::host().'/oauth2/token',
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => OttoApp::clientId(),
                    'client_secret' => OttoApp::clientSecret(),
                    'scope' => 'developer',
                ],
                asForm: true,
            );
            $developer->throw();

            $developerToken = $developer->json('access_token');

            if (! is_string($developerToken) || $developerToken === '') {
                throw new RuntimeException('OTTO geliştirici anahtarı alınamadı.');
            }

            $installation = $this->client->post(
                OttoApp::host().'/v1/apps/'.rawurlencode(OttoApp::appId()).'/installations/'.rawurlencode($installationId).'/accessToken',
                ['scope' => self::SCOPES],
                headers: ['Authorization' => 'Bearer '.$developerToken],
                asForm: true,
            );
            $installation->throw();

            $token = $installation->json('access_token');

            if (! is_string($token) || $token === '') {
                throw new RuntimeException('OTTO kurulum anahtarı yanıtı access_token taşımıyor.');
            }

            return $token;
        });
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $body = json_decode($response->body(), true);

        return is_array($body) ? $body : [];
    }

    /**
     * Kalemden yalnız iş alanları — adres ve alıcı alınmaz.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function cleanItem(array $item): array
    {
        return array_filter([
            'positionItemId' => $item['positionItemId'] ?? null,
            'fulfillmentStatus' => $item['fulfillmentStatus'] ?? null,
            'price' => $item['itemValueReducedGrossPrice']['amount'] ?? $item['itemValueGrossPrice']['amount'] ?? null,
            'sku' => $item['product']['sku'] ?? null,
            'title' => $item['product']['productTitle'] ?? null,
            'cancellationDate' => $item['cancellationDate'] ?? null,
            'returnedDate' => $item['returnedDate'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /** @param list<string> $statuses */
    private static function orderStatus(array $statuses): string
    {
        $live = array_values(array_filter($statuses, static fn (string $s): bool => ! in_array($s, self::CANCELLED, true)));

        if ($live === []) {
            return 'CANCELLED';
        }

        foreach (['ANNOUNCED', 'PROCESSABLE', 'SENT'] as $state) {
            if (in_array($state, $live, true)) {
                return $state;
            }
        }

        return 'RETURNED';
    }

    private static function nextCursor(mixed $links): ?string
    {
        foreach (self::rows($links) as $link) {
            if (($link['rel'] ?? null) === 'next' && is_string($link['href'] ?? null)) {
                parse_str((string) parse_url($link['href'], PHP_URL_QUERY), $query);
                $cursor = $query['nextcursor'] ?? null;

                return is_string($cursor) && $cursor !== '' ? $cursor : null;
            }
        }

        return null;
    }

    private static function hasNext(mixed $links): bool
    {
        foreach (self::rows($links) as $link) {
            if (($link['rel'] ?? null) === 'next') {
                return true;
            }
        }

        return false;
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
}
