# HepsiJet entegrasyon servisleri — notlar (13 Kas 2026)

Durum: **MOCK_TESTED · doğrulama bekliyor · varsayılan GİZLİ.** Kod yalnız belgeye göre yazıldı ve
belgedeki örnek yanıtlarla (`Http::fake`) sınandı. Ne test ne canlı sunucuya tek istek atıldı;
kimlik bilgisi yok.

Görünürlük: "Beta" rozeti kullanılmaz (24 Eki kararı, `NoBetaBadgeContractTest`). HepsiJet
`CarrierAccount::UNVERIFIED` listesindedir → kargo ayarlarında görünmez. Pilot satıcıyla denemek için
`.env`: `CARRIERS_UNVERIFIED_ENABLED=hepsijet`; açıkken kartta "gerçek hesapta henüz denenmedi" notu
durur. Gerçek hesapta uçtan uca denenince listeden çıkarılır ve `CertificationEvidence` güncellenir.

## Kaynak
HepsiJet'in herkese açık geliştirici portalı / Swagger'ı YOK (hepsijet.com otomatik isteğe 403).
Kullanılan belge, HepsiJet'in partnerlerine verdiği tasarım belgesinin açık depolara düşmüş kopyaları:

| Belge | Nerede | Not |
|---|---|---|
| "Module Design – Integration (Customer Services)" v2.1 (PDF, Ara 2021) | `github.com/mhunesi/yii2-hepsijet` → `docs/` | `apikey` başlığını ekleyen sürüm |
| "Module Design – Update Services" v1.9.2 (DOCX) | `github.com/Nuri-Akseli/hepsijet-api-integration` → `Documentation/` | Türkçe durum karşılıkları, `generateZplBarcode` |

Resmî olmayan istemciler (yalnız karşılaştırma için, alan adı buradan ALINMADI):
`tcgunel/omniship-hepsijet`, `mohamedhekal/shipbridge-hepsijet`, `rastmob/B2BHepsijetIntegration`.

## Uç ve kimlik
| Ortam | Adres | Kaynak |
|---|---|---|
| Canlı | `https://integration.hepsijet.com` | belge |
| Test | `https://integration-apitest.hepsijet.com` | açık kaynak istemciler — belge `dmzlastmile.hepsiexpress.com:8091` yazıyor (eskimiş görünüyor) — **doğrulanmadı** |

- `GET /auth/getToken`, `Authorization: Basic base64(kullanıcı:şifre)` → `{"status":"OK","data":{"token":"…"}}`.
  Token **60 dk** (belge); kodda 50 dk önbellek (`HEPSIJET_TOKEN_TTL`), anahtar kimliğin SHA-256 özeti.
- Yanlış kimlik: `{"status":"FAIL","message":"Kullanıcı Adı veya Şifre Hatalı"}` → AUTHENTICATION.
- Sonraki her istek `X-Auth-Token: <token>`. 401/403 gelirse token bir kez tazelenir, yine
  reddedilirse AUTHENTICATION (hesap "koptu").
- `apikey` başlığı: v2.1 PDF "zorunlu" diyor, v1.9.2 hiç yazmıyor, açık kaynak istemciler
  göndermiyor → satıcı HepsiJet'ten aldıysa gönderilir. **Zorunlu olup olmadığı doğrulanmadı.**

## Kullanılan işlemler
| İş | Uç | Not |
|---|---|---|
| Hesap doğrulama | `GET /auth/getToken` | token alınabiliyorsa kimlik doğru — firmada iz bırakmaz |
| Gönderi aç + etiket | `POST /rest/delivery/sendDeliveryOrderEnhanced` | cevapta `data.customerDeliveryNo` + `zplBarcodeDTOList[].zplBarcode` (ZPL) |
| Sonradan etiket | `GET /delivery/generateZplBarcode/{barkod}/{paket}` | belgede `/rest` öneki YOK; yii2 istemcisi `rest/delivery/…` çağırıyor — **doğrulanmadı** |
| Durum | `POST /rest/delivery/integration/track` `{"barcodes":[…]}` | son `details[].integrationStatus` |
| İptal | `POST /rest/delivery/deleteDeliveryOrder/{barkod}` `{"deleteReason":"IPTAL"}` | belge: yalnız ilk statüde |

