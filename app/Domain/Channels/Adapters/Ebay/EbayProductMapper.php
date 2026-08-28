<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Ebay;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Sync\Support\ListingPayload;

/**
 * Kanonik listing → eBay gövdeleri dönüşümü.
 *
 * V3.0 · §13.1 · §13.2 · §17 · v2.2 §7 (Adapter klasör yapısı · Mapper).
 *
 * Dönüşüm adapter'ın İÇİNDE durur: çekirdek "şunu gönder" der, "şu JSON'u
 * gönder" demez (Woo/Etsy/Shopify kalıbı). Kanal alan adları değiştiğinde
 * yalnızca bu dosya değişir.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ İKİ GÖVDE, İKİ AYRI SORU — BİRLEŞTİRİLEMEZ (§13.1)
 * ─────────────────────────────────────────────────────────────────────
 * eBay'de içerik ve satış koşulları AYRI kaynaklarda yaşar:
 *
 *   inventory item  →  NE satılıyor (başlık, açıklama, aspects, durum)
 *   offer           →  NASIL satılıyor (fiyat, miktar, kategori,
 *                      marketplace, konum, politika üçlüsü)
 *
 * Tek gövdede toplansaydı ya envanter kalemine ait olmayan alanlar
 * `PUT /inventory_item` gövdesine sızar ve `VALIDATION` alınırdı, ya da
 * offer gövdesi başlık taşır ve eBay onu SESSİZCE yok sayardı — satıcı
 * başlığı düzeltir, kanal eski başlığı göstermeye devam ederdi.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ STOK BU GÖVDELERDE GÖNDERİLMEZ — İÇERİK STOĞA DOKUNMAZ
 * ─────────────────────────────────────────────────────────────────────
 * v2.2'nin katalog kuralı: içerik aktarımı stok hareketi ÜRETMEZ ve
 * kanaldaki miktarı EZMEZ. eBay'in her iki gövdesinde de miktar alanı
 * VARDIR (`availability.shipToLocationAvailability.quantity` ve offer'ın
 * `availableQuantity`'si) ve bu, kuralı ihlal etmenin en kolay yoludur.
 *
 * Alan GÖNDERİLMEZ, sıfır da YAZILMAZ: sıfır yazmak ürünü SATIŞA
 * KAPATIRDI (Etsy'nin "fiyat turunda miktar korunur" kuralının eBay
 * karşılığı — orada tehlike aynıydı ve BEŞ testi birden kırmıştı).
 * Stok kendi domainindedir ve slice 4.6'da `offer_id` hedefiyle,
 * MUTLAK değerle gider.
 *
 * ⚠️ AMA `availability` BLOĞU İLK YARATMADA ZORUNLUDUR. eBay
 * `availability` taşımayan bir envanter kalemi için offer yaratmayı
 * REDDEDER. Çözüm alanı atmak değil, MEVCUT miktarı korumaktır: gövde
 * kalemi kanaldan okunmuş miktarla doldurulur ve bilinmiyorsa 0 yerine
 * `null` bırakılıp alan HİÇ yazılmaz — bkz. `toInventoryItemBody()`.
 */
