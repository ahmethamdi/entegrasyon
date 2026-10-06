<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Etsy;

use RuntimeException;

/**
 * 34Pazar'ın Etsy uygulaması — satıcı başına DEĞİL, tek uygulama.
 *
 * Önceden keystring bağlantı formunda SATICIYA soruluyordu: her satıcı
 * kendi Etsy geliştirici hesabını ve uygulamasını açmak, callback adresini
 * oraya kaydetmek zorundaydı — normal bir satıcı bunu yapamaz. Shopify
 * uygulamasıyla aynı model: kimlik sunucu ayarında (`services.etsy`),
 * satıcı yalnız Etsy'de izin verir.
 *
 * ⚠️ `x-api-key` = `keystring:shared_secret` (Etsy, 9 Şub 2026'dan beri
 * zorunlu). Yalnız keystring gönderen her istek REDDEDİLİR ve 401 kalıcı
 * kimlik hatası sayılırdı. Token isteğinde (`client_id`) ise YALNIZ
 * keystring gider — sır orada yoktur (resmî doküman).
 */
final class EtsyApp
{
    public static function configured(): bool
    {
        return self::value('keystring') !== null && self::value('shared_secret') !== null;
    }

    /** OAuth `client_id` — yalnız keystring. */
    public static function keystring(): string
    {
        return self::value('keystring')
            ?? throw new RuntimeException('Etsy uygulaması tanımsız: ETSY_KEYSTRING boş.');
    }

    /** `x-api-key` başlığının değeri. */
    public static function apiKey(): string
    {
        $secret = self::value('shared_secret')
            ?? throw new RuntimeException('Etsy uygulaması tanımsız: ETSY_SHARED_SECRET boş — istek reddedilirdi.');

        return self::keystring().':'.$secret;
    }

    private static function value(string $key): ?string
    {
        $value = config("services.etsy.{$key}");

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
