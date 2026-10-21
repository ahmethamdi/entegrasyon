<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Kaufland;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresChannelCurrency;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\MapsOrderState;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Contracts\SupportsFulfillment;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
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
 * Kaufland Marketplace Seller API v2 adapter'ı (`sellerapi.kaufland.com/v2`).
 *
 * Kaynak: resmî belge `?page=rest-api` + yayımlanan OpenAPI 3
 * (`sellerapi.kaufland.com/swagger.json`, 21 Eki 2026 indirildi). Notlar:
 * `docs/KAUFLAND-API-NOTLARI.md`. BETA kanal — gerçek satıcı hesabıyla
 * SINANMADI (21 Eki kararı: ilk müşteriyle birlikte doğrulanır).
 *
 * KİMLİK: her istek `Shop-Client-Key` + `Shop-Timestamp` (sn) +
 * `Shop-Signature` = HMAC-SHA256(secret, "METHOD\nURI\nBODY\nTIMESTAMP"),
 * URI tam adres (sorgu dahil). Gövde imzalanan metnin AYNISI gider; bu
 * yüzden JSON bir kez kodlanır ve ham gövde olarak gönderilir.
 *
 * MODEL: ürün = Kaufland kataloğunda EAN ile var olan ürün; satıcının
 * teklifi = "unit" (`id_unit`, fiyat KURUŞ tam sayı `listing_price`, stok
 * `amount`). Listing kimliği `id_unit`; SKU `id_offer`. Sipariş = `id_order`,
 * altında HER ADET AYRI "order unit" (adet = 1).
 *
 * ⚠️ DOĞRULANMADI (gerçek hesapta bakılacak):
 *   - Hız sınırı (belgede sayı yok; 2 istek/sn varsayıldı).
 *   - `ts_updated_from_iso` bölge biçimi (ISO 8601 UTC gönderiliyor).
 *   - `send` sonrası birim durumunun `sent` mi `sent_and_autopaid` mi döndüğü.
 *   - Yeni ürün AÇMA (EAN'ı katalogda olmayan ürün) bu sürümde YOK.
 */
final class KauflandAdapter implements ChannelAdapter, DeclaresChannelCurrency, MapsOrderState, SupportsCatalogImport, SupportsFulfillment, SupportsInventory, SupportsOrders, SupportsPricing
{
    use DeclaresRequestQuota;

    public const BASE_URL = 'https://sellerapi.kaufland.com/v2';

    public const CLIENT_KEY_SECRET = 'client_key';

    public const SECRET_KEY_SECRET = 'secret_key';

    public const SELLER_NAME_KEY = 'kaufland_seller_name';

    public const STOREFRONT_KEY = 'kaufland_storefront';

    /** OpenAPI `Storefront` enum'u. */
    public const STOREFRONTS = ['de', 'cz', 'sk', 'pl', 'at', 'fr', 'it', 'es', 'nl'];

    /** Belgedeki `User-Agent` kuralı: yazılım sağlayıcı kendi adını gönderir. */
    private const USER_AGENT = '34Pazar';

    /** `limit` üst sınırı 100 (OpenAPI). */
    private const PAGE_SIZE = 100;

    /** Birim başına tek PATCH — partiyi küçük tutar (hız sınırı bilinmiyor). */
    private const MAX_BATCH = 20;

    /** Belgedeki kod listesinden sık kullanılanlar; gerisi `Other`. */
    private const CARRIERS = [
        'DHL' => 'DHL',
        'Deutsche Post' => 'Deutsche Post',
        'DPD' => 'DPD',
        'GLS' => 'GLS',
        'Hermes' => 'Hermes',
        'UPS' => 'UPS',
        'Fedex' => 'FedEx',
        'Austrian Post' => 'Austrian Post',
        'Czech Post' => 'Czech Post',
        'InPost' => 'InPost',
        'Other' => 'Diğer',
    ];

    /** Kargo bekleyen birim durumları (OpenAPI `OrderUnitStatus`). */
    private const OPEN_STATUSES = ['open', 'need_to_be_sent'];

    private const SENT_STATUSES = ['sent', 'sent_and_autopaid', 'received'];

    private const RETURNED_STATUSES = ['returned', 'returned_paid'];

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
        return match ($this->storefront()) {
            'cz' => 'CZK',
            'pl' => 'PLN',
            default => 'EUR',
        };
    }

    // ---------------------------------------------------------------- sağlık

    /** `GET /units?limit=1` — imza ve anahtarın geçtiğini kanıtlar. */
    public function healthCheck(): HealthResult
    {
        $startedAt = hrtime(true);

        try {
            $this->api('GET', '/units', ['storefront' => $this->storefront(), 'limit' => 1]);
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

    /** Sipariş webhook'u kullanılmıyor; imzasız gövde ASLA kabul edilmez. */
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

    /** Satıcının unit'leri — her satır bir teklif; kimlik `id_unit`. */
    public function fetchProductPage(?string $cursor = null): RemoteProductPage
    {
        $offset = $cursor === null ? 0 : max(0, (int) $cursor);

        $body = $this->json($this->api('GET', '/units', [
            'storefront' => $this->storefront(),
            'limit' => self::PAGE_SIZE,
            'offset' => $offset,
            'embedded' => 'product',
        ]));

        $products = [];

        foreach (self::rows($body['data'] ?? null) as $unit) {
            if (! isset($unit['id_unit'])) {
                continue;
            }

            $products[] = $this->toRemoteProduct($unit);
        }

        $total = (int) ($body['pagination']['total'] ?? 0);
        $hasMore = $products !== [] && $offset + count($products) < $total;

        return new RemoteProductPage(
            products: $products,
            nextCursor: $hasMore ? (string) ($offset + count($products)) : null,
            hasMore: $hasMore,
        );
    }

    public function maxImportPages(): int
    {
        return 200;
    }

    /** @param array<string, mixed> $unit */
    private function toRemoteProduct(array $unit): RemoteProduct
    {
        $idUnit = (string) $unit['id_unit'];
        $offer = trim((string) ($unit['id_offer'] ?? ''));
        $product = is_array($unit['product'] ?? null) ? $unit['product'] : [];
        $eans = array_values(array_filter((array) ($product['eans'] ?? []), 'is_string'));
        $ean = $eans[0] ?? null;
        $picture = (string) ($product['main_picture'] ?? '');

        return new RemoteProduct(
            externalId: $idUnit,
            // Satıcının teklif kimliği SKU'dur; yoksa EAN.
            sku: $offer !== '' ? $offer : ($ean ?? $idUnit),
            title: trim((string) ($product['title'] ?? '')) ?: ($offer !== '' ? $offer : $idUnit),
            price: is_numeric($unit['listing_price'] ?? null) ? self::fromCents((int) $unit['listing_price']) : null,
            quantity: (int) ($unit['amount'] ?? 0),
            brand: isset($product['manufacturer']) && is_string($product['manufacturer']) ? $product['manufacturer'] : null,
            barcode: $ean,
            status: ($unit['is_live'] ?? null) === true ? 'approved' : 'inactive',
            images: str_starts_with($picture, 'https://') ? [$picture] : [],
            raw: $unit,
            listingIdentity: array_filter([
                'external_id' => $idUnit,
                'channel_metadata' => array_filter([
                    'id_offer' => $offer !== '' ? $offer : null,
                    'id_product' => isset($unit['id_product']) ? (string) $unit['id_product'] : null,
                ]),
            ]),
            currency: $this->channelCurrency(),
        );
    }

    // ------------------------------------------------------------ stok/fiyat

    /** MUTLAK stok — `PATCH /units/{id_unit}` gövdesinde YALNIZ `amount`. */
    public function pushInventory(InventoryPushBatch $batch): AdapterResult
    {
        $patches = [];

        foreach ($batch->items as $index => $item) {
            $patches[$index] = [$item->externalId, ['amount' => max(0, $item->quantity)]];
        }

        return $this->patchUnits($patches, $batch->operations());
    }

    public function maxInventoryBatchSize(): int
    {
        return self::MAX_BATCH;
    }

    /** @param list<Listing> $listings */
    public function fetchInventory(array $listings): RemoteInventorySnapshot
    {
        $quantities = [];

        foreach ($this->readUnits($listings) as $id => $unit) {
            $quantities[$id] = (int) ($unit['amount'] ?? 0);
        }

        return new RemoteInventorySnapshot($quantities, new DateTimeImmutable);
    }

    /**
     * MUTLAK fiyat — `listing_price` KURUŞ tam sayı (OpenAPI `integer`).
     * Ondalık gönderilseydi 19.90 € 19 sent sayılırdı.
     */
    public function pushPrices(PricePushBatch $batch): AdapterResult
    {
        $patches = [];

        foreach ($batch->items as $index => $item) {
            $cents = self::toCents((string) $item['price']);

            if ($cents <= 0) {
                continue;
            }

            $patches[$index] = [(string) $item['external_id'], ['listing_price' => $cents]];
        }

        return $this->patchUnits($patches, $batch->operations());
    }

    public function maxPriceBatchSize(): int
    {
        return self::MAX_BATCH;
    }

    /** @param list<Listing> $listings */
    public function fetchPrices(array $listings): RemotePriceSnapshot
    {
        $prices = [];

        foreach ($this->readUnits($listings) as $id => $unit) {
            if (is_numeric($unit['listing_price'] ?? null)) {
                $prices[$id] = self::fromCents((int) $unit['listing_price']);
            }
        }

        return new RemotePriceSnapshot($prices, new DateTimeImmutable);
    }

    /**
     * Unit başına PATCH. 4xx kaleme yazılır (partial), 5xx/ağ hatası yükselir
     * ve parti yeniden denenir — değerler mutlak olduğu için tekrar zararsız.
     *
     * @param  array<int, array{0: string, 1: array<string, int>}>  $patches
     * @param  list<mixed>  $operations
     */
    private function patchUnits(array $patches, array $operations): AdapterResult
    {
        if ($patches === []) {
            return AdapterResult::success(['pushed' => 0]);
        }

        $failed = [];
        $pushed = 0;

        foreach ($patches as $index => [$idUnit, $body]) {
            if (! ctype_digit($idUnit)) {
                $failed[self::operationId($operations, $index)] = "Kaufland unit kimliği geçersiz: {$idUnit}";

                continue;
            }

            try {
                $this->api('PATCH', "/units/{$idUnit}", ['storefront' => $this->storefront()], $body);
                $pushed++;
            } catch (RequestException $e) {
                $status = $e->response->status();

                if ($status >= 500 || $status === 429 || $status === 401 || $status === 403) {
                    throw $e;
                }

                $failed[self::operationId($operations, $index)] = 'Kaufland reddetti ('.$status.'): '.mb_substr($e->response->body(), 0, 300);
            }
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
    private function readUnits(array $listings): array
    {
        $units = [];

        foreach ($listings as $listing) {
            $id = trim((string) ($listing->external_id ?? ''));

            if (! ctype_digit($id)) {
                continue;
            }

            $body = $this->json($this->api('GET', "/units/{$id}", ['storefront' => $this->storefront()]));

            if (is_array($body['data'] ?? null)) {
                $units[$id] = $body['data'];
            }
        }

        return $units;
    }

    // ---------------------------------------------------------------- sipariş

    /**
     * Sipariş birimlerini yoklar ve `id_order`'a göre TOPLAR.
     *
     * Kaufland her adedi ayrı birim olarak verir; tek tek "created"
     * üretilseydi aynı sipariş için ikinci "created" sipariş yaratma yoluna
     * girerdi. Sipariş başına bir `created` (bütün birimler) + iptal/iade
     * için birim başına ayrı olay üretilir (Pazarama kalıbı).
     *
     * ⚠️ PENCERE `ts_updated_from_iso` — iptal sonradan gelir.
     */
    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage
    {
        $offset = $cursor === null ? 0 : max(0, (int) $cursor);

        $body = $this->json($this->api('GET', '/order-units', [
            'storefront' => $this->storefront(),
            'ts_updated_from_iso' => $since->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'sort' => 'ts_updated:desc',
            'limit' => self::PAGE_SIZE,
            'offset' => $offset,
        ]));

        $units = self::rows($body['data'] ?? null);
        $total = (int) ($body['pagination']['total'] ?? count($units));
        $hasMore = $units !== [] && $offset + count($units) < $total;

        $byOrder = [];

        foreach ($units as $unit) {
            $orderId = trim((string) ($unit['id_order'] ?? ''));

            if ($orderId !== '' && isset($unit['id_order_unit'])) {
                $byOrder[$orderId][] = self::cleanUnit($unit);
            }
        }

        $events = [];

        foreach ($byOrder as $orderId => $orderUnits) {
            $events[] = ['_kind' => 'created', 'id_order' => $orderId, 'units' => $orderUnits];

            foreach ($orderUnits as $unit) {
                $status = (string) ($unit['status'] ?? '');

                if ($status === 'cancelled') {
                    $events[] = ['_kind' => 'cancelled', 'id_order' => $orderId, 'unit' => $unit];
                } elseif (in_array($status, self::RETURNED_STATUSES, true)) {
                    $events[] = ['_kind' => 'returned', 'id_order' => $orderId, 'unit' => $unit];
                }
            }
        }

        return new OrderPage(
            orders: $events,
            nextCursor: $hasMore ? (string) ($offset + count($units)) : null,
            hasMore: $hasMore,
        );
    }

    /** @param array<string, mixed> $order */
    public function pollingEventIdFor(array $order): ?string
    {
        $orderId = trim((string) ($order['id_order'] ?? ''));
        $unitId = (string) ($order['unit']['id_order_unit'] ?? '');

        if ($orderId === '') {
            return null;
        }

        return match ($order['_kind'] ?? null) {
            'created' => "{$orderId}:created",
            'cancelled' => $unitId !== '' ? "{$orderId}:cancel:{$unitId}" : null,
            'returned' => $unitId !== '' ? "{$orderId}:return:{$unitId}" : null,
            default => null,
        };
    }

    public function parseOrderEvent(InboxMessage $message): ?NormalizedOrderEvent
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($message->payload) ? $message->payload : [];
        $orderId = trim((string) ($payload['id_order'] ?? ''));
        $kind = $payload['_kind'] ?? null;

        if ($orderId === '' || ! in_array($kind, ['created', 'cancelled', 'returned'], true)) {
            return null;
        }

        $ref = $message->external_event_id ?? $this->pollingEventIdFor($payload);

        if ($kind !== 'created') {
            $unit = is_array($payload['unit'] ?? null) ? $payload['unit'] : [];

            return new NormalizedOrderEvent(
                type: $kind,
                externalOrderId: $orderId,
                externalRef: $ref,
                payload: [
                    'lines' => [[
                        'external_line_id' => (string) ($unit['id_offer'] ?? $unit['id_order_unit'] ?? ''),
                        'sku' => (string) ($unit['id_offer'] ?? ''),
                        'quantity' => 1,
                    ]],
                ],
                occurredAt: self::date($unit['ts_updated_iso'] ?? null),
            );
        }

        // Aynı teklifin birimleri TEK satırda toplanır (adet = birim sayısı).
        $lines = [];
        $currency = null;
        $shipping = 0;
        $placedAt = null;
        $statuses = [];

        foreach ((array) ($payload['units'] ?? []) as $unit) {
            if (! is_array($unit)) {
                continue;
            }

            $offer = (string) ($unit['id_offer'] ?? '');
            $key = $offer !== '' ? $offer : (string) $unit['id_order_unit'];
            $currency ??= is_string($unit['currency'] ?? null) ? $unit['currency'] : null;
            $shipping += (int) ($unit['shipping_rate'] ?? 0);
            $placedAt ??= self::date($unit['ts_created_iso'] ?? null);
            $statuses[] = (string) ($unit['status'] ?? '');

            $lines[$key] ??= [
                'external_line_id' => $key,
                'sku' => $offer,
                'external_variant_id' => isset($unit['id_unit']) ? (string) $unit['id_unit'] : null,
                'title' => (string) ($unit['title'] ?? $key),
                'quantity' => 0,
                'cents' => 0,
            ];
            $lines[$key]['quantity']++;
            // `price` birimin satış fiyatı, KURUŞ (OpenAPI `Integer`).
            $lines[$key]['cents'] += (int) ($unit['price'] ?? 0);
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
                'unit_price' => self::fromCents(intdiv($line['cents'], max(1, $line['quantity']))),
                'line_total' => self::fromCents($line['cents']),
            ];
        }

        return new NormalizedOrderEvent(
            type: 'created',
            externalOrderId: $orderId,
            externalRef: $ref,
            payload: [
                'type' => 'created',
                'external_number' => $orderId,
                'status' => self::orderStatus($statuses),
                'currency' => $currency ?? $this->channelCurrency() ?? 'EUR',
                'subtotal' => self::fromCents($subtotal),
                'shipping_total' => self::fromCents($shipping),
                'grand_total' => self::fromCents($subtotal + $shipping),
                'lines' => $normalized,
                // Alıcı ve adres alınmaz (kişisel veri; `cleanUnit`).
                'customer_ref' => [],
            ],
            occurredAt: $placedAt,
            placedAt: $placedAt,
        );
    }

    public function orderStateFor(NormalizedOrderEvent $event): ?OrderState
    {
        return match ($event->payload['status'] ?? null) {
            'open' => OrderState::AWAITING_SHIPMENT,
            'sent' => OrderState::SHIPPED,
            'cancelled' => OrderState::CANCELLED,
            default => null,
        };
    }

    /** Kaufland'da siparişi üstlenme adımı yok — no-op. */
    public function acknowledgeOrder(Order $order): AdapterResult
    {
        return AdapterResult::success(['acknowledged' => true]);
    }

    // ---------------------------------------------------------------- kargo

    /**
     * Siparişin kargo bekleyen her birimine `PATCH /order-units/{id}/send`.
     *
     * Birimler `GET /orders/{id_order}`'dan okunur; yalnız `open` /
     * `need_to_be_sent` olanlar gönderilir — zaten gönderilmiş birime ikinci
     * kez istek gitmez (alıcı ikinci e-posta almasın).
     */
    public function pushFulfillment(Fulfillment $fulfillment): AdapterResult
    {
        $orderId = (string) ($fulfillment->order?->external_id ?? '');
        $tracking = trim((string) $fulfillment->tracking_number);

        if ($orderId === '') {
            return AdapterResult::failure(ErrorClass::VALIDATION, 'Kargo bildirimi için siparişin Kaufland kimliği yok.');
        }

        $carrier = self::carrierCode((string) $fulfillment->carrier);

        if ($tracking === '' && $carrier !== 'Other') {
            return AdapterResult::failure(ErrorClass::VALIDATION, 'Kaufland bu kargo firması için takip numarası ister.');
        }

        $order = $this->json($this->api('GET', '/orders/'.rawurlencode($orderId)));
        $sent = 0;

        foreach (self::rows($order['data']['order_units'] ?? null) as $unit) {
            if (! in_array((string) ($unit['status'] ?? ''), self::OPEN_STATUSES, true) || ! isset($unit['id_order_unit'])) {
                continue;
            }

            $this->api('PATCH', '/order-units/'.(int) $unit['id_order_unit'].'/send', [], [
                'tracking_numbers' => $tracking,
                'carrier_code' => $carrier,
            ]);
            $sent++;
        }

        return $sent === 0
            ? AdapterResult::success(['already_fulfilled' => true])
            : AdapterResult::success(['external_id' => $tracking, 'units' => $sent]);
    }

    /** @return array<string, string> */
    public function fetchCarriers(): array
    {
        return self::CARRIERS;
    }

    private static function carrierCode(string $carrier): string
    {
        foreach (array_keys(self::CARRIERS) as $code) {
            if (strcasecmp(trim($carrier), $code) === 0) {
                return $code;
            }
        }

        return 'Other';
    }

    // ------------------------------------------------------------------- iç

    /**
     * İmzalı istek. Sorgu adresin parçasıdır ve imzaya AYNEN girer.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function api(string $method, string $path, array $query = [], ?array $body = null): Response
    {
        $secrets = $this->readSecrets();
        $clientKey = (string) ($secrets[self::CLIENT_KEY_SECRET] ?? '');
        $secretKey = (string) ($secrets[self::SECRET_KEY_SECRET] ?? '');

        if ($clientKey === '' || $secretKey === '') {
            throw new RuntimeException('Kaufland Client Key / Secret Key kasada yok — bağlantıyı yeniden kur.');
        }

        $uri = self::BASE_URL.$path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $raw = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();

        $headers = [
            'Accept' => 'application/json',
            'Shop-Client-Key' => $clientKey,
            'Shop-Timestamp' => $timestamp,
            'Shop-Signature' => self::sign($method, $uri, $raw, $timestamp, $secretKey),
            'User-Agent' => self::USER_AGENT,
        ];

        $response = $this->client->request(
            strtoupper($method),
            $uri,
            body: $body === null ? null : ['raw' => $raw],
            headers: $headers,
            raw: $body === null ? null : [$raw, 'application/json'],
        );

        $response->throw();

        return $response;
    }

    /** Belgedeki imza: HMAC-SHA256(secret, METHOD\nURI\nBODY\nTIMESTAMP), hex. */
    public static function sign(string $method, string $uri, string $body, string $timestamp, string $secretKey): string
    {
        return hash_hmac('sha256', implode("\n", [strtoupper($method), $uri, $body, $timestamp]), $secretKey);
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $body = json_decode($response->body(), true);

        if (! is_array($body)) {
            throw new RuntimeException('Kaufland beklenmeyen yanıt döndü: '.mb_substr(trim($response->body()), 0, 120));
        }

        return $body;
    }

    /**
     * Birimden yalnız iş alanları — alıcı ve adres ALINMAZ (kişisel veri).
     *
     * @param  array<string, mixed>  $unit
     * @return array<string, mixed>
     */
    private static function cleanUnit(array $unit): array
    {
        $clean = array_intersect_key($unit, array_flip([
            'id_order_unit', 'id_order', 'id_offer', 'status', 'price', 'currency',
            'shipping_rate', 'ts_created_iso', 'ts_updated_iso', 'storefront', 'cancel_reason',
        ]));

        $product = is_array($unit['product'] ?? null) ? $unit['product'] : [];

        if (isset($product['title'])) {
            $clean['title'] = (string) $product['title'];
        }

        if (isset($unit['id_unit'])) {
            $clean['id_unit'] = $unit['id_unit'];
        }

        return $clean;
    }

    /** @param list<string> $statuses */
    private static function orderStatus(array $statuses): string
    {
        $live = array_values(array_filter($statuses, static fn (string $s): bool => $s !== 'cancelled'));

        if ($live === []) {
            return 'cancelled';
        }

        foreach ($live as $status) {
            if (! in_array($status, [...self::SENT_STATUSES, ...self::RETURNED_STATUSES], true)) {
                return 'open';
            }
        }

        return 'sent';
    }

    private function storefront(): string
    {
        $value = strtolower(trim((string) ($this->connection->settings[self::STOREFRONT_KEY] ?? 'de')));

        return in_array($value, self::STOREFRONTS, true) ? $value : 'de';
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private static function toCents(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
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
