<?php

declare(strict_types=1);

namespace Tests\Support\Channels;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\RemoteInventorySnapshot;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Kanal başına yanıt programlanabilen sahte stok adapter'ı.
 *
 * T4 (§18) üç kanaldan birinin 429 alıp diğer ikisinin tamamlanmasını sınar;
 * bunun için adapter'ın kanal TÜRÜNE göre farklı davranması gerekir. Örnek
 * her çağrıda yeniden yaratıldığı (AdapterRegistry değişmez kuralı) ve
 * bağlantı worker içinde elde olmadığı için program STATİK tutulur —
 * örnek durumu değil, test kurulumu taşır.
 *
 * Gerçek adapter'larla imza aynıdır: (connection, client).
 */
final class ProgrammableInventoryAdapter implements ChannelAdapter, SupportsInventory
{
    /**
     * Kanal tipi kodu → yanıt planı.
     *
     * @var array<string, array{throw: ?Throwable, class: ?ErrorClass}>
     */
    private static array $plan = [];

    /**
     * Kanal tipi kodu → gönderilen batch'lerin kalem listesi.
     *
     * @var array<string, list<list<array<string, mixed>>>>
     */
    private static array $pushes = [];

    /** @var array<string, int> */
    private static array $batchSize = [];

    /**
     * Kanal kodu → external_id → kanalda GÖZLENEN miktar.
     *
     * Mutabakat testleri uzak durumu buradan okur.
     *
     * @var array<string, array<string, int>>
     */
    private static array $remote = [];

    /** @var array<string, bool> */
    private static array $fetchFails = [];

    public function __construct(
        private readonly ChannelConnection $connection,
        public readonly mixed $client = null,
    ) {}

    // ------------------------------------------------------------- programlama

    /** Kanal başarıyla yanıt versin. */
    public static function succeedOn(string $channelTypeCode): void
    {
        self::$plan[$channelTypeCode] = ['throw' => null, 'class' => null];
    }

    /**
     * Kanal hata fırlatsın.
     *
     * classifyError() bu sınıfı döndürür — gerçek adapter'da gövde ayrıştırma
     * yapılır, testte doğrudan programlanır.
     */
    public static function failOn(string $channelTypeCode, ErrorClass $class, string $message = 'programlı hata'): void
    {
        self::$plan[$channelTypeCode] = [
            'throw' => new RuntimeException($message),
            'class' => $class,
        ];
    }

    /** Kanalın tek çağrıda kaç kalem aldığını değiştirir — gruplama testi için. */
    public static function batchSizeFor(string $channelTypeCode, int $size): void
    {
        self::$batchSize[$channelTypeCode] = $size;
    }

    /** Kanalda gözlenen miktarı programlar — §10 mutabakat testleri. */
    public static function remoteQuantity(string $channelTypeCode, string $externalId, int $quantity): void
    {
        self::$remote[$channelTypeCode][$externalId] = $quantity;
    }

    /** Uzak okuma patlasın — REMOTE_UNREACHABLE yolu. */
    public static function failFetchOn(string $channelTypeCode): void
    {
        self::$fetchFails[$channelTypeCode] = true;
    }

    public static function reset(): void
    {
        self::$plan = [];
        self::$pushes = [];
        self::$batchSize = [];
        self::$remote = [];
        self::$fetchFails = [];
    }

    /**
     * Kanala giden çağrılar — her eleman bir API çağrısının kalemleri.
     *
     * @return list<list<array<string, mixed>>>
     */
    public static function pushesFor(string $channelTypeCode): array
    {
        return self::$pushes[$channelTypeCode] ?? [];
    }

    // ------------------------------------------------------------- sözleşme

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
        return self::$plan[$this->code()]['class'] ?? ErrorClass::SERVER_ERROR;
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
        $code = $this->code();

        // Hata da olsa çağrı KAYDEDİLİR: "429 alan kanal yükü hazırlamıştı"
        // bilgisi, gruplamanın hatadan önce çalıştığını gösterir.
        self::$pushes[$code][] = $batch->toArray();

        $throw = self::$plan[$code]['throw'] ?? null;

        if ($throw !== null) {
            throw $throw;
        }

        return AdapterResult::success(['pushed' => $batch->count()]);
    }

    public function fetchInventory(array $listings): RemoteInventorySnapshot
    {
        $code = $this->code();

        if (self::$fetchFails[$code] ?? false) {
            throw new RuntimeException('kanal okunamadı');
        }

        $programmed = self::$remote[$code] ?? [];

        // YALNIZCA istenen kimlikler döner — kanalda olmayan kimlik yanıtta
        // hiç görünmez ve mutabakat onu REMOTE_MISSING sayar.
        $quantities = [];

        foreach ($listings as $listing) {
            $externalId = $listing->external_id;

            if ($externalId !== null && array_key_exists($externalId, $programmed)) {
                $quantities[$externalId] = $programmed[$externalId];
            }
        }

        return new RemoteInventorySnapshot($quantities, new DateTimeImmutable);
    }

    public function maxInventoryBatchSize(): int
    {
        return self::$batchSize[$this->code()] ?? 50;
    }

    private function code(): string
    {
        return $this->connection->channel_type_code;
    }
}
