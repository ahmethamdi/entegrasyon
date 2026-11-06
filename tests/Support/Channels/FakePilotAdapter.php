<?php

declare(strict_types=1);

namespace Tests\Support\Channels;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use App\Domain\Sync\Support\OrderPage;
use App\Domain\Sync\Support\RemoteInventorySnapshot;
use App\Domain\Sync\Support\RemoteProduct;
use App\Domain\Sync\Support\RemoteProductPage;
use Carbon\CarbonInterface;
use LogicException;
use Throwable;

/**
 * Pilot doğrulaması için sahte kanal — okuma yöntemleri statik fikstürden
 * döner, YAZMA yöntemi çağrılırsa patlar: komutun kanala hiç yazmadığı
 * böylece testte kanıtlanır.
 */
final class FakePilotAdapter implements ChannelAdapter, SupportsCatalogImport, SupportsInventory, SupportsOrders
{
    use DeclaresRequestQuota;

    public static bool $healthy = true;

    /** @var list<array<string, mixed>> */
    public static array $orders = [];

    /** @var list<RemoteProduct> */
    public static array $products = [];

    /** @var array<string, int> */
    public static array $remoteStock = [];

    public static ?Throwable $ordersFail = null;

    /** @var list<string> */
    public static array $calls = [];

    public function __construct(
        private readonly ChannelConnection $connection,
        public readonly mixed $client = null,
    ) {}

    public static function reset(): void
    {
        self::$healthy = true;
        self::$orders = [];
        self::$products = [];
        self::$remoteStock = [];
        self::$ordersFail = null;
        self::$calls = [];
    }

    public function connection(): ChannelConnection
    {
        return $this->connection;
    }

    public function healthCheck(): HealthResult
    {
        self::$calls[] = 'healthCheck';

        return self::$healthy ? HealthResult::healthy(42) : HealthResult::unhealthy('401 yetkisiz');
    }

    public function classifyError(Throwable $e): ErrorClass
    {
        return ErrorClass::SERVER_ERROR;
    }

    public function rateLimitProfile(): RateLimitProfile
    {
        return RateLimitProfile::conservative();
    }

    public function verifyWebhookSignature(string $raw, array $headers): bool
    {
        return true;
    }

    public function extractEventId(array $headers): ?string
    {
        return null;
    }

    public function extractEventType(array $headers): string
    {
        return 'order.created';
    }

    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage
    {
        self::$calls[] = 'fetchOrders';

        if (self::$ordersFail !== null) {
            throw self::$ordersFail;
        }

        return new OrderPage(self::$orders);
    }

    public function pollingEventIdFor(array $order): ?string
    {
        return isset($order['external_order_id']) ? $order['external_order_id'].':'.($order['status'] ?? '') : null;
    }

    public function parseOrderEvent(InboxMessage $message): ?NormalizedOrderEvent
    {
        $payload = $message->payload;

        return new NormalizedOrderEvent(
            type: 'created',
            externalOrderId: (string) ($payload['external_order_id'] ?? ''),
            externalRef: $message->external_event_id,
            payload: $payload,
            occurredAt: new \DateTimeImmutable('2026-11-05 10:00:00'),
        );
    }

    public function acknowledgeOrder(Order $order): AdapterResult
    {
        throw new LogicException('pilot doğrulaması kanala YAZMAMALI (acknowledgeOrder)');
    }

    public function fetchProductPage(?string $cursor = null): RemoteProductPage
    {
        self::$calls[] = 'fetchProductPage';

        return new RemoteProductPage(self::$products);
    }

    public function maxImportPages(): int
    {
        return 1;
    }

    public function pushInventory(InventoryPushBatch $batch): AdapterResult
    {
        throw new LogicException('pilot doğrulaması kanala YAZMAMALI (pushInventory)');
    }

    public function fetchInventory(array $listings): RemoteInventorySnapshot
    {
        self::$calls[] = 'fetchInventory';

        return new RemoteInventorySnapshot(self::$remoteStock);
    }

    public function maxInventoryBatchSize(): int
    {
        return 100;
    }
}
