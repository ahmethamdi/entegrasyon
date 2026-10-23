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
    // Acil kapatma anahtarı. Açıkken özellik panelde Beta rozetiyle görünür
    // (21 Eki kararı: doğrulanmamış entegrasyonlar Beta olarak açık).
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

    // SOAP isteği zaman aşımı (sn). Firmanın cevabı gecikirse istek
    // belirsiz sayılır — gönderi tekrar AÇILMAZ, aynı anahtarla sorulur.
    'timeout' => (int) env('CARRIER_TIMEOUT', 30),

    // Durum yoklaması: kargoya teslim edilmemiş gönderi en çok bu kadar gün izlenir.
    'track_days' => (int) env('CARRIER_TRACK_DAYS', 30),
];
