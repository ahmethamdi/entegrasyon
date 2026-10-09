# Yeni kanallar: API notları (Amazon SP-API, ikas, Ticimax, N11)

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

### Kod durumu (8 Ekim 2026)

`app/Domain/Channels/Adapters/Ikas/` yazıldı, kanal `is_active = false`.
Şema ikas'ın kendi paketinden alındı (`@ikas/admin-api-client` 2.1.0,
`dist/api/admin/v2/generated/index.d.ts`), gerçek mağazayla sınanmadı.

- Bağlantı: Private App `client_id`/`client_secret` + mağaza adı (hesap
  kimliği). İlk token bağlanırken `TokenRefresher::refreshConnection()`,
  sonrakiler `credentials:refresh` (pay 1 saat). Sağlık kontrolü
  `getMerchant.storeName` ile mağaza adını karşılaştırır.
- İçe aktarma: her varyant ayrı ürün, `external_parent_id` = ürün id,
  SKU'suz varyant `IKAS-{uuid}`. Görsel alınmıyor (şemada yalnız `imageId`).
- Stok: `saveVariantStocks`, ayar ekranındaki lokasyon; tek lokasyonlu
  mağazada seçimsiz. Fiyat: `updateVariantPrices` (karşılaştırma fiyatı →
  `sellPrice`, satış → `discountPrice`), para birimi bağlantı ayarı (TRY).
- Sipariş: `listOrder(updatedAt ≥, sort updatedAt)`, ms epoch. Kayıt başına
  `created` + iptal kalemi başına `cancelled` + `REFUND_DELIVERED` kalemi
  başına `returned`. Kısmi iptalde ikas kalemi böler; iptal SKU ile asıl
  kaleme düşer.
- Kargo (9 Eki 2026): `listOrder(id)` ile kalemler + paketler okunur, numara
  silinmemiş/iptal edilmemiş bir pakette varsa istek atılmaz; yalnız
  `UNFULFILLED` kalemler `fulfillOrder` ile gider. `trackingInfoDetail`:
  `trackingNumber`, `cargoCompany`, firma `listCargoCompany`'de adıyla
  bulunursa `cargoCompanyId`. `FORBIDDEN`/403 → VALIDATION (devre kesici
  açılmaz).
- Yazılmadı: ürün açma (`SupportsCatalog`), webhook.

**Gerçek mağazada ilk bakılacaklar:** `sort: "createdAt"`/`"updatedAt"`
değerleri kabul ediliyor mu · `Timestamp` gerçekten ms mi · kısmi iptal
gerçekten kalem bölüyor mu · iade akışında `REFUND_DELIVERED` görünüyor mu ·
`discountPrice: null` eski indirimi siliyor mu.

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

### Kod durumu (8 Ekim 2026)

`app/Domain/Channels/Adapters/Ticimax/` yazıldı, kanal `is_active = false`.
Canlı WSDL'e göre; filtre varsayılanları `hasokeyk/ticimax-php`'den teyitli.
Gerçek mağazayla sınanmadı.

- **SoapClient yok:** zarf `TicimaxSoap` ile metin olarak kurulur,
  `ChannelHttpClient::postRaw()` ile gider (günlük, maskeleme, SSRF,
  `Http::fake`). Karmaşık tip alanları ordinal alfabetik yazılır.
- Bağlantı: alan adı (hesap kimliği) + WS yetki kodu (`uye_kodu`, kasada).
  Sağlık: `SelectUrunCount`.
- "Hepsi" filtresi: durum alanları -1, kimlik alanları 0.
- İçe aktarma: `SelectUrun` (ID artan, ofset), varyasyon = ürün,
  `external_parent_id` = kart ID, metadata `kdv_dahil`/`kdv_orani`/
  `para_birimi_id`/`para_birimi`. KDV hariç fiyat brüte çevrilir.
- Stok: `StokAdediGuncelle` toplu (ID + StokAdedi). Fiyat:
  `VaryasyonGuncelle` tekil, yalnız fiyat bayrakları, KDV hariçse net yazılır,
  farklı para biriminde varyasyon yazılmaz.
- Sipariş: `SelectSiparis` iki geçiş (DuzenlemeTarihiBas, DurumTarihiBas),
  TR saati, 3 sa pay. Durum 8/9 → bütün kalemler `SelectSiparisUrun`'dan
  iptal/iade, yalnız sipariş daha önce alındıysa. `EntegrasyonAktarildi`
  işaretlenmez.
- Kargo (9 Eki 2026, canlı WSDL ile teyitli): `SelectSiparis` (dönen ID
  karşılaştırılır) → `SiparisKargoTakipNoKontrol` → firma
  `SelectKargoFirmalari`'nda adıyla bulunursa `SetSiparisKargoFirmaId` →
  `SaveKargoTakipNo` (kargoKodu boş) → durum 6/7 değilse
  `SetSiparisKargoyaVerildi`. Yalnız eksik adım atılır. Yetki reddi →
  VALIDATION.
- Yazılmadı: ürün açma, kısmi kalem iptali (kalem durum
  kodları belgesiz → `SelectSiparisUrunDurumlari` canlıda okunmalı).

**Gerçek mağazada ilk bakılacaklar:** `StokAdediGuncelle` dönüş sayısının
anlamı · `VaryasyonGuncelle` yalnız bayraklı alanları mı değiştiriyor ·
`WebSiparisUrun.Tutar` birim mi toplam mı, KDV dahil mi · tarih filtresi
saat dilimi · durum değişikliği `DuzenlemeTarihi`'ni güncelliyor mu.

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

## 4. N11 (REST, iade/iptal için SOAP)

### Temel gerçekler

