---
title: "Fazla satış (overselling) nedir ve çok kanallı satışta nasıl önlenir?"
description: "Elinde olmayan ürünü satmak neden olur, maliyeti nedir ve birden çok kanalda satarken fazla satış riskini hangi adımlarla düşürürsün? Pratik rehber."
published_at: 2026-09-21
tags: [fazla satış, stok yönetimi, çok kanallı satış]
---

Bir sabah iki sipariş bildirimi geliyor: biri Trendyol'dan, biri kendi Shopify mağazandan. İkisi de aynı ürün için, ama rafta yalnızca bir tane kalmış. İşte buna **fazla satış** (İngilizcede *overselling*) denir: elinde olmayan bir ürünü satmış olmak.

Tek kanalda satarken nadir görülen bu durum, ürününü iki, üç ya da beş farklı yerde satmaya başladığında neredeyse kaçınılmaz bir risk haline gelir. Bu yazıda fazla satışın neden olduğunu, sana neye mal olduğunu ve riski nasıl en aza indirebileceğini anlatıyoruz.

## Fazla satış neden olur?

Temel sebep basit: her satış kanalı kendi stok sayısını tutar ve bu sayılar birbirinden habersizdir. Shopify'da "5 adet" yazan ürün, Trendyol'da da "5 adet" görünür. Shopify'dan 3 adet satıldığında Trendyol bunu bilmez; birisi sayıyı oraya da düşürene kadar Trendyol hâlâ 5 adet satmaya hazırdır.

Pratikte fazla satışa yol açan durumlar genellikle şunlardır:

- **Elle güncelleme gecikmesi.** Stoku her kanalda elle düşüyorsan, siparişin geldiği an ile senin sayıyı güncellediğin an arasında geçen her dakika risktir. Hafta sonu, gece ya da yoğun kampanya günlerinde bu süre saatlere uzayabilir.
- **Aynı anda gelen siparişler.** Son birkaç ürün kaldığında iki kanaldan neredeyse aynı saniyede sipariş gelebilir. Otomatik bir sistem bile, iki kanalın birbirine haber verme süresi kadar bir boşluk bırakır.
- **Depo dışı stok hareketleri.** Mağazadan elden satış, hasarlı ürün, numune olarak verilen ürün ya da tedarikçiye iade — bunlar hiçbir kanala kaydedilmezse sistemdeki sayı gerçek raftaki sayıdan sapar.
- **Hatalı eşleştirme.** Aynı ürünün kanallarda farklı stok kodlarıyla (SKU) açılması, bir kanaldaki satışın diğer kanaldaki doğru ürüne yansımamasına yol açar. Bu konuyu [stok kodu düzeni yazımızda](/blog/stok-kodu-sku-duzeni-cok-kanalli-satis) ayrıntılı anlattık.
- **Varyant karmaşası.** Beden ve renk varyantları olan bir üründe, toplam stok doğru görünürken tek bir varyantın stoku yanlış olabilir.

## Fazla satışın maliyeti

Fazla satış yalnızca "bir siparişi iptal etmek" demek değildir. Gerçek maliyeti birkaç katmandan oluşur:

**Müşteri deneyimi.** Ödemesini yapmış, ürünü beklemeye başlamış bir müşteriye "maalesef stokta yok" demek, o müşteriyi büyük ihtimalle kaybetmek demektir. Olumsuz değerlendirme yazma ihtimali de yüksektir.

**Pazaryeri yaptırımları.** Pazaryerleri satıcı kaynaklı iptalleri genellikle satıcı performansına yansıtır. İptal oranı yükseldikçe mağaza puanı düşebilir, ürünlerin görünürlüğü azalabilir ve bazı durumlarda cezai işlem uygulanabilir. Kurallar ve oranlar pazaryerine göre farklıdır ve zaman içinde değişir; güncel kuralları her zaman kendi satıcı panelinden kontrol etmelisin.

**Operasyon yükü.** Her iptal; müşteriye yazmak, iade sürecini başlatmak, kanalda kaydı düzeltmek ve bazen tedarikçiyi arayıp acil ürün bulmaya çalışmak demektir. Bunlar ölçülmesi zor ama gerçek zaman kayıplarıdır.

## Fazla satış nasıl önlenir?

Fazla satışı **sıfıra indirmek** mümkün değildir; amaç riski olabildiğince küçültmek ve olduğunda hemen fark etmektir. Aşağıdaki adımlar, küçükten büyüğe her satıcı için uygulanabilir.

### 1. Tek bir stok kaynağı belirle

En önemli adım budur. Stokun "gerçek" sayısı tek bir yerde durmalı ve tüm kanallar o sayıdan beslenmelidir. Bu yer bir tablo da olabilir, bir entegrasyon paneli de. Önemli olan, her kanalın kendi sayısını değil, ortak sayıyı göstermesidir.

Merkezi bir stok defteri kullandığında, bir kanaldaki satış ortak sayıyı düşürür ve yeni sayı diğer kanallara gönderilir. 34Pazar'da [merkezi stok](/ozellikler) bu şekilde çalışır: Shopify'dan gelen sipariş stoku düşer ve düşen stok bağlı diğer kanallara iletilir.

