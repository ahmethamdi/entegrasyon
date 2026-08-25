<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Etsy;

use InvalidArgumentException;

/**
 * Etsy Open API v3 uç noktaları — TEK KAYNAK.
 *
 * V3.0 · §11 · §05 (kanal ekleme kontrol listesi · adım 2) · §21.
 *
 * ─────────────────────────────────────────────────────────────────────
 * YOLLAR DOĞRULANABİLİR — Hepsiburada'nın aksine
 * ─────────────────────────────────────────────────────────────────────
 * `developers.etsy.com` dokümantasyonu AÇIKTIR. Yollar yine de TEK YERDE
 * toplanır: adapter'a serpiştirilselerdi bir sürüm yükseltmesi on ayrı
 * yere dokunmak olurdu ve biri unutulunca o çağrı SESSİZCE yanlış adrese
 * giderdi (§05 · adım 2).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ ETSY'NİN ÜÇ SEVİYESİ YOLLARDA DA GÖRÜNÜR
 * ─────────────────────────────────────────────────────────────────────
 * Envanter yolu `listing_id` ile adreslenir, `product_id` ile DEĞİL
 * (§11.3). Bizim `listings.external_id`'miz `product_id` taşır ve
 * `external_parent_id` `listing_id` taşır — envanter çağrısı yapılırken
 * okunacak alan EBEVEYNDİR. Karıştırılırsa istek var olmayan bir
 * listing'e gider ve 404 alınır; sebebi de görünmez.
 */
final class EtsyEndpoints
{
    /**
     * API tabanı — sürüm YOL ÜZERİNDE taşınır (`/v3/`).
     *
     * Shopify'da sürüm bir sabit ve yıl-ay biçimindeydi; Etsy'de sürüm
     * yolun parçasıdır ve tek rakamdır. İkisi aynı sabitle temsil
     * edilemez, bu yüzden burada ayrı durur.
     */
    public const BASE_URL = 'https://openapi.etsy.com';

    // ───────────────────────────────────────────────────────────── kimlik

    /**
     * Yetkilendirme ekranı — satıcının TARAYICISI buraya gider.
     *
     * ⚠️ BU ADRES `openapi.etsy.com` DEĞİLDİR. Yetkilendirme satıcının
     * gördüğü bir Etsy.com sayfasıdır; API tabanına yazılsaydı satıcı
     * bir JSON hatasıyla karşılaşır ve bağlama akışı hiç başlamazdı.
     */
    public const AUTHORIZE_URL = 'https://www.etsy.com/oauth/connect';

    /** Token alma ve yenileme — İKİSİ DE aynı uç nokta (§11.2). */
    public const TOKEN = '/v3/public/oauth/token';

    /**
     * Bağlantıyı kuran satıcının kendi kullanıcı kaydı.
     *
     * Sağlık kontrolü BUNU kullanır: en ucuz kimlikli çağrıdır ve hem
     * `Bearer` hem `x-api-key` başlığının doğruluğunu birlikte kanıtlar
     * (§11.2 · iki ayrı kimlik başlığı).
     */
    public const ME = '/v3/application/users/me';

    // ──────────────────────────────────────────────────────────── katalog

    /** Mağazanın ilanları — içe aktarma ve katalog okuması. */
    public const SHOP_LISTINGS = '/v3/application/shops/{shop_id}/listings';

    /** Tek ilan — okuma ve güncelleme. */
    public const LISTING = '/v3/application/listings/{listing_id}';

    /**
     * ⚠️ ENVANTER — TÜM ENVANTERİ EZEN UÇ NOKTA (§11.3).
     *
     * GET okur, PUT yazar ve PUT gövdesi o ilanın BÜTÜN `products` +
     * `offerings` dizisini taşımak ZORUNDADIR. Kısmi güncelleme YOKTUR:
     * gönderilmeyen varyantlar KANALDAN SİLİNİR — sessiz, geri alınamaz
     * ve satıcı ancak siparişler kesilince fark eder.
     *
     * Bu yüzden yazma yolu daima OKU-BİRLEŞTİR-YAZ'dır.
     */
    public const LISTING_INVENTORY = '/v3/application/listings/{listing_id}/inventory';

    // ───────────────────────────────────────────────────────────── sipariş

    /**
     * Sipariş yoklaması — Etsy WEBHOOK SUNMAZ (§11.4 · Trendyol kalıbı).
     *
     * `receipt_id` siparişimiz, `transaction_id` sipariş satırımızdır.
     */
    public const SHOP_RECEIPTS = '/v3/application/shops/{shop_id}/receipts';

    // ──────────────────────────────────────────────────────────── taksonomi

    /** Satıcı taksonomisi — ağaç kanalın GERÇEĞİDİR, kiracısızdır. */
    public const TAXONOMY_NODES = '/v3/application/seller-taxonomy/nodes';

    /** Yaprak kategorinin öznitelikleri — ARA kategoriye sorulmaz. */
    public const TAXONOMY_PROPERTIES = '/v3/application/seller-taxonomy/nodes/{taxonomy_id}/properties';

    /**
     * Şablondaki yer tutucuları doldurur ve TAM adresi döner.
     *
     * DOLDURULMAMIŞ YER TUTUCU İSTİSNA FIRLATIR (§05): geçseydi istek
     * literal `{shop_id}` içeren bir adrese gider ve 404'ün sebebi
     * hiçbir yerde görünmezdi.
     *
     * YER TUTUCU ADIYLA DOLDURULUR, KONUMLA DEĞİL: konumla eşleştirme
     * `{listing_id}` ve `{product_id}` sırası değişince sessizce yanlış
     * değeri yazar ve istek BAŞKA bir ilana giderdi.
     *
     * @param  array<string, string|int>  $values
     */
    public static function url(string $template, array $values = []): string
    {
        return self::BASE_URL.self::path($template, $values);
    }

    /**
     * Yalnızca yol — taban adres eklenmeden.
     *
     * @param  array<string, string|int>  $values
     */
    public static function path(string $template, array $values = []): string
    {
        $path = $template;

        foreach ($values as $key => $value) {
            $placeholder = '{'.$key.'}';

            if (! str_contains($path, $placeholder)) {
                throw new InvalidArgumentException(
                    "Bilinmeyen yer tutucu: {$placeholder} — şablon: {$template}"
                );
            }

            $path = str_replace($placeholder, rawurlencode((string) $value), $path);
        }

        if (preg_match('/\{[a-z_]+\}/', $path, $m) === 1) {
            throw new InvalidArgumentException(
                "Doldurulmamış yer tutucu: {$m[0]} — şablon: {$template}"
            );
        }

        return $path;
    }
}
