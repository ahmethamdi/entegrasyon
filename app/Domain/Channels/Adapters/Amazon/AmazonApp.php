<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Amazon;

use RuntimeException;

/**
 * TEK 34Pazar Amazon SP-API uygulaması (`services.amazon`) ve pazar tablosu.
 *
 * Uygulama Solution Provider Portal'da kayıtlı olmalı (başvuru metinleri:
 * `docs/AMAZON-BASVURU.md`). Satıcı Seller Central'da yetki verir; bize yalnız
 * LWA yenileme anahtarı gelir.
 */
final class AmazonApp
{
    public const TOKEN_URL = 'https://api.amazon.com/auth/o2/token';

    /**
     * Pazarlar — kimlikler resmî "Marketplace IDs" tablosundan; Avrupa
     * bölgesinin uç noktası `sellingpartnerapi-eu.amazon.com`.
     *
     * @var array<string, array{marketplace_id: string, currency: string, seller_central: string}>
     */
    public const MARKETPLACES = [
        'de' => ['marketplace_id' => 'A1PA6795UKMFR9', 'currency' => 'EUR', 'seller_central' => 'https://sellercentral.amazon.de'],
        'tr' => ['marketplace_id' => 'A33AVAJ2PDY3EV', 'currency' => 'TRY', 'seller_central' => 'https://sellercentral.amazon.com.tr'],
    ];

    public const EU_ENDPOINT = 'https://sellingpartnerapi-eu.amazon.com';

    public static function configured(): bool
    {
        return self::value('client_id') !== null && self::value('client_secret') !== null && self::value('application_id') !== null;
    }

    public static function clientId(): string
    {
        return self::value('client_id') ?? throw new RuntimeException('Amazon uygulaması tanımsız: AMAZON_LWA_CLIENT_ID boş.');
    }

    public static function clientSecret(): string
    {
        return self::value('client_secret') ?? throw new RuntimeException('Amazon uygulaması tanımsız: AMAZON_LWA_CLIENT_SECRET boş.');
    }

    public static function applicationId(): string
    {
        return self::value('application_id') ?? throw new RuntimeException('Amazon uygulaması tanımsız: AMAZON_APPLICATION_ID boş.');
    }

    /** Taslak uygulama yetkisi `version=beta` ister (resmî belge). */
    public static function isDraft(): bool
    {
        return config('services.amazon.draft') === true;
    }

    private static function value(string $key): ?string
    {
        $value = config("services.amazon.{$key}");

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