### 2. Güncelleme süresini kısalt

Kanallar arasındaki gecikme ne kadar kısaysa risk o kadar küçüktür. Elle güncelleme yapıyorsan bunu günde bir kez değil, her sipariş geldiğinde yapmalısın — ki bu da birkaç düzine siparişten sonra sürdürülemez hale gelir. Bu noktada otomatik senkronizasyon zorunluluğa dönüşür.

Otomatik sistemlerde bile senkronizasyon anlık değildir: kanalın siparişi bildirmesi, sistemin işlemesi ve yeni stokun diğer kanala yazılması birkaç saniye ile birkaç dakika arasında sürebilir. Bu pencere küçülür ama kaybolmaz.

### 3. Tampon stok kullan

Son birkaç ürün en riskli bölgedir. Bazı satıcılar kanallara gerçek stokun biraz altında bir sayı gönderir: rafta 3 ürün varsa pazaryerinde 2 gösterir. Bu yöntem satış kaybettirebilir ama özellikle hızlı satan ürünlerde iptal riskini belirgin şekilde azaltır. Hangi ürünlerde ne kadar tampon bırakacağın, ürünün satış hızına ve iptalin sana maliyetine bağlıdır.

### 4. Kanal dışı hareketleri de kaydet

Mağazadan elden satış, hasar, kayıp ya da sayım farkı — her biri stok kaynağına işlenmelidir. "Sonra düzeltirim" denen her hareket, sistemdeki sayıyı gerçeğinden uzaklaştırır. Düzenli aralıklarla (örneğin ayda bir) fiziksel sayım yapıp sistemle karşılaştırmak da iyi bir alışkanlıktır.

### 5. Stok kodlarını tutarlı tut

Bir ürünün her kanalda aynı SKU ile tanımlanması, satışların doğru ürüne düşmesinin ön koşuludur. Kanallarda farklı kodlar kullanıyorsan, önce bunları eşleştirmen gerekir. Eşleşmemiş bir ürün, otomatik sistemlerin bile göremediği bir kör noktadır.

### 6. Fazla satışı fark edecek bir uyarı kur

Tüm önlemlere rağmen fazla satış yaşanabilir. Önemli olan bunu müşteriden önce senin fark etmendir. Sipariş geldiğinde stok sıfırın altına düşüyorsa bunun işaretlenmesi, sana müşteriye ulaşmak, alternatif sunmak ya da tedarik bulmak için zaman kazandırır.

34Pazar, gelen bir siparişin mevcut stoku aştığı durumları **fazla satış** olarak işaretler; böylece sorunlu siparişi listede kaybolmadan görürsün. Bu, fazla satışı engellemez — aynı anda gelen iki sipariş yine de çakışabilir — ama sessizce geçmesini önler.

## Hangi durumlarda risk artar?

Bazı dönemler ve ürün tipleri fazla satışa daha yatkındır. Bunları bilmek, önlemlerini ne zaman sıkılaştıracağını gösterir:

- **Kampanya ve indirim günleri:** Sipariş hızı arttıkça iki kanalın aynı anda satış yapma ihtimali de artar.
- **Az stoklu, hızlı satan ürünler:** Son 1–3 adet en kritik bölgedir.
- **Varyantlı ürünler:** Tek bir beden ya da renk, toplam stoktan çok önce tükenebilir.
- **Yeni bağlanan kanallar:** İlk senkronizasyon sırasında kanallardaki sayılar birbirinden farklıysa, hangisinin doğru olduğuna karar vermeden satışa açmak risklidir.

## Kısa kontrol listesi

Kendi işletmen için hızlı bir değerlendirme yapmak istersen şu sorulara cevap ver:

| Soru | Evet ise | Hayır ise |
|---|---|---|
| Stokun tek bir kaynağı var mı? | İyi bir temel | İlk iş bunu kur |
| Bir kanaldaki satış diğerlerine otomatik yansıyor mu? | Gecikmeyi ölç | Elle güncelleme riskini hesapla |
| Tüm kanallarda aynı SKU'lar mı kullanılıyor? | Eşleştirme kolay | Önce kodları düzenle |
| Kanal dışı hareketler kaydediliyor mu? | Sayım farkı az olur | Sapma birikir |
| Fazla satış olduğunda haberin oluyor mu? | Hızlı müdahale | Müşteri senden önce fark eder |

## Sonuç

Fazla satış, çok kanallı satışın doğal bir riskidir ve tamamen ortadan kaldırılamaz. Ama tek bir stok kaynağı, hızlı senkronizasyon, tutarlı stok kodları, kritik ürünlerde tampon stok ve fazla satışı anında işaretleyen bir uyarı ile bu riski yönetilebilir bir seviyeye indirebilirsin.

Kanallarını tek panelde toplamanın nasıl işlediğini merak ediyorsan, [desteklenen entegrasyonlara](/entegrasyonlar) göz atabilir ya da [ücretsiz planla](/fiyatlar) deneyebilirsin.
