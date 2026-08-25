<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Shopify;

use InvalidArgumentException;

/**
 * Shopify uç noktaları — TEK KAYNAK.
 *
 * V3.0 · §06.2 · §05 (kanal ekleme kontrol listesi · adım 2).
 *
 * ─────────────────────────────────────────────────────────────────────
 * HEPSİBURADA'DAN FARKI: BU YOLLAR DOĞRULANABİLİR
 * ─────────────────────────────────────────────────────────────────────
 * `developers.hepsiburada.com` bot isteklerini 403 ile reddediyor ve o
 * kanalın yolları ikincil kaynaklardan derlendi (kanal hâlâ KAPALI).
 * Shopify'ın Admin API dokümantasyonu AÇIKTIR; V3.0 §27 Shopify'ı bu
 * yüzden Hepsiburada'nın ÖNÜNE aldı — doğrulama bloke değil.
 *
 * Yollar yine de TEK YERDE toplanır: adapter'a serpiştirilselerdi bir
 * API sürümü yükseltmesi on ayrı yere dokunmak olurdu ve biri unutulunca
 * o çağrı SESSİZCE eski sürüme giderdi.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ API SÜRÜMÜ SABİTTİR VE SÜRÜMSÜZ İSTEK ASLA ATILMAZ
 * ─────────────────────────────────────────────────────────────────────
 * Shopify çeyrek dönemde bir yeni sürüm yayınlar ve eskiler ~1 yıl
 * yaşar. `unstable` veya sürümsüz çağrı ASLA atılmaz: Shopify sürümsüz
 * isteği DESTEKLENEN EN ESKİ sürüme düşürür ve alanlar habersizce
 * kaybolur — yanıt 200 döner, alan yoktur, senkron "başarılı" görünür.
 * Bu, projenin en pahalı hata biçimidir (Woo'nun `manage_stock` tuzağı).
 *
 * SÜRÜM YÜKSELTME SIRASI:
 *   1. `API_VERSION` güncellenir
 *   2. `ShopifyEndpointContractTest` beklenen metinleri güncellenir
 *   3. Gerçek mağazada sağlık kontrolü çalıştırılır
 *   4. Değişiklik günlüğü (`shopify.dev/changelog`) kırıcı alanlar için
 *      okunur — özellikle `inventorySetOnHandQuantities` ve sipariş
 *      webhook gövdeleri
 */
final class ShopifyEndpoints
{
    /**
     * Admin API sürümü — ÇEYREK DÖNEMDE BİR GÜNCELLENİR.
     *
     * Sürümsüz veya `unstable` istek ASLA atılmaz (sınıf başlığı).
     */
    public const API_VERSION = '2026-01';

    /** GraphQL Admin API — TEK uç nokta, her sorgu buradan geçer. */
    public const GRAPHQL = 'admin/api/{version}/graphql.json';

    /**
     * Webhook aboneliklerinin REST uç noktası.
     *
     * GraphQL'de karşılığı var (`webhookSubscriptionCreate`) ama abonelik
     * yönetimi kurulum işidir ve V3.0 kapsamında satıcı webhook'ları
     * Shopify admin panelinden veya custom app tanımından kurar. Sabit
     * BURADA durur ki ileride otomatik abonelik yazılırsa yol aranmasın.
     */
    public const WEBHOOKS = 'admin/api/{version}/webhooks.json';

    /**
     * Mağazanın tam GraphQL adresi.
     *
     * `$shopDomain` NORMALLEŞTİRİLMİŞ gelmelidir (`StoreUrl`): küçük harf,
     * şemasız, sondaki eğik çizgisiz. Normalleştirilmezse aynı mağaza iki
     * kimlikle bağlanır ve `UNIQUE(channel_type_code, external_account_id)`
     * HİÇBİR ŞEY korumaz (§06.2).
     */
    public static function graphql(string $shopDomain): string
    {
        return 'https://'.$shopDomain.'/'.self::path(self::GRAPHQL);
    }

    /**
     * Şablondaki `{version}` yer tutucusunu doldurur.
     *
     * DOLDURULMAMIŞ YER TUTUCU İSTİSNA FIRLATIR (§05): geçseydi istek
     * literal `{version}` içeren bir adrese gider ve 404'ün sebebi
     * hiçbir yerde görünmezdi.
     *
     * @param  array<string, string>  $values  Ek yer tutucular
     */
    public static function path(string $template, array $values = []): string
    {
        $path = str_replace('{version}', self::API_VERSION, $template);

        foreach ($values as $key => $value) {
            $placeholder = '{'.$key.'}';

            if (! str_contains($path, $placeholder)) {
                throw new InvalidArgumentException(
                    "Bilinmeyen yer tutucu: {$placeholder} — şablon: {$template}"
                );
            }

            $path = str_replace($placeholder, rawurlencode($value), $path);
        }

        if (preg_match('/\{[a-zA-Z]+\}/', $path, $m) === 1) {
            throw new InvalidArgumentException(
                "Doldurulmamış yer tutucu: {$m[0]} — şablon: {$template}"
            );
        }

        return $path;
    }
}
