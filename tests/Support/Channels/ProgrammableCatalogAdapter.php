<?php

declare(strict_types=1);

namespace Tests\Support\Channels;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\ListingPayload;
use App\Domain\Sync\Support\RemoteListing;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Kanal başına yanıt programlanabilen sahte katalog adapter'ı.
 *
 * ProgrammableInventoryAdapter'ın katalog karşılığı: PushListing'i sınamak
 * için createListing / updateListing / findExistingListing yanıtları test
 * kurulumundan programlanır.
 *
 * Program STATİKTİR: AdapterRegistry her çağrıda YENİ örnek üretir
 * (değişmez kural — paylaşılan örnek kiracı A'nın kimlik bilgisini kiracı
 * B'nin işinde kullanırdı), bu yüzden örnek durumu testler arasında yaşamaz.
 */
final class ProgrammableCatalogAdapter implements ChannelAdapter, SupportsCatalog
{
    use DeclaresRequestQuota;

    /** @var array<string, array{throw: ?Throwable, class: ?ErrorClass}> */
    private static array $plan = [];

    /** @var array<string, list<array{op: string, title: string, sku: ?string, version: int, externalId: ?string}>> */
    private static array $calls = [];

    /** Kanalda ZATEN var olan ürünler: kanal kodu → sku → external id. */
    private static array $existing = [];

    /** Kanal kodu → yaratılan listing'e verilecek external id. */
    private static array $nextExternalId = [];

    /**
     * Kanal kodu → create/update yanıtına eklenecek EK kimlikler.
     *
     * V3.0 · §07: bazı kanallar TEK kimlik döndürmez. Shopify variant +
     * product + inventory item, eBay listing + offer taşır ve ikisi de
     * KALICIDIR. Bu program `PushListing`'in o kimlikleri gerçekten
     * yazdığını sınamak için var.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $extraIdentity = [];

    public function __construct(
        private readonly ChannelConnection $connection,
        public readonly mixed $client = null,
    ) {}

    // ------------------------------------------------------------- programlama

    public static function succeedOn(string $channelTypeCode, string $externalId = '900'): void
    {
        self::$plan[$channelTypeCode] = ['throw' => null, 'class' => null];
        self::$nextExternalId[$channelTypeCode] = $externalId;
    }

    public static function failOn(
        string $channelTypeCode,
        ErrorClass $class,
        string $message = 'programlı hata',
    ): void {
        self::$plan[$channelTypeCode] = [
            'throw' => new RuntimeException($message),
            'class' => $class,
        ];
    }

    /** Kanalda bu SKU zaten varmış gibi davran — kopya listeleme testi. */
    public static function alreadyHas(string $channelTypeCode, string $sku, string $externalId): void
    {
        self::$existing[$channelTypeCode][$sku] = $externalId;
    }

    /**
     * Create/update yanıtına EK kimlikler ekler (üst ürün, kanala özgü).
     *
     * @param  array<string, mixed>  $data
     */
    public static function alsoReturns(string $channelTypeCode, array $data): void
    {
        self::$extraIdentity[$channelTypeCode] = $data;
    }

    public static function reset(): void
    {
        self::$plan = [];
        self::$calls = [];
        self::$existing = [];
        self::$nextExternalId = [];
        self::$extraIdentity = [];
    }

    /** @return list<array{op: string, title: string, sku: ?string, version: int, externalId: ?string}> */
    public static function callsFor(string $channelTypeCode): array
    {
        return self::$calls[$channelTypeCode] ?? [];
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
        return null;
    }

    public function extractEventType(array $headers): string
    {
        return 'unknown';
    }

    public function createListing(ListingPayload $payload): AdapterResult
    {
        $this->record('create', $payload);

        $this->throwIfProgrammed();

        $externalId = self::$nextExternalId[$this->code()] ?? '900';

        return AdapterResult::success([
            'external_id' => $externalId,
            'external_url' => 'https://example.test/p/'.$externalId,
            ...(self::$extraIdentity[$this->code()] ?? []),
        ]);
    }

    public function updateListing(ListingPayload $payload): AdapterResult
    {
        $this->record('update', $payload);

        $this->throwIfProgrammed();

        return AdapterResult::success([
            'external_id' => $payload->listing->external_id,
            ...(self::$extraIdentity[$this->code()] ?? []),
        ]);
    }

    public function delist(Listing $listing): AdapterResult
    {
        return AdapterResult::success(['status' => 'draft']);
    }

    public function findExistingListing(Variant $variant): ?RemoteListing
    {
        $externalId = self::$existing[$this->code()][$variant->sku] ?? null;

        if ($externalId === null) {
            return null;
        }

        return new RemoteListing(
            externalId: $externalId,
            title: 'kanalda var olan ürün',
            quantity: null,
            price: null,
            status: 'publish',
            url: null,
            raw: [],
            observedAt: new DateTimeImmutable,
        );
    }

    public function fetchListing(Listing $listing): ?RemoteListing
    {
        return null;
    }

    // ------------------------------------------------------------- iç

    private function record(string $operation, ListingPayload $payload): void
    {
        self::$calls[$this->code()][] = [
            'op' => $operation,
            'title' => $payload->title,
            'sku' => $payload->listing->variant?->sku,
            'version' => $payload->version,
            'externalId' => $payload->listing->external_id,
        ];
    }

    private function throwIfProgrammed(): void
    {
        $throw = self::$plan[$this->code()]['throw'] ?? null;

        if ($throw !== null) {
            throw $throw;
        }
    }

    private function code(): string
    {
        return $this->connection->channel_type_code;
    }
}
