---
title: "Trendyol ve Shopify'da aynı ürünü satarken stok nasıl senkron tutulur?"
description: "Aynı ürünü hem Trendyol'da hem kendi Shopify mağazanda satıyorsan stok sayılarını nasıl eşit tutarsın? Yöntemler, sık hatalar ve kurulum adımları."
published_at: 2026-09-25
tags: [trendyol, shopify, stok yönetimi, çok kanallı satış]
---

Türkiye'de birçok satıcı aynı yolu izliyor: önce Trendyol'da satışa başlıyor, sonra markasını büyütmek için kendi Shopify mağazasını açıyor. Ya da tam tersi — Shopify'daki markasını daha geniş kitleye ulaştırmak için Trendyol'a geliyor. Her iki durumda da aynı soru çıkıyor: **aynı ürünün stoku iki yerde nasıl doğru tutulur?**

Bu yazıda iki platformun stoku nasıl ele aldığını, senkronizasyon için hangi yolların olduğunu ve kurulumda sık yapılan hataları anlatıyoruz.

## İki platform, iki ayrı stok sayısı

Trendyol ve Shopify birbirinden tamamen bağımsız sistemlerdir. Birinde yaptığın satış ötekini otomatik olarak etkilemez. Shopify'da 10 adet görünen ürün Trendyol'da da 10 adet görünüyorsa ve Shopify'dan 4 adet satıldıysa, Trendyol'daki sayı sen ya da bir yazılım değiştirene kadar 10 kalır. Sonuç: gerçekte 6 ürünün varken Trendyol 10 tane satmaya hazırdır. Bu farkın yol açtığı soruna [fazla satış](/blog/fazla-satis-overselling-nedir-nasil-onlenir) denir.

Senkronizasyonun amacı, iki platformdaki sayının her zaman **gerçek** stoku yansıtmasıdır.

### Shopify tarafında bilmen gerekenler

- **Stok takibi varyant düzeyindedir.** Shopify'da her varyantın kendi stok sayısı ve kendi SKU'su vardır. Senkronizasyon ürün değil varyant bazında yapılmalıdır.
- **"Stok takibi" ayarı açık olmalı.** Bir varyantta stok takibi kapalıysa Shopify sayıyı hiç düşürmez; dışarıdan gönderilen stok bilgisi de anlamsız kalır.
- **"Stok bitince satmaya devam et" ayarına dikkat.** Bu seçenek açıksa, stok sıfır olsa bile Shopify satışa devam eder. Bilinçli olarak ön sipariş almıyorsan kapalı tutmalısın. Bu iki ayar birbirinden bağımsızdır; ikisinin de doğru olması gerekir.
- **Birden çok lokasyon.** Shopify'da birden fazla depo ya da mağaza lokasyonu tanımlıysa stok her lokasyonda ayrı tutulur. Senkronizasyonun hangi lokasyona yazacağını netleştirmelisin.

### Trendyol tarafında bilmen gerekenler

- **Ürünler barkod ile tanımlanır.** Trendyol'da her ürün (ve her varyant) kendine ait bir barkodla takip edilir; ayrıca satıcının kendi stok kodunu girebileceği bir alan da bulunur. Shopify'daki SKU ile Trendyol'daki ürünün hangi alan üzerinden eşleşeceğini baştan belirlemen gerekir.
- **Stok ve fiyat birlikte güncellenebilir.** Trendyol, entegrasyonlar için stok ve fiyat güncellemesine izin veren bir satıcı API'si sunar. Güncellemelerin kanala yansıması kısa bir süre alabilir.
- **Kurallar değişebilir.** Pazaryerinin ürün, stok ve iptal kuralları zaman içinde güncellenir. Kesin bilgi için her zaman Trendyol satıcı panelini ve resmi duyuruları kontrol et.

## Senkronizasyon için üç yol

### 1. Elle güncelleme

En basit yöntem: her sipariş geldiğinde diğer platformdaki stoku elle düşürmek. Günde birkaç sipariş alan ve az ürünü olan bir satıcı için başlangıçta işe yarayabilir.

Sorunları ise belli: siparişin geldiği an ile senin güncellediğin an arasında geçen her dakika risktir; gece ve hafta sonu bu boşluk büyür; ürün ve varyant sayısı arttıkça hata yapma ihtimali katlanır. Elle güncelleme yapıyorsan en azından kritik (az stoklu) ürünleri her gün kontrol etmelisin.

### 2. Tablo üzerinden toplu güncelleme

Bazı satıcılar stoku bir tabloda tutar ve belirli aralıklarla iki platforma toplu olarak yükler. Bu, elle güncellemeden daha düzenlidir ama aynı temel sorunu taşır: iki yükleme arasında geçen sürede yapılan satışlar diğer platforma yansımaz. Günde bir yükleme yapıyorsan, gün içinde iki platformda da aynı stok satışa açıktır.

### 3. Entegrasyon yazılımı ile otomatik senkronizasyon

Bir entegrasyon aracı iki platformu da dinler: bir tarafta sipariş geldiğinde ortak stoku düşer ve yeni sayıyı diğer tarafa yazar. Gecikme dakikalardan saniyelere iner ve insan hatası büyük ölçüde ortadan kalkar.