final class EbayProductMapper
{
    /**
     * `PUT /inventory_item/{sku}` gövdesi — NE satılıyor (§13.1).
     *
     * ⚠️ PUT İDEMPOTENTTİR ve bu bir kolaylık DEĞİL ZORUNLULUKTUR:
     * zincirin ilk adımı ara başarısızlıktan sonra YENİDEN çağrılır ve
     * ikinci çağrı bir kopya YARATMAMALIDIR (§13.2).
     *
     * ⚠️ GÖVDE TAM DEĞİŞTİRME YAPAR — Etsy'nin envanter PUT'uyla AYNI
     * TUZAK. Gönderilmeyen alan kanalda KORUNMAZ, SİLİNİR. Bu yüzden
     * `condition` her turda yazılır: atlanırsa eBay kalemi "durumu
     * belirtilmemiş" sayar ve offer yaratma `VALIDATION` alır — o hata
     * KALICIDIR.
     *
     * ⚠️ MİKTAR YALNIZCA BİLİNİYORSA YAZILIR (yukarıdaki sınıf notu).
     * `null` geçilirse blok HİÇ yazılmaz; 0 yazılsaydı bir İÇERİK turu
     * sessizce bir STOK sıfırlaması yapar ve ürün satışa kapanırdı.
     *
     * @param  int|null  $knownQuantity  Kanalda ZATEN duran miktar; bilinmiyorsa null
     * @return array<string, mixed>
     */
    public static function toInventoryItemBody(ListingPayload $payload, ?int $knownQuantity = null): array
    {
        $variant = $payload->listing->variant;

        $body = [
            // ⚠️ DURUM ZORUNLUDUR ve `NEW` VARSAYILANDIR. eBay ikinci el
            // pazarıdır ve durum alanı olmadan offer yaratılamaz; kanonik
            // modelimizde "ürün durumu" diye bir alan YOKTUR ve
            // uydurulmaz — satıcı ikinci el satıyorsa bunu kanala özgü
            // öznitelikle (`attributes`) EZER.
            'condition' => 'NEW',
            'product' => array_filter([
                'title' => $payload->title,
                'description' => $payload->description,
            ], static fn (mixed $v): bool => $v !== null && $v !== ''),
        ];

        // ⚠️ ASPECT'LER `product.aspects` ALTINDADIR, kökte DEĞİL.
        // Kökte yazılsaydı eBay onları SESSİZCE yok sayar ve zorunlu
        // aspect eksikliği ancak offer yaratmada `VALIDATION` olarak
        // görünürdü — sebebi hiçbir yerde okunmayan bir hata.
        // Taksonomi slice 4.5'te dolduracak; bugün yalnızca kanala özgü
        // öznitelik olarak geçirilebilir.
        $aspects = $payload->attributes['aspects'] ?? null;

        if (is_array($aspects) && $aspects !== []) {
            $body['product']['aspects'] = $aspects;
        }

        // Barkod eBay'de `product.upc`/`ean` ailesine girer ve YOKSA
        // gönderilmez — boş dizi göndermek `VALIDATION` üretir.
        if ($variant?->barcode !== null && $variant->barcode !== '') {
            $body['product']['ean'] = [$variant->barcode];
        }

        if ($knownQuantity !== null) {
            $body['availability'] = [
                'shipToLocationAvailability' => ['quantity' => $knownQuantity],
            ];
        }

        // Kanala özgü öznitelikler kanonik alanları EZMEZ; çakışmada
        // kanonik kazanır, yoksa panelde görünen ile gönderilen ayrışır
        // (`ShopifyProductMapper` kuralının aynısı). `aspects` yukarıda
        // zaten okundu ve gövdeye KÖK alan olarak sızmamalıdır.
        $extra = $payload->attributes;
        unset($extra['aspects']);

        return [...$extra, ...$body];
    }

