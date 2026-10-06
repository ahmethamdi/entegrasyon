# Hepsiburada API Notları — DOĞRULANDI (6 Ekim 2026)

**Kaynak:** `developers.hepsiburada.com` bota 403 veriyor; resmî doküman
Wayback arşivinden okundu. Uç noktalar sitenin ReadMe altyapısının açık
OpenAPI kayıtlarından alındı → **`docs/hepsiburada-openapi/*.json`**
(listeleme, sipariş, talep-iade, katalog, muhasebe, tedarikçi). Değişiklik
kayıtları Mayıs 2026'ya kadar okundu. Kod yazarken ÖNCE o JSON'lara bak.

"Doğrulanmadı" yazan maddeler ilk gerçek hesap/test ortamı denemesinde
ölçülecek.

---

## 1. Kimlik doğrulama

- **Basic auth:** kullanıcı = `merchantId` (GUID), şifre = **Servis
  Anahtarı** (12 karakter, harf+rakam). Eski kullanıcı adı/şifre yapısı
  **15 Ağustos 2024'te kapandı**.
- **`User-Agent` ZORUNLU:** değeri entegratörün (eski Basic auth)
  kullanıcı adı, örn. `xxx_dev`. ⚠️ Koddaki `{merchantId} - Entegrasyon`
  biçimi YANLIŞ (yalnız güvenilmez bir ikincil kaynakta geçiyor).
- **Servis Anahtarı:** Satıcı paneli → Bilgilerim → Entegrasyon →
  "Entegratör Bilgileri" → Entegratörlerim → "Servis Anahtarı".
- **Entegratör kaydı ŞART:** Satıcı paneli → Yardım Merkezi → Talepler →
  yeni talep → "API ENTEGRASYON – API Entegratör Yetkilendirme
  İşlemleri". Önce **test ortamı** bilgileri gelir, testler geçince aynı
  yoldan canlı istenir; yetki ~2 saatte devreye girer.

## 2. Ortamlar — canlı = test adresinden `-sit` çıkar

| Alan | Test (SIT) | Canlı |
|---|---|---|
| Listing | `listing-external-sit.hepsiburada.com` | `listing-external.hepsiburada.com` |
| Sipariş + talep (OMS) | `oms-external-sit.hepsiburada.com` | `oms-external.hepsiburada.com` |
| Katalog (MPOP) | `mpop-sit.hepsiburada.com/product` | `mpop.hepsiburada.com/product` |
| Muhasebe | `mpfinance-external-sit.hepsiburada.com` | `mpfinance-external.hepsiburada.com` |

Canlı hostlar kurala göre türetildi, tek tek denenmedi. Testte ürün
onaylayan ekip YOK (yüklenen ürün "İncelenecek"te kalır); test siparişi
yalnız HB'nin önceden yüklediği ürünlerle açılır.

## 3. Listing (ilanlar, stok, fiyat)

- **İlan listesi:** `GET /Listings/merchantid/{merchantId}?offset=&limit=`
  (ikisi zorunlu; örnek yanıtta `limit: 5000`). Filtreler: `hbSkuList`,
  `merchantSkuList`, `salable-listings`, `updateStartDate`,
  `updateEndDate`. Yanıt: `listings[]{listingId, hepsiburadaSku,
  merchantSku, price, availableStock, dispatchTime, isSalable, isLocked,
  lockReasons[], skuAfterSuspension, …}` + `totalCount`.
  `commissionRate` 1 Eyl 2025'te kaldırıldı.
- **Toplu güncelleme:** `POST …/inventory-uploads` — dizi
  `[{hepsiburadaSku, merchantSku, price, availableStock, dispatchTime?,
  maximumPurchasableQuantity, …}]`, alanlar nullable.
  ⚠️ Tek alan gönderilince diğerinin sıfırlanıp sıfırlanmadığı
  DOĞRULANMADI → **ayrı uç noktaları kullan:**
  - yalnız stok: `POST …/stock-uploads` `{hepsiburadaSku, merchantSku,
    availableStock, maximumPurchasableQuantity}`
  - yalnız fiyat: `POST …/price-uploads` `{hepsiburadaSku, merchantSku,
    price}`
  - tek ilan: `POST …/sku/{sku}/merchantsku/{merchantSku}`
    `{newAvailableStock, newPrice, newDispatchTime}`
