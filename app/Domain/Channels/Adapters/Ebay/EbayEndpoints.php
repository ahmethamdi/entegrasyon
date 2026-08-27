<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Ebay;

use InvalidArgumentException;

/**
 * eBay Sell API uç noktaları — TEK KAYNAK.
 *
 * V3.0 · §13 · §05 (kanal ekleme kontrol listesi · adım 2) · §20 · §21.
 *
 * ─────────────────────────────────────────────────────────────────────
 * YOLLAR DOĞRULANABİLİR — Hepsiburada'nın aksine
 * ─────────────────────────────────────────────────────────────────────
 * `developer.ebay.com` dokümantasyonu AÇIKTIR. Yollar yine de TEK YERDE
 * toplanır: adapter'a serpiştirilselerdi bir sürüm yükseltmesi on ayrı
 * yere dokunmak olurdu ve biri unutulunca o çağrı SESSİZCE yanlış adrese
 * giderdi (§05 · adım 2 · `HepsiburadaEndpoints` ile aynı gerekçe).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ eBay'İN ÜÇ ADIMI YOLLARDA DA GÖRÜNÜR (§13.1)
 * ─────────────────────────────────────────────────────────────────────
 * Envanter kalemi SKU ile adreslenir (`PUT /inventory_item/{sku}`), offer
 * `offer_id` ile (`POST /offer/{offer_id}/publish`). İkisi FARKLI
 * kimliklerdir ve karıştırılırsa istek var olmayan bir kaynağa gider.
 *
 * Bizim tarafta: SKU `variants.sku`, `offer_id`
 * `listings.channel_metadata->>'offer_id'`, `listing_id`
 * `listings.external_id`. **`external_id` = `listing_id`, `offer_id`
 * DEĞİL** — `offer_id` bir ARA kimliktir ve satıcı onu hiçbir yerde
 * görmez.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ SANDBOX AYRI ANA BİLGİSAYARDIR (§13.3)
 * ─────────────────────────────────────────────────────────────────────
 * Üretim ve sandbox YALNIZCA ana bilgisayarda ayrışır; yollar aynıdır.
 * Ayrı bir sabit kümesi yazılsaydı biri güncellenir öteki eski kalırdı.
 * Taban, bağlantının `settings.use_sandbox` bayrağından seçilir ve
 * kimlik bilgisi de AYRIDIR (sandbox anahtarı üretimde çalışmaz).
 */
final class EbayEndpoints
{
    /**
     * API tabanı — üretim.
     *
     * ⚠️ SÜRÜM YOL ÜZERİNDE TAŞINIR (`/v1/`) ve uç nokta ailesine göre
     * DEĞİŞİR: envanter `sell/inventory/v1`, sipariş
     * `sell/fulfillment/v1`, taksonomi `commerce/taxonomy/v1`. Tek bir
     * "API sürümü" sabiti YAZILAMAZ — yazılsaydı üç aileden ikisi
     * sessizce yanlış adrese giderdi.
     */
    public const BASE_URL = 'https://api.ebay.com';

    /** API tabanı — sandbox (§13.3: "ayrı ana bilgisayar + ayrı kimlik"). */
    public const SANDBOX_BASE_URL = 'https://api.sandbox.ebay.com';

    // ───────────────────────────────────────────────────────────── kimlik

    /**
     * Yetkilendirme ekranı — satıcının TARAYICISI buraya gider.
     *
     * ⚠️ BU ADRES `api.ebay.com` DEĞİLDİR (Etsy'deki ayrımın aynısı).
     * Yetkilendirme satıcının gördüğü bir eBay sayfasıdır; API tabanına
     * yazılsaydı satıcı bir JSON hatasıyla karşılaşır ve bağlama akışı
     * hiç başlamazdı.
     */
    public const AUTHORIZE_URL = 'https://auth.ebay.com/oauth2/authorize';

    /** Yetkilendirme ekranı — sandbox. */
    public const SANDBOX_AUTHORIZE_URL = 'https://auth.sandbox.ebay.com/oauth2/authorize';

    /** Token alma ve yenileme — İKİSİ DE aynı uç nokta (§13.3). */
    public const TOKEN = '/identity/v1/oauth2/token';

    // ──────────────────────────────────────────────────────────── envanter

    /**
     * Envanter kalemi — SKU ile adreslenir ve PUT İDEMPOTENTTİR (§13.1).
     *
     * İdempotentlik burada bir kolaylık değil ZORUNLULUKTUR: üç adımlı
     * zincirin ilk adımı ara başarısızlıktan sonra YENİDEN çağrılır ve
     * ikinci çağrı bir kopya YARATMAMALIDIR.
     */
    public const INVENTORY_ITEM = '/sell/inventory/v1/inventory_item/{sku}';

    /** Offer yaratma (POST) ve listeleme (GET `?sku=`). */
    public const OFFER = '/sell/inventory/v1/offer';

    /** Tek offer — güncelleme (PUT) ve okuma (GET). */
    public const OFFER_ITEM = '/sell/inventory/v1/offer/{offerId}';

    /** Offer'ı yayına alır; `listing_id` DÖNER (§13.1 · üçüncü adım). */
    public const OFFER_PUBLISH = '/sell/inventory/v1/offer/{offerId}/publish';

    /**
     * Yayından kaldırır — SİLMEZ (v2.2 · `delist` kuralı).
     *
     * ⚠️ `DELETE /offer/{id}` KULLANILMAZ. Silme geri alınamaz ve
     * `offer_id`'yi de götürür; o kimlik kaybedilirse listing'e bir daha
     * stok gönderilemez ve yeniden yaratmak `25002` duplicate hatası
     * verir (§13.1).
     */
    public const OFFER_WITHDRAW = '/sell/inventory/v1/offer/{offerId}/withdraw';

