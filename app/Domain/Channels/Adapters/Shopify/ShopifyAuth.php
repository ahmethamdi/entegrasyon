<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Shopify;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * 34Pazar Shopify uygulamasının OAuth yardımcıları (authorization code grant).
 *
 * NEDEN UYGULAMA: 1 Ocak 2026'dan beri mağaza yönetiminde yeni "özel
 * uygulama" (legacy custom app) açılamıyor → `shpat_` anahtarı yapıştırma
 * akışıyla YENİ bir mağaza bağlanamıyordu. Uygulama 34Devs Partner
 * hesabında; Client ID/Secret SUNUCU ayarıdır (`services.shopify`),
 * satıcı başına değil — kodda bu türden ilk ayar.
 *
 * SÜRESİ DOLAN ANAHTAR ZORUNLU: 1 Nisan 2026'dan sonra açılan herkese açık
 * uygulamalar `expiring=1` ister — erişim anahtarı 1 saat, yenileme
 * anahtarı 90 gün yaşar (shopify.dev · authorization-code-grant). Yenileme
 * `ShopifyAdapter::refreshCredentials()` + `credentials:refresh` turuyla.
 */
final class ShopifyAuth
{
    /**
     * Uygulamanın istediği izinler. `write_*` kendi `read_*`'ını kapsar.
     * Adapter'ın GraphQL çağrılarından çıkarıldı: productSet/productUpdate/
     * productVariantsBulkUpdate/productCreateMedia (products), inventory*
     * (inventory), locations (stok konumu seçimi), orders (sipariş
     * webhook'ları + yoklama), fulfillmentOrders/fulfillmentCreate (kargo).
     */
    public const DEFAULT_SCOPES = 'write_products,write_inventory,read_locations,read_orders,'
        .'write_merchant_managed_fulfillment_orders';

    /**
     * Mağaza adresi — İKİ UCU DA SABİTLİ. `$` olmasaydı
     * `magaza.myshopify.com.saldirgan.example` geçerdi (Shopify belgesinin
     * açık uyarısı) ve token isteği saldırganın sunucusuna gider, istemci
     * sırrı ona teslim edilirdi.
     */
    private const SHOP_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/';

    public static function configured(): bool
    {
        return self::clientIdOrNull() !== null && self::clientSecretOrNull() !== null;
    }

    public static function clientId(): string
    {
        return self::clientIdOrNull()
            ?? throw new RuntimeException('Shopify uygulaması tanımsız: SHOPIFY_CLIENT_ID boş.');
    }

    public static function clientSecret(): string
    {
        return self::clientSecretOrNull()
            ?? throw new RuntimeException('Shopify uygulaması tanımsız: SHOPIFY_CLIENT_SECRET boş.');
    }

    public static function clientSecretOrNull(): ?string
    {
        $v = config('services.shopify.client_secret');

        return is_string($v) && $v !== '' ? $v : null;
    }

    public static function scopes(): string
    {
        $v = config('services.shopify.scopes');

        return is_string($v) && $v !== '' ? $v : self::DEFAULT_SCOPES;
    }

    public static function validShopDomain(?string $shop): bool
    {
        return is_string($shop) && preg_match(self::SHOP_PATTERN, $shop) === 1;
    }

    public static function newState(): string
    {
        return Str::random(40);
    }

