<?php

declare(strict_types=1);

/*
| Çözüm sayfaları — YALNIZ kodda var olan özellikler (21 Eki 2026, koddan
| doğrulandı). Olmayan özellik (Yurtiçi/Aras kargo, muhasebe modülü) burada
| "yakında" diye bile YAZILMAZ.
|
| `channels`: kanal listesi adaptör yeteneğinden hesaplanır (arayüz adı) ya da
| sabit kod listesidir — elle tutulan kanal listesi eskir.
| `shot`: panelden gerçek ekran görüntüsü (yerel demo hesabı, 21 Eki 2026).
| `how_shots`: "Nasıl çalışır" adımlarının görselleri (step-shots.php); null =
| o adımın gösterilecek gerçek ekranı yok → satırda görsel gösterilmez.
*/

return [
    'stok-senkronu' => [
        'en_slug' => 'inventory-sync',
        'icon' => 'stock',
        'channels' => 'SupportsInventory',
        'shot' => 'images/site/cozum/stok.jpg',
        'how_shots' => ['ice-aktar', 'siparis-stok', 'urun-kanallar'],
        'tr' => [
            'menu' => 'Stok senkronu',
            'menu_hint' => 'Tek stok, fazla satışa karşı koruma',
            'title' => 'Stok senkronu ve fazla satış koruması',
            'description' => 'Bütün kanallarında tek stok: bir kanalda satış olunca diğerlerinde de düşer. Kanaldaki farkı düzenli kontrolle yakalar, fazla satışı işaretler.',
            'h1' => 'Bir kanalda satılan ürün, diğerlerinde de düşsün.',
            'lead' => '34Pazar her ürün için tek bir stok tutar. Trendyol\'da, Shopify\'da ya da başka bir kanalda sipariş gelince stok düşer ve yeni sayı bağlı bütün kanallara gider.',
            'problems' => [
                'Aynı ürün iki kanalda birden satılıyor, elinde tek tane var.',
                'Her kanalda stoğu elle düzeltmek saatler alıyor ve bir yerde unutuluyor.',
                'Kanaldaki stok sessizce senin sayından ayrılıyor ve kimse fark etmiyor.',
            ],
            'how' => [
                ['Ürünlerini içe aktar', 'Kanallarındaki ürünler SKU ile eşleşir; stok tek yerde toplanır.'],
                ['Sipariş gelince stok düşer', 'Siparişler kanallardan düzenli olarak alınır, her satış stoktan bir kez düşülür.'],
                ['Yeni sayı her kanala gider', 'Mutabakat turu kanaldaki stoğu okur; fark varsa panelde görünür ve düzeltilir.'],
            ],
            'faqs' => [
                ['q' => 'Aynı ürün iki kanalda aynı anda satılırsa ne olur?', 'a' => 'İki sipariş de kaydedilir. Stok sıfırın altına inerse sipariş "fazla satış" olarak işaretlenir ve ana sayfanda en üstte görünür. Kanallara eksi sayı gönderilmez, sıfır gönderilir.'],
                ['q' => 'Stok kanala ne kadar sürede gider?', 'a' => 'Değişiklikten sonra iş kuyruğuyla gönderilir; kanalın hız sınırına göre genelde birkaç dakika içinde. Siparişler kanallardan 5 dakikada bir alınır.'],
                ['q' => 'Stoğu kanala göndermeyi kapatabilir miyim?', 'a' => 'Evet. Her bağlantıda stok, fiyat ve ürün yayını için ayrı izin vardır. Yeni bağlantılarda bu izinler kapalı başlar; sen açarsın.'],
            ],
        ],
        'en' => [
            'menu' => 'Inventory sync',
            'menu_hint' => 'One stock count, no overselling',
            'title' => 'Inventory sync and overselling protection',
            'description' => 'One stock count across all your channels: a sale on one channel reduces stock on the others. Regular checks catch channel drift and flag overselling.',
            'h1' => 'Sold on one channel, reduced on all of them.',
            'lead' => '34Pazar keeps a single stock count per product. When an order arrives on Trendyol, Shopify or any other connected channel, stock goes down and the new number is sent to every connected channel.',
            'problems' => [
                'The same item sells on two channels while you only have one.',
                'Fixing stock by hand on every channel takes hours and something gets missed.',
                'Channel stock silently drifts away from your real count.',
            ],
            'how' => [
                ['Import your products', 'Products on your channels are matched by SKU; stock lives in one place.'],
                ['Orders reduce stock', 'Orders are fetched from channels regularly and each sale is deducted exactly once.'],
                ['The new number goes everywhere', 'A reconciliation pass reads channel stock; differences show up in the panel.'],
            ],
            'faqs' => [
                ['q' => 'What if the same item sells on two channels at once?', 'a' => 'Both orders are recorded. If stock goes below zero the order is flagged as oversold and shown first on your home screen. Channels never receive a negative number; they get zero.'],
                ['q' => 'How quickly does stock reach a channel?', 'a' => 'Changes are sent through a job queue, usually within a few minutes depending on the channel\'s rate limit. Orders are fetched from channels every 5 minutes.'],
                ['q' => 'Can I stop sending stock to a channel?', 'a' => 'Yes. Each connection has separate switches for stock, price and product publishing. New connections start with them off.'],
            ],
        ],
    ],

    'siparis-yonetimi' => [
        'en_slug' => 'order-management',
        'icon' => 'orders',
        'channels' => 'SupportsOrders',
        'shot' => 'images/site/cozum/siparis.jpg',
        'how_shots' => ['kanal-bagla', 'siparis-liste', 'siparis-kalemler'],
        'tr' => [
            'menu' => 'Sipariş yönetimi',
            'menu_hint' => 'Bütün kanalların siparişi tek listede',
            'title' => 'Sipariş yönetimi',
            'description' => 'Pazaryeri ve e-ticaret sitesi siparişlerin tek listede. İptal ve iadeler stoğa doğru yansır; eşleşmeyen ürünlü sipariş kaybolmaz.',
            'h1' => 'Bütün siparişlerin tek listede.',
            'lead' => 'Her kanalın siparişi aynı ekrana düşer: durumu, tutarı, kalemleri ve kargosu. İptal edilen siparişin stoğu geri eklenir; aynı iptal iki kez sayılmaz.',
            'problems' => [
                'Her pazaryerinin paneline ayrı ayrı girip sipariş kontrol ediyorsun.',
                'İptal edilen siparişin stoğu geri eklenmiyor ya da iki kez ekleniyor.',
                'Tanımadığı ürünü içeren sipariş sistemde kayboluyor.',
            ],
            'how' => [
                ['Kanalını bağla', 'Siparişler 5 dakikada bir alınır; webhook sunan kanallarda anında gelir.'],
                ['Sipariş tek listede', 'Kanal, durum ve kargo durumuna göre süz; ayrıntıda kalemleri gör.'],
                ['İptal ve iade stoğa yansır', 'Her olay bir kez işlenir; eşleşmeyen kalem bekler, ürün eklenince düşülür.'],
            ],
            'faqs' => [
                ['q' => 'Müşterinin adını ve adresini saklıyor musunuz?', 'a' => 'Hayır. Siparişte yalnız bir müşteri referansı tutulur; kişisel alanlar saklanmadan önce maskelenir.'],
                ['q' => 'Sipariş tanımadığım bir ürün içeriyorsa?', 'a' => 'Sipariş yine kaydedilir; o kalem "stok düşülmedi" olarak bekler. Aynı SKU ile ürünü eklediğinde stok düşülür.'],
                ['q' => 'Kısmi iade ne olur?', 'a' => 'Yalnız iade edilen adet işlenir; siparişin tamamı stoğa geri eklenmez.'],
            ],
        ],
        'en' => [
            'menu' => 'Order management',
            'menu_hint' => 'Every channel\'s orders in one list',
            'title' => 'Order management',
            'description' => 'Marketplace and online store orders in one list. Cancellations and returns update stock correctly; orders with unmatched products are never lost.',
            'h1' => 'All your orders in one list.',
            'lead' => 'Every channel\'s orders land on one screen with status, totals, items and shipping. Stock from a cancelled order is added back, and the same cancellation is never counted twice.',
            'problems' => [
                'You log in to every marketplace separately to check orders.',
                'Stock from cancelled orders is not added back, or added twice.',
                'Orders with products the system doesn\'t know get lost.',
            ],
            'how' => [
                ['Connect your channel', 'Orders are fetched every 5 minutes; channels with webhooks deliver instantly.'],
                ['One order list', 'Filter by channel, status and shipping; open an order to see its items.'],
                ['Cancellations and returns update stock', 'Each event is processed once; unmatched items wait until the product exists.'],
            ],
            'faqs' => [
                ['q' => 'Do you store customer names and addresses?', 'a' => 'No. Orders keep only a customer reference; personal fields are masked before storage.'],
                ['q' => 'What if an order contains a product I don\'t have?', 'a' => 'The order is still recorded and that item waits as "stock not deducted". Once you add the product with the same SKU, stock is deducted.'],
                ['q' => 'What about partial returns?', 'a' => 'Only the returned quantity is processed; the whole order is not added back to stock.'],
            ],
        ],
    ],

    'e-fatura' => [
        'en_slug' => 'e-invoicing',
        'icon' => 'scale',
        'channels' => 'SupportsInvoiceData',
        'shot' => 'images/site/cozum/e-fatura.jpg',
        'how_shots' => ['parasut-bagla', 'fatura-kes', null],
        'tr' => [
            'menu' => 'E-fatura (Paraşüt)',
            'menu_hint' => 'Siparişin faturası Paraşüt\'ten',
            'title' => 'E-fatura ve e-arşiv — Paraşüt entegrasyonu',
            'description' => 'Siparişin faturasını kendi Paraşüt hesabından kes: alıcı mükellefse e-fatura, değilse e-arşiv. İptal ve iade kaydı, pazaryerine fatura bağlantısı.',
            'h1' => 'Siparişin faturası, kendi Paraşüt hesabından.',
            'lead' => 'Paraşüt hesabını bağla; sipariş ekranından faturayı kes. Alıcı e-fatura mükellefiyse e-fatura, değilse e-arşiv kesilir. Alıcının adı, adresi ve kimlik numarası fatura anında kanaldan okunur, 34Pazar\'da saklanmaz.',
            'problems' => [
                'Her siparişin faturasını elle muhasebe programına giriyorsun.',
                'Pazaryerine fatura yüklemeyi unutuyorsun.',
                'İptal ve iade faturaları ayrı bir iş olarak birikiyor.',
            ],
            'how' => [
                ['Paraşüt\'ü bağla', 'Fatura ve muhasebe ekranından Paraşüt hesabınla yetki verirsin; şifren bize gelmez.'],
                ['Siparişten fatura kes', 'Tek tek, toplu ya da otomatik. Planına göre aylık fatura hakkı vardır.'],
                ['Kanala ilet', 'Trendyol\'a fatura dosyası, Hepsiburada ve n11\'e kalıcı fatura bağlantısı gider.'],
            ],
            'faqs' => [
                ['q' => 'Fatura iptali nasıl çalışır?', 'a' => 'İptal edilen siparişin e-arşiv faturası, iptal süresi geçmemişse Paraşüt üzerinden iptal edilir. E-fatura iptal edilmez; alıcı iade faturası keser.'],
                ['q' => 'İade faturası?', 'a' => 'Sonuçlanan iade talebinde kabul edilen kalemler Paraşüt\'e iade kaydı olarak yazılır. Alıcı e-fatura mükellefiyse iade faturasını alıcı keser.'],
                ['q' => 'Fatura PDF\'leri sizde mi duruyor?', 'a' => 'Hayır. Pazaryerine giden kalıcı bağlantı her açılışta Paraşüt\'ten güncel PDF\'e yönlendirir; dosya bizde saklanmaz.'],
            ],
        ],
        'en' => [
            'menu' => 'E-invoicing (Paraşüt)',
            'menu_hint' => 'Invoices from your Paraşüt account',
            'title' => 'E-invoicing for Turkey — Paraşüt integration',
            'description' => 'Issue Turkish e-invoices (e-Fatura / e-Arşiv) from your own Paraşüt account, record cancellations and returns, and send invoice links to marketplaces.',
            'h1' => 'Invoices from your own Paraşüt account.',
            'lead' => 'Connect Paraşüt and issue the invoice from the order screen: e-Fatura for registered buyers, e-Arşiv for everyone else. Buyer details are read from the channel at invoicing time and not stored by 34Pazar.',
            'problems' => [
                'You enter every invoice into your accounting software by hand.',
                'You forget to upload invoices to the marketplace.',
                'Cancellation and return invoices pile up as separate work.',
            ],
            'how' => [
                ['Connect Paraşüt', 'Authorise with your Paraşüt account; your password never reaches us.'],
                ['Invoice from the order', 'One by one, in bulk or automatically, within your plan\'s monthly allowance.'],
                ['Send to the channel', 'Trendyol receives the file; Hepsiburada and n11 receive a permanent invoice link.'],
            ],
            'faqs' => [
                ['q' => 'How do cancellations work?', 'a' => 'For a cancelled order, the e-Arşiv invoice is cancelled in Paraşüt if the cancellation window is still open. e-Fatura invoices are not cancelled; the buyer issues a return invoice.'],
                ['q' => 'And returns?', 'a' => 'Accepted items of a settled return request are recorded in Paraşüt as a return. If the buyer is an e-Fatura taxpayer, the buyer issues the return invoice.'],
                ['q' => 'Do you store invoice PDFs?', 'a' => 'No. The permanent link sent to marketplaces redirects to a fresh PDF from Paraşüt every time; no file is stored on our side.'],
            ],
        ],
    ],

    'fiyat-kurallari' => [
        'en_slug' => 'pricing-rules',
        'icon' => 'price',
        'channels' => 'SupportsPricing',
        'shot' => 'images/site/cozum/fiyat-kurali.jpg',
        'how_shots' => ['fiyat-kurali', 'kampanya', 'zarar-korumasi'],
        'tr' => [
            'menu' => 'Fiyat kuralları ve kampanya',
            'menu_hint' => 'Kanal farkı, süreli indirim, zarar koruması',
            'title' => 'Fiyat kuralları, süreli kampanya ve zarar koruması',
            'description' => 'Kanala göre yüzde veya tutar farkı, ,90 / ,99 yuvarlama, tarihli kampanya ve maliyetin altına düşen fiyatı durduran zarar koruması.',
            'h1' => 'Her kanala kendi fiyatı, zarar etmeden.',
            'lead' => 'Bir kez kural yaz: "Trendyol\'da +%15, ,90\'a yuvarla". Süreli kampanya başlangıçta indirimi uygular, bitince fiyatı geri getirir. Fiyat alış maliyeti ve kâr tabanının altına düşerse kanala gitmez.',
            'problems' => [
                'Komisyon farkı için her kanalda fiyatı elle hesaplıyorsun.',
                'Kampanya bitince eski fiyata dönmeyi unutuyorsun.',
                'Yanlış yazılan bir fiyat (1999 yerine 199) zararına satışa dönüşüyor.',
            ],
            'how' => [
                ['Kanal kuralını tanımla', 'Yüzde ve/veya tutar farkı, yuvarlama (x,90 · x,99 · tam sayı).'],
                ['Kampanya planla', 'Tarih aralığı, yüzde ya da tutar indirimi, ürün ve kanal seçimi.'],
                ['Zarar korumasını aç', 'Alış maliyeti ve en az kâr yüzdesi: tabanın altındaki fiyat gönderilmez, neden panelde yazar.'],
            ],
            'faqs' => [
                ['q' => 'Kanalın para birimi farklıysa?', 'a' => 'Kur çevirisi yapılmaz. Kanal EUR, ürün fiyatın TRY ise fiyat gönderilmez ve panelde "kanal fiyatı girin" uyarısı çıkar. Kanal fiyatını kanalın biriminde girersen o gider.'],
                ['q' => 'Ürüne kanala özel fiyat girebilir miyim?', 'a' => 'Evet. Elle girilen kanal fiyatı kuralın önüne geçer; zarar koruması onu da denetler.'],
                ['q' => 'İki kampanya çakışırsa?', 'a' => 'Müşteriye en düşük fiyatı veren kampanya uygulanır.'],
            ],
        ],
        'en' => [
            'menu' => 'Pricing rules and campaigns',
            'menu_hint' => 'Channel markup, timed discounts, loss guard',
            'title' => 'Pricing rules, timed campaigns and loss protection',
            'description' => 'Per-channel percentage or fixed markup, .90 / .99 rounding, dated campaigns, and a loss guard that stops prices below cost plus margin.',
            'h1' => 'Each channel its own price, without selling at a loss.',
            'lead' => 'Write a rule once: "Trendyol +15%, round to .90". A timed campaign applies the discount at start and restores the price at the end. A price below cost plus your minimum margin is not sent.',
            'problems' => [
                'You calculate commission markups by hand on every channel.',
                'You forget to restore prices when a campaign ends.',
                'A typo (199 instead of 1999) turns into a loss-making sale.',
            ],
            'how' => [
                ['Define a channel rule', 'Percentage and/or fixed markup, rounding (x.90 · x.99 · whole).'],
                ['Schedule a campaign', 'Date range, percentage or fixed discount, products and channels.'],
                ['Turn on the loss guard', 'Cost and minimum margin: prices below the floor are not sent and the reason appears in the panel.'],
            ],
            'faqs' => [
                ['q' => 'What if the channel uses another currency?', 'a' => 'There is no currency conversion. If the channel is EUR and your price is TRY, the price is not sent and the panel asks for a channel price. A channel price entered in the channel\'s currency is sent.'],
                ['q' => 'Can I set a channel-specific price?', 'a' => 'Yes. A manual channel price overrides the rule; the loss guard still checks it.'],
                ['q' => 'What if two campaigns overlap?', 'a' => 'The campaign giving the lowest price applies.'],
            ],
        ],
    ],

    'tedarikci-xml' => [
        'en_slug' => 'supplier-xml',
        'icon' => 'link',
        'channels' => null,
        'shot' => 'images/site/cozum/tedarikci.jpg',
        'how_shots' => ['tedarikci-ekle', null, null],
        'tr' => [
            'menu' => 'Tedarikçi XML',
            'menu_hint' => 'Toptancı listesinden ürün, stok, fiyat',
            'title' => 'Tedarikçi XML entegrasyonu',
            'description' => 'Toptancının XML ürün listesini bağla: yeni ürünleri aç, stok ve fiyatı düzenli güncelle. Her değişikliği önce önizle, sonra uygula.',
            'h1' => 'Toptancının listesi, mağazanın stoğu olsun.',
            'lead' => 'Tedarikçinin XML adresini gir; 34Pazar listeyi düzenli olarak okur. Yeni ürünler, stok ve fiyat değişiklikleri önce önizlemede görünür, onaylayınca uygulanır.',
            'problems' => [
                'Toptancının stok listesini her gün elle mağazaya aktarıyorsun.',
                'Tedarikçide biten ürün sende satılmaya devam ediyor.',
                'Bozuk bir XML bütün kataloğunu sıfırlıyor.',
            ],
            'how' => [
                ['XML adresini ekle', 'Adresi gir; ilk okumada gelecek ürünleri ve değişiklikleri önizle.'],
                ['Stok politikasını seç', 'Tedarikçi stoğu bilgi amaçlı mı, yoksa senin stoğunu mu belirlesin — sen seçersin.'],
                ['Düzenli güncelleme', 'Değişen kayıtlar işlenir; listede bir kaydın yokluğu "stok sıfır" sayılmaz.'],
            ],
            'faqs' => [
                ['q' => 'XML bozuk gelirse?', 'a' => 'Akış uygulanmadan önce doğrulanır; bozuk liste hiçbir ürüne dokunmaz.'],
                ['q' => 'Tedarikçi ürünü listeden kaldırırsa?', 'a' => 'Yokluk sıfır stok sayılmaz; yanlışlıkla bütün ürünlerin satıştan kalkmasını önler.'],
                ['q' => 'Fiyata kâr ekleyebilir miyim?', 'a' => 'Kanal fiyat kuralları tedarikçiden gelen fiyata da uygulanır.'],
            ],
        ],
        'en' => [
            'menu' => 'Supplier XML',
            'menu_hint' => 'Products, stock and prices from a feed',
            'title' => 'Supplier XML feed integration',
            'description' => 'Connect your wholesaler\'s XML product feed: create new products and update stock and prices regularly. Preview every change before applying it.',
            'h1' => 'Turn your supplier\'s feed into your store\'s stock.',
            'lead' => 'Enter the supplier\'s XML address and 34Pazar reads it regularly. New products, stock and price changes appear in a preview first and are applied when you confirm.',
            'problems' => [
                'You copy the wholesaler\'s stock list into your store by hand every day.',
                'Items out of stock at the supplier keep selling in your store.',
                'A broken XML wipes your whole catalog.',
            ],
            'how' => [
                ['Add the XML address', 'Enter the address and preview the products and changes from the first read.'],
                ['Choose a stock policy', 'Supplier stock as information only, or as your stock — your choice.'],
                ['Regular updates', 'Changed records are processed; a missing record is not treated as zero stock.'],
            ],
            'faqs' => [
                ['q' => 'What if the XML is broken?', 'a' => 'The feed is validated before anything is applied; a broken feed touches no products.'],
                ['q' => 'What if the supplier removes a product?', 'a' => 'Absence is not treated as zero stock, so your whole catalog is never taken off sale by mistake.'],
                ['q' => 'Can I add a margin?', 'a' => 'Channel pricing rules also apply to supplier prices.'],
            ],
        ],
    ],

    'excel-toplu-duzenleme' => [
        'en_slug' => 'bulk-edit',
        'icon' => 'upload',
        'channels' => null,
        'shot' => 'images/site/cozum/excel.jpg',
        'how_shots' => ['excel-indir', 'excel-yukle'],
        'tr' => [
            'menu' => 'Excel ile toplu düzenleme',
            'menu_hint' => 'Stok ve fiyatı tabloda güncelle',
            'title' => 'Excel ile toplu stok ve fiyat düzenleme',
            'description' => 'Ürünlerini Excel\'e indir, stok ve fiyatı tabloda değiştir, yükle. Değişiklikler önce önizlenir; arada değişen satır üzerine yazılmaz.',
            'h1' => 'Yüzlerce ürünü tek tabloda güncelle.',
            'lead' => 'Ürün listeni Excel olarak indir, değiştir, geri yükle. 34Pazar neyin değişeceğini önce gösterir; onaylayınca uygular ve kanallara gönderir.',
            'problems' => [
                'Fiyat güncellemesi için yüzlerce ürünü tek tek açıyorsun.',
                'Toplu yükleme bir hatayla bütün listeyi bozuyor.',
                'Sen tabloyu düzenlerken gelen satış eski sayıyla eziliyor.',
            ],
            'how' => [
                ['Excel\'i indir', 'Ürünler, SKU, stok ve fiyatla birlikte.'],
                ['Tabloda değiştir', 'Yalnız değiştirdiğin hücreler işlenir.'],
                ['Önizle ve uygula', 'İndirdiğinden sonra değişmiş satırlar uygulanmaz; seni uyarır.'],
            ],
            'faqs' => [
                ['q' => 'Tabloyu düzenlerken satış gelirse?', 'a' => 'İndirdiğinden sonra değişen satır uygulanmaz ve önizlemede uyarı görürsün; yeni sayı ezilmez.'],
                ['q' => 'Yanlış dosya yüklersem?', 'a' => 'Önce önizleme gelir; onaylamadan hiçbir şey değişmez.'],
                ['q' => 'Değişiklik kanallara gider mi?', 'a' => 'Evet, bağlantının stok ve fiyat izinleri açıksa.'],
            ],
        ],
        'en' => [
            'menu' => 'Bulk edit with Excel',
            'menu_hint' => 'Update stock and prices in a sheet',
            'title' => 'Bulk stock and price editing with Excel',
            'description' => 'Download your products to Excel, change stock and prices in the sheet and upload it. Changes are previewed first; rows changed in the meantime are not overwritten.',
            'h1' => 'Update hundreds of products in one sheet.',
            'lead' => 'Download your product list as Excel, edit it and upload it back. 34Pazar shows what will change first, then applies it and sends it to your channels.',
            'problems' => [
                'You open hundreds of products one by one to update prices.',
                'One mistake in a bulk upload breaks the whole list.',
                'A sale arriving while you edit gets overwritten with the old number.',
            ],
            'how' => [
                ['Download the sheet', 'Products with SKU, stock and price.'],
                ['Edit in the sheet', 'Only the cells you change are processed.'],
                ['Preview and apply', 'Rows changed after your download are not applied; you are warned.'],
            ],
            'faqs' => [
                ['q' => 'What if a sale arrives while I edit?', 'a' => 'Rows changed after your download are not applied and the preview warns you; the new number is not overwritten.'],
                ['q' => 'What if I upload the wrong file?', 'a' => 'You get a preview first; nothing changes until you confirm.'],
                ['q' => 'Do changes reach my channels?', 'a' => 'Yes, if the connection\'s stock and price permissions are on.'],
            ],
        ],
    ],

    'kargo-bildirimi' => [
        'en_slug' => 'shipment-tracking',
        'icon' => 'truck',
        'channels' => 'SupportsFulfillment',
        'shot' => 'images/site/cozum/siparis-detay.jpg',
        'how_shots' => ['siparis-ac', 'kargo-gir', 'siparis-gecmis'],
        'tr' => [
            'menu' => 'Kargo bildirimi',
            'menu_hint' => 'Takip numarası kanala gider',
            'title' => 'Kargo takip numarası bildirimi',
            'description' => 'Takip numarasını siparişte bir kez yaz; numara kanala iletilir ve sipariş orada da kargolandı olarak işaretlenir. Gönderilemezse nedeni siparişte görünür.',
            'h1' => 'Takip numarasını bir kez yaz.',
            'lead' => 'Sipariş ekranında kargo firmasını ve takip numarasını gir. 34Pazar numarayı kanala gönderir; kanal destekliyorsa sipariş orada da kargolandı olur. Gönderilemezse sebebi ve "tekrar gönder" düğmesi siparişte görünür.',
            'problems' => [
                'Her kanalın panelinde takip numarasını ayrı ayrı giriyorsun.',
                'Yeniden deneme aynı siparişe ikinci paket açıyor.',
                'Numara gitmedi mi gitti mi, bilmiyorsun.',
            ],
            'how' => [
                ['Siparişi aç', 'Kargo firmasını seç, takip numarasını yaz.'],
                ['Kanala gönderilir', 'Destekleyen kanallarda önce kanaldaki kargo kaydı okunur; aynı numara ikinci paket açmaz.'],
                ['Durumu izle', 'Gönderildi / gönderilemedi siparişte yazar; düzeltip tekrar gönderebilirsin.'],
            ],
            'faqs' => [
                ['q' => 'Hangi kanallarda çalışır?', 'a' => 'Aşağıdaki listede olan kanallarda. Listede olmayan kanalda numarayı kanalın kendi panelinden girersin.'],
                ['q' => 'Kargo firmam kanalın listesinde yoksa?', 'a' => 'Kanalın "Diğer" seçeneğiyle gönderilir.'],
                ['q' => 'Kargo etiketi basıyor musunuz?', 'a' => 'Hayır. 34Pazar takip numarasını iletir; etiketi kargo firmanın sisteminden alırsın.'],
            ],
        ],
        'en' => [
            'menu' => 'Shipment tracking',
            'menu_hint' => 'Tracking numbers reach the channel',
            'title' => 'Shipment tracking number sync',
            'description' => 'Enter the tracking number once on the order; it is sent to the channel and the order is marked shipped there too. If it fails, the reason shows on the order.',
            'h1' => 'Enter the tracking number once.',
            'lead' => 'Pick the carrier and enter the tracking number on the order screen. 34Pazar sends it to the channel; where supported the order is marked shipped there too. If it fails, the reason and a "send again" button appear on the order.',
            'problems' => [
                'You type tracking numbers into every channel\'s panel separately.',
                'A retry opens a second package on the same order.',
                'You don\'t know whether the number arrived.',
            ],
            'how' => [
                ['Open the order', 'Choose the carrier and enter the tracking number.'],
                ['It goes to the channel', 'Where supported, the channel\'s shipment records are read first, so the same number never opens a second package.'],
                ['Follow the status', 'Sent / failed shows on the order; fix and resend if needed.'],
            ],
            'faqs' => [
                ['q' => 'Which channels are supported?', 'a' => 'The channels listed below. On other channels you enter the number in the channel\'s own panel.'],
                ['q' => 'My carrier isn\'t in the channel\'s list?', 'a' => 'It is sent with the channel\'s "Other" option.'],
                ['q' => 'Do you print shipping labels?', 'a' => 'No. 34Pazar sends the tracking number; labels come from your carrier\'s system.'],
            ],
        ],
    ],

    'yurtdisi-pazaryerleri' => [
        'en_slug' => 'international-marketplaces',
        'icon' => 'map',
        'channels' => ['amazon', 'ebay', 'etsy', 'kaufland', 'otto'],
        'shot' => 'images/site/cozum/kanallar.jpg',
        'how_shots' => ['kanal-bagla-yurtdisi', 'fiyat-kurali', 'siparis-liste'],
        'tr' => [
            'menu' => 'Yurtdışı pazaryerleri',
            'menu_hint' => 'Amazon, eBay, Etsy, OTTO, Kaufland',
            'title' => 'Yurtdışı pazaryerleri: Amazon, eBay, Etsy, OTTO, Kaufland',
            'description' => 'Avrupa ve dünya pazaryerlerinde stok, fiyat ve sipariş tek panelden. Kanalın para birimi farklıysa yanlış fiyat gitmez.',
            'h1' => 'Türkiye\'den dünyaya, aynı stokla.',
            'lead' => 'Amazon, eBay, Etsy, OTTO Market ve Kaufland\'ı Türkiye\'deki kanallarınla aynı stoğa bağla.',
            'problems' => [
                'Yurtdışı satış için ayrı bir stok tablosu tutuyorsun.',
                'TL fiyat yurtdışı kanala aynı rakamla gidiyor.',
                'Her pazaryerinin farklı panelini ayrı ayrı izliyorsun.',
            ],
            'how' => [
                ['Kanalı bağla', 'Kaufland\'da API anahtarı, Amazon/eBay/Etsy/OTTO\'da kanalın kendi izin ekranı.'],
                ['Kanal fiyatını gir', 'Kanalın para biriminde (EUR, USD…) fiyat girersin; kur çevirisi yapılmaz.'],
                ['Stok ve sipariş tek yerde', 'Yurtdışı siparişi de stoktan düşer; Türkiye kanalların güncellenir.'],
            ],
            'faqs' => [
                ['q' => 'Kur çevirisi yapıyor musunuz?', 'a' => 'Hayır. Kanalın para birimi ürün fiyatından farklıysa fiyat gönderilmez ve panel kanal fiyatı girmeni ister. Böylece TL fiyat yanlışlıkla euro olarak satılmaz.'],
                ['q' => 'Yeni ürünü yurtdışı kanalda açabilir miyim?', 'a' => 'eBay\'de ürün yayını var. Amazon, Kaufland ve OTTO\'da şimdilik kanaldaki mevcut ürünlerinin stok ve fiyatını yönetirsin.'],
            ],
        ],
        'en' => [
            'menu' => 'International marketplaces',
            'menu_hint' => 'Amazon, eBay, Etsy, OTTO, Kaufland',
            'title' => 'International marketplaces: Amazon, eBay, Etsy, OTTO, Kaufland',
            'description' => 'Stock, prices and orders on European and global marketplaces from one panel. If the channel uses another currency, a wrong price is never sent.',
            'h1' => 'Sell abroad from the same stock.',
            'lead' => 'Connect Amazon, eBay, Etsy, OTTO Market and Kaufland to the same stock as your other channels.',
            'problems' => [
                'You keep a separate stock sheet for international sales.',
                'Your TRY price goes to a EUR channel with the same number.',
                'You watch each marketplace\'s panel separately.',
            ],
            'how' => [
                ['Connect the channel', 'API keys for Kaufland; the channel\'s own consent screen for Amazon, eBay, Etsy and OTTO.'],
                ['Enter channel prices', 'In the channel\'s currency (EUR, USD…); there is no currency conversion.'],
                ['One stock, one order list', 'International orders reduce the same stock; your other channels are updated.'],
            ],
            'faqs' => [
                ['q' => 'Do you convert currencies?', 'a' => 'No. If the channel\'s currency differs from your product price, the price is not sent and the panel asks for a channel price, so a TRY price is never sold as euros by mistake.'],
                ['q' => 'Can I create new listings abroad?', 'a' => 'eBay supports publishing. On Amazon, Kaufland and OTTO you currently manage stock and prices of your existing listings.'],
            ],
        ],
    ],
];
