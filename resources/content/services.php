<?php

declare(strict_types=1);

/*
| Hizmetler — 34Devs'in 34Pazar çevresinde VERDİĞİ işler (6 Kas 2026 · site
| sprint 1, kullanıcı kararı). 34Devs bir Shopify Partner ajansıdır.
|
| KURALLAR:
|   - Fiyat YAZILMAZ: her hizmet "Teklif al" ile iletişim formuna gider
|     (konu = Hizmet teklifi, hizmet ön seçili).
|   - Süre, müşteri sayısı, "X mağaza kurduk" gibi ölçülmemiş rakam YOK.
|   - Ücretli paketteki ücretsiz kurulum sözü fiyat sayfasındakiyle AYNI
|     cümledir (ilk kanal + ilk ürün aktarımı); burada genişletilmez.
|   - 34Pazar'ın yapabildiği iş (CSV içe aktarma, kanaldan ürün çekme)
|     koddaki ekranlara dayanır; olmayan araç ("tek tıkla rakipten taşıma")
|     yazılmaz.
|
| `detail` = ayrı sayfası var (/hizmetler/{slug}); yoksa /hizmetler'deki
| bölümü yeterlidir.
*/

return [
    'kurulum-ve-veri-tasima' => [
        'title' => 'Kurulum ve veri taşıma',
        'hint' => 'Excel\'den ya da başka entegratörden geçiş',
        'summary' => 'Ürünlerini, stok kodlarını ve kanal bağlantılarını 34Pazar\'a birlikte taşırız. Başka bir entegratörden ya da Excel tablosundan geliyorsan eski düzenini bozmadan geçersin.',
        'items' => [
            'Eski sisteminden ya da Excel\'den aldığın ürün listesini 34Pazar\'ın içe aktarma şablonuna uyarlarız',
            'Ürünlerin kanallarda zaten açıksa onları kanaldan içe aktarır, stok koduyla (SKU) eşleriz',
            'Kanallarını seninle birlikte bağlarız; anahtarları kendi hesabından sen girersin',
            'İlk sipariş panele düşüp stok diğer kanallarda düşene kadar eşlik ederiz',
        ],
        'detail' => true,
    ],
    'shopify-magaza-kurulumu' => [
        'title' => 'Shopify mağaza kurulumu',
        'hint' => 'Shopify Partner ajansı olarak mağaza açılışı',
        'summary' => '34Devs bir Shopify Partner ajansıdır. Shopify mağazanı tema, ürün, ödeme ve kargo ayarlarıyla kurar, ardından 34Pazar\'a bağlarız.',
        'items' => [
            'Mağaza ayarları: ödeme, kargo, vergi ve dil',
            'Tema kurulumu ve markana göre düzenleme',
            'Ürün, koleksiyon ve menü yapısının hazırlanması',
            'Mağazanın 34Pazar\'a bağlanması ve stok akışının kontrolü',
        ],
        'detail' => false,
    ],
    'woocommerce-magaza-ve-entegrasyon' => [
        'title' => 'WooCommerce mağaza ve entegrasyon',
        'hint' => 'WordPress sitesi kurulumu ya da bağlantısı',
        'summary' => 'WooCommerce mağazanı kurar ya da mevcut siteni düzenler, REST API anahtarlarıyla 34Pazar\'a bağlarız.',
        'items' => [
            'Yeni WooCommerce mağazası ya da mevcut sitenin düzenlenmesi',
            'Ürün ve varyant yapısının stok koduyla (SKU) eşlenmeye hazırlanması',
            'REST API anahtarı ve 34Pazar bağlantısı',
        ],
        'detail' => false,
    ],
    'ozel-entegrasyon' => [
        'title' => 'Özel entegrasyon',
        'hint' => 'ERP, depo ya da tedarikçi API\'si',
        'summary' => 'Paneldeki hazır kanalların dışında kalan bir sistemin varsa (ERP, depo yazılımı, tedarikçinin API\'si) ihtiyacını dinler, yapılabilirliğini ve kapsamını yazılı teklifle çıkarırız.',
        'items' => [
            'ERP ya da muhasebe yazılımıyla stok ve sipariş alışverişi',
            'Depo yazılımı ya da kargo çıkış sürecine bağlantı',
            'Tedarikçinin API\'sinden ya da dosyasından ürün ve stok çekme',
        ],
        'detail' => false,
    ],
    'egitim' => [
        'title' => 'Eğitim',
        'hint' => 'Sana ve ekibine panel kullanımı',
        'summary' => 'Panelin günlük kullanımını sana ve ekibine çevrim içi gösteririz: sipariş, stok, fiyat kuralı ve toplu düzenleme.',
        'items' => [
            'Sipariş ve kargo akışının günlük kullanımı',
            'Stok, fiyat kuralı ve kampanya ekranları',
            'Excel ile toplu düzenleme ve içe aktarma',
            'Ekip üyelerinin davet edilmesi ve yetkileri',
        ],
        'detail' => false,
    ],
];