- **Sonuç:** yanıttaki `id` ile `GET …/{inventory|stock|price}-uploads/id/{id}`
  → `{id, status, createdAt, total, errors[]}` (+ fiyatta
  `priceValidations[]`). Okunmazsa red sessiz kalır (Trendyol dersi).
- **Diğer:** `…/sku/{sku}/activate|deactivate`, `DELETE
  …/sku/{sku}/merchantsku/{merchantSku}`.
- **SKU:** `hepsiburadaSku` = HB katalog kodu (HBV…/HBCV…);
  `merchantSku` = satıcının kodu, BÜYÜK HARFE çevrilir, Türkçe karakter ve
  boşluk içermemeli → eşleştirmede büyük/küçük harf duyarsız karşılaştır.
- **Sınırlar:** aynı anda bekleyen yükleme ≤5 · istek başına ≤4000 SKU ·
  günlük limit = ilan sayısı × 10 · önerilen aralık ~10 sn · fiyat bandı
  (`OutOfPriceRange`): 0–50 TL %250 … 2000 TL üstü %80.

## 4. Siparişler (OMS)

- **Ödemesi tamamlanmış (paketlenecek):** `GET
  /orders/merchantid/{id}?offset=&limit=&begindate=&enddate=` (tarih
  `2023-04-02 00:00`). Yanıt `{items[], limit, offset, pageCount,
  totalCount}`; kalem: `id` (kalem), `orderId`, `orderNumber`, `sku`,
  `merchantSKU`, `quantity`, `unitPrice{amount,currency}`, `totalPrice`,
  `vatRate`, `status` (Open/Unpacked), `orderDate`, `dueDate`,
  `lastStatusUpdateDate`, `packageNumber`, `cargoCompany`, adres/fatura.
- **Paketleme:** `GET /lineitems/merchantid/{id}/packageablewith/lineitemid/{lineItemId}`,
  `POST /packages/merchantid/{id}` `{lineItemRequests[{id, quantity}],
  parcelQuantity, deci, …}`.
- **Paketler:** `GET /packages/merchantid/{id}?begindate&enddate|timespan|limit&offset`
  — ⚠️ aralık ≤24 saat, uzunsa `enddate` SESSİZCE yok sayılır; limit ≤10,
  sayfalama yanıt BAŞLIĞINDA. Alanlar: `packageNumber`, `barcode`,
  `status`, `items[]{lineItemId, orderNumber, hbSku, merchantSku,
  quantity, …}`.
- **Durum listeleri:** `/orders/merchantid/{id}/paymentawaiting`,
  `/packages/merchantid/{id}/shipped|delivered|undelivered|status/unpacked`,
  detay `/orders/merchantid/{id}/ordernumber/{no}`.
- **İptal:** `GET /orders/merchantid/{id}/cancelled` (yalnız son 1 ay;
  `cancelDate, cancelReasonCode, cancelledBy, lineItemId, orderNumber`).
  Satıcı iptali: `POST /lineitems/merchantid/{id}/id/{lineId}/cancelbymerchant` `{reasonId}`.
- **İade (talep):** `GET /claims/merchantId/{id}?beginDate&endDate&offset&limit`,
  `…/status/{status}`, `POST /claims/number/{no}/accept|reject`.
- **Sipariş numarası 19 haneye kadar**, değişken uzunluk (Şub 2026).
- ⚠️ **Saat dilimi DOĞRULANMADI:** webhook örneğinde `orderDate`
  `…Z`, `dueDate` eksiz. Trendyol `orderDate`'i +3 kaydırıyordu — ilk
  gerçek siparişte panelle karşılaştır.