    public static function authorizeUrl(string $shop, string $redirectUri, string $state): string
    {
        return 'https://'.$shop.'/admin/oauth/authorize?'.http_build_query([
            'client_id' => self::clientId(),
            'scope' => self::scopes(),
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    public static function stateMatches(?string $expected, ?string $given): bool
    {
        return is_string($expected) && $expected !== ''
            && is_string($given) && hash_equals($expected, $given);
    }

    /**
     * Geri dönüş adresinin Shopify'dan geldiğini kanıtlar.
     *
     * `hmac` (ve eski `signature`) çıkarılır, kalan parametreler ada göre
     * sıralanıp `k=v&…` olarak birleştirilir, istemci sırrıyla HMAC-SHA256
     * (HEX — webhook'taki base64 DEĞİL) hesaplanır.
     *
     * @param  array<string, mixed>  $query
     */
    public static function callbackHmacValid(array $query): bool
    {
        $given = $query['hmac'] ?? null;
        $secret = self::clientSecretOrNull();

        if (! is_string($given) || $given === '' || $secret === null) {
            return false;
        }

        unset($query['hmac'], $query['signature']);
        ksort($query);

        $message = implode('&', array_map(
            static fn (string $k, mixed $v): string => $k.'='.(is_array($v) ? implode(',', $v) : (string) $v),
            array_keys($query),
            $query,
        ));

        return hash_equals(hash_hmac('sha256', $message, $secret), $given);
    }

    /**
     * Uygulama adresine (App URL) Shopify'dan gelen istek — kurulumda ve
     * mağaza yöneticisinde uygulama her açıldığında. Üç koşul: geçerli
     * mağaza adresi, imza, TAZE zaman damgası (eski bir bağlantının
     * yeniden oynatılması; bir gün pay saat kayması için).
     *
     * @param  array<string, mixed>  $query
     */
    public static function launchRequestValid(array $query, ?int $now = null): bool
    {
        $shop = $query['shop'] ?? null;
        $timestamp = $query['timestamp'] ?? null;

        return self::validShopDomain(is_string($shop) ? $shop : null)
            && is_numeric($timestamp)
            && abs(($now ?? time()) - (int) $timestamp) <= self::LAUNCH_MAX_AGE
            && self::callbackHmacValid($query);
    }

    public const LAUNCH_MAX_AGE = 86400;

    /**
     * Webhook gövdesi imzası — uygulama webhook'ları (sipariş, app/uninstalled,
     * zorunlu gizlilik konuları) İSTEMCİ SIRRIYLA imzalanır (base64).
     */
    public static function webhookHmacValid(string $raw, ?string $given): bool
    {
        $secret = self::clientSecretOrNull();

        if ($secret === null || ! is_string($given) || $given === '') {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $raw, $secret, true)), $given);
    }

    public static function tokenUrl(string $shop): string
    {
        return 'https://'.$shop.'/admin/oauth/access_token';
    }

    /** @return array<string, string> */
    public static function tokenRequest(string $code): array
    {
        return [
            'client_id' => self::clientId(),
            'client_secret' => self::clientSecret(),
            'code' => $code,
            // Yeni herkese açık uygulamada ZORUNLU; verilmezse süresiz anahtar
            // döner ve 1 Ocak 2027'den sonra 401 ile ölür.
            'expiring' => '1',
        ];
    }

    /** @return array<string, string> */
    public static function refreshRequest(string $refreshToken): array
    {
        return [
            'client_id' => self::clientId(),
            'client_secret' => self::clientSecret(),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ];
    }

    /**
     * Token yanıtını kasaya yazılacak biçime çevirir.
     *
     * @param  array<string, mixed>  $body
     * @return array{secrets: array<string, string>, expires_at: ?\DateTimeImmutable, refresh_expires_at: ?\DateTimeImmutable, scope: ?string}
     */
    public static function credentialsFrom(array $body, ?string $previousRefreshToken = null): array
    {
        $access = $body['access_token'] ?? null;

        if (! is_string($access) || $access === '') {
            throw new RuntimeException('Shopify yanıtı erişim anahtarı taşımıyor.');
        }

        $refresh = $body['refresh_token'] ?? $previousRefreshToken;
        $seconds = $body['expires_in'] ?? null;
        $refreshSeconds = $body['refresh_token_expires_in'] ?? null;

        return [
            'secrets' => array_filter([
                'access_token' => $access,
                'refresh_token' => is_string($refresh) && $refresh !== '' ? $refresh : null,
            ], static fn (?string $v): bool => $v !== null),
            'expires_at' => is_numeric($seconds)
                ? new \DateTimeImmutable('@'.(time() + (int) $seconds))
                : null,
            'refresh_expires_at' => is_numeric($refreshSeconds)
                ? new \DateTimeImmutable('@'.(time() + (int) $refreshSeconds))
                : null,
            'scope' => is_string($body['scope'] ?? null) ? $body['scope'] : null,
        ];
    }

    private static function clientIdOrNull(): ?string
    {
        $v = config('services.shopify.client_id');

        return is_string($v) && $v !== '' ? $v : null;
    }
}
