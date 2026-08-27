<?php

declare(strict_types=1);

namespace Tests\Support\Channels;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\RemoteInventorySnapshot;
use Throwable;

/**
 * Test için sahte adapter — ağa çıkmaz.
 *
 * Gerçek adapter'lar (WooCommerce, Trendyol) henüz yazılmadı; registry'nin
 * yaşam döngüsü davranışı onlardan bağımsız sınanabilir ve sınanmalıdır.
 *
 * Kurucu imzası gerçek adapter'larla AYNIDIR (connection + client): registry
 * bu imzaya göre örnek üretir, sahte sınıf sapması testi anlamsız kılardı.
 */
final class FakeAdapter implements ChannelAdapter, SupportsInventory
{
    use DeclaresRequestQuota;

    /** Kaç kez örneklendi — paylaşım testinin sayacı. */
    private static int $instantiations = 0;

    public function __construct(
        private readonly ChannelConnection $connection,
        public readonly mixed $client = null,
    ) {
        self::$instantiations++;
    }

    public static function instantiations(): int
    {
        return self::$instantiations;
    }

    public static function resetCounter(): void
    {
        self::$instantiations = 0;
    }

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
        return RateLimitProfile::fromArray(
            $this->connection->channelType->rate_limit_profile ?? []
        );
    }

    public function verifyWebhookSignature(string $raw, array $headers): bool
    {
        return true;
    }

    public function extractEventId(array $headers): ?string
    {
        return $headers['x-fake-event-id'][0] ?? null;
    }

    public function extractEventType(array $headers): string
    {
        return $headers['x-fake-event-type'][0] ?? 'unknown';
    }

    public function pushInventory(InventoryPushBatch $batch): AdapterResult
    {
        return AdapterResult::success(['pushed' => $batch->count()]);
    }

    public function fetchInventory(array $listings): RemoteInventorySnapshot
    {
        return new RemoteInventorySnapshot([]);
    }

    public function maxInventoryBatchSize(): int
    {
        return 50;
    }
}
