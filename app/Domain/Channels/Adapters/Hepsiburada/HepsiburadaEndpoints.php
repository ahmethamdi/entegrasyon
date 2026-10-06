<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Hepsiburada;

/**
 * Hepsiburada uç noktaları — TEK KAYNAK.
 *
 * ─────────────────────────────────────────────────────────────────────
 * RESMÎ DOKÜMANDAN DOĞRULANDI (6 Eki 2026)
 * ─────────────────────────────────────────────────────────────────────
 * Yollar `docs/hepsiburada-openapi/*.json` (sitenin OpenAPI kayıtları)
 * ile BİREBİR aynıdır — büyük/küçük harf dahil (`/Listings/` büyük L).
 * Ayrıntı ve kaynaklar `docs/HEPSIBURADA-API-NOTLARI.md`. Önceki sürümdeki
 * yollar ikincil kaynaktan derlenmişti ve küçük harfliydi; tekil
 * güncelleme yolu da yanlıştı.
 *
 * Hepsi tek yerde: yanlış uç nokta bu projede SESSİZ hataya dönüşür
 * (kanal 200 dönerse senkron başarılı görünür ve hiçbir şey değişmez).
 *
 * ─────────────────────────────────────────────────────────────────────
 * İŞLEV BAŞINA AYRI HOST + TEST/CANLI ORTAM
 * ─────────────────────────────────────────────────────────────────────
 * Hepsiburada işlevi ayrı alt alan adlarına böler. Test ortamı (SIT)
 * adresi canlıdan `-sit` ekiyle ayrılır: entegratör yetkisi ÖNCE testte
 * verilir, canlı ancak testler geçince açılır. Ortam bağlantı ayarıdır
 * (`HepsiburadaAdapter::ENVIRONMENT_KEY`).
 */
final class HepsiburadaEndpoints
{
    // ───────────────────────────────────────────────── hostlar

    public const SERVICE_LISTING = 'listing-external';

    public const SERVICE_ORDER = 'oms-external';

    public const SERVICE_CATALOG = 'mpop';

    /** İşlevin tam host adresi; test ortamında `-sit` eklenir. */
    public static function host(string $service, bool $test): string
    {
        $suffix = $test ? '-sit' : '';

        return match ($service) {
            self::SERVICE_LISTING, self::SERVICE_ORDER => "https://{$service}{$suffix}.hepsiburada.com",
            // Katalog servisi `/product` ön ekiyle yaşar.
            self::SERVICE_CATALOG => "https://mpop{$suffix}.hepsiburada.com/product",
            default => throw new \InvalidArgumentException("Bilinmeyen Hepsiburada servisi: {$service}"),
        };
    }

    // ───────────────────────────────────────────────── listing

    /** İlan listesi — `offset` ve `limit` ZORUNLU. Sağlık kontrolü de bunu okur. */
    public const LISTING_LIST = '/Listings/merchantid/{merchantId}';

    /**
     * Yalnız STOK yüklemesi — ASENKRON, `id` döner.
     *
     * Toplu `inventory-uploads` stok ve fiyatı birlikte alır ama tek alan
     * gönderilince ötekinin sıfırlanıp sıfırlanmadığı belgelenmemiş.
     * Sıfırlanırsa satış kapanır; bu yüzden ayrı uçlar kullanılır.
     */
    public const STOCK_UPLOAD = '/Listings/merchantid/{merchantId}/stock-uploads';

    public const STOCK_UPLOAD_STATUS = '/Listings/merchantid/{merchantId}/stock-uploads/id/{id}';

    /** Yalnız FİYAT yüklemesi — ASENKRON, `id` döner. */
    public const PRICE_UPLOAD = '/Listings/merchantid/{merchantId}/price-uploads';

    public const PRICE_UPLOAD_STATUS = '/Listings/merchantid/{merchantId}/price-uploads/id/{id}';

    // ───────────────────────────────────────────────── katalog (MPOP)

    /**
     * Satıcının ürün bilgisi (ad, marka, görsel, barkod) — SKU ile süzülür.
     * İlan listesi bunları TAŞIMAZ; içe aktarma ilanı bununla zenginleştirir.
     */
    public const CATALOG_MERCHANT_PRODUCTS = '/api/products/all-products-of-merchant/{merchantId}';

    // ───────────────────────────────────────────────── sipariş (OMS)

    /** Ödemesi tamamlanmış, paketlenecek sipariş kalemleri. */
    public const ORDERS = '/orders/merchantid/{merchantId}';

    /** Son 1 ayın iptalleri (kalem bazında). */
    public const ORDERS_CANCELLED = '/orders/merchantid/{merchantId}/cancelled';

    /** Siparişin TÜM kalemleri (açık, paketli, iptal) — durumdan bağımsız. */
    public const ORDER_DETAIL = '/orders/merchantid/{merchantId}/ordernumber/{orderNumber}';

    /** Paketler — tarih aralığı ≤24 saat, limit ≤10, sayfalama BAŞLIKTA. */
    public const PACKAGES = '/packages/merchantid/{merchantId}';

    /** İade talepleri. */
    public const CLAIMS = '/claims/merchantId/{merchantId}';

    /**
     * Yol şablonundaki yer tutucuları doldurur.
     *
     * Yer tutucu ADIYLA doldurulur, KONUMLA değil: konumla eşleştirme
     * `{merchantId}` ve `{merchantSku}`'nun sırası değiştiğinde sessizce
     * yanlış değeri yazardı ve istek BAŞKA bir satıcının SKU'suna
     * giderdi. (Toplu içe aktarmadaki "kolonlar ADIYLA eşlenir"
     * kuralının aynısı.)
     *
     * @param  array<string, string>  $values
     */
    public static function path(string $template, array $values): string
    {
        $path = $template;

        foreach ($values as $key => $value) {
            $placeholder = '{'.$key.'}';

            if (! str_contains($path, $placeholder)) {
                throw new \InvalidArgumentException(
                    "Bilinmeyen yer tutucu: {$placeholder} — şablon: {$template}"
                );
            }

            $path = str_replace($placeholder, rawurlencode($value), $path);
        }

        // Doldurulmamış yer tutucu KALMAMALI: kalırsa istek literal
        // "{merchantId}" içeren bir adrese gider ve kanal 404 döner —
        // teşhisi zor, sebebi görünmez bir hata.
        if (preg_match('/\{[a-zA-Z]+\}/', $path, $m) === 1) {
            throw new \InvalidArgumentException(
                "Doldurulmamış yer tutucu: {$m[0]} — şablon: {$template}"
            );
        }

        return $path;
    }
}