- **Karar: REST.** N11 ürün yazma SOAP servislerini **Ocak 2025'te
  kapattı**: `ProductStockService` (tüm stok işlemleri),
  `ProductSellingService` (satışa aç/kapat) ve `ProductService` içindeki
  `SaveProduct`, `UpdateProductBasic`, `UpdateProductPrice*`,
  `UpdateDiscountValue*`. Sipariş listeleme, onay ve bölme de
  **Aralık 2024'te REST'e taşındı**.
  ([Change Log, 25 Ocak 2025 ve 25 Aralık 2024 girdileri](https://developer.n11.com/documentation/changelog/))
- SOAP **tamamen kapanmadı.** Güncel resmi dokümanda hâlâ SOAP olarak
  anlatılan servisler: iade talepleri (`ReturnService`), parçalı iptal
  (`ClaimCancelService`), fatura linki (`SellerInvoiceService`), ürün
  soru-cevap, katalog arama (`CatalogService`).
  ([İade Talepleri](https://developer.n11.com/documentation/iade-entgrasyonu/iade-talepleri-servisi/),
  [Parçalı İptal](https://developer.n11.com/documentation/iade-entgrasyonu/parcali-iptal-talebi/),
  [Fatura Linki](https://developer.n11.com/documentation/n11-siparis-entegrasyonu/fatura-linki-gonderme/))
- 34Pazar için gereken SOAP çağrısı **yalnızca iade listesi**
  (`ClaimReturnList`). Satıcı tarafı parçalı iptal gerekirse
  `ClaimCancelPartial` da eklenir.
- Tek host: `https://api.n11.com`. Yol önekleri: `/ms/` (ürün ve task),
  `/rest/` (sipariş ve teslimat), `/cdn/` (kategori), `/ws/` (SOAP).
- **Resmi doküman taşındı:** `magazadestek.n11.com/satis-surecleri/restapi-*`
  sayfaları artık "Dokümantasyon Adresimiz Güncellendi" deyip
  `developer.n11.com`'a yönlendiriyor (8 Ekim 2026, gerçek Chrome ile
  bakıldı). `magazadestek` Cloudflare yüzünden curl/WebFetch'e 403
  veriyor. `developer.n11.com` ise sorunsuz açılıyor ve sitemap'i var
  (`/wp-sitemap-posts-manual_documentation-1.xml`).

### Kaynaklar

Resmi (developer.n11.com, sayfa güncelleme tarihleri sitemap'ten):

| Konu | URL | Son güncelleme |
|---|---|---|
| Change Log (duyurular) | https://developer.n11.com/documentation/changelog/ | 14 Eyl 2026 |
| Sipariş listeleme | https://developer.n11.com/documentation/n11-siparis-entegrasyonu/siparis-listeleme-servisi/ | 15 Eyl 2026 |
| Sipariş onaylama | https://developer.n11.com/documentation/n11-siparis-entegrasyonu/siparis-kalemlerini-guncelleme-servisi/ | 25 Ağu 2026 |
| Paket bölme | https://developer.n11.com/documentation/n11-siparis-entegrasyonu/siparis-paket-bolme/ | 25 Ağu 2026 |
| Miktar bazlı bölme + kalem iptali | https://developer.n11.com/documentation/n11-siparis-entegrasyonu/miktar-bazli-paket-bolme-siparis-urun-iptali/ | 25 Ağu 2026 |
| Toplama talebi (Ceva/Horoz) | https://developer.n11.com/documentation/n11-siparis-entegrasyonu/toplama-talebi-olusturma/ | 25 Ağu 2026 |
| Satıcı ürün sorgulama | https://developer.n11.com/documentation/n11-marketplace-entegrasyonu/satici-urun-sorgulama/ | 8 Eyl 2026 |
| Fiyat-stok güncelleme | https://developer.n11.com/documentation/n11-marketplace-entegrasyonu/urun-fiyat-stok-guncelleme/ | 25 Ağu 2026 |
| Ürün bilgisi güncelleme | https://developer.n11.com/documentation/n11-marketplace-entegrasyonu/urun-bilgisi-guncelleme/ | 25 Ağu 2026 |
| Task detail | https://developer.n11.com/documentation/n11-marketplace-entegrasyonu/task-detail-sorgulama/ | 25 Ağu 2026 |
| Ürün yükleme | https://developer.n11.com/documentation/n11-marketplace-entegrasyonu/urun-yukleme/ | 25 Ağu 2026 |
| Ürün silme | https://developer.n11.com/documentation/n11-marketplace-entegrasyonu/urun-silme/ | 25 Ağu 2026 |
| Kategori ağacı / özellikleri | https://developer.n11.com/documentation/n11-marketplace-entegrasyonu/kategori-agaci-listeleme/ · https://developer.n11.com/documentation/n11-marketplace-entegrasyonu/kategori-ozellikleri-listeleme/ | Ağu 2026 |
| Hata mesajları | https://developer.n11.com/documentation/bilgilendirme/api-hata-mesajlari-ve-aciklamalari/ | 26 Ağu 2026 |
| SOAP ↔ REST sipariş alan karşılığı | https://developer.n11.com/documentation/bilgilendirme/soap-restapi-siparis-servisi-karsilastirmasi/ | 26 Ağu 2026 |

Resmi (magazadestek.n11.com, gerçek Chrome ile okundu): [API hesapları](https://magazadestek.n11.com/satis-surecleri/api-hesaplarina-nereden-ulasilir-2285),
[Kargo süreci](https://magazadestek.n11.com/satis-surecleri/kargo-sureci-141).
Eski REST sayfalarının 2025 kopyaları Wayback'te var (ör.
[sipariş listeleme, Haz 2025](https://web.archive.org/web/20250615084930/https://magazadestek.n11.com/satis-surecleri/restapi-siparis-listeleme-10413)).
Eski ve yeni sürüm arasındaki farklar aşağıda ayrıca not edildi.

Üçüncü taraf (yalnızca çapraz kontrol için): [`@hubcommerce/n11` 1.0.1](https://www.npmjs.com/package/@hubcommerce/n11)
(npm, 20 Eyl 2026, MIT). Paket indirildi, çalıştırılmadı, yalnızca
okundu. Yolları resmi dokümanla birebir aynı. Sandbox ve webhook
olmadığını o da söylüyor.

Canlı ölçüm (8 Ekim 2026, sahte anahtarla, veri yazılmadı):
`https://api.n11.com/ws/*.wsdl` hâlâ servis ediliyor. Ayrıntılar
Tuzaklar bölümünde.

### Önceden yapılması gerekenler

- Satıcı API anahtarını panelde kendisi oluşturur: **Satıcı Ofisi
  (so.n11.com) > Hesabım > API Hesapları > Yeni Hesap Oluştur**. API
  anahtarı ekranda görünür, **API şifresi (secret) satıcının e-postasına
  gönderilir.**
  ([API hesapları](https://magazadestek.n11.com/satis-surecleri/api-hesaplarina-nereden-ulasilir-2285))
- Partner veya uygulama onayı **yok**. Mağaza bazlı key + secret yeterli.
- Ürün açmak için satıcının panelde en az bir **teslimat (kargo)
  şablonu** olmalı: Hesabım > Teslimat Bilgileri. Şablonun **adı** API'de
  `shipmentTemplate` olarak gönderilir.
  ([Ürün yükleme](https://developer.n11.com/documentation/n11-marketplace-entegrasyonu/urun-yukleme/))
- Sipariş onayını API yerine otomatiğe bağlatmak isteyen satıcı
  `sellerintegration@n11.com` adresine yazar.
  ([Sipariş onaylama](https://developer.n11.com/documentation/n11-siparis-entegrasyonu/siparis-kalemlerini-guncelleme-servisi/))
- Teknik sorular için resmi kanal da `sellerintegration@n11.com`.

### Kimlik

- REST: her istekte iki başlık: `appKey` ve `appSecret`. Authorization
  başlığı yok ("no auth"). Doküman başlık adını sayfaya göre `appKey` ya
  da `appkey` yazıyor. HTTP başlıkları büyük-küçük harfe duyarsız, ikisi
  de çalışır.
- **Kategori servisleri (`/cdn/`):** doküman hâlâ "yalnızca appKey"
  diyor. Ama Change Log'a göre **15 Ağustos 2025'ten beri key + secret
  zorunlu**. Kategori çağrılarında da ikisi birden gönderilmeli. Ölçüm:
  başlıksız `GET /cdn/categories` → 403.
- SOAP: gövdede `<auth><appKey/><appSecret/></auth>`.
- Ürün task gövdelerinde zorunlu `payload.integrator` alanı var
  (serbest metin). N11 "tüm gönderimlerde aynı değeri kullanın" diyor.
  Öneri: sabit `34Pazar`.
- **IP kısıtı:** resmi dokümanda geçmiyor **[doğrulanmadı]**.
- **Sandbox/test ortamı:** resmi dokümanda yok. Tek host `api.n11.com`.
  Üçüncü taraf `@hubcommerce/n11` de "sandbox yok" diyor
  **[resmi olarak doğrulanmadı]**. Test gerçek mağazada yapılır.
- **Hız sınırları (resmi olarak yazanlar):** sipariş listeleme
  **dakikada 1000 istek**. Ürün soru listeleme dakikada 1. Diğer uç
  noktalar için sayı yazmıyor **[doğrulanmadı]**. Ürün yazma tarafında
  limit yerine istek başına **1000 SKU** sınırı var.

### Sağlık kontrolü

- Önerilen: `GET https://api.n11.com/ms/product-query?page=0&size=1`.
  Key ve secret'ı birlikte doğrular, cevap küçük. `totalElements` ürün
  sayısını da verir.
- `GET /cdn/categories` **önerilmez**: tüm kategori ağacını tek parça
  döndürüyor (büyük cevap).
- Yanlış anahtarla dönen hata (8 Ekim 2026'da ölçüldü):
  - `/ms/...` → **401**, JSON:
    `{"@type":"SellerApiUserUnauthorizedException","message":"Apide doğrulama işlemi başarısız oldu.", ...}`
  - `/rest/...` → **403**, `text/plain` gövde `Authentication failed`
    (JSON değil).
  - SOAP → HTTP 200, gövdede `<status>failure</status><errorCode>SELLER_API.authenticationFailed</errorCode>`.
    `/ws/orderService/` ise 403 `Authentication failed` döndü.

### Ürün içe aktarma

- `GET https://api.n11.com/ms/product-query`
  ([kaynak](https://developer.n11.com/documentation/n11-marketplace-entegrasyonu/satici-urun-sorgulama/))
- Filtreler (hepsi isteğe bağlı): `id` (n11 ürün kodu), `productMainId`,
  `stockCode` (**tek değer**), `saleStatus` (`On_Sale`, `Out_Of_Stock`),
  `productStatus` (`Active`, `InCatalogApproval`, `Suspended`,
  `CatalogRejected`, `Prohibited`, `Unlisted`, `InApproval`),
  `brandName`, `categoryIds`, `sender` (`SELLER` varsayılan, `N11` =
  n11depom, `ALL`), `page` (0'dan), `size` (varsayılan 20, **en çok
  250**).
- Sayfalama Spring tarzında: `content[]`, `totalElements`,
  `totalPages`, `number`, `last`. Doküman "content boş dönen sayfayı
  son sayfa say" diyor.
- **Varyant modeli:** her satır **bir SKU**. Ayrı bir varyant dizisi
  yok. Aynı modelin SKU'ları `productMainId` (satıcının grup kodu) ve
  `groupId` (n11 katalog grup ID) ile gruplanır. Varyant değerleri
  `attributes[{attributeId, attributeName, attributeValue}]` içinde
  (ör. Renk, Beden).
- Satır alanları: `n11ProductId`, `sellerId`, `sellerNickname`,
  `stockCode`, `title`, `description`, `categoryId`, `productMainId`,
  `status`, `saleStatus`, `preparingDay`, `shipmentTemplate`,
  `maxPurchaseQuantity`, `customTextOptions`, `catalogId`, `barcode`,
  `groupId`, `currencyType` (`TL`/`USD`/`EUR`), `salePrice`,
  `listPrice`, `quantity`, `rejectInfo` (yalnızca CatalogRejected),
  `attributes`, **`imageUrls`**, `vatRate`, `commissionRate`, `sender`.
- Eşleme anahtarı: `stockCode`. Tüm stok/fiyat yazımları buna göre
  yapılır.
- Artımlı (delta) filtre (güncellenme tarihi) **yok**. Tam tarama
  gerekir.

### Stok/fiyat

- `POST https://api.n11.com/ms/product/tasks/price-stock-update`
  ([kaynak](https://developer.n11.com/documentation/n11-marketplace-entegrasyonu/urun-fiyat-stok-guncelleme/))
- **Toplu ve asenkron.** Bir istekte **en çok 1000 SKU**.
  ```json
  {"payload":{"integrator":"34Pazar","skus":[
    {"stockCode":"ABC-1","listPrice":2000.00,"salePrice":1600.00,"quantity":2,"currencyType":"TL"}
  ]}}
  ```
  Cevap: `{"id":1092,"type":"SKU_UPDATE","status":"IN_QUEUE","reasons":["1 sku işlenmeye alındı."]}`.
  Gövde bütünüyle reddedilirse `status: "REJECT"` döner, `reasons`
  alanında açıklama olur.
- Yalnızca stok için yalnızca `stockCode` + `quantity` gönderilir.
  Gönderilmeyen alanlar değişmez.
- Fiyat kuralları:
  - `listPrice` (üstü çizili PSF) ile `salePrice` (TSF) **birlikte**
    gönderilmeli.
  - `listPrice >= salePrice` olmalı. Eşit olabilir.
  - Ondalık ayracı nokta, **noktadan sonra 2 hane**. Aksi halde FAIL.
- Sonuç okuma: `POST https://api.n11.com/ms/product/task-details/page-query`,
  gövde `{"taskId":1092,"pageable":{"page":0,"size":1000}}`.
  - Task durumu `status`: `IN_QUEUE` (sürüyor), `PROCESSED` (bitti),
    `REJECT` (işlenmedi).
  - SKU sonucu `skus.content[]` içinde: `itemCode` (= stockCode),
    `status` `SUCCESS`/`FAIL`, `reasons[]`. `sku` altında yazılan
    `salePrice`/`listPrice`/`stock`.
  - Bir task içinde bazı SKU'lar başarılı, bazıları başarısız
    olabilir. Satır bazlı okunmalı.
  ([kaynak](https://developer.n11.com/documentation/n11-marketplace-entegrasyonu/task-detail-sorgulama/))
- **KDV:** ürünün `vatRate` alanı ayrı (0, 1, 10, 20). Fiyatın KDV dahil
  olduğu dokümanda açıkça yazmıyor. n11 tüketiciye gösterilen fiyatı
  aldığı için KDV dahil olması beklenir **[doğrulanmadı]**.
- Stok en çok 999.999 (ürün yükleme dokümanı).
- Satıştan çekme veya yeniden açma stokla değil şu çağrıyla yapılır:
  `POST /ms/product/tasks/product-update`, `status: "Active" | "Suspended"`.
  **Bu çağrıda `vatRate` zorunlu** işaretli.
  ([kaynak](https://developer.n11.com/documentation/n11-marketplace-entegrasyonu/urun-bilgisi-guncelleme/))

### Sipariş

- `GET https://api.n11.com/rest/delivery/v1/shipmentPackages`
  ([kaynak](https://developer.n11.com/documentation/n11-siparis-entegrasyonu/siparis-listeleme-servisi/))
- **Paket bazlı.** Her kayıt bir paket (`id` = paket no). Aynı
  `orderNumber` birden çok pakete bölünebilir.
- Parametreler:
  - `startDate`, `endDate`: **milisaniye timestamp, "GMT+3 olarak"**.
  - `page`, `size` (**en çok 100**).
  - `orderNumber`, `packageIds`.
  - `status`: **istek başına tek değer**.
  - `orderByDirection` (`ASC`/`DESC`).
  - `orderByField=true`: tarih aralığı **paketin `lastModifiedDate`**
    alanına göre uygulanır. Yoksa oluşturma tarihine göre.
  - `sender` (`SELLER` varsayılan).
- **Tarih penceresi en çok 15 gün.** Yalnızca start verilirse sonraki
  15 gün gelir. Aralık çok genişse endDate'ten geriye 15 gün gelir.
  (Haziran 2025 kopyasında bu süre **1 ay** idi. Kısaltılmış.)
- **Kasım 2024 öncesi siparişler bu servisten gelmez.**
- Önerilen yoklama: `orderByField=true` + `orderByDirection=ASC` +
  son başarılı imleçten geriye birkaç dakika pay ile `startDate`.
  `totalPages` / boş `content` gelene kadar sayfalanır. Statüsüz istek
  tüm statüleri döndürüyor mu, dokümanda açık değil (örnek istekte
  status var) **[doğrulanmadı]**. Gerekirse statü başına ayrı istek
  atılır.
- **Paket statüleri:** `Created` (ödendi, onay bekliyor), `Picking`
  (onaylandı/hazırlanıyor), `Shipped`, `Delivered`, `Cancelled`,
  `Unpacked` (bölünmüş ana paket, artık geçersiz), `UnSupplied`
  (tedarik edilemedi, satıcı iptali).
- **Kalem alanları (`lines[]`):** `orderLineId` (kalem ID, onay ve
  bölmede kullanılır), `stockCode`, `productId`, `productName`,
  `barcode`, `quantity`, `price` (**birim fiyat, indirimler hariç**),
  `sellerDiscount`, `sellerCouponDiscount`, `totalSellerDiscountPrice`,
  `mallDiscount` / `totalMallDiscountPrice` (n11'in karşıladığı
  indirim), `dueAmount`, `sellerInvoiceAmount` (mağazanın fatura
  tutarı), `vatRate`, `commissionRate`, `sellerCampaignCommissionRate`,
  `orderItemLineItemStatusName` (kalem statüsü), `variantAttributes`,
  `deliveryFeeType`, `sender`, `productOrigin`.
  Resmi formül: `sellerInvoiceAmount = price × quantity − totalSellerDiscountPrice`.
- Paket alanları: `orderNumber`, `id`, `shipmentPackageStatus`,
  `lastModifiedDate`, `agreedDeliveryDate` (son kargolama tarihi),
  `totalAmount`, `totalDiscountAmount`, `packageHistories[{createdDate,status}]`,
  `cargoTrackingNumber` (kampanya kodu), `cargoSenderNumber` (takip no),
  `cargoTrackingLink`, `shipmentCompanyId`, `cargoProviderName`,
  `shipmentMethod`, `micro` (true = e-ihracat), `deliveryAddressType`
  (teslimat noktası).
- **Kişisel veri:** `billingAddress` ve `shippingAddress` (address, city,
  district, neighborhood, fullName, **gsm**, **tcId**, postalCode),
  `customerEmail`, `customerfullName`, `customerId`, `taxId`,
  `taxOffice`, `tcIdentityNumber`.
  - 15 Ekim 2025'ten beri TCKN zorunlu değil, `tcId` boş gelebilir.
  - Teslimat noktası siparişinde (`deliveryAddressType` KTN, EASYPOINT
    ya da PUP) `shippingAddress` noktanın adresidir,
    `shippingAddress.fullName` null döner. Müşteri bilgisi
    `billingAddress`'ten alınır.
  - "Konuma Özel Teslimat" siparişlerinde paket `id` **null** döner.
  ([Change Log](https://developer.n11.com/documentation/changelog/))
- **İptal:**
  - Müşteri, sipariş kargoya verilene kadar doğrudan iptal edebiliyor
    (Ocak 2025). Paket `Cancelled` olur.
  - **Kısmi iptalde kargo kampanya kodu değişir.** Kalan ürün yeni kodla
    gönderilir.
  - Satıcı tarafında kalem iptali `Created`'da **API'den yapılamaz**
    (panelde "Reddet"). `Picking`'de iki yol var:
    `POST /rest/delivery/v1/splitPackageByQuantity` + `cancelledItems[{orderLineId, quantity, cancelReasonId}]`
    (61 Stok tükendi, 62 Kusurlu, 63 Hatalı fiyat, 64 Mücbir sebep,
    65 Diğer) ya da SOAP `ClaimCancelPartial`. İptal edilen kısım
    `UnSupplied` paket olur. **Yalnızca iptal yapılamıyor**, bölme
    isteğiyle birlikte gönderilmesi gerekiyor.
  ([Miktar bazlı bölme](https://developer.n11.com/documentation/n11-siparis-entegrasyonu/miktar-bazli-paket-bolme-siparis-urun-iptali/))
- **İade REST statüsünde görünmez.** Resmi eşleme tablosunda eski
  "İade Edildi", "İade Talep Edildi" ve "Teslim Edilmiş İade" statüleri
  REST'te **`Delivered`** olarak dönüyor. İade için **SOAP
  `ClaimReturnList`** okunmalı:
  - Adres: `https://api.n11.com/ws/ReturnService.wsdl`.
  - Statüler: `REQUESTED`, `CANCELLED`, `DENIED`, `PENDING`, `PENDED`,
    `APPROVED`, `MANUAL_REFUND`, `ALL`. Boş gönderilirse yalnız
    REQUESTED gelir.
  - Tarih biçimi `dd/mm/yyyy`. Sayfa sabit 20 kayıt.
  - Alanlar: `claimReturnId`, `orderNumber`, `productId`, `quantity`,
    `unitPrice`, `finalPrice`, `approvedDate`…
  - **Kalemde `stockCode` yok.** Yalnız n11 `productId` var. Eşleme
    için ya sipariş kaleminden (`orderNumber` + `productId` →
    `stockCode`) ya da ürün tablosundan gidilmeli.
  ([kaynak](https://developer.n11.com/documentation/iade-entgrasyonu/iade-talepleri-servisi/))

### Kargo/takip

- **Onay zorunlu.** `Created` paketin kalemleri satıcı tarafından
  `Picking`'e çekilmeli:
  `PUT https://api.n11.com/rest/order/v1/update`, gövde
  `{"lines":[{"lineId":<orderLineId>}],"status":"Picking"}`.
  - Kalem bazlı. Çoklu `lineId` gönderilebilir, hatalılar ayrı ayrı
    `content[]` içinde döner.
  - Şu an **yalnız `Picking`** destekleniyor.
  - Onaylanmayan sipariş "Kargo Yapılması Gecikmiş" olur (eski kod 14,
    REST'te hâlâ `Created`).
- **Takip numarası bildirilmez (n11 anlaşmalı kargo).** Satıcı paketi
  şablondaki **kampanya numarası** (`cargoTrackingNumber`) ile kargoya
  verir. Kargo firması faturayı kesince takip no güncellenir ve sipariş
  **otomatik** "Kargolandı" (`Shipped`) olur. Kampanya numarası
  kullanılmazsa sipariş "geciken kargolama"ya düşer.
  ([Kargo süreci](https://magazadestek.n11.com/satis-surecleri/kargo-sureci-141))
  34Pazar'ın kargo adımında yapacağı iş: onay + (gerekirse) bölme.
  Takip no ve link yoklamada okunur.
- Ceva/Horoz lojistik şablonunda ayrıca **toplama talebi** gerekir:
  `PUT /rest/delivery/v1/collectionRequest` (`id` paket, `orderLineId`,
  `boxQuantity`, `desi`, `shipmentCompany` HLZ/CEVA/BL). Paketteki
  **tüm kalemler** gönderilmeli.
- Satıcının kendi kargosuyla takip no bildirmesi için REST'te uç nokta
  yok. SOAP `OrderService.MakeOrderItemShipment` (`trackingNumber`,
  `campaignNumber`, `shipmentMethod`) WSDL'de duruyor ama yeni
  dokümanda yer almıyor. Hâlâ çalıştığı **[doğrulanmadı]**.
- Paket bölme (ürün bazlı): `POST /rest/delivery/v1/splitCombinePackage`,
  `{"splitGroups":[{"orderLineIds":[...]}]}`. Yalnız `Picking`'de
  yapılabilir. Ana paket `Unpacked` olur, yeni paketler yeni `id` ve
  yeni kampanya kodu alır.
  ([kaynak](https://developer.n11.com/documentation/n11-siparis-entegrasyonu/siparis-paket-bolme/))

### Ürün açma

- `POST https://api.n11.com/ms/product/tasks/product-create`. Toplu (en
  çok 1000 SKU), asenkron (`type: PRODUCT_CREATE`), sonuç
  task-details'ten okunur.
  ([kaynak](https://developer.n11.com/documentation/n11-marketplace-entegrasyonu/urun-yukleme/))
- Kategori: `GET /cdn/categories` tüm ağacı tek istekte verir. Yalnızca
  **en alt kırılım** (`subCategories: null`) kabul edilir.
- Özellikler: `GET /cdn/category/{categoryId}/attribute`. Alanlar
  `isMandatory`, `isVariant`, `isSlicer`, `isCustomValue`,
  `attributeValues[{id,value}]`.
  - `isCustomValue=false` olan özellikte `valueId` zorunlu.
  - `isCustomValue=true` olan özellikte serbest `customValue`
    gönderilebilir.
- **Marka ayrı servis değil:** özellik `id: 1` ("Marka"). Kategoride
  marka yoksa `customValue` ile serbest yazılır.
- Zorunlu alanlar: `title` (**en az 15 karakter**), `description`
  (HTML), `categoryId`, `currencyType`, `productMainId` (varyantlar aynı
  değeri alır), `preparingDay` (mağazanın asgarisinden küçük olamaz),
  `shipmentTemplate` (şablon adı), `stockCode` (en çok 255), `quantity`,
  `images[{url,order}]` (**https**, en çok 10 MB), `attributes`,
  `salePrice`, `listPrice`, `vatRate`. İsteğe bağlı alanlar `barcode` ve
  `catalogId`.
- **Onay süreci:** ürün `InCatalogApproval` ile başlar. Sonra `Active`
  ya da `CatalogRejected` olur (`rejectInfo` ile). Barkod n11
  kataloğuyla eşleşirse ürün "satıcı onayı bekliyor" açılır (buybox).
  Onay süresi yazmıyor **[doğrulanmadı]**. Ürün yalnız `Active` ya da
  `Suspended` iken güncellenebilir.
- Silme: `POST /ms/product/tasks/product-delete`, `skus[{stockCode}]`.
- Ret sebepleri (hata sayfası): fahiş düşük/yüksek fiyat koruması
  (min/max TL), yasaklı kelime, kategori için tedarik faturası şartı,
  stockCode çakışması.
  ([kaynak](https://developer.n11.com/documentation/bilgilendirme/api-hata-mesajlari-ve-aciklamalari/))

### Webhook

- **Yok.** developer.n11.com'daki 34 doküman sayfasının hiçbirinde
  webhook veya callback yok (sitemap'in tamamı indirildi ve tarandı).
  Sipariş ve iade için yalnız yoklama var. `@hubcommerce/n11` de
  "webhook yok, poll-primary" diyor.

### Kod durumu (8 Ekim 2026)

`app/Domain/Channels/Adapters/N11/` yazıldı, kanal `is_active = false`.
Bu bölüm, developer.n11.com sayfaları ve canlı `ReturnService.wsdl` esas
alınarak yazıldı. Gerçek mağazayla sınanmadı. Ürün açma (`product-create`)
ve kargo adımı yok.

- **Bağlantı:** kasada `app_key` + `app_secret` (başlık `appKey`/`appSecret`).
  `api_key`/`api_secret` adı bilerek kullanılmadı, çünkü
  `ChannelHttpClient` o çifti Basic auth'a çevirir. Hesap kimliği, satıcının
  yazdığı n11 mağaza adı (`n11_seller_name`). Sağlık kontrolü:
  `product-query?page=0&size=1`.
- **`sender`:** her istekte açıkça `SELLER`. n11depom bilerek dışarıda
  bırakıldı, çünkü depodaki stok satıcının stoğu değil. `sender = N11`
  gelen kalem de stoğa dokunmaz.
- **İçe aktarma:** `product-query` 250'lik sayfalarla. Kimlik `stockCode`
  (metin), üst kimlik `productMainId`. Metadata: `n11_product_id`,
  `currency_type`, `vat_rate`.
- **Stok/fiyat:** `price-stock-update` görevi (≤1000 SKU). Görev kimliği
  `task_id` alanında taşınır. Stok yükünde yalnız `stockCode` + `quantity`
  gider. Fiyat yükünde yalnız `listPrice` + `salePrice` gider (tam 2 ondalık,
  gövde metin olarak kurulur). `currencyType` gönderilmez. TL dışı ürüne
  fiyat yazılmaz. `REJECT` VALIDATION sayılır. Mutabakat 20 SKU'ya kadar
  her `stockCode` için ayrı istek atar, fazlasında tam tarama yapar.
  Kimliksiz listing için istek atılmaz.
- **Sipariş:** pencere 14 günlük dilimlere bölünür, her statü ayrı
  sorgulanır (`orderByField=true`, ASC). Pencerenin iki ucuna 3 saat pay
  eklenir. Anahtar `orderNumber`, kalem anahtarı `orderLineId`. Sipariş
  ilk kez görüldüğünde `orderNumber` ile bütün paketleri okunur.
  `Unpacked` yok sayılır. `Cancelled`/`UnSupplied` kalem bazlı iptal
  kaydı üretir, ama yalnız sipariş daha önce alındıysa. İade için yoklama
  SOAP `ClaimReturnList` (`APPROVED`) ile son 30 günün taleplerine bakar;
  iadeler `productId` → alınmış sipariş kalemi yoluyla eşlenir.
- **Onay:** `acknowledgeOrder` kalemleri `Picking`'e çeker, ama çekirdek
  onu çağırmıyor. Otomatik onay satıcının "Reddet" seçeneğini kaldırır,
  bu yüzden bilinçli bir kararla bağlanmalı.

**DOĞRULANMADI (kodda da işaretli):** "GMT+3 timestamp" kaydırması ·
`orderNumber` sorgusu tarih ve statü süzgeci olmadan bütün paketleri
döndürüyor mu · `price` KDV dahil mi · fiyatın 2 ondalık şartı (resmi örnek
tamsayı gönderiyor) · `ClaimReturnList` dönem aralığına üst sınır var mı ·
`MANUAL_REFUND` ürünün geri geldiği anlamına mı geliyor · n11depom
kullanan satıcıda karışık paket olur mu · ürün `status` değerinin
çekirdekte nasıl yorumlandığı.

**Gerçek mağazada ilk bakılacaklar:**
1. Sağlık kontrolü: doğru anahtarla 200 dönmeli, yanlış anahtarla
   401/403 dönmeli ve bağlantı AUTHENTICATION'a düşmeli.
2. İçe aktarma: SKU sayısı panelle tutuyor mu, `productMainId` dolu mu,
   `currencyType` TL mi.
3. Stok görevi: `task_id` ile `task-details/page-query` çağrısı
   satır bazında `SUCCESS` döndürüyor mu, fiyat değişmeden mi kalıyor.
4. Fiyat görevi: `2000.00` biçimi kabul ediliyor mu.
5. İlk gerçek sipariş: `packageHistories.createdDate`, sipariş anıyla
   karşılaştırılıp +3 saat kayma var mı bakılmalı. `orderNumber`
   sorgusu bütün paketleri getiriyor mu.
6. Kısmi iptal ya da bölme: yeni `orderLineId` SKU üzerinden eşleşiyor mu.
7. İlk onaylı iade: `ClaimReturnList` `APPROVED` döndürüyor mu,
   `productId` sipariş kalemiyle eşleşiyor mu.
8. Gönderilen istekler `api_calls` günlüğünde görünüyor olmalı ve
   `appSecret` maskelenmiş olmalı (secret en az 8 karakter olmalı, yoksa
   maskeleyici onu tanımaz).

### Tuzaklar

1. **Kapalı SOAP işlemleri WSDL'de hâlâ duruyor.** 8 Ekim 2026'da
   `ProductService.wsdl` hâlâ `SaveProduct`, `UpdateProductPriceBySellerCode`
   gibi işlemleri listeliyor. Change Log bunların kapatıldığını
   söylüyor. `ProductStockService.wsdl` ve `ProductSellingService.wsdl`
   ise 405 dönüyor. Eski SOAP kütüphaneleri (`ismail0234/n11-php-api`
   vb.) bu yüzden yanıltıcı. **WSDL'e bakarak "çalışıyor" denmemeli.**
2. **Statü yazımı tutarsız:** sorgu parametresinde `Cancelled`, eşleme
   tablosunda `Canceled`. `packageHistories` örneğinde statülerin başında
   boşluk var (`" Shipped"`). Statü karşılaştırması `trim` + büyük-küçük
   harfe duyarsız yapılmalı.
3. **Tarih penceresi sessizce kırpılır.** 15 günden geniş aralıkta hata
   dönmez, yalnız son 15 gün gelir (sessiz veri kaybı deseni). Geri
   doldurma 15 günlük dilimlerle yapılmalı.
4. **"GMT+3 timestamp" belirsiz.** Epoch ms zaten saat diliminden
   bağımsız. n11'in değeri +3 saat kaydırılmış mı beklediği
   **[doğrulanmadı]**. Pencereye birkaç saatlik pay bırakılmalı, ilk
   gerçek siparişte ölçülmeli.
5. **İade `Delivered` olarak görünür.** REST'e bakan adapter iadeyi hiç
   görmez. Stok geri yükleme için ayrı SOAP iade yoklaması şart.
6. **Kısmi iptal ve bölme kampanya kodunu ve paket `id`'sini
   değiştirir.** Paket `id` kalıcı anahtar olarak kullanılmamalı. Sipariş
   anahtarı `orderNumber`, kalem anahtarı `orderLineId` olmalı.
   `Unpacked` paketler yok sayılmalı. Bölme sonrası yeni `orderLineId`'ler
   de üretiliyor.
7. **Sayı uzunlukları büyüyor:** doküman `orderNumber` için 12→13 hane,
   `n11ProductId` için 9→10 hane uyarısı yapıyor. Hepsi string ya da
   BIGINT saklanmalı.
8. **Fiyat biçimi:** 2 ondalık hane zorunlu. `listPrice < salePrice`
   FAIL olur, tek alan gönderilirse de olmaz. İndirim yoksa iki alan aynı
   değerle gönderilir.
9. **`/rest/` hata gövdesi JSON değil** (`text/plain`
   "Authentication failed"). JSON çözümleyici hata vermemeli.
10. **Ürün sorgusunda `stockCode` tek değer** alıyor. Toplu doğrulama
    tam tarama ile yapılır (250'lik sayfalar).
11. **n11depom:** `sender` gönderilmezse yalnız `SELLER` kayıtları gelir.
    n11 deposu kullanan satıcının ürün ve siparişleri **sessizce
    eksik** gelir. Bağlantı ayarına "n11depom kullanıyor mu" sorusu
    konmalı.
12. **E-ihracat (`micro: true`) siparişleri** ihracat faturası ister
    (`SendInvoiceLink`'te `invoiceNumber` ve `invoiceDateTime` zorunlu).
13. **Eski PHP SOAP istemcilerinde** aralıklı "SOAP oturumu başarısız"
    hatası bildirilmiş. Kullanıcı çözümü `keep_alive: false` + WSDL
    önbelleği.
    ([issue #24](https://github.com/ismail0234/n11-php-api/issues/24))
    Bizim SOAP çağrımız tek işlem (iade listesi), Ticimax'taki gibi
    metin zarfla gönderilebilir.

---

## 5. Çiçeksepeti (REST, JSON, yalnız x-api-key)

### Temel gerçekler

- Tek REST API, tek kimlik: `x-api-key` başlığı. OAuth, imza, token
  yenileme yok. SOAP yok.
  ([Giriş](https://ciceksepeti.dev/))
- İki host:
  - Canlı: `https://apis.ciceksepeti.com/api/v1/`
  - Test: `https://sandbox-apis.ciceksepeti.com/api/v1/`
  - **Test ve canlı anahtarları ayrı.** TLS 1.2 ve üstü zorunlu.
  ([Giriş](https://ciceksepeti.dev/))
- **Model: alt sipariş (orderItem) bazlı.** Her sipariş kaydı bir ürün
  satırıdır. `orderId` ana sipariş, `orderItemId` alt sipariş. Onay,
  kargo, iade, fatura işlemlerinin hepsi `orderItemId` ile yapılır.
- **Yazma işlemlerinin hepsi asenkron.** Ürün açma, ürün güncelleme,
  stok/fiyat cevabı yalnız `batchId` döndürür. Sonuç
  `GET /Products/batch-status/{batchId}` ile okunur.
- **Webhook yok.** Sipariş ve iade için yalnız yoklama var.
- Doküman sitesinin sekme başlığı "Mizu Api" (Çiçeksepeti'nin yurt dışı
  markası). İçerik Çiçeksepeti satıcı API'sinin kendisi.
- **Doküman sitesi ve API hostları Cloudflare arkasında.**
  `ciceksepeti.dev` curl/WebFetch'e "Just a moment" challenge döndürüyor,
  `robots.txt` `Disallow: /` diyor. Sayfalar 8 Ekim 2026'da gerçek Chrome
  ile (Playwright, `channel: chrome`) okundu. Sitede sayfa güncelleme tarihi
  yok. Sipariş listeleme örneğinde 2026-07 tarihli veri var, yani o sayfa
  en az Temmuz 2026'da güncellenmiş.

### Kaynaklar

Resmi (ciceksepeti.dev, 8 Ekim 2026'da gerçek Chrome ile okundu, sitenin
tamamı 27 sayfa):

| Konu | URL |
|---|---|
| Giriş (host, kimlik, anahtar edinme) | https://ciceksepeti.dev/ |
| Akış özeti + hız sınırları | https://ciceksepeti.dev/giris/nasil-entegrasyon-yapilir |
| Ürün kuralları (görsel, açıklama, fiyat) | https://ciceksepeti.dev/urun |
| Kategori listesi | https://ciceksepeti.dev/urun/ciceksepeti-kategori |
| Kategori özellikleri | https://ciceksepeti.dev/urun/ciceksepeti-kategori-ozellik |
| Ürün yükleme | https://ciceksepeti.dev/urun/urunlerin-alinmasi |
| Ürün güncelleme | https://ciceksepeti.dev/urun/urunlerin-guncellenmesi |
| Ürün listeleme | https://ciceksepeti.dev/urun/urun-listeleme-v2 |
| Stok ve fiyat | https://ciceksepeti.dev/urun/stokvefiyat-guncelleme-v2 |
| Batch kontrolü | https://ciceksepeti.dev/urun/urunislemleri-kontrolu |
| Sipariş genel kurallar | https://ciceksepeti.dev/siparis |
| Sipariş listeleme | https://ciceksepeti.dev/siparis/siparis-listeleme |
| Kargo: Çiçeksepeti entegrasyonu | https://ciceksepeti.dev/siparis/sparis-kargo-srecleri/cicek-sepeti-kargo-entegrasyonu |
| Kargo: satıcının kendi kargosu | https://ciceksepeti.dev/siparis/sparis-kargo-srecleri/tedarikcinin-kendi-kargo-entegrasyonu |
| Kargo firması değiştirme | https://ciceksepeti.dev/siparis/sparis-kargo-srecleri/siparisin-kargo-firmasinin-degistirilmesi |
| Desi/adet | https://ciceksepeti.dev/siparis/sparis-kargo-srecleri/desi-ve-adet-bilgisi-gnderilmesi |
| Dijital sipariş | https://ciceksepeti.dev/siparis/sparis-kargo-srecleri/dijital-kod-gonderimi |
| Fatura gönderimi | https://ciceksepeti.dev/siparis/fatura-gonderim |
| İşçilik bedeli (kuyumculuk) | https://ciceksepeti.dev/siparis/iscilik-bedeli-gonderimi |
| İade genel | https://ciceksepeti.dev/iade-siparisler |
| İade listeleme | https://ciceksepeti.dev/iade-siparisler/iade-siparislerin-cekilmesi |
| İade teslim aldım | https://ciceksepeti.dev/iade-siparisler/iade-siparisi-teslim-aldim |
| İade onay/red | https://ciceksepeti.dev/iade-siparisler/iade-siparis-onaylama-veya-reddetme |

Üçüncü taraf (yalnızca çapraz kontrol için, indirildi, çalıştırılmadı):

- [`fvz233/Open-Entegre-WP`](https://github.com/fvz233/Open-Entegre-WP),
  son commit 7 Ağu 2026. `docs/ciceksepeti_api_plaintext.txt` resmi
  dokümanın sadeleştirilmiş özeti ("kontrol tarihi 07.08.2026"),
  `includes/marketplaces/CiceksepetiMarketplace.php` çalışan PHP istemcisi.
  Yollar resmi dokümanla birebir aynı.
- [`ciceksepeti-api` 1.1.1](https://www.npmjs.com/package/ciceksepeti-api)
  ([GitHub](https://github.com/Coskntkk/ciceksepeti-api)), Haziran 2023.
  Eski ama tüm uç noktaları kapsıyor. Yollar aynı. Farkları aşağıda
  not edildi.
- Packagist'te Çiçeksepeti paketi yok (8 Ekim 2026'da arandı, 0 sonuç).

Canlı ölçüm (8 Ekim 2026, sahte anahtarla, veri yazılmadı): Tuzaklar
bölümünde 1. madde.

### Önceden yapılması gerekenler

- **API anahtarı:** satıcı paneli (seller.ciceksepeti.com) **Hesap Yönetimi
  > Entegrasyon Bilgilerim**. Daha önce hiç oluşturulmadıysa satıcı
  panelden **Çiçeksepeti Destek Ekibi > Konuşma Başlat > "API Entegrasyon
  Süreçleri"** konusuyla talep açar, anahtar talep üzerinden iletilir.
  Yani anahtar anında değil, **destek talebiyle** geliyor.
  ([Giriş](https://ciceksepeti.dev/))
  (Paraşüt'ün eski rehberi farklı menü yolu yazıyor: "Ürün Yönetimi >
  Ürün Listesi > API Erişim" ([kaynak](https://www.parasut.com/blog/ciceksepeti-entegrasyonu-icin-api-anahtari-nasil-temin-edilir)).
  Panel güncellenmiş olabilir. Resmi doküman esas alınmalı.)
- **Test anahtarı ayrı talep edilir** ("Test işlemleri için test API
  bilgilerinin talep edilmesi gereklidir").
- **Entegratör adı:** satıcı, Entegrasyon Bilgilerim'deki **Entegratör
  Adı** alanına `34Pazar` yazmalı. `user-agent` başlığı bununla eşleşir
  (aşağıda).
- **Kargo modeli seçimi:** "Kullanılacak kargo entegrasyon modelinin hem
  Çiçeksepeti mağaza ayarlarında hem de entegrasyon ayarlarında
  tanımlanması gerekmektedir." İki model var (Kargo bölümü). Satıcının
  hangisini kullandığı bağlantı kurulurken sorulmalı. Sipariş satırındaki
  `cargoModelType` (1 = Çiçeksepeti entegrasyonu, 2 = kendi kargosu) bunu
  da söylüyor.
  ([Sipariş](https://ciceksepeti.dev/siparis),
  [Sipariş listeleme](https://ciceksepeti.dev/siparis/siparis-listeleme))
- Partner veya uygulama onayı **yok**. Mağaza bazlı tek anahtar yeterli.

### Kimlik

- Her istekte iki başlık:
  - `x-api-key: <anahtar>` (zorunlu; "Security scheme type: API Key,
    Header parameter name: x-api-key").
  - `user-agent`: kendi yazılımı için `"<SatıcıId>"`, entegratör için
    `"<SatıcıId>-<EntegratörAdı>"`. 34Pazar için: `"<SatıcıId>-34Pazar"`.
    Doküman "zorunlu değil, yalnızca uyarı" diyor ama girilmesini
    istiyor. **Satıcı ID'si de bağlantı formunda istenmeli.**
  ([Giriş](https://ciceksepeti.dev/))
- JSON gövdeli uçlarda `Content-Type: application/json` ("Body raw JSON
  gönderilmelidir").
- **IP kısıtı:** dokümanda IP beyaz listesi yazmıyor. Ama yurt dışı
  IP'den tüm yollar 403 dönüyor (Tuzaklar 1). Coğrafi engel mi, WAF mı
  **[doğrulanmadı]**.
- **Sandbox:** var (`sandbox-apis.ciceksepeti.com`). Test anahtarı destek
  talebiyle alınır.
- **Hız sınırları (resmi):**

  | Uç nokta | Farklı istek | Aynı istek (aynı gövde/sorgu) | Parti |
  |---|---|---|---|
  | `GET /Products` | 5 sn'de 1 | **10 dk'da 1** | sayfa en çok 60 |
  | `POST /Products` (açma) | 5 sn'de 1 | - | en çok 1000 |
  | `PUT /Products` (güncelleme) | sn'de 1 | - | en çok 200 |
  | `PUT /Products/price-and-stock` | sn'de 1 | **30 dk'da 1** | en çok 200 |
  | `GET /Products/batch-status/{id}` | sn'de en çok 5 | dk'da 1 | - |
  | `POST /Order/GetOrders` | 5 sn'de 1 | dk'da 1 | sayfa en çok 100 |
  | Kargo ve iade uçları | 5 sn'de 1 | - | - |
  | `GET /sellerquestions` | 5 sn'de 1 | 10 sn'de 1 | - |

  Sınır aşılınca dönen HTTP kodu ve gövde dokümanda yok
  **[doğrulanmadı]** (429 beklenir).

### Sağlık kontrolü

- Ayrı bir "ben kimim" ucu **yok**.
- Önerilen: `GET /api/v1/Products?PageSize=1&Page=1`. Anahtarı doğrular,
  cevap küçük, `totalCount` ürün sayısını da verir.
  **Dikkat:** aynı sorgu 10 dakikada bir kez atılabilir. Sağlık kontrolü
  sık çalışacaksa her seferinde sorguyu değiştirmek gerekir (ör.
  `SortMethod` dönüşümlü) ya da sağlık kontrolü 10 dakikadan seyrek
  olmalı.
- `GET /Categories` **önerilmez**: tüm kategori ağacını tek parça
  döndürüyor.
- Yanlış anahtarla dönen hata: dokümanda yalnız fatura ucu için "api key
  mevcut değilse - Geçersiz API Key" yazıyor. HTTP kodu ve JSON biçimi
  **[doğrulanmadı]**. Ölçülemedi, çünkü istek Cloudflare'de takılıyor
  (Tuzaklar 1). Üçüncü taraf istemciler hata metnini
  `Message` ya da `message` alanından okuyor.

### Ürün içe aktarma

- `GET https://apis.ciceksepeti.com/api/v1/Products`
  ([kaynak](https://ciceksepeti.dev/urun/urun-listeleme-v2))
- Sorgu parametreleri (hepsi isteğe bağlı):
  - `ProductStatus`: 2 Onay Bekleyen, 3 Satışta, 4 Reddedilen, 5 Satışa
    Kapalı, 7 Stoğu Tükenen, 8 Kilitli.
  - `PageSize` (**en çok 60**), `Page` (**1'den başlar**).
  - `SortMethod` 1–8 (ad, stok, fiyat, oluşturma tarihi; artan/azalan).
  - `StockCode` (tek değer), `variantName`.
- Cevap: `{"totalCount": N, "products": [...]}`. Sayfa sayısı
  `ceil(totalCount / PageSize)`.
- Satır alanları: `productName`, `productCode` (Çiçeksepeti kodu, `kc` ile
  başlar), `categoryId`, `categoryName`, `stockCode`, `mainProductCode`,
  `variantName`, `productStatusType`, `description`, `link`, `mediaLink`,
  `deliveryType`, `deliveryMessageType`, `isUseStockQuantity` (false =
  stoksuz satış), `StockQuantity`, `ListPrice`, `TotalPrice` (satış
  fiyatı), `barcode`, `isActive`, `passiveDescription`, `images[]`,
  `attributes[]`, `operatorContacts[]`, `safetyInfo`.
- **Varyant modeli:** her satır **bir varyant (SKU)**. Aynı ürünün
  varyantları `mainProductCode` ile gruplanır. Varyant değerleri
  `attributes[]` içinde (ör. Renk, Beden). `stockCode` satıcı genelinde
  tekil olmalı.
- Eşleme anahtarı: `stockCode`. Stok/fiyat yazımı buna göre yapılır.
- Artımlı (güncellenme tarihi) filtre **yok**. Tam tarama gerekir.
  1000 SKU = 17 sayfa × 5 sn ≈ 1,5 dakika.
- **Alan adları örnekle tablo arasında tutarsız** (Tuzaklar 4).

### Stok/fiyat

- `PUT https://apis.ciceksepeti.com/api/v1/Products/price-and-stock`
  ([kaynak](https://ciceksepeti.dev/urun/stokvefiyat-guncelleme-v2))
- **Toplu ve asenkron.** Bir istekte **en çok 200 kalem**. Saniyede 1
  istek. **Aynı gövde 30 dakikada bir.**
  ```json
  {"items":[{"stockCode":"test1","stockQuantity":5,"listPrice":50.50,"salesPrice":49.99}]}
  ```
  Cevap: `{"batchId":"cef33e24-2f49-4c7f-a745-a59f7d5ce90d"}` (HTTP 200).
- Kurallar:
  - Her kalemde `stockCode` + (`stockQuantity` **veya** `salesPrice`).
    Gönderilmeyen alan değişmez. Yalnız stok için `stockCode` +
    `stockQuantity` yeterli.
  - `listPrice` (üstü çizili) **tek başına gönderilemez**, `salesPrice`
    ile birlikte gider. Tabloda eski adları `firstPrice` / `totalPrice`.
  - Üstü çizili ile satış fiyatı arasındaki fark **%1'den az, %80'den
    fazla olamaz.** İndirim yoksa `listPrice` hiç gönderilmemeli.
  - **Yeni fiyat mevcut satış fiyatının %50'sinden düşük olamaz.** Büyük
    indirim kademeli yapılır.
  - `listPrice` son 30 günün en düşük fiyatından yüksekse **uyarı** döner
    ama fiyat yine de yazılır.
- Sonuç okuma: `GET /api/v1/Products/batch-status/{batchId}`
  ([kaynak](https://ciceksepeti.dev/urun/urunislemleri-kontrolu))
  - Cevap: `batchId`, `itemCount`, `items[]`. Her kalemde `data`
    (gönderilen stockCode/stok/fiyat), `itemId`, `status`,
    `failureReasons[{message, code}]`, `lastModificationDate`.
  - Statüler: `Pending`, `Processing`, `Success`, `Failed`, `Warning`
    ("başarılı ama istek kontrol edilmeli").
  - Bir batch'te bazı kalemler başarılı, bazıları başarısız olabilir.
    Kalem bazlı okunmalı. Örnek hata: `"Girmiş olduğunuz kod
    bulunmamaktadır"`, `code: 4000` (bilinmeyen stockCode).
  - Stok/fiyat işlemi **en geç 4 saatte** biter. Aynı batch dakikada bir
    sorgulanabilir.
  - `Failed` = teknik hata, aynı kalem yeniden gönderilmeli. Destek
    talebinde `batchId` istenir.
- **KDV:** ürün açma ve stok/fiyat isteklerinde KDV alanı **yok**.
  Fiyatın KDV dahil olduğu açıkça yazmıyor. Tüketiciye gösterilen fiyat
  olduğu için KDV dahil olması beklenir **[doğrulanmadı]**. (İşçilik
  bedeli için "KDV dahil olmalıdır" açıkça yazıyor. Siparişte `tax`
  alanı geliyor, örnekte değeri `18`, yani oran.)
- Stok üst sınırı dokümanda yok **[doğrulanmadı]**.
- Satıştan çekme: stok 0 yapılır ya da `PUT /Products` ile
  `isActive: false` (yalnız varyant). Ürünün tamamı API ile pasife
  alınamaz, destek talebi gerekir.
  ([Ürün güncelleme](https://ciceksepeti.dev/urun/urunlerin-guncellenmesi))

### Sipariş

- `POST https://apis.ciceksepeti.com/api/v1/Order/GetOrders`
  ([kaynak](https://ciceksepeti.dev/siparis/siparis-listeleme))
- Gövde:
  - `startDate`, `endDate` (string). `orderNo` veya `orderItemNo`
    verilmezse zorunlu. Örnekte ISO biçimi:
    `"2020-01-01T03:52:09.390Z"`.
  - `pageSize` (zorunlu, **en çok 100**), `page` (zorunlu, **0'dan
    başlar**).
  - `statusId` (isteğe bağlı, tek değer).
  - `orderNo`, `orderItemNo`. İkisi birden verilirse `orderNo` geçerli,
    ana siparişin tüm alt siparişleri döner.
  - `isOrderStatusActive`: pasif (iptal) siparişleri görmek için
    **`false` gönderilmeli.**
- **Tarih penceresi en çok 2 hafta.** Fazlası **hata döner** (n11'deki
  gibi sessiz kırpma değil).
- **Tarih filtresinin hangi alana uygulandığı belirsiz.** Doküman
  "Yazılan tarihten sonraki siparişler gelsin" diyor. Oluşturma mı, son
  güncelleme mi **[doğrulanmadı]**. Oluşturma tarihine göreyse, eski bir
  siparişin statü değişikliği ancak 14 günlük pencere içindeyse görülür.
- Cevap: `{"orderListCount": N, "supplierOrderListWithBranch": [...]}`.
  Üçüncü taraf istemci ayrıca `pageCount` alanını okuyor. Resmi tabloda
  yok **[doğrulanmadı]**.
- **Statüler (`orderItemStatusId`, alt sipariş bazlı):**

  | ID | Anlam |
  |---|---|
  | 1 | Yeni |
  | 2 | Hazırlanıyor |
  | 3 | Arabaya Verildi (servis aracı, aynı gün teslimat) |
  | 5 | Kargoya Verildi |
  | 7 | Teslim Edildi |
  | 11 | Kargoya Verilecek |
  | 18 | Firmaya İade Edildi |
  | 20 | İade Süreci Başladı (iade listesinde) |
  | 21 | İade Kargoda |
  | 22 | İade Tedarikçide |
  | 23 | İade Tedarikçi Onayı Bekliyor |

  Liste filtresi (`statusId`) yalnız 1, 2, 5, 7, 11'i belgeliyor. 3 ve
  18 kendi kargo statü güncellemesinde, 20–23 iade listesinde geçiyor.
- **Kalem (satır) alanları:** `orderId`, `orderItemId`, `orderCreateDate`
  + `orderCreateTime` (ayrı string, örnek `"02/01/2020"` + `"17:54"`),
  `orderModifyDate` + `orderModifyTime`, `productId`, `productCode`,
  **`code` (satıcının stockCode'u)**, `barcode`, `name`, `quantity`,
  `quantityUnit`, `itemPrice` (ürün satış fiyatı), `totalPrice`,
  `discount`, `branchDiscountPart` (satıcının indirim payı),
  `csDiscountPart` (Çiçeksepeti'nin payı), `invoicePrice` (fatura
  tutarı), `tax`, `cargoPrice`, `deliveryCharge`, `allowanceRate`
  (hakediş oranı), `credit` (vade farkı), `orderPaymentType`,
  `orderItemStatusId`, `orderProductStatus` (metin), `isOrderStatusActive`,
  `cancellationResult`, `deliveryType`, `deliveryDate`,
  `requestedDeliveryDate`, `cargoCompany`, `cargoNumber`,
  `shipmentTrackingUrl`, `partialNumber` (Çiçeksepeti kargo kodu),
  `cargoModelType`, `isInvoiceSent`, `orderItemStatusHistoryList[]`
  (`orderItemStatusId`, `partialNumber`, `transactionTime`,
  `shipmentNumber`...), `orderItemTextListModel[]` (kişiselleştirme
  metinleri), `branchId`, `accountCode`.
  - Eşleme: kalemin SKU'su `code` alanında (stockCode). `barcode` yalnız
    ürün açılırken girildiyse dolu.
  - Bir alt siparişte birden çok adet olabilir (`quantity`).
- **Kişisel veri:** `customerId`, `receiverName`, **`receiverPhone`**
  (yalnız kendi kargo modelinde verilir), `receiverAddress`,
  `receiverCity`, `receiverDistrict`, `receiverRegion`, `senderName`,
  `senderAddress`, `senderCity`, `senderRegion`, `senderCompanyName`,
  **`senderTaxNumber`**, `senderTaxOfficeName`, `invoiceEmail` (aracı
  adres, örnek `fatura+…@ciceksepeti.com`), `cardMessage` (hediye kartı
  notu), `qrCodeMessage` (video mesaj linki), `orderItemTextListModel`
  (kişiye özel ürün yazıları).
  - Gönderici (fatura) adresi zorunlu değil, **boş gelebilir**.
  - `senderCompanyName` doluysa şirket faturası kesilir.
  - Dijital siparişlerde alıcı telefonu gelmez.
- **İptal:**
  - Sipariş "Kargoya Verilecek" (11) ve "Kargoya Verildi" (5) durumunda
    **iptal edilemez**.
    ([İade genel](https://ciceksepeti.dev/iade-siparisler))
  - İptal olan alt sipariş `isOrderStatusActive: false` olur. Normal
    listede gelmez, `isOrderStatusActive: false` ile ayrıca istenmeli.
    İstek parametresiz atılsa bile iptal olmuş kalemde cevap `false`
    döner. ([Sipariş](https://ciceksepeti.dev/siparis))
  - **Kısmi iptal** alt sipariş bazında doğal olarak var (her ürün ayrı
    `orderItemId`). Bir alt siparişin **adetinin bir kısmının** iptali
    dokümanda yok **[doğrulanmadı]**.
  - **Satıcı tarafı iptal/ret ucu yok.** 27 sayfanın hiçbirinde satıcının
    siparişi reddetmesi veya "tedarik edemiyorum" demesi için uç nokta
    yok. Panelden yapıldığı varsayılıyor **[doğrulanmadı]**.
- **İade ayrı uçta:** `POST /api/v1/Order/getcanceledorders`
  ([kaynak](https://ciceksepeti.dev/iade-siparisler/iade-siparislerin-cekilmesi))
  - Gövde: `pageSize`, `page` (0'dan, zorunlu), `orderItemStatusId`
    (20–23), `startDate`/`endDate` (**sipariş tarihi**) ya da
    `cancellationStartDate`/`cancellationEndDate` (**iptal/iade tarihi**,
    statüden bağımsız döner). İkisi birlikte gönderilmez. Pencere en çok
    1 ay. Tarih yoksa son 1 ay.
  - Yoklama için doğru filtre: `cancellationStartDate/EndDate`.
  - Cevap `orderItemList[]`: `orderId`, `orderItemId`, `customerName`,
    `price`, `orderItemStatus` (metin), `cancelReasonId` (1 iade, 2
    değişim), `subCancelReasonId`, `orderItemCancelStatus` (metin),
    `cargoCompany`, `shipmentNumber`, `partialNumber`, `variantName`,
    **`supplierProductVariantCode`** (stockCode), `productCode`,
    `supplierProductCode`, `texts[]`, `cancelType` (**1 iptal, 2 iade,
    3 müşteriye geri gönderilecek iade**).
  - **Kalemde adet yok.** İade edilen miktar alt siparişin tamamı mı
    **[doğrulanmadı]**.
  - Karar kodları (`orderItemCancelStatusId`): 1 Müşteri Haklı, 2 Bayi
    Haklı, 4 Bayi Onay, 8 Bayi Red, 16 İptal/İknadan Geri Çekildi.
  - Sipariş satırındaki `cancellationResult`: 1 = müşteri haklı (para
    iadesi, ürün geri gitmez), 2 = satıcı haklı (ürün müşteriye tekrar
    gönderilir).
  - İade akışı uçları: `POST /Order/refundprocessstartreceivedprocess`
    (`orderItemIds[]`, iade satıcıya ulaştı, yalnız statü 20'de) ve
    `POST /Order/cancelevaluation` (`orderItemId`, `process` 1 onay /
    3 red, yalnız statü 22'de). **Değişim onaylanırsa sipariş "Yeni"
    statüsüne döner** ve yeni siparişler arasında listelenir.

### Kargo/takip

İki model var. Satıcının modeli Çiçeksepeti tarafında tanımlı, yanlış
modelin ucu hata döner ("This branch's integration type is not supported
this method").
([Kargo süreçleri](https://ciceksepeti.dev/siparis/sparis-kargo-srecleri))

**Model 1: Çiçeksepeti kargo entegrasyonu** (`cargoModelType: 1`, yalnız
anlaşmalı kargo firmaları)

- `PUT /api/v1/Order/readyforcargowithcsintegration`
  ([kaynak](https://ciceksepeti.dev/siparis/sparis-kargo-srecleri/cicek-sepeti-kargo-entegrasyonu))
  - Gövde: `{"orderItemsGroup":[{"orderItemIds":[123456,78901]}]}`. Aynı
    pakete girecek alt siparişler bir grupta gönderilir.
  - Cevap: `statusUpdateResponse[].orderItems[{orderItemId, partialNumber,
    cargoCompany}]`, `isSuccess`, `message`.
  - `partialNumber` (ör. `"7001111-10"`) Çiçeksepeti'nin kargo kodu.
    Paket bu kodla kargoya verilir. Statü **11 Kargoya Verilecek** olur.
  - **Yalnız "Yeni" (1) statüsünde** çalışır. Gruptaki alt siparişler aynı
    ana siparişe, aynı alıcı ve adrese ait olmalı.
  - Sonraki statüler (5, 7) ve müşteri bilgilendirmesi **Çiçeksepeti
    tarafından** ilerletilir. Takip no sipariş yoklamasında
    (`cargoNumber`, `shipmentTrackingUrl`) okunur.
- Kargo firmasını değiştirme: `PUT /Order/CargoCompany`,
  `{"items":[{"orderProductId":…,"cargoId":2}]}`, yalnız "Yeni"de.
- Lojistik firmasına atanmış yüksek desili siparişte önce
  `POST /Order/CargoMeasurement` (`orderProductId`, `desi`, `quantity`),
  yalnız "Yeni"de.

**Model 2: satıcının kendi kargosu** (`cargoModelType: 2`)

- `PUT /api/v1/Order/statusupdatewithsupplierintegration`
  ([kaynak](https://ciceksepeti.dev/siparis/sparis-kargo-srecleri/tedarikcinin-kendi-kargo-entegrasyonu))
  - Gövde: `{"orderItems":[{orderItemId, orderItemStatusId,
    cargoBusinessId, shipmentNumber, shipmentTrackingUrl, receiverName,
    deliveryTime}]}`. Toplu.
  - "Kargoya Verildi" (5) için `cargoBusinessId`, `shipmentNumber`,
    `shipmentTrackingUrl` **zorunlu.**
  - `cargoBusinessId`: 1 MNG, 2 Yurtiçi, 25 Sürat, 43 Aras, 44 PTT, 45 UPS,
    46 Horoz, 55 Ceva, 59 Sendeo, 116 kargomSENDE, 117 Kolay Gelsin,
    118 Arvato. (Kargo firması değiştirme sayfasındaki liste farklı:
    49 Borusan var, 55 ve sonrası yok.)
  - **Statü güncellemelerinden satıcı sorumlu:** "Firma siparişin kargo
    takip bilgileri ve statüsü için bu metoda gelip güncellemeleri yapmak
    zorundadır." Teslim Edildi (7) dahil. Çiçeksepeti müşteriye bu
    güncellemelere göre e-posta/SMS atar.
  - **Yurtiçi Kargo istisnası:** kendi modelinde Yurtiçi ile çalışan satıcı
    yalnız 11 (Kargoya Verilecek) gönderir. Barkodu Çiçeksepeti üretir,
    cevaptaki `partialNumber` ile kargoya verilir.
    ([Sipariş](https://ciceksepeti.dev/siparis))
  - Servis aracıyla (aynı gün) teslimatta 3 "Arabaya Verildi" kullanılır.
    5 gönderilirse hata döner.
  - Hata mesajları: `Order not found!`, `Order delivered!`,
    `Orders belong to different merchant!`,
    `Order not available for this status!`.

**Onay zorunlu mu?**

- Ayrı bir "siparişi onayla" ucu **yok**. Statü 2 (Hazırlanıyor) yalnız
  model 2'nin statü güncellemesinde gönderilebiliyor. Zorunlu olduğu
  yazmıyor.
- Model 1'de kargo kodu almak (`readyforcargowithcsintegration`) fiilen
  "işleme aldım" adımı. Model 2'de statüyü satıcı ilerletmek zorunda.
- Kargolama süresi ve gecikme cezası dokümanda yok **[doğrulanmadı]**.

**Fatura:** `POST /api/v1/Branch/SendInvoiceMail`,
`{"items":[{"orderItemId":…,"document":"<base64 pdf>"|"documentUrl":"<pdf link>"}]}`.
Müşteriye fatura e-postası gönderilir. Zorunlu olup olmadığı yazmıyor
**[doğrulanmadı]**. Satırdaki `isInvoiceSent` durumu gösterir.
([kaynak](https://ciceksepeti.dev/siparis/fatura-gonderim))

### Ürün açma

- `POST https://apis.ciceksepeti.com/api/v1/Products`, gövde
  `{"products":[…]}`. **En çok 1000 kalem**, 5 sn'de 1 istek, asenkron
  (`batchId`). Başarısız istekte de `batchId` döner. İşlem **en geç 24
  saatte** biter.
  ([kaynak](https://ciceksepeti.dev/urun/urunlerin-alinmasi))
- Kategori: `GET /Categories` tüm ağaç (`id`, `name`, `parentCategoryId`,
  `subCategories`). Yalnız **en alt kırılım** kabul edilir. Mağazaya
  tanımlı ürün grubuna uygun kategori seçilmeli.
  ([kaynak](https://ciceksepeti.dev/urun/ciceksepeti-kategori))
- Özellikler: `GET /Categories/{categoryId}/attributes` →
  `categoryAttributes[{attributeId, attributeName, required, varianter,
  type, attributeValues[{id,name}]}]`. Üç tür:
  - `"Variant Özelliği"` (`varianter: true`): varyant yapan özellik.
  - `"Ürün Özelliği"`: ürün bilgisi.
  - `"Kişiselleştirilebilir Özellik"`: yalnız kişiye özel ürünlerde
    (müşteriden yazı/fotoğraf alınır), `TextLength` ile.
  ([kaynak](https://ciceksepeti.dev/urun/ciceksepeti-kategori-ozellik))
- Zorunlu alanlar: `productName` (en çok 255), `mainProductCode`
  (varyantlar aynı değeri alır), `stockCode`, `categoryId`,
  `stockQuantity`, `salesPrice`, `description`, `images[]` (URL),
  `deliveryType` (1 servis aracı, 2 kargo, 3 ikisi), `deliveryMessageType`
  (teslim süresi: 1 çiçek servis, 4 aynı gün, 18 1–2 gün, 5 1–3 gün,
  6 1–5 gün, 7 1–7 gün, 13 3–5 gün, 19 1–10 iş günü), kategoride zorunlu
  olan `Attributes[{Id, ValueId, TextLength}]`. İsteğe bağlı: `listPrice`,
  `barcode` (3–50 karakter), `mediaLink`, `operatorContacts[]`
  (imalatçı/ithalatçı, GPSR benzeri), `safetyInfo`.
- **Marka ve KDV alanı yok.** Marka bir özellik olarak mı geçiyor
  **[doğrulanmadı]**.
- Görsel: JPG/PNG, 500×500 – 2000×2000, önerilen 10×11 oran (ör.
  1300×1430).
- **Onay:** ürün "Onay Bekleyen" (2) olarak düşer, sonra "Satışta" (3)
  ya da "Reddedilen" (4). Ret sebebinin API'de hangi alanda döndüğü
  belgelenmemiş (`batch-status` `failureReasons` ya da listede
  `passiveDescription` olabilir) **[doğrulanmadı]**.
- Güncelleme: `PUT /Products`, en çok 200 kalem, saniyede 1. Zorunlu
  alanlar açma ile aynı + `isActive`. **Özellikler (attributes) ve
  barkod API'den değiştirilemez.** Değişiklik için yeni varyant açılır ya
  da destek talebi gerekir.
  ([kaynak](https://ciceksepeti.dev/urun/urunlerin-guncellenmesi))
- Silme ucu **yok.**

### Webhook

- **Yok.** ciceksepeti.dev'in 27 sayfasının hiçbirinde webhook, callback
  veya bildirim URL'si geçmiyor (tüm sayfalar indirildi ve tarandı).
  Sipariş ve iade için yalnız yoklama var.

### Tuzaklar

1. **Almanya'dan API'ye erişilemiyor (ölçüldü).** 8 Ekim 2026'da Almanya
   IP'sinden (Cloudflare FRA) `apis.ciceksepeti.com` ve
   `sandbox-apis.ciceksepeti.com` altındaki **her yol**, `/robots.txt`
   dahil, anahtarlı/anahtarsız, her `user-agent` ile **HTTP 403** döndü.
   Gövde JSON değil, HTML: `<title>404 Not Found</title>`, "Page Not
   Available". Kimlik kontrolüne hiç ulaşılmıyor. Coğrafi engel mi
   (yalnız TR IP) yoksa WAF kuralı mı **[doğrulanmadı]**. **34Pazar'ın
   sunucusu IONOS'ta (212.227.142.108). Oradan ölçülmedi, SSH erişimi
   yoktu.** Kod yazmadan önce sunucuda tek bir
   `curl -s -o /dev/null -w "%{http_code}" -H "x-api-key: x" -A 1 https://apis.ciceksepeti.com/api/v1/Products`
   çalıştırılmalı. 403 HTML gelirse Türkiye çıkışlı bir proxy
   gerekir. JSON biçiminde bir anahtar hatası gelirse erişim var demektir.
2. **"Aynı istek" sınırları sessiz tuzak.** Stok/fiyatta aynı gövde 30
   dakikada bir. Stok 5→4→5 dönerse ikinci "5" isteği ilk istekle aynı
   gövde olur. Reddedilip reddedilmediği, reddedilirse hangi kodla döndüğü
   **[doğrulanmadı]**. Tekrar denemelerde (retry) aynı gövde
   gönderilecekse 30 dakika kuralı göz önünde tutulmalı. Ürün listesinde
   aynı sorgu 10 dakika, sipariş listesinde 1 dakika. Kuyruk tasarımında
   "son gönderilen gövde" saklanmalı.
3. **200 = bitti değil.** Stok/fiyat 4 saate, ürün açma 24 saate kadar
   kuyrukta kalabilir. `batchId` kalıcı saklanıp kalem bazlı sonuç
   okunmalı. `Warning` başarılı sayılmalı ama loglanmalı.
4. **Alan adları örnek ile tablo arasında tutarsız:**
   - Ürün listesi tablosu `StockQuantity`, `ListPrice`, `TotalPrice`
     diyor. Örnek cevapta `StockQuantity` + `salesPrice`, ikinci üründe
     `stock`. `deliveryType` bir yerde `deliverType` yazılmış.
   - `productStatusType` tabloda sayı, örnekte metin (`"YAYINDA"`).
   - Statü kodları üç yerde üç farklı: filtre tablosu (7 Stoğu Tükenen,
     8 Kilitli), cevap tablosu (7 Yayında-Onay Bekleyen, 8 Stoğu
     Bitenler), 2023 istemcisi (7 published_waiting_for_approval, 8
     out_of_stock).
   - İstekte `stockQuantity`/`StockQuantity`, `Attributes[].Id`/`id`
     karışık yazılmış.
   - Çözümleyici büyük-küçük harfe duyarsız, birden çok alan adını
     deneyen biçimde yazılmalı. İlk gerçek cevapta ölçülmeli.
5. **Sayfalama tabanı farklı:** ürün `Page` **1'den**, sipariş ve iade
   `page` **0'dan** başlıyor. Ürün sayfası en çok 60, sipariş en çok 100.
6. **Tarih biçimleri karışık.** İstekte ISO (`2020-01-01T03:52:09.390Z`).
   Sipariş cevabında tarih ve saat ayrı string, `dd/MM/yyyy` + `HH:mm`.
   Statü geçmişinde saat dilimsiz ISO. `deliveryDate` örneğinde
   `dd-MM-yyyy HH:mm`. Saat dilimi (TR mi UTC mi) **[doğrulanmadı]**,
   Trendyol'daki −3 saat dersi burada da ölçülmeli.
7. **İptaller varsayılan listede görünmez.** `isOrderStatusActive: false`
   ile ayrı yoklama yapılmazsa iptal olan sipariş 34Pazar'da açık kalır,
   stok geri yüklenmez (sessiz veri kaybı deseni).
8. **İade ayrı uç ve ayrı statü uzayı.** İade `GetOrders`'ta değil
   `getcanceledorders`'ta. İade kaleminde adet yok, SKU
   `supplierProductVariantCode` adıyla geliyor. Değişim onayı siparişi
   "Yeni"ye geri çevirir: aynı `orderItemId` ikinci kez "Yeni" görünür.
   Statü geçmişi örneğinde de 11 → 18 → 1 dönüşü var. Durum makinesi
   "Yeni"ye geri dönüşü kabul etmeli, stok iki kez düşülmemeli.
9. **Fiyat koruma kuralları reddettirir:** %50'den büyük tek adımlık
   indirim hata. Liste-satış farkı %1–%80 dışında hata. Kategori bazlı
   asgari fiyat olabilir ("Ürün yüklenen kategoride minimum fiyat
   olabilir").
10. **Doküman kendi içinde çelişkili:** açıklama üst sınırı 20.000
    (ürün yükleme) ve 7.750 (ürün kuralları) karakter. Görsel üst sınırı
    5 MB ve 2 MB. Güvenli olan küçük değer: **7.750 karakter, 2 MB.**
11. **2023 istemcisi (`ciceksepeti-api`) bir kalemde `stockQuantity` ve
    `salesPrice`'ın birlikte gönderilmesini yasaklıyor.** Resmi örnek
    ikisini birlikte gönderiyor. Resmi doküman esas. Eski kısıt artık
    geçerli mi **[doğrulanmadı]**.
12. **`receiverPhone` modele bağlı:** Çiçeksepeti kargo modelinde
    gelmiyor. Telefon alanı zorunlu tutulmamalı.
13. **Ürün tamamen kapatılamaz ve silinemez.** Yalnız varyant `isActive:
    false` ya da stok 0. 34Pazar'daki "kanaldan kaldır" işlemi
    Çiçeksepeti'nde "stok 0 + pasif varyant" olarak eşlenmeli.

### Kod durumu

8 Ekim 2026: `app/Domain/Channels/Adapters/Ciceksepeti/CiceksepetiAdapter.php`
(+ `CiceksepetiFault.php`) yazıldı. Kanal `is_active = false`, gerçek
mağazayla sınanmadı. **API Almanya'dan erişilemiyor (Tuzaklar 1); sunucu
IP'si açılmadan ya da Türkiye çıkışlı vekil kurulmadan bağlantı kurulamaz.**
Testler: `tests/Feature/Channels/CiceksepetiAdapterTest.php` (26) ve
`tests/Feature/Orders/CiceksepetiOrderSliceTest.php` (10). 26 mutasyonun
hepsi kırmızı.

**Yapılanlar:**

- **Kimlik:** kasada `ciceksepeti_api_key` (`api_key` değil, Basic auth
  çiftinin yarısı). `settings`'te `ciceksepeti_seller_id` (yalnız rakam,
  `User-Agent: <SatıcıId>-34Pazar`'a giriyor) ve isteğe bağlı
  `ciceksepeti_environment` (`test` = sandbox hostu). Anahtar yoksa istek
  atılmıyor.
- **Erişim engeli:** HTML gövdeli 403 ayrı tanınıyor. Kalıcı
  AUTHENTICATION (devre süresiz açılır) ama mesaj "Çiçeksepeti erişimi
  IP'yi engelliyor — sunucu IP'sini Çiçeksepeti'ye bildir." Bağlanma
  ekranında bağlantı `pending` kalıyor ve son hata bunu söylüyor.
- **Hız sınırları:** farklı istekler arası (ürün/sipariş/iade listesi 5 sn,
  stok-fiyat 1 sn) bağlantı + uç nokta başına önbellek damgasıyla tutuluyor,
  erken istek **bekliyor** (`Sleep`). Sağlık kontrolü `SortMethod`'u 1–8
  döndürüyor (aynı sorgu 10 dk kuralı).
- **Aynı gövde 30 dk:** stok-fiyat gövdesinin özeti 30 dk tutuluyor.
  Tekrar gelirse istek atılmıyor, kalan süreyle `RATE_LIMITED` dönüyor.
  Karar gerekçesi (5→4→5 senaryosu) sınıf notunda: başarı denseydi kanal
  kalıcı olarak 4'te kalırdı. Bilinen bedel: 4→5→4'te kanal en çok 30 dk
  fazla stok gösterir. 429 gelirse özet siliniyor.
- **İçe aktarma:** `GET /Products`, sayfa 1'den, 60'lık, statü süzgeci yok
  (stoğu tükenen ve satışa kapalı da geliyor). Kimlik `stockCode`, üst
  kimlik `mainProductCode`. Alan adları harf duyarsız ve çok adlı
  (`StockQuantity`/`stock`, `salesPrice`/`TotalPrice`). Tur başına 50 sayfa
  (3000 varyant).
- **Stok/fiyat:** `PUT /Products/price-and-stock`, ≤200 kalem. Stok yükü
  yalnız `stockCode` + `stockQuantity`, fiyat yükü yalnız `stockCode` +
  `salesPrice` (+ indirim %1–%80 aralığındaysa `listPrice`). Sıfır fiyat
  gönderilmiyor. `batchId` sonuçta `batch_id`; yoksa istisna.
- **Sipariş:** üç aşama. (a) `GetOrders` aktif satırlar, ≤13 günlük
  dilimler, iki uçtan 3 saat pay → "created" / durum. İlk görülen sipariş
  dolu sayfadaysa `orderNo` ile bütünüyle yeniden okunuyor. (b) `GetOrders`
  `isOrderStatusActive: false`, en az 12 gün geriye → iptal (yalnız açık
  `false` ve "created"e girmiş satır). (c) `getcanceledorders`, iptal/iade
  tarihiyle, 27 gün geriye → iade (yalnız `cancelType = 2`, değişim değil,
  "Bayi Onay" ya da "Müşteri Haklı" + "İade Tedarikçide"). Miktar
  "created" kaydından. Olay kimlikleri `{orderId}:created`,
  `:cancel:{orderItemId}`, `:return:{orderItemId}`, `:status:{imza}`.
  Kişisel veri beyaz listeyle süzülüyor.
- **Yazılmayanlar (bilinçli):** sipariş onayı ve kargo bildirimi (iki kargo
  modeli satıcıya göre değişiyor, yanlış modelin ucu hata döner;
  `acknowledgeOrder` istek atmıyor), ürün açma/güncelleme, fatura
  gönderimi, iade onay/red. (`batch-status` okuma 8 Eki'de eklendi:
  `SupportsBatchStatus`, `sync:poll-batches`; 4xxx kodlu `Failed` kalıcı,
  kodsuz `Failed` geçici sayılıyor — DOĞRULANMADI.)

**Bilinen sınırlar:**

- İçe aktarma sayfa başına 5 sn bekliyor; `ImportProductsFromChannelJob`
  300 sn'de kesildiği için ~3000 varyanttan büyük katalog tek turda gelmez.
  Çekirdekte devam imleci gerekir.
- Sipariş yoklaması bağlantı başına en az ~10 sn bekliyor (3 istek × 5 sn
  aralık); `orders:poll` bağlantıları sırayla yokladığı için çok sayıda
  Çiçeksepeti bağlantısı turu uzatır.

**Gerçek mağazada ilk bakılacaklar:**

1. Sunucudan `curl -s -o /dev/null -w "%{http_code}" -H "x-api-key: x" -A 1
   https://apis.ciceksepeti.com/api/v1/Products` — 403 HTML mi JSON hata mı.
   HTML ise Çiçeksepeti'ye IP bildirimi ya da TR vekil.
2. Yanlış anahtarla dönen HTTP kodu ve gövde (`classifyError` 401/403/
   "Geçersiz API Key" metnine göre kuruldu).
3. Ürün listesindeki gerçek alan adları (`StockQuantity` mı `stock` mı,
   `salesPrice` mı `TotalPrice` mı), `productStatusType` sayı mı metin mi.
4. İlk stok isteğinin `batchId`'si `GET /Products/batch-status/{id}` ile
   elle sorgulanıp `Success` görülmeli. İstek alanı `stockQuantity` mı
   `StockQuantity` mı (örnek ikincisini yazıyor).
5. Aynı gövde 30 dk içinde gönderilirse Çiçeksepeti ne dönüyor (adapter
   göndermediği için normalde görülmez; elle denenmeli).
6. `GetOrders` tarih süzgeci oluşturma mı güncellenme tarihine mi, TR saati
   mi UTC mi. `orderCreateDate` gerçekten `dd/MM/yyyy` mi.
7. `isOrderStatusActive: false` isteği iptal satırlarını getiriyor mu,
   alan yanıtta `false` (boolean) mı.
8. `getcanceledorders` yanıtında karar/statü sayısal alanları var mı;
   iade adedi alt siparişin tamamı mı. `pageSize` üst sınırı.
9. Satır `totalPrice` ile `itemPrice` farkı; `cargoPrice` sipariş başına mı
   satır başına mı.
10. Sınır aşılınca dönen kod (429 mu, `Retry-After` var mı).

---

## 6. Pazarama (REST + OAuth2 client_credentials)

### Temel gerçekler

- **REST/JSON**, tek API host: `https://isortagimapi.pazarama.com`. Token
  ayrı hosttan alınır: `https://isortagimgiris.pazarama.com/connect/token`
  (IdentityServer/OpenID; [openid-configuration](https://isortagimgiris.pazarama.com/.well-known/openid-configuration)).
  ([Token Alma](https://isortagim.pazarama.com/auth/integration/token-alma))
- **Resmi doküman satıcı panelinin içinde, herkese açık:** "API Entegrasyon
  Portali", `https://isortagim.pazarama.com/auth/integration/<slug>`.
  Sayfa bir Vue SPA. curl/WebFetch boş kabuk görür. İçerik CMS'ten JSON
  olarak geliyor ve oturumsuz okunabiliyor:
  `GET https://isortagimapi.pazarama.com/api/content/getByName/api-integration`
  (menü, 53 sayfa) ve `GET .../api/content/getBySlug/<slug>` (sayfa),
  başlık `x-channelcode: 22`. 8 Ekim 2026'da 53 sayfanın tamamı bu yolla
  indirildi ve okundu. Portal Aralık 2025'te açılmış, sayfaların çoğu
  2026'da güncellenmiş (Servis Limitleri: 5 Eki 2026).
- **Eşleme anahtarı barkod (`code`).** Stok, fiyat, satışa aç/kapat, KDV
  ve ürün detayı hep `code` (barkod) ile çalışır. `stockCode` ayrı bir
  alan, yazma çağrılarında anahtar değil.
- Cevap zarfı her yerde aynı:
  `{"data":…, "success":bool, "messageCode", "message", "userMessage", "fromCache"}`.
  Para alanları siparişte nesne olarak gelir
  (`{"currency":"TL","value":7.00,"valueInt":700,"valueString":"7,00 TL",…}`).

### Kaynaklar

Resmi (API Entegrasyon Portali, son güncelleme tarihleri CMS'ten):

| Konu | URL (`https://isortagim.pazarama.com/auth/integration/…`) | Son güncelleme |
|---|---|---|
| Servis limitleri | `servis-limitleri` | 5 Eki 2026 |
| Token alma | `token-alma` | 28 Tem 2026 |
| Onaylı/onaysız ürün listeleme | `onayli-onaysiz-urun-listeleme` | 29 Tem 2026 |
| Tek ürün detayı | `tek-urun-filtreleme` | 5 May 2026 |
| Ürün ekleme | `urun-ekleme` | 8 Tem 2026 |
| Ürün batch sonucu | `batchrequest-sorgulama` | 30 Mar 2026 |
| Fiyat güncelleme | `urun-fiyat-guncelleme` | 17 Mar 2026 |
| Stok güncelleme | `urun-stok-guncelleme` | (tarih yok) |
| Fiyat/stok dataId sorgulama | `dataid-sorgulama-servisi` | 15 Nis 2026 |
| Satışa aç/kapat (tekli/toplu) | `kataloglu-urunleri-satisa-kapatip-acma`, `…-toplu` | 24 Haz 2026 / 24 Ara 2025 |
| Siparişler | `siparisler` | 30 Tem 2026 |
| Split order (V2) | `split-order-split-refund` | 24 Haz 2026 |
| Kalem statü güncelleme | `siparise-ait-durum-guncelleme` | 24 Haz 2026 |
| Kargo takip bildirme | `kargo-takip-durumu-bildirme` | (tarih yok) |
| Toplu kalem statüsü | `itemlari-tek-seferde-guncelleme` | 24 Haz 2026 |
| Paket yapısı | `paket-yapisi` | 24 Haz 2026 |
| İptal talepleri | `iptal-taleplerini-sorgulama`, `iptal-durumu-ve-guncelleme` | 17 Şub 2026 |
| İadeler | `iadeler`, `iade-durum-guncelleme` | 17 Şub / 24 Haz 2026 |
| Teslimat tipi (kargo firmaları) | `teslimat-tipi-goruntuleme` | 14 Oca 2026 |
| Kategori / özellik / marka | `kategori-listesi`, `kategori-ozellikleri-semasi`, `brand-marka` | Ara 2025–Şub 2026 |

Üçüncü taraf (yalnız çapraz kontrol; indirildi, çalıştırılmadı, okundu):
[`wiensa/pazarama-sp-api`](https://github.com/wiensa/pazarama-sp-api)
(Laravel, Nis 2025), [`kivancagaogluu/pazarama`](https://github.com/kivancagaogluu/pazarama)
(Kas 2024), [`4li4ydin/Pazarama-API-Entegrasyonu-MSSQL`](https://github.com/4li4ydin/Pazarama-API-Entegrasyonu-MSSQL)
(Ağu 2026). Üçü de token ve temel yollar için resmi dokümanla aynı. Hepsi
**eski** `product/updateStock` / `product/updatePrice` yollarını
kullanıyor (resmi doküman artık `-v2` gösteriyor). `wiensa` paketindeki
`/shipping/templates`, `/carriers`, `/orders/shipments/bulk` gibi yollar
resmi dokümanda **yok**, uydurma görünüyor. `isarud-woocommerce`
eklentisi kendi sunucusuna köprü kuruyor, Pazarama yolu içermiyor.
Packagist/npm/PyPI'de güncel ve güvenilir bir Pazarama istemcisi
bulunamadı.

Canlı ölçüm (8 Ekim 2026, sahte anahtarla, veri yazılmadı): bkz. Sağlık
kontrolü.

### Önceden yapılması gerekenler

- Satıcı anahtarı panelde kendisi üretir: **isortagim.pazarama.com >
  Hesabım > Hesap Bilgileri > Entegrasyon Bilgileri > "Yeni API Key
  Üret"**. clientId + clientSecret.
  ([Token Alma](https://isortagim.pazarama.com/auth/integration/token-alma))
- Partner/uygulama onayı **yok**, mağaza bazlı key + secret yeterli.
- Teslimat tipleri (kargo/kurye/mağaza) **panelden** girilir, API'den
  değil. Paket servisleri boş depo adresinde "varsayılan şablondaki depo
  adresini" kullanıyor. Ürün açmadan önce panelde teslimat ve depo
  ayarının bitmiş olması gerekir **[doğrulanmadı: ürün açmayı engellediği]**.
  ([Ürün ekleme](https://isortagim.pazarama.com/auth/integration/urun-ekleme))
- Pazarama anlaşmalı kargo mu kullanıyor, kendi kargosu mu? Sipariş akışı
  buna göre değişir (bkz. Kargo/takip). `GET /sellerRegister/getSellerDelivery`
  cevabındaki `contracted` alanı bunu gösteriyor olabilir
  **[doğrulanmadı]**.

### Kimlik

- `POST https://isortagimgiris.pazarama.com/connect/token`,
  `Content-Type: application/x-www-form-urlencoded`, gövde
  `grant_type=client_credentials&scope=merchantgatewayapi.fullaccess`,
  **HTTP Basic** (clientId:clientSecret). OpenID yapılandırması
  `client_secret_post`'u da destekliyor.
- **Access token 1 saat** geçerli. Sonra her istekte
  `Authorization: Bearer <token>`.
- Başarılı token cevabının biçimi dokümanda yalnız ekran görüntüsü
  (Cloudflare yüzünden indirilemedi). `kivancagaogluu` istemcisi
  `data.accessToken` + `success` okuyor, `wiensa` standart `access_token`
  okuyor **[doğrulanmadı]**. Hata cevabı Pazarama zarfında geldiği için
  başarılı cevabın da zarflı (`data.accessToken`) olması muhtemel. Adapter
  ikisini de denemeli.
- **API secret en çok 365 gün geçerli.** Bitmeden 14 gün önce e-posta ve
  panel uyarısı geliyor. Süresi dolan anahtar otomatik sıfırlanıyor.
  Yeni anahtar üretilince **eskisi anında iptal**. Secret ekranda bir kez
  gösteriliyor.
- **Scope:** dokümanda `merchantgatewayapi.fullaccess`. OpenID listesinde
  `merchantgatewayapi.read` ve `.write` da var. Salt okuma anahtarı
  mümkün mü **[doğrulanmadı]**.
- **IP kısıtı:** dokümanda yok **[doğrulanmadı]**.
- **Sandbox/test ortamı:** dokümanda yok. Tek host. Test gerçek mağazada
  yapılır **[doğrulanmadı: başka ortam olmadığı]**.
- **Hız sınırları (resmi):**
  - Stok-fiyat: **satıcı başına iki istek arasında 10 sn**, istek başına
    **en çok 3000 ürün**.
  - Ürün ekleme: istekler arası 10 sn, istek başına **en çok 500 ürün**.
  - Fiyat kilitleme: istek başına en çok 1000.
  - Sipariş, iade, ürün listeleme için sayı yazmıyor **[doğrulanmadı]**.
  ([Servis Limitleri](https://isortagim.pazarama.com/auth/integration/servis-limitleri))

### Sağlık kontrolü

- Önerilen: token al + `GET https://isortagimapi.pazarama.com/product/products/approved?Size=1`.
  Token adımı key/secret'ı, ikinci adım token'ın API'de geçtiğini
  doğrular, cevap küçük.
- Alternatif: `GET /sellerRegister/getSellerDelivery`. Kargo firması
  GUID'leri zaten lazım olduğu için bağlantı kurulurken bir kez çağrılıp
  saklanabilir.
- Yanlış anahtarla dönen hata (8 Ekim 2026'da ölçüldü):
  - Token: **HTTP 400**, JSON
    `{"data":null,"success":false,"messageCode":"invalid_token","message":"Token bulunamadı veya geçersiz",…}`.
    Standart OAuth `invalid_client` değil.
  - API, token yok: **401**, **boş gövde**, `WWW-Authenticate: Bearer`.
  - API, geçersiz token: **401**, boş gövde,
    `WWW-Authenticate: Bearer error="invalid_token"`.

### Ürün içe aktarma

- **Onaylı (satıştaki) ürünler:**
  `GET /product/products/approved?Size=100[&Cursor=…][&Code=…][&StartDate=…&EndDate=…]`
  ([kaynak](https://isortagim.pazarama.com/auth/integration/onayli-onaysiz-urun-listeleme))
  - **Cursor sayfalama**, `Size` en çok **100**. Cevapta
    `data.nextCursor`. Null olunca bitti.
  - Cevap: `data.sellerProducts[]`.
- **Onaysız (onayda / reddedilen) ürünler:**
  `GET /product/products/unapproved` ile **sayfa numaralı** eski yapı
  (`pageIndex`/`pageSize`, `totalCount`, `hasNextPage`). Cevap `data[]`.
  Parametre adları doküman içinde tutarsız: metin "pageIndex ve
  pageSize", örnek istek `Size` **[doğrulanmadı]**.
- Eski `GET /product/products` ikiye bölündü (dokümanın ifadesi). Eski
  yolun hâlâ çalıştığı **[doğrulanmadı]**.
- `StartDate`/`EndDate`'in hangi tarihe (oluşturma mı güncelleme mi)
  uygulandığı yazmıyor **[doğrulanmadı]**. Artımlı tarama için
  güvenilmemeli, tam tarama yapılmalı.
- Satır alanları: `name`, `displayName`, `description`, `brandName`,
  **`code` (barkod)**, **`groupCode` (varyant grubu)**, `stockCount`,
  `stockCode`, `listPrice`, `salePrice`, `vatRate`, `categoryName`,
  `categoryId`, `state`, `status`, `productStatus`, `waitingApproveExp`
  (ret/bekleme açıklaması), `productSaleLimitDetail.quantity`,
  `attributes[{attributeName, attributeValue}]`,
  `images[{imageUrl, sortOrder}]`, `deliveryTypes[]`,
  `productGroups[{productId, code, attributeName, attributeValue, salePrice, listPrice}]`
  (aynı gruptaki kardeş varyantlar).
- **Varyant modeli:** her satır bir barkod (SKU). Aynı modelin
  varyantları `groupCode` ile gruplanır. Varyant değerleri `attributes`
  içinde.
- `state`/`productStatus` sayı değerlerinin tam listesi yok. Örnekte
  `state: 3` = "Onaylandı" **[diğerleri doğrulanmadı]**.
- Tek ürün: `POST /product/getProductDetail`, gövde `{"Code":"…"}`.
  Barkoda göre tüm detay (`brandId`, `attributes` ID'li,
  `isCatalogProduct`).
  ([kaynak](https://isortagim.pazarama.com/auth/integration/tek-urun-filtreleme))

### Stok/fiyat

- **Stok:** `POST /product/updateStock-v2`, gövde
  `{"items":[{"code":"<barkod>","stockCount":5}]}`.
  ([kaynak](https://isortagim.pazarama.com/auth/integration/urun-stok-guncelleme))
- **Fiyat:** `POST /product/updatePrice-v2`, gövde
  `{"items":[{"code":"<barkod>","listPrice":1037.61,"salePrice":999.90}]}`.
  `listPrice` ve `salePrice` **ikisi de zorunlu**, `decimal(18,2)`.
  ([kaynak](https://isortagim.pazarama.com/auth/integration/urun-fiyat-guncelleme))
- **Ayrı çağrılar**, ikisi de **toplu ve asenkron**. Cevap:
  `{"data":"<dataId GUID>","message":"Fiyat/Stok güncelleme işleminiz sıraya alındı."}`.
- **Sınırlar:** istek başına en çok **3000** ürün. Stok ve fiyat
  istekleri arasında **satıcı başına 10 sn** (stok ve fiyat aynı sayaçta
  mı **[doğrulanmadı]**; ortak sayaç varsayılmalı).
- **Sonuç okuma:**
  `GET /listing-state/batch-id/{dataId}/lake-projections?page=1&pageSize=3000`
  ([kaynak](https://isortagim.pazarama.com/auth/integration/dataid-sorgulama-servisi))
  - Satır başına: `code`, `price{status, operationDetail, salePrice, listPrice}`
    ya da `stock{…}`, `operationStatusText`.
  - Durumlar: `0` Başarılı, `1` Tamamlanamadı, `2` Hata oluştu, `3`
    İşleniyor, `5` Onaya gönderildi.
  - "İşleniyor" dönen istek **en geç 4 saat** içinde sonuçlanıyor.
- **Fiyat onayı:** **%70 indirimli** fiyatlar önce Pazarama onay ekibine
  gider ("Onaya gönderildi"), onaydan sonra yayına çıkar. Örnek
  cevapta daha düşük indirimlerde de "Ürün fiyat onayına gönderildi"
  görülüyor, eşik kesin değil **[doğrulanmadı]**.
- **Liste/satış fiyatı:** `listPrice` üstü çizili fiyat, `salePrice`
  satış fiyatı. Eşit gönderilebiliyor (resmi örnekte eşit).
  `listPrice < salePrice`'ın reddedildiği **[doğrulanmadı]**.
- **KDV:** fiyat alanlarının KDV dahil olduğu ürün dokümanında açıkça
  yazmıyor. Sipariş kaleminde `taxIncluded: true` dönüyor, yani satış
  fiyatı KDV dahil **[doğrulanmadı, sipariş örneğinden çıkarım]**. KDV
  oranı ayrı çağrıyla: `PUT /product/vatRate/bulk`,
  `{"listingVatRates":[{"productCode":"…","vatRate":20}]}`.
- **Satışa kapat/aç** stokla değil:
  `POST /product/external-status` (tekli,
  `{"productItem":{"code":"…","productStatus":10}}`) ya da
  `POST /product/bulkUpdateProductStatusFromApi` (toplu,
  `{"productItems":[…]}`). `1` = satışa aç, `10` = satıştan kapat. Toplu
  çağrı GUID döner, sonuç `lake-projections?…&lakeType=1` ile okunur.
- Fiyat kilidi (kampanya dönemi sabit fiyat): `POST /price-lock/save-price-lock-api`.
  **Kilitli üründe fiyat panelden değiştirilemiyor.** API'den gelen fiyat
  güncellemesinin ne olduğu (red mi, sessizce yok sayma mı) yazmıyor
  **[doğrulanmadı]**. Stok güncellemesi engellenmiyor.
  ([kaynak](https://isortagim.pazarama.com/auth/integration/urun-fiyat-kilitleme))

### Sipariş

- `POST /order/getOrdersForApi`
  ([kaynak](https://isortagim.pazarama.com/auth/integration/siparisler))
  - Gövde: `{"pageSize":500,"pageNumber":1,"startDate":"2026-10-01","endDate":"2026-10-08"}`
    ya da saat-dakikalı `"2026-10-01T13:30"`. `orderNumber` ile tekil
    sorgu.
  - **Tarih aralığı en çok 1 ay.**
  - **`endDate` hariç**: "girilen tarihten önceki siparişler gelir, ilgili
    günü kapsamaz".
  - `orderNumber` ile sorguda **en çok 6 ay** geriye gidilebiliyor.
  - Tarih hangi alana uygulanıyor? Yalnız sipariş tarihi gibi duruyor
    **[doğrulanmadı]**. **Güncellenme tarihi filtresi yok.** Yani iptal,
    iade gibi sonradan değişen siparişleri yakalamak için geriye dönük
    pencere (ör. son 30 gün) düzenli yeniden taranmalı.
  - Statü filtresi ("order item statüsü belirterek") metinde geçiyor ama
    parametre adı örnekte yok **[doğrulanmadı]**.
  - `pageSize` üst sınırı yazmıyor. Örnek 500 **[doğrulanmadı]**.
  - Saat dilimi yazmıyor. Cevaptaki tarihler `+03:00`. İstekte de TR
    saati varsayılmalı **[doğrulanmadı]**.
- **Parçalı sipariş V2:** `POST /order/getOrdersForApiV2`. Aynı üründen
  birden çok adet varsa her adet ayrı `orderItemId` ve `quantity: 1`
  ile gelir, tutar ve indirim de bölünür. Kısmi iptal ve iadeyi kalem
  bazında izlemek için **V2 önerilir**. Satıcı ayarına bağlı mı
  **[doğrulanmadı]**.
  ([kaynak](https://isortagim.pazarama.com/auth/integration/split-order-split-refund))
- **Sipariş alanları:** `orderId` (GUID, tekil anahtar), `orderNumber`
  (müşteriye gösterilen, sayı), `orderDate`, `orderAmount`,
  `shipmentAmount`, `discountAmount`, `currency`, `paymentType`,
  `orderStatus`, `customerId`, `customerName`, `customerEmail`,
  `shipmentAddress`, `billingAddress`, `items[]`, `plusOrder`,
  `channelCode`.
- **Kalem alanları (`items[]`):** `orderItemId` (GUID, statü
  güncellemede kullanılır), `orderItemStatus`, `orderItemStatusName`,
  `orderItemStatusHistory[{historyStatus, historyStatusName, historyCreatedDate}]`,
  `quantity`, `listPrice`, `salePrice`, `taxAmount`, `shipmentAmount`,
  `totalPrice`, `discountAmount`, `discountDescription`, `taxIncluded`,
  `deliveryType`, `deliveryDetail`, `shipmentCode` (anlaşmalı kargo
  gönderi kodu), `shipmentCost`, `estimatedShippingDate` (son kargolama
  tarihi), `cargo{companyId, companyName, trackingNumber, trackingUrl}`,
  `product{productId, name, code (barkod), stockCode, variantOptionDisplay, vatRate, url, imageURL}`,
  `sellerAddressId`, `packageNumber`, `laborCostPerItem`.
- **Kalem statüleri (tam liste, resmi):** `3` Siparişiniz Alındı, `12`
  Hazırlanıyor, `5` Kargoya Verildi, `16` Mağazada, `19` Teslimat
  Noktasında, `11` Teslim Edildi, `14` Teslim Edilemedi, `6` İptal
  Edildi, `18` İptal Süreci Başlatıldı, `13` Tedarik Edilemedi, `7` İade
  Süreci Başlatıldı, `8` İade Onaylandı, `9` İade Reddedildi, `10` İade
  Edildi.
- `deliveryType`: `1` Kargo, `2` Kurye, `3` Mağaza, `4` Dijital, `5`
  Bağış, `10001` Teslimat noktası. `paymentType`: `1` Kart, `5` Cüzdan,
  `8` Visa tek tık, `11` Taksitli ek hesap.
- **Kişisel veri:** `customerName`, `customerEmail`; adreslerde
  `nameSurname`, `customerEmail`, `cityName`, `districtName`,
  `neighborhoodName`, `addressDetail`, `displayAddressText`,
  **`phoneNumber`**, `postalCode`; fatura adresinde ayrıca
  **`identityNumber` (TCKN)**, `invoiceType` (1 bireysel, 2 kurumsal),
  `companyName`, `taxNumber`, `taxOffice`, `isEInvoiceObliged`.
  `identityNumber` örnekte null. İade listesinde de müşteri adı, e-posta,
  telefon ve adres var.
- **İptal:**
  - Müşteri `3`'te doğrudan iptal edebilir → kalem `6`.
  - `12`'de müşteri yalnız **iptal talebi** açar → kalem `18`. Talepler
    `POST /order/api/cancel/items` ile listelenir (`refundId`,
    `orderNumber`, `productCode`, `productStockCode`, `quantity`,
    `refundStatus`) ve `PUT /order/api/cancel` ile `status: 2` (onay) ya
    da `3` (ret) verilir.
    ([kaynak](https://isortagim.pazarama.com/auth/integration/iptal-durumu-ve-guncelleme))
  - Satıcı tarafı iptal: kalemi `13` (Tedarik Edilemedi) yapmak.
  - **Kısmi iptal** kalem bazında görünür (statü kalemde). V1'de 3 adetlik
    bir kalemden 1'inin iptali nasıl görünüyor **[doğrulanmadı]**. V2
    bu durumu adet başına ayrı kalemle çözüyor.
- **İade:**
  - Sipariş servisinde kalem `7`'ye geçer. Ayrıntı için
    `POST /order/getRefund` (`pageSize`, `pageNumber`, `refundStatus`,
    `requestStartDate`, `requestEndDate`, `SplitItems`).
  - İade alanları: `refundId`, `refundNumber`, `orderNumber`,
    `productCode` (barkod), `productStockCode`, `refundType` (sebep),
    `refundStatus`, `refundAmount`, `shipmentCode` (iade kargo kodu).
  - İade statüleri: `1` Onay bekliyor, `2` Tedarikçi onayladı, `3`
    Tedarikçi reddetti, `4`/`5` Backoffice onay/ret, `6` Otomatik onay,
    `7` Talep iptal, `8` Direkt onay. Satıcı yalnız `2` ve `3` yazabilir
    (`POST /order/updateRefund`, ret için `RefundRejectType` 1–12 zorunlu).
  - Eşleme: getRefund `1`→sipariş `7`, `2`/`4`→`8`, `3`/`5`→`9`, ve
    sipariş `10` İade Edildi.
  - Satıcı işlem yapmazsa iade **otomatik onaylanır** (`6`). Süre yazmıyor
    **[doğrulanmadı]**.
  ([İadeler](https://isortagim.pazarama.com/auth/integration/iadeler),
  [İade durum](https://isortagim.pazarama.com/auth/integration/iade-durum-guncelleme))

### Kargo/takip

- **Onay zorunlu.** Yeni kalem `3` gelir. Başka hiçbir statüye geçmeden
  önce satıcı `12`'ye (Hazırlanıyor) çekmeli:
  `PUT /order/updateOrderStatus`,
  `{"orderNumber":735071747,"item":{"orderItemId":"<guid>","status":12}}`.
  Toplu hali: `PUT /order/updateOrderStatusList`,
  `{"orderNumber":…,"status":12}` (siparişin tüm kalemleri).
  ([kaynak](https://isortagim.pazarama.com/auth/integration/siparise-ait-durum-guncelleme))
- `12`'ye alınıp kargoya verilmeyen sipariş için **otomatik iade süreci
  başlar ve satıcı puanı düşer**. Süre `estimatedShippingDate` ile
  ilişkili görünüyor **[doğrulanmadı]**.
- **Pazarama anlaşmalı kargo kullanan satıcı:** `12`'den sonra süreç
  **otomatik** ilerler. Kalemdeki `shipmentCode` ile kargoya verilir.
  Takip numarası bildirilmez, yoklamada okunur.
- **Kendi kargosunu kullanan satıcı:** `12`'den sonra kalem `5`'e çekilir
  ve takip bilgisi verilir:
  `PUT /order/updateOrderStatus`,
  `{"orderNumber":…,"item":{"orderItemId":"…","status":5,"deliveryType":1,"shippingTrackingNumber":"…","trackingUrl":"…","cargoCompanyId":"<GUID>"}}`.
  `cargoCompanyId` GUID'i `GET /sellerRegister/getSellerDelivery`
  cevabındaki `cargoCompanies[]`'ten alınır. Sonra teslim durumu da
  satıcı tarafından `11`/`14` olarak yazılmalı (teslimde yalnız `status`
  dolu, diğer alanlar null).
  ([kaynak](https://isortagim.pazarama.com/auth/integration/kargo-takip-durumu-bildirme))
- Aynı siparişin birden çok kalemine tek istekte takip:
  `POST /order/api/bulk-status-update` (`orderNumber`, `orderItemIds[]`,
  `updateShipmentDto{cargoCompanyId, deliveryType:1, shipmentNumber, shippingTrackingNumber, status:5, subStatus:0, trackingUrl}`).
  ([kaynak](https://isortagim.pazarama.com/auth/integration/itemlari-tek-seferde-guncelleme))
- Paket: `GET /order/api/shipment-packages/orderId=<guid>` (doküman yolu
  böyle yazıyor, query mi path mi **[doğrulanmadı]**), bölme
  `POST /order/api/packages-split`, değiştirme `PUT /order/update-packages`.
  Paket statüleri `0` yok, `10` güncel, `20`/`30`/`40` eski. **Yeniden
  paketlemede paket numarası değişir.**
  ([kaynak](https://isortagim.pazarama.com/auth/integration/paket-yapisi))
- Fatura linki: `POST /order/invoice-link` (`orderid`, `invoiceLink`,
  isteğe bağlı paket için `deliveryCompanyId` + `trackingNumber`). Zorunlu
  mu **[doğrulanmadı]**.

### Ürün açma

- `POST /product/create`, gövde `{"products":[…]}`. Toplu (istek başına
  en çok **500**, istekler arası 10 sn), asenkron. Cevapta
  `batchRequestId`. **Tek ürünlük istekte batch ID üretilmez**
  (`00000000-…` döner). Ürün onay sürecine girer.
  ([kaynak](https://isortagim.pazarama.com/auth/integration/urun-ekleme))
- Sonuç: `GET /product/getProductBatchResult?BatchRequestId=…`. Durum `1`
  İşleniyor, `2` Bitti, `3` Hata. `failedProducts[{productCode, errorReason}]`.
  **Batch ID yalnız 4 saat sorgulanabilir.**
  ([kaynak](https://isortagim.pazarama.com/auth/integration/batchrequest-sorgulama))
- Zorunlu alanlar: `Name` (100), `DisplayName` (250), `Description`,
  `BrandId` (GUID), `Desi`, `Code` (barkod, istek içinde benzersiz),
  `GroupCode` (varyant grubu), `StockCount`, `StockCode`, `VatRate`,
  `ListPrice`, `SalePrice`, `CategoryId`,
  `attributes[{attributeId, attributeValueId}]` (hep ID, serbest metin
  yok), `images[{imageUrl}]`. Örnekte ayrıca `currencyType: "TRY"`,
  `deliveries: []`. İsteğe bağlı `productSaleLimitQuantity`.
- `GroupCode` için tablo **`Nvarchar(10)`** diyor ama resmi örnekte 12
  karakter var (`YNGC68339664`) **[sınır doğrulanmadı]**.
- Kategori: `GET /category/getCategoryTree`. Yalnız `leaf: true`
  kategorilere ürün açılır. Özellikler:
  `GET /category/getCategoryWithAttributes?Id=<guid>` (`isVariantable`,
  `isRequired`, `attributeValues[{id,value}]`). Marka:
  `GET /brand/getBrands?Page=1&Size=100[&name=…]`.
- Katalogda olan barkod için kısa yol: önce
  `POST /product/getProductTitleCodeSearch`, sonra
  `POST /product/addProductsWithBarcodes` (`productId`, `code`,
  `stockCode`, fiyatlar, stok).
- Yeni zorunluluk adayı: ürün güvenliği "temin bilgileri"
  (ithalatçı/imalatçı şablonu, parti/seri no, güvenlik belgeleri). Hangi
  kategorilerde zorunlu **[doğrulanmadı]**.

### Webhook

- **Yok.** Portaldaki 53 sayfanın hiçbirinde webhook/callback/bildirim
  servisi geçmiyor (CMS'ten tamamı indirildi ve tarandı). Sipariş, iptal
  ve iade için yalnız yoklama var.

### Tuzaklar

1. **Doküman SPA içinde.** WebFetch/curl boş sayfa görür. Okumak için
   CMS JSON'u (`/api/content/getBySlug/<slug>`, `x-channelcode: 22`)
   kullanılmalı. Örnek cevaplardaki ekran görüntüleri (token cevabı
   dahil) Cloudflare arkasında, otomatik indirilemiyor.
2. **Açık kaynak istemciler eski yolları kullanıyor.**
   `product/updateStock` / `updatePrice` → artık `-v2`. `product/products`
   → `approved` + `unapproved`. Eski yolların kapandığı da açıldığı da
   yazmıyor **[doğrulanmadı]**. Yeni kod `-v2` ile yazılmalı.
3. **Anahtar barkod.** 34Pazar varyant eşlemesi `stockCode` ile kurulursa
   yazmalar boşa gider. Pazarama tarafı `code` (barkod) bekler. Barkodsuz
   varyant Pazarama'ya bağlanamaz.
4. **10 sn kuralı + 3000 sınırı.** Stok ve fiyat ayrı çağrılar, aynı
   satıcı için arka arkaya atılamaz. Kuyrukta Pazarama için satıcı başına
   tek işçi, istekler arası ≥10 sn, stok ve fiyat birleştirilip 3000'lik
   dilimlerle gönderilmeli. Aşılınca dönen hata biçimi **[doğrulanmadı]**.
5. **Asenkron sonuç 4 saate kadar "İşleniyor" kalabilir.** Sonuç
   yoklaması uzun süreli ve seyrek olmalı. Ürün batch ID'si de 4 saat
   sonra sorgulanamıyor, sonuç zamanında çekilmeli.
6. **Fiyat onayına düşme.** Büyük indirimde fiyat "Onaya gönderildi" kalır,
   satış eski fiyattan sürer. Adapter bunu "başarılı" saymamalı, ayrı
   durum olarak göstermeli.
7. **`endDate` hariç.** "Bugüne kadar" sorgusu için `endDate` yarın
   verilmeli. Yoksa günün siparişleri sessizce eksik gelir.
8. **Güncellenme tarihi filtresi yok.** İptal/iade gibi sonradan gelen
   değişiklikler yalnız sipariş tarihine göre pencere yeniden taranarak
   görülür. Pencere 1 ayı geçemez.
9. **Kendi kargosu olan satıcıda teslim durumu da satıcıda.** `5`'ten sonra
   `11`/`14`'ü 34Pazar (ya da satıcı) yazmazsa sipariş açık kalır.
   Kargo firması takibinden teslim bilgisi çekmek ayrı iş.
10. **Secret 365 günde ölür.** Bağlantı kaydına "anahtar oluşturma
    tarihi" alanı ve 14 gün önceden uyarı konmalı. Satıcı panelde yeni
    anahtar üretirse eskisi anında düşer, 34Pazar'daki bağlantı da
    kopar.
11. **Hata gövdeleri farklı:** token hatası 400 + JSON zarf, API yetki
    hatası 401 + **boş gövde**. JSON çözümleyici boş gövdede patlamamalı.
12. **Kimlikler GUID, numaralar sayı.** `orderId`, `orderItemId`,
    `refundId`, `cargoCompanyId`, kategori/marka/özellik ID'leri GUID.
    `orderNumber`, `refundNumber` büyük sayı. Hepsi string saklanmalı.
13. **Paket numarası kalıcı değil.** Yeniden paketleme, iptal ve tedarik
    edilememe yeni paket üretir. Kalıcı anahtar `orderItemId`.
14. **Örnek cevaplarda gerçek görünen müşteri verisi var** (ad, e-posta,
    telefon, adres). Dokümandan test verisi kopyalanırken temizlenmeli.

### Kullanıcıdan istenecekler

1. Test edilecek gerçek Pazarama satıcı hesabının **clientId + clientSecret**'ı
   (Hesabım > Hesap Bilgileri > Entegrasyon Bilgileri) ve üretim tarihi.
2. Bu satıcı **Pazarama anlaşmalı kargo** mu kullanıyor, kendi kargosu mu?
3. Mağazada **parçalı sipariş (split)** açık mı? (V1/V2 seçimi)
4. Ürünlerin hepsinde **barkod** var mı, 34Pazar'daki varyantlar barkodla
   eşleşiyor mu?
5. İlk gerçek siparişte doğrulanacaklar: token cevap biçimi, tarih saat
   dilimi, statü filtresi parametresi, 10 sn kuralı aşılınca dönen hata.

### Kod durumu

8 Ekim 2026: `app/Domain/Channels/Adapters/Pazarama/PazaramaAdapter.php`
yazıldı. Kanal `is_active = false`, gerçek mağazayla sınanmadı. Testler:
`tests/Feature/Channels/PazaramaAdapterTest.php` (21) ve
`tests/Feature/Orders/PazaramaOrderSliceTest.php` (10). 22 mutasyonun hepsi
kırmızı.

**Yapılanlar:**

- **Kimlik:** kasada `client_id` / `client_secret` (ikas'la aynı adlar,
  `BASIC_AUTH_KEY_PAIRS` içinde yok). Token Basic başlıkla ve form gövdeyle
  alınıyor. Bağlanırken `token_exchange` ile alınıyor, sonra
  `credentials:refresh` taraması yeniliyor (payı 40 dk). Hem
  `access_token` hem `data.accessToken` okunuyor. Süre gelmezse resmi
  1 saat yazılıyor.
- **Secret 365 gün:** formda isteğe bağlı "API anahtarının üretildiği gün"
  alanı var (`pazarama_secret_created_at`). Tarih girildiyse bitiş
  `refresh_expires_at` olarak yazılıyor. Mevcut rozet (`TokenStatus`) ve
  `token_expiring_soon` metriği 14 gün kala uyarıyor. Tarih girilmezse
  rozet 1 saatlik erişim anahtarını gösteriyor (ikas'taki gibi).
- **İçe aktarma:** yalnız `approved` uç noktası, imleçle, `Size=100`.
  Kimlik `code` (barkod), üst kimlik `groupCode`, SKU `stockCode`.
  Onaysız ürünler alınmıyor.
- **Stok/fiyat:** `updateStock-v2` / `updatePrice-v2`, istek başına
  ≤3000. Stok yükünde fiyat, fiyat yükünde stok yok. `dataId` sonuçta
  `batch_id` olarak taşınıyor. 10 sn kuralı adapter'da, bağlantı başına
  önbellek kilidiyle uygulanıyor: erken gelen yazım istek atmadan
  `RATE_LIMITED` + kalan süre dönüyor. Çekirdeğin kovası saniyede 1'in
  altını ifade edemediği için seeder profili 1/sn, patlama 1, tek eşzamanlı.
- **Sipariş:** `getOrdersForApiV2`. 27 günlük gün dilimleri,
  `endDate` = yarın, yeniden eskiye. Her turda en az 30 gün geriye
  bakılıyor (güncellenme filtresi yok). Aynı barkodun kalemleri tek satırda
  toplanıyor. İptal `6`/`13`, iade `8` (`10` yalnız geçmişte `8` varsa).
  Alınmamış siparişin iptali/iadesi stok değiştirmiyor. Kişisel veri
  beyaz listeyle süzülüyor.
- **Onay (3→12):** `acknowledgeOrder` → `PUT /order/updateOrderStatusList`.
  Yazıldı ama hiçbir akışa bağlı değil (gerekçe sınıf notunda: `12`'de
  kargolanmayan sipariş otomatik iadeye düşüyor ve satıcı puanı düşüyor).
- **Yazılmayanlar:** ürün açma, kargo/takip bildirme, `getRefund` ile iade
  ayrıntısı. (`lake-projections` ile batch sonucu okuma 8 Eki'de eklendi:
  `SupportsBatchStatus`, `sync:poll-batches`; `5` "Onaya gönderildi"
  başarı sayılmıyor, panelde "Bekliyor". DOĞRULANMADI: satır listesinin
  zarftaki yeri, `2` "Hata oluştu"nun geçici olup olmadığı.)

**Gerçek mağazada ilk bakılacaklar:**

1. Token cevabının biçimi (`access_token` mı `data.accessToken` mı) ve
   `expires_in` gelip gelmediği.
2. `getOrdersForApiV2` bölme kullanmayan satıcıda da çalışıyor mu, yanıtı
   V1 ile aynı biçimde mi. `pageSize: 500` kabul ediliyor mu.
3. Bölgesiz `orderDate` Türkiye saati mi. İstekteki gün sınırları doğru
   siparişleri getiriyor mu.
4. Otomatik onaylanan iade sipariş tarafında `8` mi `10` mu görünüyor.
   Yalnız `10` görülürse günlükte `pazarama.refund_without_approved_return`
   uyarısı çıkar ve stok eklenmez.
5. Kalem `totalPrice` adet toplamı mı birim mi. `orderAmount` kargoyu
   içeriyor mu.
6. İlk stok/fiyat isteğinin `dataId`'si `lake-projections` ile elle
   sorgulanıp sonuç "Başarılı" görülmeli (adapter bunu okumuyor).
7. 10 sn kuralı aşılınca dönen hata biçimi. Bugün adapter kuralı kendisi
   uyguladığı için görülmemesi beklenir.
8. `Code` süzgecinin tam eşleşme yapıp yapmadığı (mutabakat).

---

## 7. Yetenek → işlem eşleme tablosu (özet)

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

## 8. Kullanıcıdan istenecekler

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

## 9. Kanal başına uygulama zorluğu

| Kanal | Zorluk | Neden |
|---|---|---|
| **ikas** | **Kolay–orta** | Modern GraphQL. Stok ve fiyat toplu ve senkron, `updatedAt` filtresi ve webhook var. Private app ile partner onayı gerekmiyor. Riskler: hata oranına göre kalıcı engel (retry disiplini şart), stok lokasyon eşlemesi, uç nokta belirsizliği (v1/v2, .com/.dev). |
| **Ticimax** | **Orta** | Kavram olarak basit (anahtar + SOAP, onay yok) ama dokümanlar 2020–2021'den kalma ve canlı WSDL'den farklı. PHP SoapClient + WCF + Türkçe alan adları serileştirme tuzakları var. Hız limiti ve sandbox yok. Fiyat güncelleme ya tekil ya da dokümansız. Sipariş için yalnızca yoklama var ve "aktarıldı" bayrağı paylaşımlı. |
| **Amazon SP-API** | **Zor** | Teknik olarak en kapsamlı API, ama asıl zorluk teknik değil: public developer kaydı, kısıtlı rol (PII) güvenlik incelemesi ve belirsiz onay süresi. Ayrıca: 365 günlük yeniden yetkilendirme, PII 30 gün saklama kuralı, asenkron feed ve rapor akışları, ürün tipi JSON Schema'sı ile ilan oluşturma, çok dar sipariş hız sınırı, 27 Mart 2027 Orders v0 kapanışı (doğrudan v2026 yazılmalı) ve TR/DE için muhtemelen ayrı yetkilendirme. |

**Önerilen sıra:** Amazon başvurusu **bugün** yapılmalı, çünkü onay
beklenirken iş durmaz. Bu sırada ikas (en hızlı değer), ardından Ticimax
yazılır. Amazon kodu onay gelince başlar.
