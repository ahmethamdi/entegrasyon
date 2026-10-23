# İdefix satıcı API'si: notlar (24 Ekim 2026)

Kaynak: **developer.idefix.com** (herkese açık Grav sitesi, giriş istemiyor).
55 sayfanın tamamı 24 Ekim 2026'da indirilip okundu. Sitede sayfa tarihi yok;
örneklerdeki veriler 2022–2023 tarihli.

Kod: `app/Domain/Channels/Adapters/Idefix/IdefixAdapter.php`,
test `tests/Feature/Channels/IdefixAdapterTest.php`. Kanal **BETA**: gerçek
satıcı hesabıyla sınanmadı.

## Temel gerçekler

- **Kimlik:** satıcı paneli → Hesap Bilgilerim → Entegrasyon Bilgileri →
  "Yeni API Oluştur". API KEY ve API SECRET satıcının e-postasına gelir.
  Her istekte `X-API-KEY: base64(ApiKey:ApiSecret)`. Yanlış kimlik:
  `401` + `"message": "VENDOR_TOKEN_NOT_EXIST"`.
  ([Authorization](https://developer.idefix.com/authorization))
- **Satıcı ID** her yolda var (`/oms/{vendorId}/list`). Kimlik bilgileri
  prod ve stage'de ayrı.
- **Ortamlar:** canlı `https://merchantapi.idefix.com` (`/pim/…`, `/oms/…`).
  Test ortamı `ide-pimapi.idefiks.net` / `ide-omsapi.idefiks.net`, yolları
  farklı (`/api/connector/…`, `/api/shipment/connect/…`), **IP izni ve
  İdefix'in açacağı test hesabı** ister. Canlıda IP izni gerekmez.
  ([Canlı - Test Ortam Bilgileri](https://developer.idefix.com/canli-test-ortam-bilgileri))
- **Hız sınırı:** sayı yok. Aşılınca `429`, başlıklar `X-Api-Call-Limit`
  ve `Retry-After`. Bir istekte en çok **200** kalem.
  ([SSS](https://developer.idefix.com/sikca-sorulan-sorular))
- **Webhook yok.** Sipariş ve iade yalnız yoklamayla.
- **API ürün güncellemesi yok:** yalnız stok-fiyat güncellenir (SSS).

## Ürün

- Havuz listesi: `GET /pim/pool/{vendorId}/list?page&limit&barcode&state`.
  Alanlar: `barcode`, `title`, `productMainId`, `vendorStockCode`,
  `inventoryQuantity`, `price`, `comparePrice`, `images[{url}]`,
  `attributes`, `status`.
- Ürün statüleri (batch-result sayfası): `waiting_catalog_action`,
  `waiting_vendor_approve`, `ready_for_sale`, `vendor_declined`,
  `missing_info`, `platform_declined`, `not_matched`, `auto_matched`,
  `manual_matched`.
- Ürün açma `POST /pim/pool/{vendorId}/create` (marka/kategori/özellik
  kimlikleriyle) ve eşleşme onay/red uçları var. **Bu sürümde yazılmadı.**

## Stok ve fiyat

- `POST /pim/catalog/{vendorId}/inventory-upload`, gövde `{"items":[…]}`.
  Kalemde **yalnız `barcode` zorunlu**; `price`, `comparePrice` ("göndermek
  istemiyorsanız 0"), `inventoryQuantity`, `maximumPurchasableQuantity`,
  `deliveryDuration`, `deliveryType` (`regular` / `same_day_shipping`),
  `isZoneSale`. Yanıtta `batchRequestId`.
- Sonuç: `GET /pim/catalog/{vendorId}/inventory-result/{batchId}`. Kalem
  `status`: `created` / `decline` / `completed`. `failureReasons`:
  `DATA_PARSE_ERROR`, `BATCH_NOT_EXIST`, `BATCH_ALREADY_PROCESSED`,
  `PRODUCT_NOT_FOUND`, `CATALOG_PRICE_LOCKED` (örnekte bir de `NO_PRICE`).
- Güncel stok/fiyat: `GET /pim/catalog/{vendorId}/inventory/list` (parametre
  belgelenmemiş). Adapter tekil okuma için havuz listesinin `barcode`
  süzgecini kullanıyor.

## Sipariş (sevkiyat)

- `GET /oms/{vendorId}/list` — `ids`, `orderNumber`, `state`, `startDate`,
  `endDate`, `lastUpdatedAt` (biçim `2022/09/30 23:59:59`), `page`
  (varsayılan 1), `limit` (varsayılan 10), `sortByField`, `sortDirection`.
  Yanıtta `pageCount`, `currentPage`.
- **Sevkiyat = satıcı başına sipariş.** `orderNumber` ana sipariş, `id`
  sevkiyat. Kalemlerde **her kalem 1 adet** (SSS); adetli liste için
  `list-v2` var.
- Statüler: `created`, `shipment_ready`, `shipment_picking`,
  `shipment_invoiced`, `shipment_cancelled`, `shipment_unsupplied`,
  `shipment_split`, `shipment_in_cargo`, `shipment_delivered`,
  `shipment_undeliver`, `shipment_approved`.
- **Bölme:** `split-package` sonrası aynı `orderNumber` altında yeni
  sevkiyatlar açılır, eskisi `shipment_split` olur.
- Statü bildirimi `POST /oms/{vendorId}/{shipmentId}/update-shipment-status`
  (`picking` → müşteri artık iptal edemez; `invoiced` + fatura no).
- Kargo: `update-tracking-number` (takip URL'si + numara) belgeye göre
  siparişi **platform anlaşmasından satıcının kendi anlaşmasına geçirir**;
  takip adresi siparişteki kargo profiliyle uyuşmazsa hata verir.
  Alternatif teslimat ayrı uç.
- İade: `GET /oms/{vendorId}/claim-list` (`lastUpdatedAt` dahil). İade
  `orderNumber` taşır; kalemde `orderLineId` (= sevkiyat kalem `id`),
  `barcode`, `state` (`ready`, `in_cargo`, `waiting_vendor_approve`,
  `approved`, `decline`, `vendor_decline_request`).

## 34Pazar'daki eşleme ve kararlar

| Konu | Karar | Neden |
|---|---|---|
| Listing kimliği | barkod | stok-fiyat ucunun anahtarı |
| İçe aktarma | yalnız `ready_for_sale`, `auto_matched`, `manual_matched` | incelemedeki ürüne stok `PRODUCT_NOT_FOUND` olur |
| Sayfalama | boş sayfa gelene kadar | sayfa üst sınırı belgede yok; kısa sayfayı "son" saymak kataloğu sessizce yarıda keserdi |
| Stok işi | `barcode` + `inventoryQuantity` | fiyat taşınırsa bayat fiyat kampanyayı ezer |
| Fiyat işi | `barcode` + `price` + `comparePrice` (yoksa 0) | stok taşınırsa satılmış ürün yeniden satışa açılır |
| Sipariş kimliği | sevkiyat `id` | iptal/bölme sevkiyat düzeyinde |
| `shipment_split` | iptal | yeni sevkiyatlar ayrıca "created" olur; net sıfır (Trendyol `UnPacked` kararı) |
| İlk kez görülen iptal/bölünmüş sevkiyat | kayıt yok | stok hiç düşmedi |
| İade | yalnız `approved`, kalem başına, adet 1 | sevkiyat, `orderLineId` ile `orderNumber`'ın sevkiyatlarında aranır |
| Kargo bildirimi | **yazılmadı** | platform anlaşmalı siparişi satıcı anlaşmasına geçirip kargo bedelini satıcıya kaydırabilirdi |
| `picking` bildirimi | yazılmadı | müşterinin iptal hakkını kapatır |
| Kişisel veri | alınmaz | alıcı, adres, T.C. kimlik no sevkiyat kaydına girmez |

## Doğrulanmadı (ilk gerçek hesapta)

1. Yalnız stoklu `inventory-upload` kalemi kabul ediliyor mu (fiyatı hiç
   olmayan ürün `NO_PRICE` alabilir).
2. `lastUpdatedAt` Türkiye saati mi; süzgeç güncellenme tarihine mi bakıyor.
3. Sayfa boyutu üst sınırı (`limit=100` kabul ediliyor mu).
4. Kalem düzeyi iptal: `itemStatus` değerleri belgede yok; yalnız sevkiyat
   düzeyi iptal okunuyor.
5. `inventory-result` kaç saat sorgulanabiliyor (adapter 24 saat varsayıyor).
6. Havuz listesinin `barcode` süzgeci tam eşleşme mi.
7. Üst sınır: `X-Api-Call-Limit` değeri.

---

# ePttAVM: neden kod yazılmadı (24 Ekim 2026)

- Resmî geliştirici portalı **developers.pttavm.com** Cloudflare
  arkasında. Hem bu bilgisayardan (gerçek Chrome dahil) hem 34Pazar
  sunucusundan (212.227.142.108) "Sorry, you have been blocked" (403)
  dönüyor. Web arşivinde kopyası yok.
- Erişilebilen tek resmî parça SOAP hizmetinin WSDL'i:
  `https://ws.pttavm.com:93/service.svc?wsdl` (sunucudan da 200). İşlem
  adları: `StokFiyatGuncelle(2/3/Bulk)`, `StokGuncelle(V2/Bulk)`,
  `SiparisKontrolListesi(V2)`, `SiparisDetay`, `UpdateProductsV3`,
  `GetProductsWithVariants`, `KargoBilgiListesi`, `GetCategoryTree`,
  `BarkodKontrol(Bulk)`, `SaveInvoince`, `GetVersion` vb.
- WSDL kimlik doğrulamasını, sipariş/iade durum kodlarını ve hata
  biçimlerini **anlatmıyor**. Arama sonuçlarındaki portal duyurusu API
  anahtarına geçiş olduğunu ve kullanıcı adı/şifrenin bir geçiş süresi
  daha çalışacağını söylüyor. Ayrıntılar yalnız üçüncü taraf sayfalarda
  (Codeilla, Rentegre), doğrulanamadı.
- Bu yüzden ePttAVM adapter'ı **yazılmadı** (yarım/tahmini adapter canlıya
  çıkmaz kuralı).

**Gerekenler:** (1) developers.pttavm.com'a Türkiye IP'sinden ya da bir
PttAVM satıcı hesabıyla erişip belgenin tamamını (kimlik, sipariş
statüleri, iade, kargo) almak; (2) bir PttAVM satıcı hesabının API
bilgileri (Mağaza Yönetimi → "Api Bilgilerim & Entegratör Firmalarım":
kullanıcı adı, API şifresi, mağaza ID) ya da yeni API anahtarı.
