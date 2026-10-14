<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Domain\Channels\Models\ChannelConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * WooCommerce "uygulamayla bağlan" — resmî `/wc-auth/v1/authorize` akışı
 * (S5, ADR-012 · §12). Belge + WC_Auth kaynağı 14 Eki 2026'da okundu:
 *
 *   - Parametreler: app_name, scope (read|write|read_write), user_id
 *     (BİZİM kimliğimiz — Woo kullanıcı kimliği değil), return_url,
 *     callback_url (https ZORUNLU, Woo `https://` değilse reddeder).
 *   - Satıcı onaylayınca Woo anahtarı yaratır ve ÖNCE callback'e JSON POST
 *     eder: {key_id, user_id, consumer_key, consumer_secret, key_permissions}.
 *     Callback 200 dönmezse Woo anahtarı SİLER ve satıcıya hata gösterir.
 *     Ancak 200'den SONRA tarayıcı return_url'e `success=1&user_id=` ile döner.
 *   - Kalıcı bağlantılar (pretty permalinks) kapalı mağazada uç çalışmaz —
 *     o mağaza için elle anahtar girişi kalır.
 *
 * `user_id` TEK KULLANIMLIK, süreli bir nonce'tur; bağlantı ayarında yalnız
 * SHA-256 özeti tutulur (ham değer saklanmaz). Callback kimlik doğrulaması
 * budur: nonce'u bilmeyen anahtar enjekte edemez; bilse bile anahtar,
 * satıcının girdiği mağaza adresine sabit bağlantıya yazılır ve o mağazada
 * çalışmazsa sağlık kontrolü düşer.
 */
final class WooAuth
{
    public const SETTINGS_KEY = 'wc_auth';

    public const NONCE_TTL_MINUTES = 30;

    /** Talep edilen kapsam. Yazma izni yine ADR-010 bayraklarına bağlıdır. */
    public const SCOPE = 'read_write';

    public function begin(ChannelConnection $connection): string
    {
        $nonce = Str::random(48);

        $connection->forceFill([
            'settings' => [
                ...$connection->settings ?? [],
                self::SETTINGS_KEY => [
                    'nonce_hash' => hash('sha256', $nonce),
                    'expires_at' => Carbon::now()->addMinutes(self::NONCE_TTL_MINUTES)->toIso8601String(),
                ],
            ],
        ])->save();

        $root = preg_replace('#/wp-json/wc/v3/?$#', '', (string) ($connection->settings['base_url'] ?? '')) ?? '';

        return rtrim($root, '/').'/wc-auth/v1/authorize?'.http_build_query([
            'app_name' => config('app.name', '34Pazar'),
            'scope' => self::SCOPE,
            'user_id' => $nonce,
            'return_url' => route('channels.woocommerce.return'),
            'callback_url' => route('webhooks.woocommerce.auth'),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function nonceHash(string $nonce): string
    {
        return hash('sha256', $nonce);
    }

    /** Ayar nonce'u hâlâ geçerli mi (süre)? */
    public static function stillValid(ChannelConnection $connection): bool
    {
        $expires = $connection->settings[self::SETTINGS_KEY]['expires_at'] ?? null;

        return is_string($expires) && Carbon::parse($expires)->isFuture();
    }
}
