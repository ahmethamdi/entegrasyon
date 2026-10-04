---
title: "Stok kodu (SKU) düzeni: çok kanallı satışın görünmeyen temeli"
description: "SKU nedir, barkoddan farkı ne, iyi bir stok kodu nasıl kurulur? Çok kanallı satışta tutarlı SKU düzeninin kuralları, örnekleri ve sık hatalar."
published_at: 2026-10-03
tags: [sku, stok yönetimi, çok kanallı satış]
---

Çok kanallı satışta sorunların büyük kısmı, en sıkıcı görünen yerden çıkar: **stok kodlarından**. Aynı ürün Shopify'da bir kodla, pazaryerinde başka bir kodla, depodaki tabloda üçüncü bir adla duruyorsa, hiçbir yazılım bu üçünün aynı ürün olduğunu kendiliğinden bilemez.

Bu yazıda SKU'nun ne olduğunu, barkoddan farkını, iyi bir kod düzeninin nasıl kurulacağını ve mevcut karmaşık bir kataloğu nasıl toparlayabileceğini anlatıyoruz.

## SKU nedir?

SKU (*Stock Keeping Unit*, Türkçede genellikle **stok kodu**), satıcının bir ürünü kendi içinde tanımlamak için verdiği benzersiz koddur. Önemli nokta şu: SKU **senin** kodundur. Bir standart kurum tarafından verilmez; nasıl kuracağına sen karar verirsin.

SKU ürün düzeyinde değil, **satılabilir birim** düzeyinde tanımlanır. Siyah, M beden bir tişört ile siyah, L beden aynı tişört iki ayrı SKU'dur; çünkü stokları ayrı tutulur ve ayrı ayrı tükenebilirler.

### SKU ve barkod aynı şey mi?

Hayır, ama sık karıştırılırlar.

| | SKU (stok kodu) | Barkod (ör. EAN/GTIN) |
|---|---|---|
| Kim belirler? | Satıcı | Genellikle üretici, GS1 gibi bir kuruluş üzerinden |
| Biçim | Serbest (harf, rakam, tire) | Standart, genellikle yalnız rakam |
| Okunabilirlik | İnsan için anlamlı olabilir | İnsan için anlamsız bir sayı dizisi |
| Kapsam | Senin kataloğun içinde benzersiz | Küresel olarak benzersiz olması amaçlanır |

Pazaryerleri ürünleri genellikle barkodla takip eder; kendi mağaza altyapın (Shopify, WooCommerce gibi) ise hem SKU hem barkod alanı sunar. Bir ürünün iki kodu birden olması normaldir. Önemli olan, kanallar arasında eşleştirmeyi **hangi kod üzerinden** yapacağına karar vermek ve bu kararı tutarlı uygulamaktır. Pazaryerlerinin barkod ve ürün tanımlama kuralları değişebileceğinden, güncel gereksinimleri her zaman satıcı panelinden kontrol et.

## Çok kanallı satışta SKU neden bu kadar önemli?

Tek kanalda satarken SKU bir kolaylıktır. Birden fazla kanalda satarken ise **zorunluluktur**, çünkü kanallar arasındaki tüm otomatik işlemler bir eşleştirmeye dayanır:

- Pazaryerinden gelen bir siparişin hangi ürünün stokunu düşüreceği,
- Düşen stokun diğer kanallarda hangi ürüne yazılacağı,
- Bir fiyat değişikliğinin hangi ürünlere gideceği.

Bu eşleştirmenin en sağlam yolu, her kanalda aynı ürünün aynı SKU'yu taşımasıdır. Kodlar farklıysa, araya bir eşleştirme tablosu girer ve her yeni ürünle bu tablonun güncellenmesi gerekir — unutulan her satır, [fazla satış](/blog/fazla-satis-overselling-nedir-nasil-onlenir) için açık bir kapıdır.

34Pazar'da kanallardan gelen sipariş satırları ürünle **SKU üzerinden** eşleşir ve bir SKU hesabın içinde yalnızca bir kez kullanılabilir. Katalogda karşılığı olmayan bir SKU ile gelen sipariş satırı kaybolmaz; bekletilir ve o SKU kataloğa eklendiğinde ürüne bağlanır. Yine de en temiz yol, kodların baştan tutarlı olmasıdır.

## İyi bir SKU nasıl olmalı?

Herkese uyan tek bir şablon yok, ama işe yarayan düzenlerin ortak özellikleri var.

### 1. Benzersiz olmalı

Aynı kod iki farklı ürüne verilmemeli. Bu basit görünür ama özellikle farklı kişiler ürün eklediğinde veya eski ürünlerin kodları yeniden kullanıldığında bozulur. Satıştan kalkan bir ürünün kodunu yeni bir ürüne vermek, geçmiş siparişlerin ve raporların yanlış ürüne bağlanmasına yol açar.

### 2. Değişmemeli

SKU bir kez verildikten sonra değiştirilmemelidir. Kanallardaki eşleşmeler, sipariş geçmişi ve stok hareketleri bu koda bağlıdır. Kodu değiştirmek, bütün bu bağları koparmak demektir. Ürünün adı, fiyatı, görseli değişebilir; SKU değişmez.

### 3. Sade karakterler kullanmalı

