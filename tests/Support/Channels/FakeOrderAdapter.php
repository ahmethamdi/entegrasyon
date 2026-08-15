<?php

declare(strict_types=1);

namespace Tests\Support\Channels;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use App\Domain\Sync\Support\OrderPage;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Sipariş yeteneği olan sahte adapter.
 *
 * Gerçek WooCommerce ve Trendyol adapter'ları henüz yazılmadı; gelen hattın
 * davranışı onlardan bağımsız sınanabilir ve sınanmalıdır.
 *
 * İmza doğrulama davranışı statik bayrakla kontrol edilir: HMAC reddi testi
 * gerçek bir imza hesaplamak zorunda kalmasın, yalnızca controller'ın RED
 * yolunu izlediği doğrulansın.
 */
final class FakeOrderAdapter implements ChannelAdapter, SupportsOrders
{
    /** Testler imza sonucunu buradan kontrol eder. */
    public static bool $signatureValid = true;

    /** parseOrderEvent null dönsün mü — "sipariş olayı değil" yolu. */
    public static bool $parsesEvents = true;

    public function __construct(
        private readonly ChannelConnection $connection,
        public readonly mixed $client = null,
    ) {}

    public static function reset(): void
    {
        self::$signatureValid = true;
        self::$parsesEvents = true;
    }

    public function connection(): ChannelConnection
    {
        return $this->connection;
    }

    public function healthCheck(): HealthResult
    {
        return HealthResult::healthy();
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
        return self::$signatureValid;
    }

    public function extractEventId(array $headers): ?string
    {
        return $headers['x-fake-delivery-id'][0] ?? null;
    }

    public function extractEventType(array $headers): string
    {
        return $headers['x-fake-topic'][0] ?? 'order.created';
    }

    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage
    {
        return new OrderPage([]);
    }

    /**
     * Ham yükü kanonik olaya çevirir.
     *
     * Test yükleri zaten kanonik biçimde yazılıyor; gerçek adapter burada
     * kanal formatını dönüştürecek.
     */
    public function parseOrderEvent(InboxMessage $message): ?NormalizedOrderEvent
    {
        if (! self::$parsesEvents) {
            return null;
        }

        $payload = $message->payload;

        return new NormalizedOrderEvent(
            type: (string) ($payload['type'] ?? 'created'),
            externalOrderId: (string) ($payload['external_order_id'] ?? ''),
            externalRef: $payload['external_ref'] ?? null,
            payload: $payload,
            occurredAt: new \DateTimeImmutable,
        );
    }

    public function acknowledgeOrder(Order $order): AdapterResult
    {
        return AdapterResult::success();
    }
}