    /**
     * `POST /offer` ve `PUT /offer/{offerId}` gövdesi — NASIL satılıyor.
     *
     * ⚠️ BEŞ ALAN `settings`'TEN OKUNUR ve BEŞİ DE ZORUNLUDUR (§17):
     * `merchantLocationKey`, `marketplaceId` ve politika üçlüsü. Sağlık
     * kontrolü onları ZATEN şart koşar ve bağlantı onlarsız `active`
     * OLMAZ; buradaki okuma o garantiye yaslanır.
     *
     * ⚠️ `format` = `FIXED_PRICE`. eBay müzayede de destekler ve
     * varsayılan olarak bırakılsaydı kanal hesabın tercihine göre
     * müzayede açabilirdi — stok/fiyat senkronunun hiçbir anlamı
     * kalmazdı çünkü müzayede fiyatı BİZİM kontrolümüzde değildir.
     *
     * ⚠️ FİYAT STRING TAŞINIR — para float taşımaz (yuvarlama kuruş
     * kayması üretir). `decimal(12,2)` PHP'ye zaten string döner ve
     * `(float)` dönüşümü YAPILMAZ (projenin her kanalda tekrarlanan
     * kuralı).
     *
     * ⚠️ MİKTAR BURADA DA GÖNDERİLMEZ. Offer gövdesinin
     * `availableQuantity` alanı vardır ve doldurulsaydı her içerik turu
     * stoğu ezerdi; miktar slice 4.6'nın `bulk_update_price_quantity`
     * çağrısıyla, MUTLAK değerle gider.
     *
     * @return array<string, mixed>
     */
    public static function toOfferBody(
        ListingPayload $payload,
        ChannelConnection $connection,
        ?string $categoryId = null,
    ): array {
        $variant = $payload->listing->variant;
        $settings = is_array($connection->settings) ? $connection->settings : [];

        $body = [
            'sku' => (string) $variant?->sku,
            'marketplaceId' => (string) ($settings[EbayAdapter::MARKETPLACE_ID_KEY] ?? ''),
            'format' => 'FIXED_PRICE',
            'merchantLocationKey' => (string) ($settings[EbayAdapter::MERCHANT_LOCATION_KEY] ?? ''),
            'listingPolicies' => [
                'fulfillmentPolicyId' => (string) ($settings[EbayAdapter::FULFILLMENT_POLICY_KEY] ?? ''),
                'paymentPolicyId' => (string) ($settings[EbayAdapter::PAYMENT_POLICY_KEY] ?? ''),
                'returnPolicyId' => (string) ($settings[EbayAdapter::RETURN_POLICY_KEY] ?? ''),
            ],
        ];

        // ⚠️ PARA BİRİMİ MARKETPLACE'İN GERÇEĞİDİR, kanonik modelin
        // değil. `variants.currency` varsayılanı TRY'dir ve `EBAY_DE`'ye
        // TRY gönderilseydi `VALIDATION` alınırdı — o hata KALICIDIR.
        //
        // ⚠️ BİLİNMEYEN MARKETPLACE'TE FİYAT BLOĞU HİÇ YAZILMAZ. Uydurma
        // bir para birimi ("USD") yazılsaydı ilan sessizce YANLIŞ parayla
        // giderdi; eksik fiyat GÖRÜNÜR bir hatadır (eBay offer'ı
        // reddeder), yanlış fiyat GÖRÜNMEZ bir hatadır.
        $currency = EbayMarketplace::currencyFor(
            (string) ($settings[EbayAdapter::MARKETPLACE_ID_KEY] ?? ''),
        );

        if ($variant?->price !== null && $currency !== null) {
            $body['pricingSummary'] = [
                'price' => [
                    // Fiyat STRING taşınır — para float taşımaz
                    // (yuvarlama kuruş kayması üretir).
                    'value' => (string) $variant->price,
                    'currency' => $currency,
                ],
            ];
        }

        // Açıklama offer'da DA yaşar — envanter kalemindekinden AYRI bir
        // alandır (`listingDescription`) ve eBay ilan sayfasında GÖSTERİLEN
        // odur. Yalnızca envanter kalemine yazılsaydı satıcının açıklaması
        // ilanda HİÇ görünmezdi.
        if ($payload->description !== null && $payload->description !== '') {
            $body['listingDescription'] = $payload->description;
        }

        // ⚠️ KATEGORİ SLICE 4.5'İN İŞİDİR ve BOŞ GÖNDERİLMEZ. Boş dize
        // yazılsaydı eBay `VALIDATION` döner ve o hata KALICIDIR; alan
        // hiç yazılmazsa kanal hesabın varsayılanını kullanır.
        if ($categoryId !== null && $categoryId !== '') {
            $body['categoryId'] = $categoryId;
        }

        return $body;
    }
}
