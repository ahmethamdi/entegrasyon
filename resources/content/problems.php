<?php

declare(strict_types=1);

/*
| Çözümler — İŞ SORUNUNA göre sayfalar (6 Kas 2026 · site sprint 1).
| Modül sayfaları (`solutions.php`, /cozumler/stok-senkronu vb.) "Özellikler"
| altında aynen durur; bunlar satıcının derdinden başlar.
|
| Kalıp: sorun → çözüm → nasıl çalışır (gerçek ekran) → kanıt → çağrı.
|
| KANIT ELLE YAZILMAZ: `proof.capabilities` × `proof.channels` hücreleri ve
| `proof.services` (kargo, e-fatura) S15 sertifikasyon matrisinden okunur
| (`SiteProof`). `proof.channels` null = paneldeki AÇIK bütün kanallar.
|
| "Hiç fazla satmazsın" DENMEZ (ana sayfadaki kuralla aynı): iki kanalda
| aynı anda satış ya da geç bildirim fazla satışı yine doğurabilir; vaat,
| stoğun birlikte düşmesi ve sorunun hemen görünmesidir.
*/

return [
    'cok-kanalli-stok-yonetimi' => [
        'title' => 'Çok kanallı stok yönetimi',
        'hint' => 'Aynı ürünü iki kanalda birden satma derdi',
        'description' => 'Birden fazla pazaryerinde ve sitende satıyorsan stoğu tek yerde tut: bir kanalda satış olunca diğerlerinde de düşer, fazla satış panelde en üstte görünür.',
        'h1' => 'Tek ürünün var, üç kanalda satılıyor.',
        'lead' => 'Stoğu her kanalın panelinde ayrı ayrı tutunca bir yerde mutlaka eski sayı kalıyor ve aynı ürün iki kez satılıyor. 34Pazar her ürün için tek bir stok tutar ve bağlı kanallara aynı sayıyı gönderir.',
        'problems' => [
            'Elinde bir tane kalmış ürün iki kanalda birden satılıyor, birini iptal etmek zorunda kalıyorsun.',
            'Her satıştan sonra diğer kanallarda stoğu elle düzeltmek saatler alıyor.',
            'Kanaldaki stok sessizce senin sayından ayrılıyor ve sipariş gelene kadar fark etmiyorsun.',
        ],
        'solution' => [
            'Stok tek yerde durur; ürünler kanallarla stok koduyla (SKU) eşleşir.',
            'Sipariş hangi kanaldan gelirse gelsin stok bir kez düşer ve yeni sayı bağlı kanallara gider.',
            'Kanaldaki stok düzenli okunur; fark varsa panelde görünür. Kanala eksi sayı gitmez, sıfır gider.',
            'İki kanalda neredeyse aynı anda satış olursa fazla satış yine olabilir; o sipariş panelde kırmızıyla en üstte görünür.',
        ],
        'how' => [
            ['Ürünleri içe aktar', 'Ürünlerini kanaldan ya da Excel/CSV dosyasından al; stok koduyla eşleşir, aynı ürün iki kez açılmaz.'],
            ['Sipariş stoğu düşer', 'Siparişler kanallardan düzenli alınır; her satış stoktan bir kez düşülür.'],
            ['Her kanal aynı sayıyı görür', 'Ürünün hangi kanallarda olduğunu ve her kanaldaki durumunu tek ekranda görürsün.'],
        ],
        'how_shots' => ['ice-aktar', 'siparis-stok', 'urun-kanallar'],
        'shot' => 'images/site/panel-stok.png',
        'shot_alt' => '34Pazar stok ekranı: ürünlerin tek stok sayısı ve kanallardaki durumu',
        'proof' => [
            'capabilities' => ['inventory', 'order_import'],
            'channels' => null,
            'services' => [],
        ],
        'feature' => 'stok-senkronu',
    ],

    'siparis-ve-kargo-operasyonu' => [
        'title' => 'Sipariş ve kargo operasyonu',
        'hint' => 'Her kanalın siparişine ayrı panelden bakma derdi',
        'description' => 'Bütün kanallarının siparişleri tek listede: kargo bekleyeni süz, takip numarasını bir kez gir. Hangi kanalda neyin doğrulandığını açıkça yazıyoruz.',
        'h1' => 'Siparişler beş ayrı panelde, kargo gecikiyor.',
        'lead' => 'Her pazaryerinin siparişine kendi panelinden bakınca biri gözden kaçıyor ve kargo süresi doluyor. 34Pazar bütün kanallarının siparişini tek listeye toplar.',
        'problems' => [
            'Sabah her kanalın paneline tek tek girip yeni siparişe bakıyorsun.',
            'Takip numarasını her kanalın paneline ayrı ayrı yazıyorsun.',
            'Kargoya verilmeyi bekleyen sipariş başka bir panelde unutuluyor.',
        ],
        'solution' => [
            'Siparişler kanallardan düzenli alınır ve tek listeye düşer; kanal, durum ve tarih ile süzülür.',
            'Paneli açınca önce yapman gerekenleri görürsün: kargolanmayı bekleyen siparişler, fazla satılan ürün.',
            'Takip numarasını siparişin içine bir kez yazarsın; kanal destekliyorsa numara oraya iletilir. Desteklemiyorsa sayfada açıkça yazar.',
        ],
        'how' => [
            ['Siparişler tek listeye düşer', 'Bütün kanalların siparişi aynı tabloda; kargo bekleyenleri tek tıkla süzersin.'],
            ['Siparişi aç', 'Kalemler, alıcı ve kanal bilgisi tek ekranda.'],
            ['Takip numarasını gir', 'Kargo firması ve takip numarası bir kez girilir.'],
        ],
        'how_shots' => ['siparis-liste', 'siparis-kalemler', 'kargo-gir'],
        'shot' => 'images/site/panel-siparisler.png',
        'shot_alt' => '34Pazar sipariş listesi: farklı kanallardan gelen siparişler tek listede',
        'proof' => [
            'capabilities' => ['order_import', 'shipment'],
            'channels' => null,
            'services' => ['Yurtiçi Kargo', 'Aras Kargo'],
        ],
        'feature' => 'siparis-yonetimi',
    ],

    'pazaryerine-acilma' => [
        'title' => 'Pazaryerine açılma',
        'hint' => 'Sitende satıyorsun, Trendyol ve Hepsiburada\'ya açılmak istiyorsun',
        'description' => 'Shopify, WooCommerce ya da başka bir altyapıda satıyorsan ürünlerini 34Pazar\'a al, Trendyol ve Hepsiburada\'ya gönder; stok site ile pazaryeri arasında ortak kalır.',
        'h1' => 'Siten hazır, pazaryerine açılmak istiyorsun.',
        'lead' => 'Ürünlerin zaten sitende. Her birini pazaryerinin paneline yeniden girmek ve iki ayrı stok tutmak yerine ürünü sitenden 34Pazar\'a alır, pazaryerine oradan gönderirsin.',
        'problems' => [
            'Yüzlerce ürünü pazaryerinin paneline tek tek girmek gözünü korkutuyor.',
            'Pazaryeri kategorisi ve zorunlu özellikler yüzünden ürün reddediliyor, sebebini anlamıyorsun.',
            'Site ile pazaryerinde iki ayrı stok tutmaktan çekiniyorsun.',
        ],
        'solution' => [
            'Ürünler sitenden içe aktarılır; stok ve fiyat tek yerde toplanır.',
            'Pazaryerinin kategorisi ve zorunlu özellikleri panelde eşlenir; eksik özellik varsa ürün gönderilmeden önce gösterilir.',
            'Ürün panelden pazaryerine gönderilir; kanal reddederse sebebi panelde yazar, düzeltip tekrar gönderirsin.',
            'Stok site ile pazaryeri arasında ortaktır: hangi kanalda satılırsa diğerinde de düşer.',
        ],
        'how' => [
            ['Siteni ve pazaryerini bağla', 'Bağlantı formu kanalın panelinden hangi bilgiyi alacağını tek tek gösterir.'],
            ['Ürünleri içe aktar', 'Sitendeki ürünler stok koduyla (SKU) eşleşerek gelir.'],
            ['Pazaryerine gönder', 'Ürünün hangi kanalda yayında olduğunu ve kanalın cevabını ürün ekranında görürsün.'],
        ],
        'how_shots' => ['kanal-bagla', 'ice-aktar', 'urun-kanallar'],
        'shot' => 'images/site/cozum/kanallar.jpg',
        'shot_alt' => '34Pazar kanallar ekranı: bağlı site ve pazaryerleri',
        'proof' => [
            'capabilities' => ['product_import', 'publishing'],
            'channels' => ['shopify', 'woocommerce', 'ikas', 'ticimax', 'trendyol', 'hepsiburada'],
            'services' => [],
        ],
        'feature' => null,
    ],
];
