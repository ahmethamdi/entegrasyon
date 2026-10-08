<?php

declare(strict_types=1);

namespace Tests\Support\Channels;

use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Sync\Enums\ErrorClass;
use Throwable;

/**
 * Sağlık sonucu testten SIRAYLA verilen sahte adapter — ağa çıkmaz.
 *
 * `results()` ile kuyruk kurulur; her `healthCheck()` çağrısı sıradakini
 * döner, kuyruk bitince son sonuç tekrarlanır. Çağrı sayısı ölçülür:
 * taramanın "doğrulama için ikinci ölçüm" kuralı ancak böyle sınanır.
 */
final class ProgrammableHealthAdapter implements ChannelAdapter
{
    use DeclaresRequestQuota;

    /** @var list<HealthResult> */
    private static array $queue = [];

    private static int $calls = 0;

    public function __construct(
        private readonly ChannelConnection $connection,
        public readonly mixed $client = null,
    ) {}

    public static function results(HealthResult ...$results): void
    {
        self::$queue = array_values($results);
        self::$calls = 0;
    }

    public static function calls(): int
    {
        return self::$calls;
    }

    public static function reset(): void
    {
        self::$queue = [];
        self::$calls = 0;
    }

    public function connection(): ChannelConnection
    {
        return $this->connection;
    }

    public function healthCheck(): HealthResult
    {
        self::$calls++;

        if (self::$queue === []) {
            return HealthResult::healthy(latencyMs: 1);
        }

        return count(self::$queue) > 1 ? array_shift(self::$queue) : self::$queue[0];
    }

    public function classifyError(Throwable $e): ErrorClass
    {
        return ErrorClass::SERVER_ERROR;
    }

    public function rateLimitProfile(): RateLimitProfile
    {
        return RateLimitProfile::fromArray([]);
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
        return 'unknown';
    }
}
