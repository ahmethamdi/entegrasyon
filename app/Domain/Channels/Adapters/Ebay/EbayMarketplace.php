<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Ebay;

/**
 * Marketplace kimliği → para birimi.
 *
 * V3.0 · §13.1 · §13.5 · §17.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ PARA BİRİMİ MARKETPLACE'İN GERÇEĞİDİR, KANONİK MODELİN DEĞİL
 * ─────────────────────────────────────────────────────────────────────
 * `variants.currency` kolonu VARDIR ve varsayılanı `TRY`'dir. Offer
 * gövdesine o değer yazılsaydı `EBAY_DE`'ye TRY fiyat gider, eBay
 * `VALIDATION` döner ve o hata KALICIDIR — listing "düzeltilemez"
 * damgasıyla ölür ve sebebi "para birimi" olarak HİÇBİR YERDE görünmez.
 *
 * Ters yön de yanlıştır: kanonik değer YOK SAYILIP her marketplace'e
 * kendi para birimi yazılsaydı, satıcının 199.90 TRY'lik ürünü Almanya'da
 * **199.90 EUR** olurdu. Bu sınıf yalnızca "hangi para biriminde
 * yazılmalı" sorusunu cevaplar; DÖNÜŞTÜRME YAPMAZ ve yapmamalıdır —
 * kur çevrimi bir ÜRÜN kararıdır ve V3.0 kapsamında değildir.
 *
 * ⚠️ BİLİNMEYEN MARKETPLACE'TE UYDURULMAZ. Varsayılan bir para birimi
 * ("USD") yazılsaydı yeni açılan bir eBay pazarında her ilan sessizce
 * yanlış parayla gider ve satıcı bunu ancak ilk siparişte görürdü.
 * Bilinmeyen kimlikte `null` döner ve çağıran fiyat bloğunu HİÇ yazmaz:
 * eksik fiyat GÖRÜNÜR bir hatadır (offer yaratma reddedilir), yanlış
 * fiyat GÖRÜNMEZ bir hatadır.
 *
 * ⚠️ TABLO KANALIN GERÇEĞİDİR ve `if ($code === ...)` DEĞİLDİR: yeni
 * pazar eklendiğinde dokunulacak TEK yer burasıdır.
 */
final class EbayMarketplace
{
    /**
     * eBay'in yayınladığı marketplace kimlikleri ve para birimleri.
     *
     * Liste TAM DEĞİLDİR ve olmak zorunda da değildir — bilinmeyen
     * kimlik `null` döner ve fiyat yazılmaz (yukarıdaki kural).
     *
     * @var array<string, string>
     */
    private const CURRENCIES = [
        'EBAY_US' => 'USD',
        'EBAY_GB' => 'GBP',
        'EBAY_DE' => 'EUR',
        'EBAY_FR' => 'EUR',
        'EBAY_IT' => 'EUR',
        'EBAY_ES' => 'EUR',
        'EBAY_NL' => 'EUR',
        'EBAY_BE' => 'EUR',
        'EBAY_IE' => 'EUR',
        'EBAY_AT' => 'EUR',
        'EBAY_PL' => 'PLN',
        'EBAY_CH' => 'CHF',
        'EBAY_CA' => 'CAD',
        'EBAY_AU' => 'AUD',
        'EBAY_HK' => 'HKD',
        'EBAY_SG' => 'SGD',
        'EBAY_MY' => 'MYR',
        'EBAY_PH' => 'PHP',
    ];

    /**
     * `Content-Language` başlığının değeri — eBay yazma çağrılarında
     * ZORUNLUDUR ve eksikse istek `VALIDATION` alır (KALICI hata).
     *
     * ⚠️ PARA BİRİMİNDEN AYRI BİR TABLODUR ve birleştirilemez: dört
     * marketplace euro kullanır ama dördünün dili FARKLIDIR (`EBAY_DE`
     * → `de-DE`, `EBAY_FR` → `fr-FR`). Tek tabloya sıkıştırılsaydı ya
     * para birimi ya dil yanlış giderdi.
     *
     * ⚠️ BURADA VARSAYILAN VERİLİR ve bu, para birimindeki kararın
     * TERSİDİR — çünkü bedeller ters. Eksik başlık isteği KALICI hatayla
     * öldürür (yani yokluk = kesin arıza); yanlış dil etiketi ise ilanı
     * yalnızca yanlış dilde ETİKETLER, satışı durdurmaz. Bilinmeyen
     * pazarda `en-US` göndermek, hiç göndermemekten iyidir.
     *
     * @var array<string, string>
     */
    private const CONTENT_LANGUAGES = [
        'EBAY_US' => 'en-US',
        'EBAY_GB' => 'en-GB',
        'EBAY_IE' => 'en-IE',
        'EBAY_CA' => 'en-CA',
        'EBAY_AU' => 'en-AU',
        'EBAY_DE' => 'de-DE',
        'EBAY_AT' => 'de-AT',
        'EBAY_CH' => 'de-CH',
        'EBAY_FR' => 'fr-FR',
        'EBAY_BE' => 'fr-BE',
        'EBAY_IT' => 'it-IT',
        'EBAY_ES' => 'es-ES',
        'EBAY_NL' => 'nl-NL',
        'EBAY_PL' => 'pl-PL',
        'EBAY_HK' => 'zh-HK',
        'EBAY_SG' => 'en-SG',
        'EBAY_MY' => 'en-MY',
        'EBAY_PH' => 'en-PH',
    ];

    /** Bilinmeyen kimlikte `null` — UYDURULMAZ (sınıf notu). */
    public static function currencyFor(string $marketplaceId): ?string
    {
        return self::CURRENCIES[strtoupper(trim($marketplaceId))] ?? null;
    }

    /** Bilinmeyen pazarda `en-US` — eksik başlık KALICI hata verirdi. */
    public static function contentLanguageFor(string $marketplaceId): string
    {
        return self::CONTENT_LANGUAGES[strtoupper(trim($marketplaceId))] ?? 'en-US';
    }
}