## 5. Webhook

- Var; önce test ortamında tamamlanmalı. Satıcı temel URL'ini HB'ye
  bildirir.
- ⚠️ **HMAC / imza başlığı YOK** — güvenlik Basic auth (satıcının
  verdiği kullanıcı/şifre). Koddaki `X-HB-Signature` doğrulaması YANLIŞ.
- Olaylar: `POST /orders`, `POST /packages`, `/lineitems/{id}/cancel`,
  `/packages/{no}/intransit`, `/packages/{no}/deliver`, Unpack,
  Undeliver, `/orders/{no}/shippingaddress`, talep "awaitingaction".
  Unpack/Undeliver/talep yolları doğrulanmadı. Alıcı idempotent olmalı.

## 6. Katalog (MPOP) — kısaca

- Kategoriler: `GET /api/categories/get-all-categories?leaf=true&status=ACTIVE&available=true&page=&size=` (size ≤2000).
- Öznitelikler: `GET /api/categories/{id}/attributes` → `{id, name,
  mandatory, type: string|enum, multiValue}`; değerler `GET
  /api/categories/{id}/attribute/{attrId}/values?page=&size=` (≤1000).
- Ürün yükleme: `POST /api/products/import` (JSON dosyası form-data) →
  `trackingId`; durum `GET /api/products/status/{trackingId}`.
- Aynı barkod HB kataloğunda varsa "Eşleşen" olur: `POST
  /api/products/approve-prematch` / `reject-prematch`.
- Görsel sunucusunda HB IP'leri beyaz listede olmalı: 193.28.225.94,
  185.92.214.94, 34.78.190.48, 104.155.47.90, 34.76.71.175, 35.240.98.85.

## 7. Hız sınırları

- Sipariş: 1000 istek/sn; aşımda 429 + `X-RateLimit-Remaining/Limit/Reset`.
- Katalog: 100 istek/sn; aşımda **403** (401/403'ü kimlik hatası sayma!);
  5 sn aşımda IP engellenir, kaldırmak için talep açılır.
- Listing: istek/sn sınırı yayımlanmamış; yükleme/SKU kotaları geçerli (§3).

---

## Kod durumu (6 Eki akşam)

- ✅ ① kimlik/User-Agent/ortam/webhook-auth (`a86a402`) · ② ilan içe aktarma +
  katalog zenginleştirme (`2031421`) · ③ stok (`stock-uploads`) ve fiyat
  (`price-uploads`) + uzak okuma (`8126c24`) · ④ sipariş yoklaması: açık
  kalemler (siparişe gruplu, sayfa sınırında erteleme) + kalem iptalleri.
- Kanal yalnız YOKLAMA ile çalışır (`supports_webhooks=false`): webhook'lu
  kanal yoklanmıyor; HB webhook'u satıcının HB'ye bildirmesini ve gövde
  işlemeyi gerektirir — sonraki adım.
- ⏭️ Açık: `/packages` (iki tur arasında paketlenen sipariş `/orders`'tan
  düşer), iadeler (`/claims`), webhook, upload sonucunu okuma
  (`…-uploads/id/{id}` errors), saat dilimi ölçümü, gerçek SIT testi.

## Koddaki sapmalar (6 Eki tespiti — ①'de düzeltildi)

1. `User-Agent` biçimi yanlış (§1).
2. Webhook HMAC doğrulaması var ama HB imza göndermiyor (§5) → meşru
   her webhook reddedilir.
3. `HepsiburadaEndpoints` yolları küçük harf `/listings/…`; resmî yol
   `/Listings/…` (büyük/küçük harf duyarlılığı ölçülecek).
4. Ortam seçimi yok: test (SIT) ile canlı arasında geçiş gerekli.
5. Stok/fiyat/sipariş fonksiyonlarının hepsi "yazılmadı" fırlatıyor.
6. Katalog 403'ü kimlik hatası sayılıyor (§7).
