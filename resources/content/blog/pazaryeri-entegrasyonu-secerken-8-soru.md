---
title: "Pazaryeri entegrasyonu seçerken sorman gereken 8 soru"
description: "Pazaryeri entegrasyonu seçmeden önce stok, sipariş, fiyat, veri güvenliği ve maliyet hakkında sorman gereken 8 soru ve cevapları nasıl değerlendireceğin."
published_at: 2026-09-29
tags: [pazaryeri entegrasyonu, çok kanallı satış, stok yönetimi]
---

Birden fazla kanalda satış yapmaya başladığında, bir noktada entegrasyon yazılımı aramaya başlarsın. Piyasada çok sayıda seçenek var ve tanıtım sayfaları birbirine benziyor: "tüm pazaryerleri tek panelde", "otomatik stok", "kolay kurulum". Bu cümleler karar vermene pek yardımcı olmaz.

Bu yazıda bir entegrasyon aracını değerlendirirken sorman gereken sekiz soruyu ve cevapları nasıl yorumlayacağını derledik. Bu soruları satış görüşmesinde de, ücretsiz deneme sırasında da kullanabilirsin.

## 1. Stokun "doğru" sayısı nerede tutuluyor?

Bu, listedeki en önemli soru. Bazı araçlar kanallar arasında sayı kopyalar: bir kanalda değişen stoku diğerine yazar. Bazıları ise merkezi bir stok defteri tutar: her satış, iade ve düzeltme bir hareket olarak kaydedilir ve kanallara bu defterden hesaplanan tek bir sayı gönderilir.

Kopyalama yaklaşımı basit görünür ama iki kanal aynı anda değiştiğinde hangisinin doğru olduğu belirsiz kalır. Merkezi defter ise "bu sayı neden böyle?" sorusuna geçmiş hareketlerle cevap verebilir.

**Nasıl test edersin:** Bir ürünün stok geçmişini görmek iste. "Şu tarihte şu siparişle 2 adet düştü" gibi bir döküm alabiliyorsan, araç hareket bazlı çalışıyordur.

## 2. Bir kanaldaki satış diğer kanallara ne kadar sürede yansıyor?

"Gerçek zamanlı" ifadesi sık kullanılır ama pratikte her senkronizasyonun bir gecikmesi vardır: kanalın siparişi bildirmesi, aracın işlemesi ve yeni stokun diğer kanallara yazılması zaman alır. Önemli olan bu sürenin dakikalar mı, saatler mi olduğudur.

Bazı araçlar kanalları belirli aralıklarla yoklar (örneğin her 15 dakikada bir); bazıları ise kanalın gönderdiği anlık bildirimleri (webhook) kullanır. İkincisi genellikle daha hızlıdır, ama her kanal bu bildirimleri sunmaz.

**Nasıl test edersin:** Deneme sürecinde bir test siparişi oluştur ve diğer kanalda stokun ne zaman düştüğünü ölç. Kampanya günlerini düşünerek bu süreyi değerlendir.

## 3. Fazla satış olduğunda ne oluyor?

Dürüst bir cevap şudur: hiçbir araç fazla satışı **tamamen** engelleyemez. İki kanaldan aynı saniyede gelen siparişler, senkronizasyon tamamlanmadan çakışabilir. "Asla fazla satış olmaz" diyen bir araca temkinli yaklaşmalısın.

Asıl soru, fazla satış olduğunda aracın bunu fark edip sana gösterip göstermediğidir. Stoku sıfırın altına düşüren bir sipariş işaretleniyor mu, yoksa sessizce kayboluyor mu? Bu konuyu [fazla satış rehberimizde](/blog/fazla-satis-overselling-nedir-nasil-onlenir) daha ayrıntılı ele aldık.

## 4. Fiyat güncellemelerinde çakışma nasıl ele alınıyor?

Fiyatı tek yerden yönetmek pratik, ama pazaryeri panelinde elle yaptığın bir indirimin bir sonraki senkronizasyonda sessizce ezilmesi ciddi sorun yaratabilir: kampanyaya katıldığını sanırken ürün eski fiyattan satılmaya devam eder.

İyi bir araç, kanaldaki fiyatın senin belirlediğin fiyattan farklı olduğunu fark edip sana sormalı ya da en azından bunu açıkça göstermelidir. Ayrıca kanallara farklı fiyat uygulayabilmen (örneğin pazaryerinde komisyonu karşılayacak bir fark) önemlidir.

## 5. Siparişler tek listede toplanıyor mu ve kargo bilgisi nasıl giriliyor?

Birden fazla kanaldan sipariş aldığında, her kanalın paneline ayrı ayrı girmek zaman alır ve sipariş kaçırma riskini artırır. Tüm siparişlerin tek bir listede, kanal bilgisiyle birlikte görünmesi günlük işi ciddi şekilde kolaylaştırır.

Kargo takip numarası da benzer bir iş yüküdür. Takip numarasını bir kez girip ilgili kanala otomatik gönderebilmek, her kanalda ayrı ayrı işlem yapmaktan çok daha az hataya açıktır. Burada şunu da sor: **hangi kanallar için** gönderiliyor? Bazı kanallarda kargo süreci pazaryerinin kendi anlaşmalı kargosuyla yürür ve takip bilgisi dışarıdan girilmez; bu kanal bazında farklılık gösterir.

