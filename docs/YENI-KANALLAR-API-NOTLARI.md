# Yeni kanallar: API notları (Amazon SP-API, ikas, Ticimax)

Araştırma tarihi: 8 Ekim 2026. Kaynaklar resmi dokümanlar. Resmi kaynakta
bulunamayan her bilgi **[doğrulanmadı]** diye işaretli. Kod yazılmadı.

> Not: Amazon doküman alanı `developer-docs.amazon.com` adresinden
> `developer-docs.amazon` adresine 301 ile yönleniyor. İki adres de aynı
> sayfayı açıyor; aşağıdaki linkler yeni alan adını kullanıyor.

---

## 1. Amazon Selling Partner API (SP-API): Amazon.com.tr + Amazon.de

### Temel gerçekler

| Konu | Değer | Kaynak |
|---|---|---|
| Bölge uç noktası (TR ve DE) | `https://sellingpartnerapi-eu.amazon.com` (AWS bölgesi eu-west-1). Türkiye de EU uç noktasında. | [sp-api-endpoints](https://developer-docs.amazon/sp-api/docs/sp-api-endpoints) |
| Marketplace ID | TR `A33AVAJ2PDY3EV`, DE `A1PA6795UKMFR9` | [marketplace-ids](https://developer-docs.amazon/sp-api/docs/marketplace-ids) |
| Seller Central | TR `https://sellercentral.amazon.com.tr`, DE `https://sellercentral-europe.amazon.com` | [seller-central-urls](https://developer-docs.amazon/sp-api/docs/seller-central-urls) |
| Sandbox | `https://sandbox.sellingpartnerapi-eu.amazon.com` (statik ve dinamik) | [sandbox](https://developer-docs.amazon/sp-api/docs/the-selling-partner-api-sandbox) |

### Önceden yapılması gereken başvurular

- 34Pazar başka satıcılara hizmet verdiği için **Public Developer** kaydı
  gerekiyor. Private developer yalnızca kendi şirketini entegre eden
  geliştiriciler için.
  ([public](https://developer-docs.amazon/sp-api/docs/register-as-a-public-developer),
  [private](https://developer-docs.amazon/sp-api/lang-en_US/docs/register-as-a-private-developer))
- Kayıt **Solution Provider Portal** (veya Seller Central > Apps and
  Services) üzerinden yapılıyor. Developer Profile formunda şunlar isteniyor:
  iletişim bilgileri, rol seçimi, kullanım senaryosu (serbest metin 500
  karakterden kısa), güvenlik kontrolleri anketi ve politika onayı. Ayrıca
  hizmeti anlatan, herkese açık bir web sitesi gerekiyor.
- Amazon'un ek bilgi isteğine **5 gün içinde** cevap verilmezse vaka
  kapanıyor. **Toplam onay süresi resmi dokümanda yazmıyor [doğrulanmadı].**
- **Kısıtlı roller (PII):** müşteri adı ve adresi için *Direct-to-Consumer
  Shipping (Restricted)* rolü gerekiyor (bölgeye göre Tax Invoicing veya Tax
  Remittance da olabilir). Kısıtlı rol başvurusu iki aşamada inceleniyor:
  1) iş doğrulaması, 2) veri güvenliği değerlendirmesi (mimari ve PII
  koruma). ([access-orders-pii](https://developer-docs.amazon/sp-api/docs/access-orders-pii))
- Gereken standart roller: Product Listing, Pricing, Inventory and Order
  Tracking. Kargo bildirimi ve adres için Direct-to-Consumer Shipping
  gerekiyor.
- **Ücret durumu:** Amazon Kasım 2025'te yıllık $1.400 ve GET çağrısı başına
  kullanım ücreti duyurmuştu. Mayıs 2026'da bu ücretleri iptal etti: "We
  will not move forward with the SP-API usage and annual fees at this time".
  Bu bilgi ikincil kaynaktan geliyor, resmi duyuru sayfası bulunamadı
  [kısmen doğrulandı].
  ([ppc.land](https://ppc.land/amazon-drops-sp-api-fees-after-developer-pushback),
  [novadata](https://novadata.io/resources/news/amazon-cancels-sp-api-fees-may-2026))

### Kimlik doğrulama ve bağlantı

- **OAuth (Website authorization workflow).** Satıcı, kendi Seller Central
  alan adında şu adrese yönlendirilir:
  `https://{sellercentral}/apps/authorize/consent?application_id=...&state=...`
  Uygulama Draft durumundayken adrese `&version=beta` eklenir. Dönüşte
  `spapi_oauth_code` ve `selling_partner_id` gelir. Bu kod **5 dakika**
  geçerli ve `https://api.amazon.com/auth/o2/token` adresinde refresh
  token ile değiştirilir. Akışın tamamı 10 dakikada bitmeli. Yönlendirme
  sayfasında `Referrer-Policy: no-referrer` başlığı olmalı.
  ([website-authorization-workflow](https://developer-docs.amazon/sp-api/docs/website-authorization-workflow))
- **Access token** LWA'dan `grant_type=refresh_token` ile alınır ve
  **1 saat** geçerlidir. İsteklerde `x-amz-access-token` başlığıyla
  gönderilir. Grantless işlemler (ör. notifications) için
  `client_credentials` + `scope` kullanılır.
  ([connecting](https://developer-docs.amazon/sp-api/docs/connecting-to-the-selling-partner-api))
- **Refresh token 365 günde bir yenilenmeli.** Satıcı uygulamayı her yıl
  ve her yeni rol eklendiğinde yeniden yetkilendirmek zorunda. Amazon,
  süre dolmadan 30 gün önce e-posta atıyor. Adapter'ın
  `SupportsTokenRefresh` tarafına "yetki bitiş tarihi" alanı ve uyarı
  eklenmeli. ([renew-authorizations](https://developer-docs.amazon/sp-api/lang-US/docs/renew-authorizations))
- TR ve DE ayrı Seller Central'lar olduğu için **TR ve DE muhtemelen ayrı
  yetkilendirme ve ayrı refresh token** gerektirir. Bu bir çıkarım
  [doğrulanmadı]. Bağlantı modelinde "mağaza = satıcı + bölge/hesap" olarak
  düşünülmeli.
- AWS SigV4 imzası artık zorunlu değil. Bu, yukarıdaki LWA sayfasından
  çıkarıldı: sayfada yalnızca LWA token'ı anlatılıyor ve SigV4 hiç
  geçmiyor [doğrulanmadı, yine de bilinen durum].

### Sağlık kontrolü ("ben kimim")

- `GET /sellers/v1/marketplaceParticipations`: satıcının aktif olduğu
  marketplace'leri, para birimini ve dili döndürür. Hız sınırı 0,016 rps,
  burst 15 (yani yaklaşık dakikada 1). Sağlık kontrolünü sık çağırma,
  sonucu önbellekte tut.
  ([sellers model](https://github.com/amzn/selling-partner-api-models/blob/main/models/sellers-api-model/sellers.json))

### Ürün/varyant listesini çekme (katalog içe aktarma)

- **Rapor yolu (önerilen, tam liste):** Reports API ile
  `GET_MERCHANT_LISTINGS_ALL_DATA` (aktif ve pasif ilanlar) ya da
  `GET_MERCHANT_LISTINGS_DATA` (aktif ilanlar) istenir. Kolonlar: seller-sku,
  asin, price, quantity, fulfillment-channel ve diğerleri. Akış asenkron:
  createReport → getReport ile durum yoklanır → getReportDocument →
  sıkıştırılmış TSV indirilir.
  ([report types](https://developer-docs.amazon/sp-api/docs/report-type-values-inventory))
- **API yolu:** `searchListingsItems` (Listings Items 2021-08-01).
  `includedData=summaries,attributes,offers,fulfillmentAvailability,relationships,...`
  alınabilir. **En fazla 1.000 sonuç** sayfalanabiliyor. `pageToken` 24
  saatte doluyor ve sorgu parametrelerine bağlı. Bu yüzden 1.000'den fazla
  SKU'su olan satıcıda tam içe aktarma için rapor şart.
  ([search-for-listings-items](https://developer-docs.amazon/sp-api/lang-US/docs/search-for-listings-items-by-id))
- Varyasyonlar parent/child SKU olarak geliyor (`relationships`,
  `variationParentSku` filtresi).

### Stok güncelleme

- **Tekil:** `PATCH /listings/2021-08-01/items/{sellerId}/{sku}`
  (`patchListingsItem`) ile `fulfillment_availability` (quantity) yazılır.
  Senkron doğrulama yapar ama ilanın işlenmesi asenkron. Hız: hesap+uygulama
  başına 5 rps, burst 5.
  ([rate limits](https://developer-docs.amazon/sp-api/docs/listings-items-api-rate-limits))
- **Toplu:** Feeds API + `JSON_LISTINGS_FEED`. Feed başına 1.500–25.000
  ürün, 5 dakikada 5 feed. Amazon'un önerisi: 5 dakikada 1.500'den az ürün
  varsa tekil API, fazlaysa feed. Sonuç asenkron: getFeed ile durum
  yoklanır, bitince processing report indirilir.
  ([listings workflows](https://developer-docs.amazon/sp-api/docs/building-listings-management-workflows-guide))
  Feeds hız sınırları: createFeed 0,0083 rps / burst 15, getFeed 2 rps /
  burst 15.
  ([feeds model](https://github.com/amzn/selling-partner-api-models/blob/main/models/feeds-api-model/feeds_2021-06-30.json))
- **Tuzak:** eski XML ve flat-file feed'ler (ör.
  `POST_INVENTORY_AVAILABILITY_DATA`, fiyat feed'leri) **31 Temmuz 2025'ten
  beri desteklenmiyor**, gönderilirse `FATAL` dönüyor. Eski kod örneklerini
  kopyalamayın.
  ([migration](https://developer-docs.amazon/sp-api/docs/listings-management-workflow-migration))
- Yalnızca FBM stoğu yazılabilir. FBA stoğu Amazon'da durur, oradan okunur.

### Fiyat güncelleme

- Stokla **aynı mekanizma**, farklı attribute: `purchasable_offer`
  (`our_price`, para birimi, `marketplace_id` seçicisiyle). Aynı PATCH'te
  stok ve fiyat birlikte gönderilebilir. `replace` gönderilmeyen alt alanları
  silmez, `merge` null ile siler.
  ([manage-purchasable-offer](https://developer-docs.amazon/sp-api/docs/manage-purchasable-offer))
- TR fiyatı TRY, DE fiyatı EUR. `DeclaresChannelCurrency` marketplace
  bazında olmalı.

### Ürün/ilan oluşturma

- `putListingsItem` + **Product Type Definitions API**
  (`getDefinitionsProductType`). Ürün tipinin JSON Schema'sı alınır, yerel
  olarak doğrulanır, sonra gönderilir. Şema önbelleğe alınmalı ve
  `PRODUCT_TYPE_DEFINITIONS_CHANGE` bildirimi gelince yenilenmeli.
  ([listings workflows](https://developer-docs.amazon/sp-api/docs/building-listings-management-workflows-guide))
- Var olan ASIN'e teklif eklemek (offer-only), yeni ASIN açmaktan çok daha
  kolay. Yeni ASIN için genellikle GTIN/EAN ya da GTIN muafiyeti, marka
  (Brand Registry) ve ürün tipine özel zorunlu attribute'lar gerekir.
  GTIN/Brand ayrıntıları bu araştırmada resmi sayfadan okunmadı
  [doğrulanmadı].
- Hız: putListingsItem 5 rps / burst 5.

### Sipariş çekme

- **Yeni API: Orders v2026-01-01.** v0'daki getOrders, getOrder,
  getOrderItems, getOrderAddress ve BuyerInfo işlemleri deprecated ve
  **27 Mart 2027'ye kadar geçiş zorunlu**. Yeni adapter doğrudan v2026 ile
  yazılmalı.
  ([changelog](https://developer-docs.amazon/sp-api/changelog/new-introducing-the-orders-api-v2026-01-01),
  [migration guide](https://developer-docs.amazon/sp-api/docs/orders-api-migration-guide))
- `GET /orders/2026-01-01/orders` (`searchOrders`) parametreleri:
  - `createdAfter` **veya** `lastUpdatedAfter` (tam olarak biri zorunlu).
    `lastUpdatedBefore` istek anından en az 2 dakika önce olmalı.
  - `fulfillmentStatuses`: PENDING, UNSHIPPED, PARTIALLY_SHIPPED, SHIPPED,
    CANCELLED, UNFULFILLABLE, PENDING_AVAILABILITY
  - `fulfilledBy`: MERCHANT veya AMAZON
  - `maxResultsPerPage` en fazla 100, sayfalama `paginationToken` ile
  - `includedData`: BUYER, RECIPIENT, PROCEEDS, EXPENSE, PROMOTION,
    CANCELLATION, FULFILLMENT, PACKAGES, TAX, PAYMENT, FULFILLMENT_ORDERS
  - Hız: **0,0056 rps, burst 20** (yaklaşık 3 dakikada 1 istek). `getOrder`
    0,5 rps, burst 30.
  ([searchOrders](https://developer-docs.amazon/sp-api/reference/searchorders.md),
  [orders model](https://github.com/amzn/selling-partner-api-models/blob/main/models/orders-api-model/orders_2026-01-01.json))
- Durum değişiklikleri ve iptaller `lastUpdatedAfter` yoklamasıyla
  görülür. İptal ayrıntısı (kim istedi, sebep) `includedData=CANCELLATION`
  ile gelir.
- v2026'da PII için **Restricted Data Token gerekmiyor**. Erişim rolle
  belirleniyor.
- Gerçek zamanlıya yakın akış için Notifications API `ORDER_CHANGE`
  bildirimi (SQS veya EventBridge) kullanılabilir. Bu bildirimin ayrıntıları
  bu araştırmada okunmadı [doğrulanmadı]. İlk sürümde yoklama yeterli.
- **PII saklama kuralı:** PII teslimattan sonra en fazla **30 gün**
  tutulabilir, yasal zorunluluk varsa şifreli soğuk yedekte. Mevcut sipariş
  tablosunda Amazon müşteri adresini kalıcı tutmak politikayı ihlal eder.
  ([data retention](https://developer-docs.amazon.com/sp-api/docs/protecting-amazon-api-applications-data-encryption-and-recovery))
- Satıcı tarafında telefon ve e-posta, FBA siparişlerinde ve teslimattan
  sonraki FBM siparişlerinde gizleniyor.

### Kargo/takip numarası bildirme

- `POST /orders/v0/orders/{orderId}/shipmentConfirmation`
  (`confirmShipment`). Bu işlem **deprecated değil**, v0'da kalıyor. Gövde:
  `marketplaceId` ve `packageDetail` içinde `packageReferenceId` (sayısal),
  `carrierCode` (Other ise `carrierName`), `shippingMethod`,
  `trackingNumber`, `shipDate`, `orderItems[{orderItemId, quantity}]`.
  Hız: 2 rps, burst 10.
  ([confirmShipment](https://developer-docs.amazon/sp-api/reference/confirmshipment),
  [ordersV0 model](https://github.com/amzn/selling-partner-api-models/blob/main/models/orders-api-model/ordersV0.json))
- Türk kargo firmaları (Yurtiçi, Aras, MNG…) için geçerli `carrierCode`
  listesi doğrulanmadı. Büyük ihtimalle "Other" + `carrierName` yoluna
  düşülecek [doğrulanmadı].

### Hız sınırları özeti

Token bucket modeli kullanılıyor. Gerçek limit her yanıtta
`x-amzn-RateLimit-Limit` başlığında dönüyor, 429'da geri çekilmek gerekiyor
(`CarriesRetryAfter`). En dar boğaz `searchOrders` (yaklaşık 3 dakikada 1).

### Test/sandbox

- Statik sandbox önceden tanımlı sahte cevaplar döndürür. Dinamik sandbox
  bazı API'lerde durumlu cevap verir. Sandbox ölçek testi için değil (5 rps
  / burst 15). RDT testi production ister.
  ([sandbox](https://developer-docs.amazon/sp-api/docs/the-selling-partner-api-sandbox))
- **Gerçek bir test satıcı hesabı sunulmuyor.** Uçtan uca test için
  gerçek bir Seller Central hesabı ve Draft uygulamanın `version=beta` ile
  kendi hesabına yetkilendirilmesi gerekiyor.

### Bilinen tuzaklar

1. Katalog içe aktarmada `searchListingsItems` 1.000 sonuçta kesiliyor ve
   hata vermiyor (sessiz veri kaybı). Tam liste için rapor kullan.
2. Eski XML/flat-file feed'leri artık FATAL dönüyor.
3. `searchOrders` hız sınırı çok dar. Mağaza başına yoklama aralığını
   3 dakikanın altına çekme.
4. Orders v0 ile başlamak, 27 Mart 2027'de ikinci kez iş yapmak demek.
5. PII 30 gün kuralı mevcut sipariş saklama modeline ters düşebilir.
6. 365 günlük yeniden yetkilendirme yapılmazsa bağlantı sessizce düşer.
7. patchListingsItem senkron "ACCEPTED" döner ama ilan sonradan hata
   alabilir. `LISTINGS_ITEM_ISSUES_CHANGE` bildirimi ya da getListingsItem
   (`issues`) ile kontrol edilmeli.

---

## 2. ikas (Admin API, GraphQL)

> ikas'ın iki dokümanı var: eski **ikas.dev** (v1) ve yeni
> **builders.ikas.com** (v2). ikas.dev yeni geliştiricileri builders'a
> yönlendiriyor. Bu not v2'ye göre yazıldı, iki doküman arasındaki
> farklar ayrıca belirtildi.

### Temel gerçekler

| Konu | Değer | Kaynak |
|---|---|---|
| GraphQL uç noktası (v2) | `https://api.myikas.com/api/v2/admin/graphql` | [private auth](https://builders.ikas.com/docs/app-development/private-app/authentication) |
| GraphQL uç noktası (v1, eski) | `https://api.myikas.com/api/v1/admin/graphql` | [ikas.dev intro](https://ikas.dev/docs/intro) |
| Token uç noktası | `POST https://api.myikas.com/api/admin/oauth/token` (v1 dokümanı mağaza alt alan adını gösteriyor: `https://{magaza}.myikas.com/api/admin/oauth/token`) | [authorization](https://builders.ikas.com/docs/admin-api/authorization), [ikas.dev auth](https://ikas.dev/docs/api/getting-started/authentication) |
| Token süresi | `expires_in` 14.400 sn (**4 saat**) | aynı |
| Yetkiler (scope) | read/write_products, _orders, _customers, _campaigns, _inventories | [ikas.dev intro](https://ikas.dev/docs/intro) |

**Dikkat:** builders.ikas.com'daki cURL örnekleri `https://api.myikas.dev/api/v2/admin/graphql`
adresini kullanıyor (`.dev`), metin ise `api.myikas.com` diyor. Hangisinin
doğru olduğu (ya da `.dev`'in test ortamı olup olmadığı) **[doğrulanmadı]**.
Canlı testte ilk denenecek şey bu.

### Önceden yapılması gereken başvurular: iki yol

**A) Private App (mağaza başına, en hızlı).** Satıcı kendi panelinde
*Uygulamalar > Uygulamalarım > Özel Uygulamalar > Standart Uygulama*
adımlarıyla uygulamayı oluşturur. Ekranda `client_id` ve **bir kez
gösterilen** `client_secret` çıkar, satıcı bunları 34Pazar'a yapıştırır.
Partner hesabı gerekmez. Bu yol Trendyol'daki API anahtarı modeliyle aynı.
([private-app](https://builders.ikas.com/docs/app-development/private-app))

- Token `grant_type=client_credentials` ile alınır, refresh token yoktur.
  Süre dolunca yeniden alınır.

**B) Public/Admin App (OAuth, ikas App Store).**
([app-development](https://builders.ikas.com/docs/app-development))

- **ikas Partner hesabı** gerekir. Geliştirme için Partner Panel'den
  **ücretsiz geliştirme mağazası** açılır.
  ([development](https://builders.ikas.com/docs/app-development/admin-app/development))
- Akış OAuth2 Authorization Code. `https://{storeName}.myikas.com/api/admin/oauth/authorize?client_id&redirect_uri&scope&state`
  adresine yönlendirilir (URL, SDK'daki `getOAuthUrl` yardımcısından
  çıkarıldı). Callback'e `code`, `storeName`, `signature`, `state` gelir.
  `signature` = HMAC-SHA256(code, clientSecret) doğrulanmalı. Code, token ile
  değiştirilir.
  ([authorization-steps](https://builders.ikas.com/docs/app-development/admin-app/authorization/authorization-steps),
  [oauth-authorize-api](https://builders.ikas.com/docs/app-development/admin-app/authorization/oauth-authorize-api))
- Refresh token akışının ayrıntısı (grant tipi, süre) dokümanda açıkça
  yazmıyor. Yalnızca "otomatik token yenileme" deniyor **[doğrulanmadı]**.
- **Yayınlama şartları:** doğrulanmış partner hesabı, çalışan OAuth akışı,
  **ikas paneli içinde arayüz veya yönlendirme** ve **en az 2 geliştirme
  mağazasında kurulu olması**. "Herkese açık" yayın ikas ekibince
  inceleniyor (süre yazmıyor [doğrulanmadı]). "**Gizli yayınlama**"
  incelenmiyor ve paylaşılan linkle (`https://apps.ikas.com/tr/uygulama/{app_id}`)
  kuruluyor.
  ([build-publish](https://builders.ikas.com/docs/app-development/admin-app/build-publish))

**Öneri:** Başlangıçta **A (Private App)** yolu seçilmeli. Kod tarafında
token alma dışındaki her şey aynı, çünkü iki tip de aynı GraphQL uç noktasını
kullanıyor. B sonra eklenebilir.

### Sağlık kontrolü

- `query { getMerchant { id merchantName storeName email phoneNumber } }`
  ([get-merchant](https://builders.ikas.com/docs/admin-api/admin-apis/merchant/get-merchant))
- Stok yazmak için gereken stok lokasyonları: `listStockLocation`.
  Satış kanalları: `listSalesChannel`.
  ([list-stock-location](https://builders.ikas.com/docs/admin-api/admin-apis/merchant/list-stock-location),
  [list-sales-channel](https://builders.ikas.com/docs/admin-api/admin-apis/sales-channels/list-sales-channel))

### Ürün/varyant listesini çekme

- `listProduct(pagination: {limit, page})`. Sayfalama **sayfa numarasıyla**
  yapılıyor, yanıtta `count`, `hasNext`, `limit`, `page` var. Sıralama
  createdAt, updatedAt ve name ile. Varyantlar ürünün içinde geliyor
  (`variants { id sku prices stocks ... }`). En büyük `limit` değeri
  yazmıyor **[doğrulanmadı]**.
  ([list-product](https://builders.ikas.com/docs/admin-api/admin-apis/product/list-product),
  [ikas.dev products](https://ikas.dev/docs/api/admin-api/products))

### Stok güncelleme

- `saveVariantStocks(input: { stockInputs: [{ productId, variantId, stockLocationId, stockCount, deleted:false }] })`.
  **Toplu ve senkron** çalışıyor, yanıt `isSuccess` ve `errorInputs
  {variantId, productId}` döndürüyor. **`stockLocationId` zorunlu**, yani
  önce lokasyon eşlemesi yapılmalı.
  ([update-product-stock-count](https://builders.ikas.com/docs/admin-api/admin-apis/product/update-product-stock-count))

### Fiyat güncelleme (stoktan ayrı)

- `updateVariantPrices(input: { priceListId: null, variantPriceInputs: [{ productId, variantId, price:{ sellPrice, currency }, deleted:false }] })`.
  Toplu çalışıyor, yanıt `isSuccess` ve `errorInputs` döndürüyor.
  `priceListId: null` varsayılan fiyat listesi demek.
  ([update-variant-prices](https://builders.ikas.com/docs/admin-api/admin-apis/product/update-variant-prices))
- v1 dokümanında aynı iş `saveVariantPrices` adıyla geçiyor. Hangi adın
  güncel olduğu GraphQL Playground'da teyit edilmeli **[doğrulanmadı]**.

### Ürün oluşturma

- `createProduct(input: { name, type: PHYSICAL, variants: [{ sku, isActive, prices:[{sellPrice}], variantValues:[{variantTypeName, variantValueName}] }] })`.
  v1'de `saveProduct` ile zorunlu alanlar `name`, `type` ve `variants`.
  Kategori ve marka **zorunlu görünmüyor**. Pazaryerlerine göre çok daha
  rahat. ([create-product-with-variants](https://builders.ikas.com/docs/admin-api/admin-apis/product/create-product-with-variants))
- Satış kanalında görünürlük ayrıca `updateProductSalesChannelStatus` ile
  açılıyor.

### Sipariş çekme

- **Yoklama:** `listOrder(pagination, orderedAt, updatedAt, status, orderPackageStatus, orderPaymentStatus, salesChannelId, ...)`.
  `updatedAt: DateFilterInput` filtresi var (v1 dokümanı), yani son
  güncellemeye göre yoklanabiliyor. Örnekte tarih alanı sayısal timestamp
  (`{"gt": 0}`).
  ([list-order](https://builders.ikas.com/docs/admin-api/admin-apis/order/list-order),
  [ikas.dev orders](https://ikas.dev/docs/api/admin-api/orders))
- **Webhook:** `saveWebhooks(input:{ scopes, endpoint, salesChannelIds })`
  ile kaydediliyor. Kapsamlar: `store/order/created`, `store/order/updated`,
  `store/product/created|updated`, `store/customer/created|updated|statusUpdated`,
  `store/stock/created|updated`. İmza client secret ile doğrulanıyor. 200
  dışında bir cevapta **3 kez** tekrar deneniyor, sonra bırakılıyor.
  ([webhooks](https://builders.ikas.com/docs/app-development/ikas-sdk/webhooks),
  [save-webhook](https://builders.ikas.com/docs/admin-api/admin-apis/webhook/save-webhook))
- **Öneri:** webhook'u tetikleyici olarak kullan, asıl kaynak `updatedAt`
  yoklaması olsun, çünkü 3 denemeden sonra olay kayboluyor. İptaller
  `store/order/updated` ve `status` alanıyla görülüyor. Status enum'unun
  tam listesi dokümanda yok **[doğrulanmadı]**.
- İmza doğrulama algoritması yalnızca Node paketiyle
  (`@ikas/admin-api-client`) anlatılıyor. PHP'de nasıl yapılacağı
  belgelenmemiş **[doğrulanmadı]**. Paketin kaynağına bakılmalı.

### Kargo/takip numarası bildirme

- `fulfillOrder(input: { orderId, markAsReadyForShipment, lines, sendNotificationToCustomer, trackingInfoDetail:{ cargoCompany, cargoCompanyId (ör. "MNG_KARGO"), trackingNumber, trackingLink, barcode, isSendNotification } })`.
  Teslim edildi bildirimi için ayrı bir mutation var
  (`updateOrderPackageStatus`, Delivered).
  ([fulfill-order](https://builders.ikas.com/docs/admin-api/admin-apis/order/fulfill-order))

### Hız sınırları

- **10 saniyede en fazla 50 istek**. Aşılınca 429 dönüyor ve yanıtta kalan
  hak görünüyor.
- **Hata oranı cezası:** son 1 saatte hata oranı %25'i geçerse 1 saat
  engel. Hata oranı %60 ve üstündeyken: 1 saatte 300'den fazla istek 30 dk
  engel, 1 günde 3.000'den fazla istek 12 saat engel, 5 günde 9.000'den
  fazla istek **kalıcı engel**.
- Webhook endpoint'inin hata oranı %70'i geçerse 15 dk, 1 saat ya da
  **kalıcı** engel geliyor.
  ([rate-limits](https://builders.ikas.com/docs/admin-api/rate-limits))
- GraphQL karmaşıklık (query cost) limiti dokümanda **yok**. Yalnızca
  istek sayısı ve hata oranı limitleri var **[doğrulanmadı: sorgu
  karmaşıklığı limiti olmadığı da teyit edilmedi]**.

### Test

- Public app için Partner Panel'den ücretsiz geliştirme mağazası alınıyor.
  Private app için gerçek bir mağaza (ya da ikas'ın deneme mağazası)
  gerekiyor. Ayrı bir sandbox API'si yok, test mağazası production API'yi
  kullanıyor.
- GraphQL Playground var: <https://builders.ikas.com/docs/admin-api/graphql-playground>.
  Şema keşfi buradan yapılmalı.

### Bilinen tuzaklar

1. **Hata oranı engeli:** 4xx/5xx dönen istekleri tekrar tekrar göndermek
   (ör. bozuk varyant ID'siyle stok yazmak) mağazayı **kalıcı engele**
   götürebilir. Validasyon hataları retry kuyruğuna alınmamalı.
2. Webhook endpoint'imiz hata verirse ikas webhook'u da engelliyor. Önce
   200 dön, işi kuyrukta yap.
3. Uç nokta karmaşası (v1/v2, `.com`/`.dev`, token adresi alt alan adı mı
   değil mi). İlk bağlantıda üçü de denenip sonuç not edilmeli.
4. `client_secret` bir kez gösteriliyor. Satıcı kaybederse yeni uygulama
   açması gerekiyor.
5. Stok lokasyon bazlı. Tek lokasyon varsayımı çok lokasyonlu mağazada
   yanlış stok yazar.

---

## 3. Ticimax (SOAP / WCF web servisleri)

### Temel gerçekler

- Servisler **her mağazanın kendi alan adında** çalışan WCF/SOAP
  servisleri:
  - Ürün: `https://{magaza-alan-adi}/Servis/UrunServis.svc`
  - Sipariş: `https://{magaza-alan-adi}/Servis/SiparisServis.svc`
  - Üye: `https://{magaza-alan-adi}/Servis/UyeServis.svc`
  - Custom (kargo firmaları, iller vb.): `https://{magaza-alan-adi}/Servis/CustomServis.svc`
  - WSDL: `...svc?singleWsdl`
  ([UrunServis.pdf](https://static.ticimax.com/dokumanlar/UrunServis.pdf),
  [SiparisServis.pdf](https://static.ticimax.com/dokumanlar/SiparisServis.pdf),
  [CustomServis.pdf](https://static.ticimax.com/dokumanlar/CustomServis.pdf))
- Kimlik doğrulama: **her çağrının ilk parametresi `UyeKodu`** (dokümanda
  "servis sağlayıcısı tarafından verilen şifre", pratikte WS yetki kodu).
  OAuth, token ya da süre yok.
- **Resmi PDF'ler eski** (UrunServis Ekim 2020, SiparisServis Temmuz 2021).
  Bu yüzden canlı WSDL'e bakıldı (`https://demo.ticimax.com/Servis/UrunServis.svc?singleWsdl`,
  8 Ekim 2026). Canlı WSDL'de UrunServis'te **103**, SiparisServis'te **69**
  işlem var ve PDF'lerde olmayan işlemler de bulunuyor (`UpdateUrunFiyat`,
  `SelectUrunStokFiyat`, `SelectSiparisDurumLog`, `SetSiparisDurumListe`…).
  **Geliştirici PDF'e değil, WSDL'e göre kod yazmalı.**

### Önceden yapılması gerekenler

- Satıcı yetki kodunu Ticimax yönetim panelindeki **"WS Yetki Kodu
  Yönetimi"** sayfasında oluşturuyor. Bu bilgi üçüncü taraf PHP kütüphanesi
  dokümanından alındı, resmi Ticimax sayfası Cloudflare yüzünden
  okunamadı **[kısmen doğrulandı]**.
  ([hasokeyk/ticimax-php](https://packagist.org/packages/hasokeyk/ticimax-php))
- Web servis erişiminin her pakette açık olup olmadığı ya da ek ücretli
  olup olmadığı **[doğrulanmadı]**. Satıcıya sorulmalı, gerekirse Ticimax
  destekten (destekalani.com) açtırılmalı.
- Partner veya uygulama onayı **yok**. Mağaza bazlı anahtar yeterli.
- Yetki kodunun hangi servis veya işlem izinleriyle sınırlanabildiği
  (yalnızca ürün, yalnızca sipariş gibi) **[doğrulanmadı]**.

### Sağlık kontrolü

- Ayrı bir "ben kimim" işlemi yok. Hafif bir çağrı önerisi:
  `SelectParaBirimi(UyeKodu)` ya da `SelectUrunCount(UyeKodu, filtre)`.
  Yanlış kodda dönen hata biçimi **[doğrulanmadı]**. Cevaptaki
  `IsError`/`ErrorMessage` alanlarına bakılmalı.

### Ürün/varyant listesini çekme

- `SelectUrun(UyeKodu, UrunFiltre f, UrunSayfalama s)`. Sayfalama
  **offset/limit** ile: `BaslangicIndex`, `KayitSayisi`, `SiralamaDeger`
  ("Id"), `SiralamaYonu` (ASC/DESC). Toplam için `SelectUrunCount`. Ürün
  kartı içinde `Varyasyonlar` listesi geliyor. Her varyasyonda ID,
  StokKodu, Barkod, SatisFiyati, IndirimliFiyati, StokAdedi, KdvOrani ve
  `Ozellikler[{Tanim, Deger}]` var.
- Canlı WSDL'deki `UrunFiltre` alanları `DuzenlemeTarihiBaslangic/Bitis`,
  `StokGuncellemeTarihiBaslangic/Bitis`, `StokKoduList`, `UrunKartiIDList`.
  Artımlı (delta) senkron yapılabiliyor (PDF'te yok, WSDL'de var).
- Varyasyon bazlı liste için `SelectVaryasyon(UyeKodu, VaryasyonFiltre, UrunSayfalama)`,
  hafif stok ve fiyat okuması için `SelectUrunStokFiyat`.
- En büyük `KayitSayisi` değeri yazmıyor **[doğrulanmadı]**. PDF
  örneğinde 100 kullanılıyor.

### Stok güncelleme

- `StokAdediGuncelle(UyeKodu, List<Varyasyon> urunler)`. Her öğede yalnızca
  `ID` (varyasyon ID) ve `StokAdedi` yeterli. **Toplu ve senkron**. Canlı
  WSDL'e göre dönüş tipi `int`. Değerin anlamı (güncellenen kayıt sayısı
  olabilir) **[doğrulanmadı]**. Satır bazlı hata dönmüyor, toplu çağrıdan
  sonra okuyup doğrulamak gerekebilir.
  (UrunServis.pdf §11.5)
- Mağaza bazlı stok için `SaveMagazaStok` ve `SelectMagazaStok` kullanılıyor.

### Fiyat güncelleme

- PDF yolu: `VaryasyonGuncelle(UyeKodu, Varyasyon, VaryasyonAyar)`.
  `VaryasyonAyar` içinde hangi alanın yazılacağı bayrakla seçiliyor
  (`SatisFiyatiGuncelle`, `IndirimliFiyatiGuncelle`, `StokAdediGuncelle`…).
  **Tekil**: her çağrı tek varyasyon. Dikkat: `ParaBirimiID` ve
  `SatisFiyati` "zorunlu" işaretli.
- Canlı WSDL yolu (PDF'te yok): `UpdateUrunFiyat(UyeKodu, List<UpdateUrunFiyat>, UpdateUrunFiyatAyar)`
  toplu fiyat güncellemesi gibi duruyor. Ayar bayrakları
  `UrunIdGoreGuncelle`, `TedarikciKodunaGoreGuncelle`,
  `BarkodKodunaGoreGuncelle`, `IndirimliFiyatGuncelle`… Bu işlemin
  davranışı dokümansız **[doğrulanmadı]**, test mağazada denenmeli.

### Ürün oluşturma

- `SaveUrun(UyeKodu, ref List<UrunKarti>, UrunKartiAyar, VaryasyonAyar)`.
  Aynı işlem hem ekliyor hem güncelliyor: `ID=0` yeni kayıt, `ID>0`
  güncelleme.
- Zorunlu alanlar (PDF): `UrunAdi`, `Aciklama`, `AnaKategori` +
  `AnaKategoriID` (0 olursa ürün görünmüyor), `Kategoriler`, `MarkaID`
  (kayıtlı değilse ürün eklenmiyor), `TedarikciID`, `Resimler` (URL
  listesi), `SatisBirimi`, `UcretsizKargo`, `TedarikciKodu` (**tekil
  anahtar olmalı**), en az bir `Varyasyon` (zorunlu alanlar `ParaBirimiID`
  ve `SatisFiyati`). Varyant özellikleri serbest metin
  (`Tanim="Renk", Deger="Mavi"`).
- Kategori, marka ve tedarikçi önce `SaveKategori`, `SaveMarka` ve
  `SaveTedarikci` ile açılmalı ya da `Select*` ile eşlenmeli.

### Sipariş çekme

- **Yalnızca yoklama.** Resmi dokümanda webhook yok.
- `SelectSiparis(UyeKodu, WebSiparisFiltre f, WebSiparisSayfalama s)`.
  Tüm integer filtrelerde `-1` "filtre yok" demek.
  - PDF'te yalnızca `SiparisTarihiBas/Son` (oluşturma tarihi) var.
  - **Canlı WSDL'de ek olarak `DuzenlemeTarihiBas/Son` ve
    `DurumTarihiBas/Son` var.** Son güncellemeye göre yoklama mümkün
    görünüyor, test edilmeli **[WSDL'de var, davranış doğrulanmadı]**.
  - `EntegrasyonAktarildi` (0 = aktarılmayan, 1 = aktarılan, -1 = hepsi) ve
    `SetSiparisAktarildi(UyeKodu, siparisId)`: Ticimax'ın klasik
    "aktarılmayanları çek, aktarıldı işaretle" modeli. Dikkat: bu bayrak
    mağaza genelinde **tek** gibi duruyor. Satıcının ERP'si de aynı bayrağı
    kullanıyorsa çakışır **[doğrulanmadı]**.
- Sipariş durumları: 0 Ön sipariş, 1 Onay bekliyor, 2 Onaylandı, 3 Ödeme
  bekliyor, 4 Paketleniyor, 5 Tedarik ediliyor, 6 Kargoya verildi, 7 Teslim
  edildi, **8 İptal edildi**, 9 İade edildi, 10 Silinmiş, 11–17 iade ve
  iptal talepleri. Ödeme durumu: 0 Onay bekliyor, 1 Onaylandı, 2 Hatalı,
  3 İade, 4 İptal. (SiparisServis.pdf s.2–4)
- Kalemler `SelectSiparisUrun` ile, durum geçmişi `SelectSiparisDurumLog`
  ile okunuyor (WSDL).

### Kargo/takip numarası bildirme

- `SaveKargoTakipNo(UyeKodu, siparisId, kargoKodu, kargoTakipNo, kargoTakipLink, BarkodBilgisi, KargoTakipLinkGoster)`.
  Dönüş `string`. Ardından gerekirse `SetSiparisKargoyaVerildi(UyeKodu, siparisId)`
  çağrılıyor. Paket bazlı alternatif
  `SaveSiparisKargoPaketKargoTakipNo`. Kargo firma kodları
  `GetKargoSecenek` / `SelectKargoFirmalari` ile alınıyor.
  (SiparisServis.pdf §15, §12)

### Hız sınırları

- Resmi dokümanda **hiçbir limit yazmıyor [doğrulanmadı]**. Mağaza kendi
  sunucusunda çalıştığı için saldırgan paralellikten kaçınılmalı. Mağaza
  başına tek eşzamanlı çağrı ve toplu işlemler (StokAdediGuncelle)
  önerilir.

### Test

- Resmi sandbox **yok [doğrulanmadı]**. `demo.ticimax.com` WSDL'i açık ama
  yetki kodu olmadan çağrı yapılamaz. Gerçek ya da deneme Ticimax mağazası
  ve yetki kodu gerekiyor.

### Bilinen tuzaklar

1. **PDF ile canlı WSDL farklı.** Sınıflar ve işlemler WSDL'den üretilmeli,
   PHP `SoapClient` ile `?singleWsdl` kullanılmalı (PHP `ext-soap` gerekir).
   WSDL mağaza başına çekilmeli ya da önbelleğe alınmalı.
2. .NET WCF: PDF, `security mode="Transport"` (HTTPS) bağlamasını şart
   koşuyor. Eski PDF'lerde `http://` adresleri geçiyor, **https** kullanın.
3. Alan adları Türkçe karakterli (`İndirimliFiyati`, `Tanım`). XML
   serileştirmesinde birebir eşleşmezse alan **sessizce boş** gider (sessiz
   veri kaybı deseni).
4. `SaveUrun` + `VaryasyonAyar` bayrakları: bayrak `false` ise
   gönderdiğin değer yok sayılıyor. Hata vermiyor.
5. `TedarikciKodu` tekil anahtar. Ürün eşlemesinde SKU/StokKodu ile
   karıştırılmamalı.
6. Sipariş "aktarıldı" bayrağı satıcının başka entegrasyonlarıyla
   çakışabilir.
7. Yetki kodu düz metin ve süresiz. Sızarsa tüm mağaza açılır. Şifreli
   saklanmalı.

---

## 4. Yetenek → işlem eşleme tablosu (özet)

| Yetenek | Amazon SP-API | ikas | Ticimax |
|---|---|---|---|
| Bağlantı | OAuth (Seller Central consent) → refresh token (365 gün) → LWA access 1 sa | Private app client_credentials (4 sa) / Public app OAuth code | Mağaza alan adı + `UyeKodu` (süresiz) |
| Sağlık | `GET /sellers/v1/marketplaceParticipations` | `getMerchant` | `SelectParaBirimi` / `SelectUrunCount` (öneri) |
| Katalog içe aktarma | Rapor `GET_MERCHANT_LISTINGS_ALL_DATA` (+ `searchListingsItems` ≤1.000) | `listProduct` (page/limit) | `SelectUrun` (offset/limit) + `SelectUrunCount` |
| Stok | `patchListingsItem` (fulfillment_availability) / `JSON_LISTINGS_FEED` (asenkron) | `saveVariantStocks` (toplu, lokasyonlu) | `StokAdediGuncelle` (toplu) |
| Fiyat | Aynı PATCH/feed, `purchasable_offer` | `updateVariantPrices` (toplu) | `VaryasyonGuncelle` (tekil) / `UpdateUrunFiyat` (WSDL, dokümansız) |
| Ürün oluştur | `putListingsItem` + Product Type Definitions şeması | `createProduct` (name, type, variants) | `SaveUrun` (kategori, marka, tedarikçi, TedarikciKodu zorunlu) |
| Sipariş | `searchOrders` v2026-01-01 (`lastUpdatedAfter`) + `ORDER_CHANGE` | `listOrder` (`updatedAt`) + webhook `store/order/updated` | `SelectSiparis` (`DuzenlemeTarihi*` WSDL'de) + `EntegrasyonAktarildi` |
| Kargo | `confirmShipment` (v0, deprecated değil) | `fulfillOrder` (trackingInfoDetail) | `SaveKargoTakipNo` + `SetSiparisKargoyaVerildi` |
| Hız sınırı | İşlem başına token bucket. searchOrders ≈ 3 dakikada 1 | 50 istek / 10 sn + hata oranı engelleri | Yazılı değil |
| Sandbox | Statik/dinamik sandbox, gerçek test hesabı yok | Partner geliştirme mağazası | Yok |

---

## 5. Kullanıcıdan istenecekler

**Amazon**
1. Profesyonel Amazon satıcı hesabı (TR ve/veya DE). Uçtan uca test için
   gerçek hesap şart.
2. **Solution Provider Portal'da Public Developer kaydı** (34Devs/34Pazar
   adına): şirket bilgileri, herkese açık web sitesi (34pazar.com),
   kullanım senaryosu metni, güvenlik anketi cevapları.
3. Rol seçimi: Product Listing, Pricing, Inventory and Order Tracking +
   **Direct-to-Consumer Shipping (Restricted)**. Kısıtlı rol için veri
   güvenliği değerlendirmesine hazırlık gerekiyor: PII akış diyagramı,
   şifreleme, 30 günlük saklama politikası.
4. Uygulama kaydından sonra LWA `client_id` / `client_secret`, OAuth
   redirect URI (ör. `https://34pazar.com/channels/amazon/callback`) ve
   Seller Central'da uygulamanın "login URI"si.
5. Onay süresi belirsiz. Başvuru **hemen** yapılmalı, kod beklemeden.

**ikas**
1. Başlangıç (önerilen): her satıcıdan panelde açacağı **Private App
   `client_id` + `client_secret`** ve mağaza adı (`{magaza}.myikas.com`).
2. Public app'e geçilecekse: **ikas Partner hesabı** (doğrulanmış), Partner
   Panel'de en az 2 geliştirme mağazası, uygulama kaydı, redirect URI.
3. Test için bir ikas mağazası (geliştirme mağazası ya da kullanıcının
   deneme mağazası).

**Ticimax**
1. Satıcının mağaza alan adı ve panelde **WS Yetki Kodu Yönetimi**'nden
   oluşturacağı yetki kodu.
2. Satıcının paketinde web servis erişimi olduğunun teyidi (Ticimax
   destek).
3. Satıcının başka bir ERP veya entegratörünün sipariş "aktarıldı"
   bayrağını kullanıp kullanmadığı bilgisi.
4. Test için bir Ticimax mağazası ve yetki kodu. Ticimax kendi sandbox'ını
   sunmuyor.

---

## 6. Kanal başına uygulama zorluğu

| Kanal | Zorluk | Neden |
|---|---|---|
| **ikas** | **Kolay–orta** | Modern GraphQL. Stok ve fiyat toplu ve senkron, `updatedAt` filtresi ve webhook var. Private app ile partner onayı gerekmiyor. Riskler: hata oranına göre kalıcı engel (retry disiplini şart), stok lokasyon eşlemesi, uç nokta belirsizliği (v1/v2, .com/.dev). |
| **Ticimax** | **Orta** | Kavram olarak basit (anahtar + SOAP, onay yok) ama dokümanlar 2020–2021'den kalma ve canlı WSDL'den farklı. PHP SoapClient + WCF + Türkçe alan adları serileştirme tuzakları var. Hız limiti ve sandbox yok. Fiyat güncelleme ya tekil ya da dokümansız. Sipariş için yalnızca yoklama var ve "aktarıldı" bayrağı paylaşımlı. |
| **Amazon SP-API** | **Zor** | Teknik olarak en kapsamlı API, ama asıl zorluk teknik değil: public developer kaydı, kısıtlı rol (PII) güvenlik incelemesi ve belirsiz onay süresi. Ayrıca: 365 günlük yeniden yetkilendirme, PII 30 gün saklama kuralı, asenkron feed ve rapor akışları, ürün tipi JSON Schema'sı ile ilan oluşturma, çok dar sipariş hız sınırı, 27 Mart 2027 Orders v0 kapanışı (doğrudan v2026 yazılmalı) ve TR/DE için muhtemelen ayrı yetkilendirme. |

**Önerilen sıra:** Amazon başvurusu **bugün** yapılmalı, çünkü onay
beklenirken iş durmaz. Bu sırada ikas (en hızlı değer), ardından Ticimax
yazılır. Amazon kodu onay gelince başlar.
