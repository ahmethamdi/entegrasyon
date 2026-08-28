<?php

declare(strict_types=1);

namespace Tests\Support\Channels;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\SupportsOfferLifecycle;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\ListingPayload;
use RuntimeException;
use Throwable;

/**
 * Üç adımlı yayın zincirini ADIM ADIM programlanabilir sahte adapter.
 *
 * `ProgrammableCatalogAdapter`'ın çok adımlı karşılığı ve `PushOfferListing`
 * için var olma sebebi ŞUDUR: bu işin asıl iddiası "ara başarısızlıkta
 * kaldığı yerden devam eder" (§13.2) ve o iddia ancak ADIMLARDAN BİRİ
 * seçici olarak patlatılabildiğinde sınanabilir.
 *
 * Gerçek `EbayAdapter` bu iş için KULLANILAMAZ: gövdeleri slice 4.4'te
 * yazılacak ve `Http::fake()` ile üç ayrı çağrının sırasını programlamak,
 * sınanan şeyi (çekirdeğin zincir mantığı) kanalın HTTP şekliyle
 * karıştırırdı.
 *
 * Program STATİKTİR: `AdapterRegistry` her çağrıda YENİ örnek üretir
 * (değişmez kural), bu yüzden örnek durumu testler arasında yaşamaz.
 */
final class ProgrammableOfferAdapter implements ChannelAdapter, SupportsOfferLifecycle
{
    use DeclaresRequestQuota;

    /** Hangi adım patlayacak: `inventory_item` · `offer` · `publish`. */
    private static ?string $failStep = null;

    private static ?ErrorClass $failClass = null;

    /** @var list<string> Çağrılan adımlar, SIRASIYLA. */
    private static array $calls = [];

    private static string $offerId = 'OFFER-1';

    private static string $listingId = 'LISTING-1';

    public function __construct(
        private readonly ChannelConnection $connection,
        public readonly mixed $client = null,
    ) {}

    // ------------------------------------------------------------- programlama

    public static function succeed(string $offerId = 'OFFER-1', string $listingId = 'LISTING-1'): void
    {
        self::$failStep = null;
        self::$failClass = null;
        self::$offerId = $offerId;
        self::$listingId = $listingId;
    }

    /**
     * Belirli bir adımda patlat — zincirin kurtarma davranışının çekirdeği.
     *
     * @param  string  $step  `inventory_item` · `offer` · `publish`
     */
    public static function failAt(string $step, ErrorClass $class = ErrorClass::RATE_LIMITED): void
    {
        self::$failStep = $step;
        self::$failClass = $class;
    }

    public static function reset(): void
    {
        self::$failStep = null;
        self::$failClass = null;
        self::$calls = [];
        self::$offerId = 'OFFER-1';
        self::$listingId = 'LISTING-1';
    }

    /** @return list<string> */
    public static function calls(): array
    {
        return self::$calls;
    }

    // ------------------------------------------------- SupportsOfferLifecycle

    /**
     * ⚠️ UZAK KİMLİK DÖNDÜRMEZ — kimlik SKU'nun KENDİSİDİR (§13.1).
     *
     * Sahte de olsa bu doğru modellenmelidir: bir `external_id`
     * döndürseydi test, `persist()`'in "kimlik gelmeyen adım satıra
     * dokunmaz" kuralını hiç sürmezdi.
     */
    public function upsertInventoryItem(Listing $listing, ListingPayload $payload): AdapterResult
    {
        self::$calls[] = 'inventory_item';

        $this->throwIfProgrammed('inventory_item');

        return AdapterResult::success();
    }

    /** `offer_id` döner — zincirin KURTARMA ÇIPASI. */
    public function upsertOffer(Listing $listing, ListingPayload $payload): AdapterResult
    {
        self::$calls[] = 'offer';

        $this->throwIfProgrammed('offer');

        return AdapterResult::success([
            'channel_metadata' => ['offer_id' => self::$offerId],
        ]);
    }

    /** `listing_id` döner — satıcının kanalda GÖRDÜĞÜ ilan. */
    public function publishOffer(Listing $listing): AdapterResult
    {
        self::$calls[] = 'publish';

        $this->throwIfProgrammed('publish');

        return AdapterResult::success([
            'external_id' => self::$listingId,
            'external_url' => 'https://ebay.test/itm/'.self::$listingId,
        ]);
    }

    public function withdrawOffer(Listing $listing): AdapterResult
    {
        self::$calls[] = 'withdraw';

        return AdapterResult::success(['status' => 'withdrawn']);
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
        return self::$failClass ?? ErrorClass::SERVER_ERROR;
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

    // ------------------------------------------------------------- iç

    private function throwIfProgrammed(string $step): void
    {
        if (self::$failStep === $step) {
            throw new RuntimeException("programlı hata: {$step}");
        }
    }
}