## 6. Hangi kanallar gerçekten destekleniyor?

Tanıtım sayfalarında uzun logo listeleri görmek yaygındır. Ama bir kanalın "listede olması" ile "üretimde, gerçek mağazalarla çalışıyor olması" aynı şey değildir. Bazı bağlantılar yalnızca ürün yüklemeyi destekler, sipariş çekmeyi desteklemez; bazıları ise henüz geliştirme aşamasındadır.

**Nasıl test edersin:** Satış yaptığın her kanal için şunu sor: stok güncelleme, sipariş çekme, fiyat güncelleme ve kargo bildirimi — hangileri çalışıyor? "Yakında" olan kanalları ayrı not al.

Bir de başlangıç sorusu var: kanallarında zaten yüzlerce ürünün varsa, bunları araca **içe aktarabiliyor musun**, yoksa kataloğu baştan mı girmen gerekiyor? Mevcut ürünleri kanaldan çekebilmek, kurulumu günlerden saatlere indirebilir. İçe aktarma sırasında aynı ürünün iki kanaldaki kayıtlarının nasıl eşleştirildiğini de sor; bu genellikle stok kodu üzerinden yapılır ve kodların tutarlı olmasını gerektirir.

34Pazar'da hangi kanalın bugün bağlanabildiğini, hangisinin yakında açılacağını [entegrasyonlar sayfasında](/entegrasyonlar) açıkça gösteriyoruz.

## 7. Verilerim nerede ve nasıl saklanıyor?

Bir entegrasyon aracına kanallarının API anahtarlarını, ürün kataloğunu ve sipariş bilgilerini veriyorsun. Bu yüzden şu sorular önemlidir:

- **API anahtarları şifreli mi saklanıyor?** Anahtarlar açık metin olarak duruyorsa, bir veri sızıntısında tüm mağaza hesapların risk altına girer.
- **Sunucular nerede?** Verinin hangi ülkede tutulduğu, hangi veri koruma mevzuatının geçerli olduğunu etkiler.
- **Müşteri bilgileri nasıl işleniyor?** Siparişlerdeki müşteri verileri kişisel veridir. Aracın bu verileri hangi amaçla ve ne kadar süre sakladığını gizlilik metninden kontrol et.
- **Ayrıldığımda verilerime ne olur?** Verilerini dışa aktarabiliyor musun ve hesabını kapattığında silinip silinmediğini öğren.

Bu soruların cevapları bir tanıtım sayfasında değil, aracın [gizlilik metninde](/yasal/gizlilik) ve [kullanım koşullarında](/yasal/kullanim-kosullari) yazılı olmalıdır.

## 8. Maliyet büyüdükçe nasıl değişiyor?

Fiyatlandırma modelleri farklıdır: bazıları ürün sayısına, bazıları kanal sayısına, bazıları sipariş adedine ya da cirodan yüzde almaya dayanır. Bugünkü maliyetin kadar, işin büyüdüğünde ne ödeyeceğin de önemlidir.

Değerlendirirken şunlara bak:

- **Ücretsiz deneme ya da ücretsiz plan var mı?** Gerçek ürünlerinle denemeden karar vermek risklidir.
- **Limit aşılınca ne oluyor?** Senkronizasyon duruyor mu, ek ücret mi çıkıyor?
- **Kurulum ücreti ya da uzun süreli taahhüt var mı?** Aylık iptal edilebilen bir abonelik, seni yanlış bir seçime bağlamaz.
- **Sipariş başına ya da ciro yüzdesi var mı?** Kampanya dönemlerinde maliyeti beklenmedik şekilde artırabilir.

34Pazar'ın planları ürün ve kanal sayısına göre kademelenir; sipariş başına ücret yoktur. Güncel limitleri ve fiyatları [fiyatlar sayfasında](/fiyatlar) görebilirsin.

## Soruları bir tabloda topla

Birden fazla aracı karşılaştırıyorsan, cevapları yan yana yazmak kararı kolaylaştırır:

| Soru | Araç A | Araç B |
|---|---|---|
| Stok kaynağı merkezi mi? | | |
| Senkronizasyon gecikmesi (ölçülen) | | |
| Fazla satış işaretleniyor mu? | | |
| Fiyat çakışması gösteriliyor mu? | | |
| Tek sipariş listesi ve kargo bildirimi | | |
| Kullandığın kanallarda gerçekten çalışıyor mu? | | |
| API anahtarları şifreli mi, sunucu nerede? | | |
| Büyüdüğünde aylık maliyet | | |

## Son bir öneri: deneme süresini ciddiye al

En iyi değerlendirme, gerçek ürünlerinle yapılan bir denemedir. Birkaç ürünü bağla, bir test siparişi oluştur, stokun diğer kanalda düştüğünü gör, bir fiyatı kanalda elle değiştirip aracın nasıl tepki verdiğine bak. Tanıtım sayfasında yazanla senin gözünle gördüğün arasındaki fark, karar için en güvenilir veridir.

Bu soruları 34Pazar için de sorabilirsin: [özellikler sayfası](/ozellikler) ve ücretsiz plan, kendi ürünlerinle denemen için hazır.
