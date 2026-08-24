# Hepsiburada API Notları — DOLDURULACAK

**Amaç:** `HepsiburadaEndpoints` içindeki **doğrulanmamış** sabitleri
resmî bilgiyle değiştirmek.

**Durum: BOŞ — kullanıcı dolduracak, sonra koda geçirilecek.**

---

## ⚠️ Neden bu belge var

`developers.hepsiburada.com` bot isteklerini **403**,
`listing-external.hepsiburada.com/docs` **401** ile reddediyor. Uç
noktalar ikincil kaynaklardan derlendi ve **doğrulanmadı**.

Bu projede yanlış uç noktanın bedeli ağırdır: kanal 200 dönerse senkron
**BAŞARILI görünür**, `synced_version` ilerler ve satır kanalda hiçbir
şey değişmemişken "senkron" damgası taşır. Teşhisi en zor hata sınıfı.

**Kanal bu yüzden `is_active = false`** ve panelde görünmüyor.

---

## Nasıl dolduracağız

Her bölümün altındaki bloğa **resmî dokümandan kopyala-yapıştır**
yapmanız yeterli — ham hâliyle, düzenlemeye gerek yok. Ben onu koda
çevirir, testleri güncellerim.

**Ekran görüntüsü de olur.** Metin kopyalanamıyorsa görüntü paylaşın.

Eksik bıraktığınız bölüm sorun değil: o maddeye ait kod **yazılmaz** ve
gövde açıkça istisna fırlatmaya devam eder.

---

## 1 · Kimlik doğrulama ve ortak başlıklar

**Elimizdeki (DOĞRULANMADI):**
- HTTP Basic auth — `api_key` / `api_secret` çifti
- `User-Agent: {merchantId} - {AppName}` **zorunlu**, eksikse 401
- `merchantId` satıcı hesabının kimliği

**Sorular:**
- Basic auth kullanıcı adı/parola tam olarak ne? (API key mi, merchantId mi?)
- `User-Agent` biçimi birebir doğru mu?
- Başka zorunlu başlık var mı?
- Test (sandbox) ortamının adresi farklı mı?

```
(buraya yapıştır)
```

---

## 2 · Listing — tekil fiyat/stok güncelleme

**Elimizdeki (DOĞRULANMADI):**
```
PUT /listings/merchantid/{merchantId}/sku/{merchantSku}
{ "availableStock": 42, "price": 299.90 }
```

**⚠️ EN KRİTİK SORU:** stok ve fiyat **aynı yükte mi** gitmek zorunda?
Yalnızca `availableStock` gönderilirse `price` **sıfırlanır mı**?

Bu cevap kodun şeklini belirliyor: Trendyol'da ikisini ayırmak
zorunluydu, burada tersi olabilir ve yanlış varsayım **satışı kapatır**.

**Diğer sorular:**
- `merchantSku` büyük harfe mi çevriliyor?
- Fiyat ondalık ayırıcısı nokta mı? Kaç basamak?
- Kargo süresi (`dispatchTime`) zorunlu mu?

```
(buraya yapıştır)
```

---

## 3 · Listing — toplu güncelleme (`trackingId`)

**Elimizdeki (DOĞRULANMADI):**
```
POST   /listings/merchantid/{merchantId}/inventory-uploads
GET    /listings/merchantid/{merchantId}/inventory-uploads/id/{trackingId}
```

**Sorular:**
- Tek istekte en fazla kaç SKU? (ikincil kaynak: 4000; biz 1000
  kullanıyoruz)
- Aynı anda kaç bekleyen işlem? (ikincil kaynak: 5)
- Yoklama yanıtı hangi alanları döner? Satır bazlı hata nasıl geliyor?
- Kabul yanıtı `202` mi?

```
(buraya yapıştır)
```

---

## 4 · Kategori ağacı ve zorunlu öznitelikler

**Elimizdeki (DOĞRULANMADI):**
```
GET /product/api/categories/get-all-categories
GET /product/api/categories/{categoryId}/attributes
```

**Sorular:**
- Ağaç düz liste mi, iç içe mi? Yaprak kategori nasıl anlaşılıyor?
- Öznitelikler zorunlu/isteğe bağlı olarak işaretli mi?
- İzinli değer listesi (`allowedValues`) var mı? Boş liste ne demek?
- Sayfalama var mı?

**Neden önemli:** Trendyol'un taksonomi + eşleştirme + ön koşul kapısı
altyapısı **hazır ve yeniden kullanılabilir**; yalnızca bu iki uç nokta
gerekiyor.

```
(buraya yapıştır)
```

---

## 5 · Sipariş listeleme ve webhook

**Elimizdeki (DOĞRULANMADI):**
```
GET /packages/merchantid/{merchantId}
webhook imza başlığı: X-HB-Signature (HMAC-SHA256, base64)
```

**Sorular:**
- Tarih penceresi parametreleri ne? (`startDate`/`endDate`?)
- Sayfalama `offset/limit` mi, imleç mi?
- **Webhook imzası nasıl hesaplanıyor?** Ham gövde + secret →
  HMAC-SHA256 → base64 mi? Hex mi?
- Olay kimliği hangi başlıkta? (`X-HB-Event-Id`?)
- Olay tipleri: `order.created`, `order.cancelled`, `return.created`?
- Sipariş durumları listesi (iptal/iade/teslim ayrımı için)

**Neden kritik:** imza biçimi yanlışsa **meşru her webhook reddedilir**
ve kanal sonsuza kadar yeniden gönderir. Ya da tersi — doğrulama
gevşetilirse sahte sipariş enjeksiyonu mümkün olur.

```
(buraya yapıştır)
```

---

## 6 · Ürün açma (`PRODUCT_IMPORT`)

**Elimizdeki (DOĞRULANMADI):**
```
POST /product/api/products/import          → trackingId
GET  /product/api/products/import/{trackingId}
```

**Sorular:**
- Zorunlu alanlar neler? (barkod, marka, kategori, öznitelikler…)
- Marka kayıtlı kimlikten mi seçiliyor?
- Onay süreci var mı? (`WAITING_FOR_APPROVAL` durumu)
- Varyant grubu (`varyantGroupID`) nasıl çalışıyor?

```
(buraya yapıştır)
```

---

## 7 · Hız sınırı

**Elimizdeki (DOĞRULANMADI):**
- listing ~30 istek/sn, sipariş ~10 istek/sn
- Şu an **en düşük** olan 10/sn kullanılıyor (kova bağlantı başınadır ve
  tek kova iki sınırı ayrı ayrı temsil edemez)

**Sorular:**
- Yanıtta hız sınırı başlığı dönüyor mu? (`X-RateLimit-*`?)
- Dönüyorsa Trendyol'daki gibi **dinamik öğrenme** yazılabilir.
- 429 yanıtında `Retry-After` var mı?

```
(buraya yapıştır)
```

---

## Doldurulduktan sonra — doğrulama sırası

1. `HepsiburadaEndpoints` sabitlerini güncelle, "DOĞRULANMADI"
   işaretlerini kaldır.
2. `HepsiburadaAdapterTest`'teki **beklenen metinleri** güncelle
   (sabitler beklenen metinle sınanır — mutasyon ikisini birlikte
   kaydırmasın diye).
3. Gerçek satıcı hesabıyla **sağlık kontrolü** çalıştır.
4. `ChannelTypeSeeder` → `is_active = true`.

**Test anahtarınız varsa gerçek çalıştırma yapılmalı** — bu projede
gerçek çalıştırma her turda gerçek bir hata buldu.
