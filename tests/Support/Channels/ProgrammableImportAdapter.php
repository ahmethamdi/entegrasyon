<?php

declare(strict_types=1);

namespace Tests\Support\Channels;

use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Support\RemoteProduct;
use App\Domain\Sync\Support\RemoteProductPage;
use RuntimeException;
use Throwable;

/**
 * Sayfa sayfa ürün döndüren sahte içe aktarma adapter'ı.
 *
 * `ProgrammableCatalogAdapter`'ın içe aktarma karşılığı. AYRI SINIFTIR:
 * o adapter'a `SupportsCatalogImport` eklenseydi onu kullanan mevcut
 * katalog testleri sınadıkları yeteneği değiştirmiş olurdu.
 *
 * Program STATİKTİR — `AdapterRegistry` her çağrıda YENİ örnek üretir
 * (değişmez kural), bu yüzden örnek durumu test kurulumunu taşıyamaz.
 */
final class ProgrammableImportAdapter implements ChannelAdapter, SupportsCatalogImport
{
    use DeclaresRequestQuota;

    /** Kanal kodu → imleç → o sayfada dönecek ürünler. */
    private static array $pages = [];

    /** Kanal kodu → sayfa çekilirken fırlatılacak hata. */
    private static array $failures = [];

    /** Kanal kodu → çekilen sayfa imleçleri (çağrı izi). */
    private static array $cursors = [];

    /** Kanal kodu → tur başına sayfa üst sınırı. */
    private static array $maxPages = [];

    public function __construct(
        private readonly ChannelConnection $connection,
        public readonly mixed $client = null,
    ) {}

    // ------------------------------------------------------------- programlama

    /**
     * Tek sayfalık katalog döndür.
     *
     * @param  list<RemoteProduct>  $products
     */
    public static function returns(string $channelTypeCode, array $products): void
    {
        self::$pages[$channelTypeCode] = [
            '1' => ['products' => $products, 'next' => null, 'more' => false],
        ];
    }

    /**
     * Çok sayfalı katalog — imleçler '1', '2', ... olarak zincirlenir.
     *
     * @param  list<list<RemoteProduct>>  $pages
     */
    public static function returnsPages(string $channelTypeCode, array $pages): void
    {
        $plan = [];
        $total = count($pages);

        foreach ($pages as $index => $products) {
            $number = $index + 1;
            $isLast = $number === $total;

            $plan[(string) $number] = [
                'products' => $products,
                'next' => $isLast ? null : (string) ($number + 1),
                'more' => ! $isLast,
            ];
        }

        self::$pages[$channelTypeCode] = $plan;
    }

    /**
     * SON SAYFADA BİLE `hasMore = true` dönen kanal — üst sınır testi.
     *
     * @param  list<RemoteProduct>  $products
     */
    public static function returnsEndlessly(string $channelTypeCode, array $products): void
    {
        self::$pages[$channelTypeCode] = ['*' => [
            'products' => $products,
            'next' => 'sonraki',
            'more' => true,
        ]];
    }

    public static function failsOnPage(string $channelTypeCode, string $cursor, string $message = 'kanal cevap vermedi'): void
    {
        self::$failures[$channelTypeCode][$cursor] = new RuntimeException($message);
    }

    public static function maxPages(string $channelTypeCode, int $max): void
    {
        self::$maxPages[$channelTypeCode] = $max;
    }

    /** @return list<?string> */
    public static function cursorsFor(string $channelTypeCode): array
    {
        return self::$cursors[$channelTypeCode] ?? [];
    }

    public static function reset(): void
    {
        self::$pages = [];
        self::$failures = [];
        self::$cursors = [];
        self::$maxPages = [];
    }

    // ------------------------------------------------------------- sözleşme

    public function fetchProductPage(?string $cursor = null): RemoteProductPage
    {
        $code = $this->code();
        $key = $cursor ?? '1';

        self::$cursors[$code][] = $cursor;

        if (isset(self::$failures[$code][$key])) {
            throw self::$failures[$code][$key];
        }

        $plan = self::$pages[$code]['*'] ?? self::$pages[$code][$key] ?? null;

        if ($plan === null) {
            return new RemoteProductPage(products: []);
        }

        return new RemoteProductPage(
            products: $plan['products'],
            nextCursor: $plan['next'],
            hasMore: $plan['more'],
        );
    }

    public function maxImportPages(): int
    {
        return self::$maxPages[$this->code()] ?? 50;
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
        return null;
    }

    public function extractEventType(array $headers): string
    {
        return 'unknown';
    }

    private function code(): string
    {
        return $this->connection->channel_type_code;
    }
}