## Gönderdiğimiz alanlar (belgede M = zorunlu)
- `company.abbreviationCode` = satıcının firma kısaltma kodu (formda zorunlu).
- `delivery.customerDeliveryNo` (M) = bizim anahtar: **firma kodu + rastgele kuyruk, toplam 16**
  (belge: "firma baş harfleriyle başlar, 9–16 karakter"). `Carriers::newCargoKeyFor()`.
- `customerOrderId` (M) = kanal sipariş numarası · `deliveryType` (M) = `RETAIL` (belge: "Dış müşteri
  entegrasyonunda RETAIL gelmelidir") · `product.productCode` (M) = `HX_STD` · `totalParcels` (String).
- `receiver.companyCustomerId` (M, "alıcı adresi başına tekil") ve `recipientAddress.companyAddressId` (M):
  alıcı adı+adres+il+ilçe'nin SHA-256 özetinden (`C…` / `A…`) — kişisel veri değil, özet.
- `receiver.firstName/lastName/phone1` (M): ad son kelimeden bölünür; tek kelimeyse ikisi aynı
  (**belge tek kelimelik adı anlatmıyor — doğrulanmadı**). Telefon belge örneği gibi 10 hane, başta 0 yok.
- `recipientPerson`, `recipientPersonPhone1` (M) · `recipientAddress.country/city/town/addressLine1` (M).
- `senderAddress.country/city/town/addressLine1` (M) → satıcının formda girdiği gönderici adresi;
  `companyAddressId` (O) varsa. `currentXDock.abbreviationCode` (O) depo kodu girildiyse.
- GÖNDERİLMEYEN (O): desi/ağırlık/ölçü (belge `lenght` yazıyor, istemciler `length` — çelişki),
  `deliveryContent` (yeri belgede ve istemcilerde farklı), teslim slotu, tahsilatlı gönderi.

## Cevaplar ve eşleme
| Durum | Belgedeki anlamı | Çekirdek |
|---|---|---|
| `NEO` | Gönderi oluştu | waiting |
| `ACCEPTED` | Gönderi teslim alındı | shipped (takip no kanala) |
| `OUT_FOR_DELIVERY` | Gönderi yolda | shipped |
| `FAILED_ATTEMPT` | Teslimat denemesi başarısız | shipped |
| `RETURNED` | Gönderi satıcıya iade edildi | shipped (firma teslim almıştı) |
| `DELIVERED` | Gönderi teslim edildi | delivered |
| `CANCELLED` / `DELETED` | iptal / silindi | cancelled |
| başka / hareket yok | — | unknown (hiçbir şey değişmez) |

- Aynı barkod: `{"status":"FAIL","detailStatus":"BARCODE_EXIST","message":"Gönderi numarası sistemde kayıtlı."}`
  → gönderi ilk istekte açılmış; etiketi `generateZplBarcode` ile isteriz, sonuç BAŞARI (ikinci gönderi yok).
- Takipte "Gönderi numarası bulunamadı" → NOT_FOUND.
- Okunabilir `FAIL` gövdesi → VALIDATION (firmanın metniyle, yeniden denenmez); gövdesiz 4xx/5xx,
  bağlantı/zaman aşımı → geçici (RetryPolicy).
- Tam hata kodu kataloğu belgede YOK.

## Doğrulanmadı (ilk gerçek hesapta bakılacak)
1. Test sunucusu adresi ve test kullanıcısı (HepsiJet'ten istenir).
2. `apikey` başlığı zorunlu mu.
3. `generateZplBarcode` yolunda `/rest` öneki.
4. `deliveryType=RETAIL` pazaryeri siparişini kendi sözleşmesiyle gönderen satıcı için doğru mu
   (`MARKET_PLACE` "pazaryeri entegrasyonları" için).
5. Tek kelimelik alıcı adı, desi gönderilmemesinin fiyatlamaya etkisi.
6. `ACCEPTED`'ın gerçekten şubeye/kuryeye teslimde geldiği (erken gelirse takip no kanala erken gider).
7. Güncel belge sürümü (bulunan v2.1, 2021–22).

## Satıcıdan gerekenler
HepsiJet entegrasyon kullanıcı adı + şifre, firma kısaltma kodu, (varsa) API anahtarı, gönderici
adresi, (varsa) depo kodu. Başvuru: HepsiJet satış temsilcisi / şube üzerinden "API entegrasyonu"
talebi; herkese açık self-servis sandbox yok.
