<?php

declare(strict_types=1);

namespace Tests\Support\Channels;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\PricePushBatch;
use App\Domain\Sync\Support\RemoteInventorySnapshot;
use App\Domain\Sync\Support\RemotePriceSnapshot;
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
final class ProgrammableInventoryAdapter implements ChannelAdapter, SupportsInventory, SupportsPricing
{
    use DeclaresRequestQuota;

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
     * Kanal kodu → istisna FIRLATMADAN dönülecek başarısızlık.
     *
     * Gerçek adapter'lar başarısızlığı iki biçimde bildirir; bu, ikincisi
     * (`AdapterResult::failure()`). `classifyError()` bu programdan
     * ETKİLENMEZ — çekirdeğin sınıfı sonuçtan okuduğu böyle kanıtlanır.
     *
     * @var array<string, array{class: ErrorClass, message: string, retryAfter: ?int}>
     */
    private static array $resultFailure = [];

    /**
     * Kanal kodu → kısmi başarıda BAŞARISIZ sayılacak kalem sırası
     * (0'dan). Operasyon kimliği yükten okunur.
     *
     * @var array<string, list<int>>
     */
    private static array $partialFailures = [];

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

    /**
     * Kanal kodu → external_id → kanalda GÖZLENEN fiyat (metin).
     *
     * Fiyat STRING tutulur, float değil: kanonik kolon `decimal(12,2)` ve
     * PHP'ye string döner; testin float taşıması, üretimde olmayan bir
     * ölçeği sınamak olurdu (§7 · para float taşınmaz).
     *
     * @var array<string, array<string, string>>
     */
    private static array $remotePrices = [];

    /** @var array<string, list<list<array<string, mixed>>>> */
    private static array $pricePushes = [];

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

    /** Kanal istisna fırlatmadan `AdapterResult::failure()` dönsün. */
    public static function returnFailureOn(
        string $channelTypeCode,
        ErrorClass $class,
        string $message = 'programlı başarısız sonuç',
        ?int $retryAfter = null,
    ): void {
        self::$resultFailure[$channelTypeCode] = [
            'class' => $class,
            'message' => $message,
            'retryAfter' => $retryAfter,
        ];
    }

    /**
     * Kanal `AdapterResult::partial()` dönsün — verilen sıradaki kalemler
     * başarısız, gerisi geçti.
     *
     * @param  list<int>  $positions
     */
    public static function partiallyFailOn(string $channelTypeCode, array $positions): void
    {
        self::$partialFailures[$channelTypeCode] = $positions;
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

    /** Kanalda gözlenen FİYATI programlar — §9 çakışma testleri. */
    public static function remotePrice(string $channelTypeCode, string $externalId, string $price): void
    {
        self::$remotePrices[$channelTypeCode][$externalId] = $price;
    }

    /** Uzak okuma patlasın — REMOTE_UNREACHABLE yolu. */
    public static function failFetchOn(string $channelTypeCode): void
    {
        self::$fetchFails[$channelTypeCode] = true;
    }

    /**
     * Kanala giden FİYAT çağrıları.
     *
     * `PriceBatchBuilder`'ın override'lı listing'i elemesi ancak buraya
     * bakarak sınanabilir: "gönderilmedi" iddiası, çağrının hiç yapılmamış
     * olmasıyla kanıtlanır.
     *
     * @return list<list<array<string, mixed>>>
     */
    public static function pricePushesFor(string $channelTypeCode): array
    {
        return self::$pricePushes[$channelTypeCode] ?? [];
    }

    public static function reset(): void
    {
        self::$plan = [];
        self::$pushes = [];
        self::$batchSize = [];
        self::$resultFailure = [];
        self::$partialFailures = [];
        self::$remote = [];
        self::$fetchFails = [];
        self::$remotePrices = [];
        self::$pricePushes = [];
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

        return $this->programmedResult($batch->operations(), $batch->count());
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

    // ------------------------------------------------------------- fiyat

    public function pushPrices(PricePushBatch $batch): AdapterResult
    {
        $code = $this->code();

        // `PricePushBatch` `toArray()` TAŞIMAZ; kalemler açık alanda durur.
        // Önceden olmayan metot çağrılıyordu ve bu yol hiç koşmamıştı.
        self::$pricePushes[$code][] = $batch->items;

        $throw = self::$plan[$code]['throw'] ?? null;

        if ($throw !== null) {
            throw $throw;
        }

        return $this->programmedResult($batch->operations(), $batch->count());
    }

    public function fetchPrices(array $listings): RemotePriceSnapshot
    {
        $code = $this->code();

        // Aynı bayrak stok okumasıyla PAYLAŞILIR: "kanal okunamıyor" bir
        // BAĞLANTI durumudur, domaine göre değişmez.
        if (self::$fetchFails[$code] ?? false) {
            throw new RuntimeException('kanal okunamadı');
        }

        $programmed = self::$remotePrices[$code] ?? [];

        // YALNIZCA istenen kimlikler döner — stok okumasıyla aynı kural:
        // kanalda olmayan kimlik yanıtta hiç görünmez ve mutabakat onu
        // REMOTE_MISSING sayar.
        $prices = [];

        foreach ($listings as $listing) {
            $externalId = $listing->external_id;

            if ($externalId !== null && array_key_exists($externalId, $programmed)) {
                $prices[$externalId] = $programmed[$externalId];
            }
        }

        return new RemotePriceSnapshot($prices, new DateTimeImmutable);
    }

    public function maxPriceBatchSize(): int
    {
        return self::$batchSize[$this->code()] ?? 50;
    }

    /**
     * Programlanan sonuç: başarısız sonuç → kısmi → başarı.
     *
     * @param  list<SyncOperation>  $operations
     */
    private function programmedResult(array $operations, int $count): AdapterResult
    {
        $code = $this->code();

        if (isset(self::$resultFailure[$code])) {
            $failure = self::$resultFailure[$code];

            return AdapterResult::failure($failure['class'], $failure['message'], $failure['retryAfter']);
        }

        if (isset(self::$partialFailures[$code])) {
            $failed = [];

            foreach (self::$partialFailures[$code] as $position) {
                if (isset($operations[$position])) {
                    $failed[$operations[$position]->id] = 'programlı kalem hatası';
                }
            }

            return AdapterResult::partial($failed, ['pushed' => $count]);
        }

        return AdapterResult::success(['pushed' => $count]);
    }

    private function code(): string
    {
        return $this->connection->channel_type_code;
    }
}