Yalnızca büyük harf, rakam ve tire kullanmak en güvenli yoldur. Boşluk, Türkçe karakter (ç, ğ, ı, ö, ş, ü), eğik çizgi ve özel işaretler bazı sistemlerde farklı yorumlanabilir, tablolara aktarılırken bozulabilir ya da aramada bulunamayabilir. "TSRT-SYH-M" güvenlidir; "Tişört Siyah / M" değildir.

### 4. Okunabilir ama aşırı yüklenmemiş olmalı

Koda anlam yüklemek işe yarar: bir bakışta ürünün ne olduğunu görebilirsin. Ama her bilgiyi koda koymaya çalışmak kodları uzatır ve hata riskini artırır. Genellikle üç-dört parça yeterlidir:

```
KATEGORİ-MODEL-RENK-BEDEN
TSRT-0142-SYH-M
TSRT-0142-SYH-L
TSRT-0142-BYZ-M
```

Fiyat, tedarikçi, sezon gibi değişebilen bilgileri koda koyma. Fiyat değiştiğinde kodu değiştirmek zorunda kalırsın — ki bu, ikinci kuralı çiğnemek demektir.

### 5. Baştaki sıfırlara dikkat

"00142" gibi sıfırla başlayan kodlar, tablo programlarında sayıya çevrilip "142" olabilir. Kodlarını tabloyla yönetiyorsan ya sıfırla başlamayan bir düzen kur ya da ilgili sütunu metin olarak biçimlendir. Bu, hata vermeden veri bozan klasik bir tuzaktır.

### 6. Büyük-küçük harf tutarlı olmalı

Bazı sistemler "tsrt-0142" ile "TSRT-0142"yi aynı kod sayar, bazıları farklı. Karışıklığı önlemenin en kolay yolu, tüm kodları tek bir biçimde (örneğin hepsi büyük harf) yazmaktır.

## Varyantlı ürünlerde SKU

Beden, renk ya da boyut seçenekleri olan ürünlerde her varyant ayrı bir SKU almalıdır. Sık yapılan hata, ana ürüne bir kod verip varyantları kodsuz bırakmaktır. Bu durumda toplam stok doğru görünse bile, tek bir beden tükendiğinde hiçbir kanal bunu bilemez.

Pratik bir yöntem, varyant kodlarını ana model kodundan türetmektir. Böylece aynı modelin tüm varyantları listede yan yana durur ve bir bakışta gruplanır.

## Mevcut karmaşık kataloğu nasıl toparlarsın?

Çoğu satıcı SKU düzenini sıfırdan değil, yıllar içinde büyümüş karışık bir kataloğun üzerine kurmak zorunda kalır. Aşağıdaki adımlar bu işi yönetilebilir kılar:

1. **Tüm kanallardan ürün listesini dışa aktar.** Her kanaldaki ürün adı, varyant, SKU ve barkod bilgisini tek bir tabloya topla.
2. **Eşleşmeleri bul.** Aynı ürünün kanallardaki karşılıklarını yan yana getir. Barkod ortak bir anahtar olarak işe yarayabilir.
3. **Eksik ve çakışan kodları işaretle.** Kodsuz varyantları, iki farklı ürüne verilmiş kodları ve aynı ürüne kanallarda farklı verilmiş kodları ayrı ayrı listele.
4. **Hedef düzeni belirle.** Yukarıdaki kurallara göre bir kod şablonu seç ve önce yeni ürünlerde uygulamaya başla.
5. **Kanalları aşamalı güncelle.** Tüm kataloğu bir günde değiştirmeye çalışma. Önce en çok satan ürünlerden başla; her kanalda kodu güncelledikten sonra bir sipariş akışını kontrol et.
6. **Kuralı yazılı hale getir.** Ürün ekleyen herkesin aynı şablonu kullanması için kısa bir not hazırla. Düzeni korumak, kurmaktan daha zordur.

Bir pazaryerinde mevcut bir ürünün kodunu değiştirmenin ürün kaydını, değerlendirmeleri ya da listelemeyi nasıl etkilediği pazaryerine göre farklıdır. Değişiklik yapmadan önce ilgili kanalın kurallarını kontrol etmek, beklenmedik bir kayıp yaşamanı önler.

## Kısa kontrol listesi

- Her satılabilir birimin (varyant dahil) bir SKU'su var mı?
- Aynı ürün tüm kanallarda aynı SKU'yu taşıyor mu?
- Kodlarda boşluk, Türkçe karakter ya da özel işaret var mı?
- Satıştan kalkan ürünlerin kodları yeni ürünlere veriliyor mu?
- Kod şablonu yazılı mı, ürün ekleyen herkes biliyor mu?

## Sonuç

SKU düzeni, kimsenin konuşmak istemediği ama her şeyin üzerine kurulduğu bir temeldir. Tutarlı kodlar, stok senkronizasyonunun, sipariş eşleştirmesinin ve raporlamanın doğru çalışmasını sağlar. Kodları bir kez doğru kurmak, ileride sayısız düzeltme işinden kurtarır.

Kodlarını düzenledikten sonra kanallarını bağlamak için [Shopify](/entegrasyonlar/shopify) ve [WooCommerce](/entegrasyonlar/woocommerce) entegrasyon sayfalarına göz atabilir ya da [Trendyol ve Shopify stok senkronizasyonu](/blog/trendyol-shopify-stok-senkronizasyonu) yazımızla devam edebilirsin.
