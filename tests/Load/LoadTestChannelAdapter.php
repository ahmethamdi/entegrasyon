<?php

declare(strict_types=1);

namespace Tests\Load;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\MapsOrderState;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\MapsOrderStateFromTable;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\InventoryPushItem;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use App\Domain\Sync\Support\OrderPage;
use App\Domain\Sync\Support\PricePushBatch;
use App\Domain\Sync\Support\RemoteInventorySnapshot;
use App\Domain\Sync\Support\RemotePriceSnapshot;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * YÜK TESTİ KANALI — `tests/Load/black-friday.php` kurar, AĞA ÇIKMAZ.
 *
 * Gerçek pazaryerinin yerine geçer ama iki şeyi gerçek gibi yapar:
 *   - stok gönderimi GECİKİR (varsayılan 150 ms/istek, Redis'ten
 *     ayarlanır): ölçülen şey bizim kuyruğumuzun bu gecikme altında
 *     ayakta kalıp kalmadığıdır, kanalın hızı değil;
 *   - her başarılı gönderimi Redis'e yazar (`loadtest:pushes`): sipariş
 *     anından "stok BEŞ kanalda da düştü" anına kadar geçen süre buradan
 *     ölçülür — işin bittiğini kuyruğun boşalması değil kanalın aldığı
 *     son değer kanıtlar;
 *   - kanalın kendi stoğunu tutar (`loadtest:remote`): gönderim değeri
 *     ÜZERİNE YAZAR, siparişte pazaryeri kendi stoğunu kendisi düşer
 *     (sürücü betiği düşer). Sistem siparişin geldiği kanala normalde stok
 *     geri yazmaz (yankı bastırma, `InventoryLevelChangedConsumer`); bu yüzden
 *     "eski değerli gönderim pazaryerinin kendi düşüşünü ezdi mi" yarışı
 *     ancak durumlu sahte kanalla görülür.
 *
 * `autoload-dev` altındadır: üretim imajında (`--no-dev`) sınıf YOKTUR,
 * yanlışlıkla bağlanmış bir `channel_types` satırı orada çalışamaz.
 */
final class LoadTestChannelAdapter implements ChannelAdapter, MapsOrderState, SupportsInventory, SupportsOrders, SupportsPricing
{
    use DeclaresRequestQuota;
    use MapsOrderStateFromTable;

    public const PUSHES_KEY = 'loadtest:pushes';

    public const LATENCY_KEY = 'loadtest:latency_ms';

    /** Kanalın elindeki stok: `bağlantı|sku` → adet. */
    public const REMOTE_KEY = 'loadtest:remote';

    /** Fiyat gönderimleri ve kanalın elindeki fiyat: `listing` → fiyat. */
    public const PRICE_PUSHES_KEY = 'loadtest:price_pushes';

    public const REMOTE_PRICE_KEY = 'loadtest:remote_price';

    private const ORDER_STATE_MAP = [
        'created' => 'awaiting_shipment',
        'shipped' => 'shipped',
        'cancelled' => 'cancelled',
    ];

    public function __construct(
        private readonly ChannelConnection $connection,
        public readonly mixed $client = null,
    ) {}

    public function connection(): ChannelConnection
    {
        return $this->connection;
    }

    public function healthCheck(): HealthResult
    {
        return HealthResult::healthy(latencyMs: 1);
    }

    public function classifyError(Throwable $e): ErrorClass
    {
        return ErrorClass::SERVER_ERROR;
    }

    public function rateLimitProfile(): RateLimitProfile
    {
        return RateLimitProfile::fromArray($this->connection->channelType->rate_limit_profile ?? []);
    }

    public function verifyWebhookSignature(string $raw, array $headers): bool
    {
        return true;
    }

    public function extractEventId(array $headers): ?string
    {
        return $headers['x-loadtest-event-id'][0] ?? null;
    }

    public function extractEventType(array $headers): string
    {
        return 'order.created';
    }

    public function pushInventory(InventoryPushBatch $batch): AdapterResult
    {
        usleep(max(0, (int) (Redis::get(self::LATENCY_KEY) ?? 150)) * 1000);

        $now = sprintf('%.6f', microtime(true));
        $request = uniqid();
        Redis::rpush(self::PUSHES_KEY, ...array_map(
            fn (InventoryPushItem $item): string => "{$this->connection->id}|{$item->sku}|{$item->quantity}|{$now}|{$request}",
            $batch->items,
        ));
        foreach ($batch->items as $item) {
            Redis::hset(self::REMOTE_KEY, "{$this->connection->id}|{$item->sku}", $item->quantity);
        }

        return AdapterResult::success(['pushed' => $batch->count()]);
    }

    /**
     * Fiyat da stok gibi gecikir ve ÜZERİNE YAZAR: eski fiyatlı paket
     * yenisinden sonra varırsa kanalda eski fiyat kalır — kilit (d6f7675)
     * bunu önlemeli; betik sonunda kanal fiyatını yerel fiyatla karşılaştırır.
     */
    public function pushPrices(PricePushBatch $batch): AdapterResult
    {
        usleep(max(0, (int) (Redis::get(self::LATENCY_KEY) ?? 150)) * 1000);

        $now = sprintf('%.6f', microtime(true));
        $request = uniqid();
        Redis::rpush(self::PRICE_PUSHES_KEY, ...array_map(
            static fn (array $item): string => "{$item['listing_id']}|{$item['price']}|{$now}|{$request}",
            $batch->items,
        ));
        foreach ($batch->items as $item) {
            Redis::hset(self::REMOTE_PRICE_KEY, $item['listing_id'], $item['price']);
        }

        return AdapterResult::success(['pushed' => $batch->count()]);
    }

    public function fetchPrices(array $listings): RemotePriceSnapshot
    {
        return new RemotePriceSnapshot([]);
    }

    public function maxPriceBatchSize(): int
    {
        return 100;
    }

    public function fetchInventory(array $listings): RemoteInventorySnapshot
    {
        return new RemoteInventorySnapshot([]);
    }

    public function maxInventoryBatchSize(): int
    {
        return (int) ($this->connection->channelType->rate_limit_profile['max_inventory_batch'] ?? 100);
    }

    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage
    {
        return new OrderPage([]);
    }

    public function pollingEventIdFor(array $order): ?string
    {
        return isset($order['external_order_id']) ? (string) $order['external_order_id'] : null;
    }

    /** Yük zaten kanonik biçimde gelir (sürücü betiği yazar). */
    public function parseOrderEvent(InboxMessage $message): ?NormalizedOrderEvent
    {
        $payload = $message->payload;

        return new NormalizedOrderEvent(
            type: (string) ($payload['type'] ?? 'created'),
            externalOrderId: (string) ($payload['external_order_id'] ?? ''),
            externalRef: null,
            payload: $payload,
            occurredAt: new DateTimeImmutable,
        );
    }

    public function acknowledgeOrder(Order $order): AdapterResult
    {
        return AdapterResult::success();
    }
}
