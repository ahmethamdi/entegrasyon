<?php

declare(strict_types=1);

namespace Tests\Support\Channels;

use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\RefreshedCredentials;
use App\Domain\Channels\Contracts\SupportsTokenRefresh;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Sync\Enums\ErrorClass;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Token yenilemesi programlanabilen sahte adapter — ağa çıkmaz.
 *
 * V3.0 · §03 · Delta 3 · P0-5 · T-V3-15.
 *
 * Etsy ve eBay adapter'ları henüz yazılmadı; `TokenRefresher`'ın kilitleme ve
 * yazma davranışı onlardan BAĞIMSIZ sınanabilir ve sınanmalıdır — kilit
 * kuralı çekirdeğe aittir, kanala değil.
 *
 * `ProgrammableInventoryAdapter` ile aynı kalıp: yanıt statik olarak
 * programlanır çünkü registry her çağrıda YENİ örnek üretir ve örneğe
 * dışarıdan erişilemez.
 */
final class ProgrammableTokenRefreshAdapter implements ChannelAdapter, SupportsTokenRefresh
{
    use DeclaresRequestQuota;

    /** Kaç kez GERÇEKTEN yenileme çağrısı yapıldı — P0-5'in sayacı. */
    private static int $refreshCalls = 0;

    private static bool $shouldFail = false;

    private static ?int $expiresInSeconds = 3600;

    private static int $leadSeconds = 900;

    /** @var array<string, mixed> */
    private static array $secrets = ['access_token' => 'YENI-TOKEN'];

    public function __construct(
        private readonly ChannelConnection $connection,
        public readonly mixed $client = null,
    ) {}

    public static function reset(): void
    {
        self::$refreshCalls = 0;
        self::$shouldFail = false;
        self::$expiresInSeconds = 3600;
        self::$leadSeconds = 900;
        self::$secrets = ['access_token' => 'YENI-TOKEN'];
    }

    public static function refreshCalls(): int
    {
        return self::$refreshCalls;
    }

    public static function failNextRefresh(): void
    {
        self::$shouldFail = true;
    }

    public static function useLeadSeconds(int $seconds): void
    {
        self::$leadSeconds = $seconds;
    }

    /** @param array<string, mixed> $secrets */
    public static function returnSecrets(array $secrets): void
    {
        self::$secrets = $secrets;
    }

    public function refreshCredentials(): RefreshedCredentials
    {
        self::$refreshCalls++;

        if (self::$shouldFail) {
            // Gerçek kanalın 401 gövdesi sırrı YANSITABİLİR; maskeleme
            // testinin gerçekçi olması için mesaja bir sır gömülür.
            throw new RuntimeException('401 Unauthorized {"refresh_token":"ESKI-REFRESH"}');
        }

        return new RefreshedCredentials(
            secrets: self::$secrets,
            expiresAt: self::$expiresInSeconds === null
                ? null
                : new DateTimeImmutable('+'.self::$expiresInSeconds.' seconds'),
        );
    }

    public function refreshLeadSeconds(): int
    {
        return self::$leadSeconds;
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
        return ErrorClass::AUTHENTICATION;
    }

    public function rateLimitProfile(): RateLimitProfile
    {
        return RateLimitProfile::fromArray(
            $this->connection->channelType->rate_limit_profile ?? []
        );
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
