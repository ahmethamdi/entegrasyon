<?php

declare(strict_types=1);

/*
| Doğrudan kargo firması entegrasyonları (S6 · Kargo II).
|
| Adresler firmaların kendi WSDL'lerindeki `soap:address` değerleridir
| (23 Eki 2026 okundu; bkz. docs/YURTICI-API-NOTLARI.md, docs/ARAS-API-NOTLARI.md).
| "test" ortamı firmanın herkese açık test sunucusudur — satıcı hesabını
| denemek için seçilebilir, gerçek gönderi açmaz.
*/

return [
    // Acil kapatma anahtarı. Açıkken özellik panelde normal görünür
    // (23 Eki kararı: Beta rozeti kaldırıldı).
    // Kapalıyken kargo ekranı/rotaları 404, sipariş ekranında bölüm yok ve
    // yoklama turu firmaya istek GÖNDERMEZ. Firmaya istek zaten yalnız
    // satıcı kendi web servis bilgisini girince gider — varsayılan durumda
    // hiçbir dış çağrı yoktur.
    'enabled' => (bool) env('CARRIER_SHIPPING_ENABLED', true),

    'yurtici' => [
        'live' => env('YURTICI_ENDPOINT', 'https://ws.yurticikargo.com/KOPSWebServices/ShippingOrderDispatcherServices'),
        'test' => env('YURTICI_TEST_ENDPOINT', 'http://testwebservices.yurticikargo.com:9090/KOPSWebServices/ShippingOrderDispatcherServices'),
    ],

    'aras' => [
        'live' => env('ARAS_ENDPOINT', 'https://customerws.araskargo.com.tr/arascargoservice.asmx'),
        'test' => env('ARAS_TEST_ENDPOINT', 'https://customerservicestest.araskargo.com.tr/arascargoservice/arascargoservice.asmx'),
    ],

    /*
    | HepsiJet (REST/JSON). Canlı adres partner belgesindeki adrestir;
    | belgedeki test adresi (dmzlastmile.hepsiexpress.com:8091) eskimiş
    | görünüyor — test adresi açık kaynak istemcilerin ortak kullandığı
    | adrestir ve 13 Kas 2026 itibarıyla GERÇEK istekle DOĞRULANMADI
    | (docs/HEPSIJET-API-NOTLARI.md).
    */
    'hepsijet' => [
        'live' => env('HEPSIJET_ENDPOINT', 'https://integration.hepsijet.com'),
        'test' => env('HEPSIJET_TEST_ENDPOINT', 'https://integration-apitest.hepsijet.com'),
        // Belge: token 60 dk geçerli. Süre dolmadan yenilemek için biraz kısa tutulur.
        'token_ttl_minutes' => (int) env('HEPSIJET_TOKEN_TTL', 50),
    ],

    // Yeni bağlantıya SUNULMAYAN firmalar (virgüllü kod listesi, ör. "aras").
    // Kanallardaki CHANNELS_WITHHELD ile aynı desen; mevcut hesaplar etkilenmez.
    'withheld' => array_values(array_filter(array_map('trim', explode(',', (string) env('CARRIERS_WITHHELD', ''))))),

    // Gerçek hesapta DOĞRULANMAMIŞ firmalar (CarrierAccount::UNVERIFIED)
    // varsayılan gizlidir; pilot satıcıyla denemek için burada açılır
    // (virgüllü kod listesi, ör. "hepsijet"). Varsayılan: hiçbiri.
    'unverified_enabled' => array_values(array_filter(array_map('trim', explode(',', (string) env('CARRIERS_UNVERIFIED_ENABLED', ''))))),

    // SOAP/REST isteği zaman aşımı (sn). Firmanın cevabı gecikirse istek
    // belirsiz sayılır — gönderi tekrar AÇILMAZ, aynı anahtarla sorulur.
    'timeout' => (int) env('CARRIER_TIMEOUT', 30),

    // Durum yoklaması: kargoya teslim edilmemiş gönderi en çok bu kadar gün izlenir.
    'track_days' => (int) env('CARRIER_TRACK_DAYS', 30),
];
