<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Otto;

use RuntimeException;

/**
 * TEK 34Pazar OTTO Market uygulaması — Service Partner kimliği (`services.otto`).
 *
 * Kayıt: servicepartner.otto.market → OTTO Partner Connect (portal.otto.market)
 * → App Management. Kurulum akışı ve anahtar üretimi resmî belgeden
 * (`api.otto.market/docs/sp-integration`, 21 Eki 2026).
 */
final class OttoApp
{
    public const PRODUCTION_HOST = 'https://api.otto.market';

    public const SANDBOX_HOST = 'https://sandbox.api.otto.market';

    public static function configured(): bool
    {
        return self::value('client_id') !== null && self::value('client_secret') !== null && self::value('app_id') !== null;
    }

    public static function host(): string
    {
        return config('services.otto.sandbox') === true ? self::SANDBOX_HOST : self::PRODUCTION_HOST;
    }

    public static function clientId(): string
    {
        return self::value('client_id') ?? throw new RuntimeException('OTTO uygulaması tanımsız: OTTO_CLIENT_ID boş.');
    }

    public static function clientSecret(): string
    {
        return self::value('client_secret') ?? throw new RuntimeException('OTTO uygulaması tanımsız: OTTO_CLIENT_SECRET boş.');
    }

    public static function appId(): string
    {
        return self::value('app_id') ?? throw new RuntimeException('OTTO uygulaması tanımsız: OTTO_APP_ID boş.');
    }

    private static function value(string $key): ?string
    {
        $value = config("services.otto.{$key}");

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
