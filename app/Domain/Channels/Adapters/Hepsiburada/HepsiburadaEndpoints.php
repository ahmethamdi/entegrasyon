<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Hepsiburada;

/**
 * Hepsiburada uç noktaları — TEK KAYNAK ve DOĞRULAMA SINIRI.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ BU DOSYADAKİ YOLLAR RESMÎ DOKÜMANDAN DOĞRULANMADI
 * ─────────────────────────────────────────────────────────────────────
 * `developers.hepsiburada.com` bot isteklerini 403, `listing-external
 * .hepsiburada.com/docs` 401 ile reddediyor. Aşağıdaki yollar ikincil
 * kaynaklardan (entegratör dokümantasyonu + arama sonuçları) derlendi.
 *
 * **HEPSİ TEK BİR YERDE TUTULUYOR ki doğrulama TEK dosyada bitsin.**
 * Adapter içine serpiştirilselerdi düzeltme on ayrı yere dokunmak
 * demek olurdu ve biri unutulunca o çağrı sessizce yanlış adrese
 * giderdi.
 *
 * **NEDEN BU RİSK CİDDİ:** bu projede yanlış uç nokta SESSİZ hataya
 * dönüşür. Kanal 200 dönerse senkron BAŞARILI görünür, `synced_version`
 * ilerler ve satır "senkron" damgası taşırken kanalda hiçbir şey
 * değişmemiş olur. Trendyol'un "KİMLİK BARKODDUR VE SAYIYA ÇEVRİLMEZ"
 * kuralı tam olarak bu hata biçimini anlatıyor.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DOĞRULAMA YAPILMADAN CANLI BAĞLANTI AÇILMAZ
 * ─────────────────────────────────────────────────────────────────────
 * `ChannelTypeSeeder` bu kanalı `is_active = false` ile yazar; panelde
 * açılır listede GÖRÜNMEZ. Doğrulama sırası:
 *
 *   1. Aşağıdaki her sabiti resmî dokümanla karşılaştır.
 *   2. `HepsiburadaEndpointContractTest`'teki BEKLENEN METİNLERİ güncelle
 *      (test sabitleri beklenen metinle sınar — mutasyon ikisini
 *      BİRLİKTE kaydırmasın diye).
 *   3. Gerçek satıcı hesabıyla sağlık kontrolü çalıştır.
 *   4. `is_active = true` yap.
 *
 * ─────────────────────────────────────────────────────────────────────
 * BEŞ AYRI ALT ALAN ADI — TRENDYOL'DAN FARKI
 * ─────────────────────────────────────────────────────────────────────
 * Trendyol tek host kullanır; Hepsiburada işlevi ayrı alt alan adlarına
 * böler ve her birinin kendi hız sınırı ve sayfalama sözleşmesi vardır.
 * `base_url` bu yüzden bağlantı ayarlarında TEK bir değer olarak
 * TUTULAMAZ — host işleve göre seçilir.
 */
final class HepsiburadaEndpoints
{
    // ───────────────────────────────────────────────── hostlar

    /** Listing CRUD — fiyat, stok, listeleme durumu. DOĞRULANMADI. */
    public const HOST_LISTING = 'https://listing-external.hepsiburada.com';

    /** Ürün açma ve kategori/öznitelik (MPOP). DOĞRULANMADI. */
    public const HOST_PRODUCT = 'https://mpop.hepsiburada.com';

    /** Sipariş yönetimi (OMS). DOĞRULANMADI. */
    public const HOST_ORDER = 'https://oms-external.hepsiburada.com';

    /** Test ortamı ön eki — sandbox hostları DOĞRULANMADI. */
    public const HOST_LISTING_SANDBOX = 'https://listing-external-sit.hepsiburada.com';

    // ───────────────────────────────────────────────── listing

    /**
     * Tekil fiyat/stok güncelleme. DOĞRULANMADI.
     *
     * `{merchantId}` ve `{merchantSku}` yerine konur.
     *
     * ⚠️ STOK VE FİYAT AYNI YÜKTE GİDER — TRENDYOL'UN TERSİ.
     * Trendyol'da "stok yükü fiyat alanı TAŞIMAZ" katı bir kuraldı
     * çünkü orada biri diğerini SESSİZCE ezerdi. Hepsiburada'nın uç
     * noktası ikisini birlikte bekliyor; ayrı göndermek eksik alanı
     * SIFIRLAYABİLİR ve bu, satışı kapatmak demektir (kanal "stok 0 =
     * satışa kapat" diye yorumluyor).
     */
    public const LISTING_UPDATE = '/listings/merchantid/{merchantId}/sku/{merchantSku}';

    /**
     * Toplu fiyat/stok güncelleme — ASENKRON, `trackingId` döner.
     * DOĞRULANMADI.
     *
     * Tek istekte en fazla 4000 SKU; aynı anda en fazla 5 bekleyen
     * işlem (ikincil kaynak). Bu sayılar `HepsiburadaAdapter`'ın
     * `inventoryBatchSize()` değerini belirler.
     */
    public const LISTING_BULK_UPDATE = '/listings/merchantid/{merchantId}/inventory-uploads';

    /** Toplu işlem sonucu yoklaması. DOĞRULANMADI. */
    public const LISTING_BULK_STATUS = '/listings/merchantid/{merchantId}/inventory-uploads/id/{trackingId}';

    /** Satıcının listeleri — sağlık kontrolü ve uzak durum okuma. DOĞRULANMADI. */
    public const LISTING_LIST = '/listings/merchantid/{merchantId}';

    // ───────────────────────────────────────────────── ürün / kategori

    /** Ürün açma — ASENKRON, `trackingId` döner. DOĞRULANMADI. */
    public const PRODUCT_IMPORT = '/product/api/products/import';

    /** Ürün açma sonucu yoklaması. DOĞRULANMADI. */
    public const PRODUCT_IMPORT_STATUS = '/product/api/products/import/{trackingId}';

    /** Kategori ağacı. DOĞRULANMADI. */
    public const CATEGORIES = '/product/api/categories/get-all-categories';

    /** Kategoriye ait zorunlu/isteğe bağlı öznitelikler. DOĞRULANMADI. */
    public const CATEGORY_ATTRIBUTES = '/product/api/categories/{categoryId}/attributes';

    // ───────────────────────────────────────────────── sipariş

    /** Satıcının paketleri (sipariş listesi). DOĞRULANMADI. */
    public const ORDER_PACKAGES = '/packages/merchantid/{merchantId}';

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
