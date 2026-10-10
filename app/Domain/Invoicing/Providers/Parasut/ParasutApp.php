<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Providers\Parasut;

use RuntimeException;

/**
 * 34Pazar'ın Paraşüt uygulaması — satıcı başına DEĞİL, tek uygulama.
 *
 * Etsy ile aynı model (`EtsyApp`): istemci kimliği sunucu ayarında
 * (`services.parasut`), satıcı yalnız Paraşüt'te "izin ver" der. Satıcının
 * Paraşüt ŞİFRESİ bize hiç gelmez — password grant bilinçli olarak
 * kullanılmaz: şifreyi saklamak hem KVKK yükü hem de iki adımlı doğrulama
 * açık hesapta zaten çalışmaz.
 *
 * Uygulama Paraşüt destekten istenir (client_id + client_secret + kayıtlı
 * redirect_uri). Test ortamı da aynı yoldan: `PARASUT_BASE_URL`.
 */
final class ParasutApp
{
    public const DEFAULT_BASE_URL = 'https://api.parasut.com';

    public static function configured(): bool
    {
        return self::value('client_id') !== null && self::value('client_secret') !== null;
    }

    public static function clientId(): string
    {
        return self::value('client_id')
            ?? throw new RuntimeException('Paraşüt uygulaması tanımsız: PARASUT_CLIENT_ID boş.');
    }

    public static function clientSecret(): string
    {
        return self::value('client_secret')
            ?? throw new RuntimeException('Paraşüt uygulaması tanımsız: PARASUT_CLIENT_SECRET boş.');
    }

    /**
     * Taban adres SATICIDAN GELMEZ: gelseydi token'lar satıcının yazdığı
     * herhangi bir adrese gönderilirdi (Trendyol `baseUrl()` kuralı).
     */
    public static function baseUrl(): string
    {
        return rtrim(self::value('base_url') ?? self::DEFAULT_BASE_URL, '/');
    }

    private static function value(string $key): ?string
    {
        $value = config("services.parasut.{$key}");

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