Burada dikkat etmen gereken nokta, aracın **tek bir stok kaynağı** kullanıp kullanmadığıdır. İki platformun sayılarını birbirine kopyalayan bir yapı (Shopify'daki sayıyı Trendyol'a, Trendyol'dakini Shopify'a yazmak) çakışmalara açıktır: iki taraf aynı anda değişirse hangisinin doğru olduğu belirsiz kalır. Merkezi bir stok defteri ise her hareketi kaydeder ve kanallara tek bir doğru sayı gönderir.

## Kurulum adımları

Hangi yöntemi seçersen seç, sağlıklı bir senkronizasyon için aşağıdaki sırayı izlemeni öneririz.

### Adım 1: Ürünleri eşleştir

Her Shopify varyantının Trendyol'daki karşılığını belirle. En sağlam yol, iki tarafta da aynı stok kodunu kullanmaktır. Kodlar farklıysa önce bir eşleştirme listesi çıkar. Eşleşmemiş ürün senkronize olmaz — ve bunu çoğu zaman bir sorun çıkana kadar fark etmezsin. Kod düzeni için [SKU rehberimize](/blog/stok-kodu-sku-duzeni-cok-kanalli-satis) bakabilirsin.

### Adım 2: Doğru başlangıç sayısını belirle

İki platformda farklı stok sayıları görünüyorsa hangisinin doğru olduğuna **rafa bakarak** karar ver. Senkronizasyonu yanlış bir başlangıç sayısıyla açarsan, hata iki platforma birden yayılır. Mümkünse senkronizasyonu açmadan önce kısa bir sayım yap.

### Adım 3: Shopify ayarlarını kontrol et

Tüm varyantlarda stok takibinin açık olduğundan ve "stok bitince satmaya devam et" seçeneğinin (bilinçli bir tercih değilse) kapalı olduğundan emin ol. Birden fazla lokasyon varsa senkronizasyonun hangisini kullanacağını belirle.

### Adım 4: Küçük bir ürün grubuyla başla

Tüm kataloğu bir anda bağlamak yerine birkaç ürünle başla. Bir test siparişi oluştur (ya da gerçek bir siparişi izle) ve stokun diğer platformda düştüğünü kendi gözünle gör.

### Adım 5: İptal ve iadeleri de düşün

Bir sipariş iptal edildiğinde ya da ürün iade edildiğinde stok geri artmalıdır. Kullandığın yöntemin bu hareketleri de işlediğinden emin ol; yoksa zamanla stok gerçeğin altında görünür ve satış kaçırırsın.

## Fiyatlar ne olacak?

Stok senkronizasyonu kurarken fiyatı da aynı yerden yönetmek cazip gelir. Ancak Trendyol ve Shopify'da aynı fiyatı göstermek her zaman istenen bir şey değildir: pazaryeri komisyonu ve kargo koşulları farklı olduğu için birçok satıcı platformlara farklı fiyat uygular.

Önemli olan, bir fiyatın **habersizce** ezilmemesidir. Örneğin Trendyol panelinde elle indirim yaptıysan, bir senkronizasyon aracının bunu sessizce eski fiyata döndürmesi istenmez. 34Pazar fiyat farklarını sessizce ezmek yerine [çakışma olarak gösterir](/ozellikler); hangi fiyatın geçerli olacağına sen karar verirsin.

## Sık yapılan hatalar

- **Varyantları ürün gibi senkronize etmek:** Toplam stok doğru görünür ama tek bir beden tükendiğinde fark edilmez.
- **Shopify'da stok takibini açmayı unutmak:** Gönderilen stok bilgisi hiçbir şeyi değiştirmez.
- **Başlangıç sayısını kontrol etmeden senkronizasyonu açmak:** Yanlış sayı iki platforma birden yayılır.
- **Kanal dışı satışları kaydetmemek:** Mağazadan elden yapılan satış sisteme girmezse iki platform da fazla stok gösterir.
- **İki platformda aynı ürünü farklı kodlarla açmak:** Eşleştirme tablosu zamanla eskir; yeni eklenen bir varyant tabloya girmezse o varyantın stoku hiç senkronize olmaz.
- **Senkronizasyonu kurup hiç kontrol etmemek:** Haftada bir, birkaç ürünün iki platformdaki sayısını karşılaştırmak, sessiz sorunları erken yakalar.

## Sonuç

Trendyol ve Shopify'da aynı ürünü satmak, satış hacmini büyütmenin etkili bir yoludur; ama iki ayrı stok sayısını elle eşit tutmak, sipariş arttıkça sürdürülemez hale gelir. Ürünleri tutarlı kodlarla eşleştirmek, doğru başlangıç sayısıyla başlamak ve tek bir stok kaynağı kullanan otomatik bir senkronizasyon kurmak, sorunların çoğunu baştan önler.

34Pazar'da hangi kanalların bağlanabildiğini ve Trendyol bağlantısının güncel durumunu [Trendyol entegrasyonu](/entegrasyonlar/trendyol) ve [Shopify entegrasyonu](/entegrasyonlar/shopify) sayfalarında görebilirsin.