    /**
     * Stok VE fiyat — AYNI çağrıda, en çok 25 offer (§13.4 · §21).
     *
     * ⚠️ HEPSİBURADA GİBİ, TRENDYOL'UN TERSİ. Trendyol'da "stok yükü
     * fiyat alanı TAŞIMAZ" katı kuraldı; burada uç nokta ikisini birlikte
     * bekler ve ayrı gönderilemez.
     */
    public const BULK_UPDATE_PRICE_QUANTITY = '/sell/inventory/v1/bulk_update_price_quantity';

    // ────────────────────────────────────────────────────────────── hesap

    /**
     * Satıcının konumları — `merchantLocationKey` buradan seçtirilir.
     *
     * Offer için ZORUNLUDUR (§17); eksikse offer yaratma `VALIDATION`
     * alır ve o hata KALICIDIR.
     */
    public const LOCATION = '/sell/inventory/v1/location';

    /**
     * Politika üçlüsü — offer için ZORUNLU (§17).
     *
     * Üçü de bağlama akışında seçtirilir; eksikse offer yaratma
     * `VALIDATION` alır ve listing "düzeltilemez" damgasıyla ölür.
     */
    public const FULFILLMENT_POLICY = '/sell/account/v1/fulfillment_policy';

    public const PAYMENT_POLICY = '/sell/account/v1/payment_policy';

    public const RETURN_POLICY = '/sell/account/v1/return_policy';

    /**
     * Sağlık kontrolü — en ucuz kimlikli çağrı.
     *
     * Satıcının kendi ayrıcalıklarını döndürür; hem token'ın geçerliliğini
     * hem de `sell.account` scope'unun varlığını birlikte kanıtlar.
     */
    public const PRIVILEGE = '/sell/account/v1/privilege';

    // ─────────────────────────────────────────────────────────── sipariş

    /**
     * Sipariş yoklaması (§13.6).
     *
     * ⚠️ eBay Notification API SİPARİŞ İÇİN DEĞİLDİR — hesap kapanma ve
     * politika ihlali bildirir. Sipariş YALNIZCA yoklamayla gelir ve
     * `supports_webhooks = false` yazılır.
     */
    public const ORDER = '/sell/fulfillment/v1/order';

    /**
     * İade — AYRI API ve AYRI sürüm ailesi (`post-order/v2`).
     *
     * Etsy'de iade için uç nokta YOKTU ve yoklama iadeyi `updated`
     * görüyordu; eBay'de gerçek bir uç nokta VAR ve V3.0 kapsamındadır
     * (§13.6).
     */
    public const RETURN_SEARCH = '/post-order/v2/return/search';

    // ─────────────────────────────────────────────────────────── taksonomi

    /**
     * Kategori ağacı — MARKETPLACE BAŞINA (§13.5).
     *
     * ⚠️ AĞAÇ KİMLİĞİ MARKETPLACE'E BAĞLIDIR (`EBAY_US`, `EBAY_DE`).
     * `taxonomyVersion()` marketplace kimliğini İÇERMEK ZORUNDADIR;
     * içermezse ABD ağacıyla eşleştirilen bir kategori Almanya'ya
     * gönderilir ve `VALIDATION` alır.
     */
    public const CATEGORY_TREE = '/commerce/taxonomy/v1/category_tree/{treeId}';

    /** Marketplace kimliğinden varsayılan ağaç kimliğini verir. */
    public const DEFAULT_CATEGORY_TREE_ID = '/commerce/taxonomy/v1/get_default_category_tree_id';

    /** Kategorinin aspect'leri — Trendyol'un "zorunlu öznitelik"lerinin karşılığı. */
    public const CATEGORY_ASPECTS = '/commerce/taxonomy/v1/category_tree/{treeId}/get_item_aspects_for_category';

    /**
     * Tam adres üretir.
     *
     * @param  array<string, string|int>  $replacements
     *
     * ⚠️ YER TUTUCU ADIYLA DOLDURULUR, KONUMLA DEĞİL
     * (`HepsiburadaEndpoints` kuralının aynısı). Konumla eşleştirme
     * `{offerId}` ve `{sku}` sırası değişince sessizce yanlış değeri
     * yazar ve istek BAŞKA bir kaynağa giderdi.
     */
    public static function url(string $path, array $replacements = [], bool $sandbox = false): string
    {
        foreach ($replacements as $key => $value) {
            $path = str_replace('{'.$key.'}', rawurlencode((string) $value), $path);
        }

        self::assertNoPlaceholdersLeft($path);

        return ($sandbox ? self::SANDBOX_BASE_URL : self::BASE_URL).$path;
    }

    /** Yetkilendirme ekranının adresi — sandbox bayrağına göre. */
    public static function authorizeUrl(bool $sandbox = false): string
    {
        return $sandbox ? self::SANDBOX_AUTHORIZE_URL : self::AUTHORIZE_URL;
    }

    /**
     * ⚠️ DOLDURULMAMIŞ YER TUTUCU İSTİSNA FIRLATIR.
     *
     * Geçseydi istek literal `{offerId}` içeren bir adrese gider, 404
     * alınır ve sebebi HİÇBİR YERDE görünmezdi — 404'ün "ilan silinmiş"
     * anlamına geldiği mutabakat yolunda bu, var olan bir ilanı
     * `REMOTE_MISSING` saydırırdı.
     */
    private static function assertNoPlaceholdersLeft(string $path): void
    {
        if (preg_match('/\{([a-zA-Z]+)\}/', $path, $matches) === 1) {
            throw new InvalidArgumentException(
                "eBay uç noktasında doldurulmamış yer tutucu var: `{$matches[0]}` ({$path})."
            );
        }
    }
}
