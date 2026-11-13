# MNG Kargo API Zone — notlar (13 Kas 2026)

Durum: **MOCK_TESTED · doğrulama bekliyor · varsayılan GİZLİ.** Kod resmî Swagger tanımına göre
yazıldı ve tanımdaki örnek yanıtlarla (`Http::fake`) sınandı. Ne sandbox ne canlı sunucuya istek
atıldı; kimlik bilgisi yok.

Görünürlük (iki kapı, ikisi de açılmadan satıcı MNG'yi göremez):
1. `MngApp::configured()` — 34Pazar'ın API Zone uygulama anahtarı (`MNG_CLIENT_ID`/`MNG_CLIENT_SECRET`,
   sandbox için `MNG_TEST_CLIENT_ID`/`MNG_TEST_CLIENT_SECRET`) yoksa sunulmaz (Amazon/OTTO/Etsy deseni).
2. `CarrierAccount::UNVERIFIED` — pilot için `CARRIERS_UNVERIFIED_ENABLED=mng`.
"Beta" rozeti kullanılmaz (24 Eki kararı).

## Kaynak
- RESMÎ: apizone.mngkargo.com.tr ürün sayfaları; her sayfa Swagger 2.0 tanımını girişsiz HTML'e gömüyor
  (`drupal-settings-json` → `api.explorer.apis[0]`). Okunan ürünler:
  Identity `/tr/product/5500/api/2990` · Standard Command `/tr/product/5496/api/1745` ·
  Barcode Command `/tr/product/5494/api/1739` · Standard Query `/tr/product/5498/api/1746` ·
  CBS Info `/tr/product/5510/api/1741` · Plus Command `/tr/product/5502/api/1743`.
- RESMÎ OLMAYAN (yalnız işaretli yerlerde kullanıldı): `tcgunel/omniship-mng`, `mohamedhekal/shipbridge-mng`.

## Uç ve kimlik
| Ortam | Adres | Kaynak |
|---|---|---|
| Canlı | `https://api.mngkargo.com.tr/mngapi/api` | spec `host` + `basePath` |
| Sandbox | `https://testapi.mngkargo.com.tr/mngapi/api` | yalnız açık kaynak istemciler — **doğrulanmadı** (sandbox portalı 13 Kas'ta 522) |

- Her istek: `X-IBM-Client-Id` + `X-IBM-Client-Secret` (spec `securityDefinitions`) — PLATFORMUN anahtarı.
- `POST /token` `{"customerNumber","password","identityType":1}` → `{"jwt","refreshToken","jwtExpireDate",…}`.
  Hata 400 ProblemDetails / 401 → AUTHENTICATION.
- Komut/sorgu: `Authorization: Bearer <jwt>`; CBS Info Bearer istemez (spec'te parametre yok).
- JWT ömrü spec'te yok (örnek tarihlerden ve istemcilerden ~saatler); kodda 60 dk önbellek
  (`MNG_TOKEN_TTL`). `GET /refresh`'in gövdesi/parametresi spec'te YOK → kullanılmadı, süre bitince yeni token.
- 401 → token bir kez tazelenir; yine 401 → AUTHENTICATION (hesap "koptu").
- `x-api-version` başlığı isteğe bağlı, değerleri belgesiz → gönderilmez.

## Kullanılan işlemler
| İş | Uç | Not |
|---|---|---|
| Hesap doğrulama | `POST /token` | iz bırakmaz |
| İl kodu | `GET /cbsinfoapi/getcities` | `[{"code":"01","name":"Adana"}]`; 7 gün önbellek |
| İlçe kodu | `GET /cbsinfoapi/getdistricts/{cityCode}` | `[{"cityCode","cityName","code","name"}]` |
| Sipariş aç | `POST /standardcmdapi/createOrder` | `{order, orderPieceList[], recipient}` |
| Barkod + etiket | `POST /barcodecmdapi/createbarcode` `{"referenceId"}` | `shipmentId` + `barcodes[].value` |
| Durum | `GET /standardqueryapi/getshipmentstatus/{referenceId}` | 404 = yok / henüz gönderiye çevrilmedi |
| İptal | `PUT /barcodecmdapi/cancelshipment` `{referenceId, shipmentId}` → `PUT /standardcmdapi/cancelorder/{ref}` | sıra açık kaynak notundan |

## Gönderdiğimiz alanlar (spec'te zorunlu olanlar dahil)
- `order.referenceId` = `order.barcode` = bizim anahtar (`34P…`, zaten BÜYÜK harf; spec: benzersiz, büyük harf).
- `shipmentServiceType` 1 (STANDART_TESLİMAT) · `packagingType` 3 (PAKET) · `paymentType` 1 (GONDERICI_ODER) ·
  `deliveryType` 1 (ADRESE_TESLIM) · `smsPreference1/2/3` 0 (ek SMS hizmeti istenmez) · `isCOD` 0 ·
  `content`/`description` "Sipariş {no}".
- `orderPieceList[]`: paket başına `{barcode: "{ref}_{n}", desi: 1, kg: 1, content}` — desi/kg zorunlu int;
  ölçü bilinmediği için 1 (Aras'la aynı varsayım; **fiyatlamaya etkisi doğrulanmadı**).
- `recipient`: `fullName`, `address`, `cityCode`, `districtCode` (int), `mobilePhoneNumber` (10 hane, spec
  `createRecipient` örneği gibi), `email`. `cityName`/`districtName` yalnız örnekte var, şemada YOK → gönderilmez.
- İl/ilçe adı CBS listesiyle eşlenir (Türkçe harf, büyük/küçük ve "ADANA 11" gibi rakam ekleri yok sayılır).
  Eşleşmezse firmaya gidilmez → VALIDATION ("ilçesi MNG ilçe listesinde bulunamadı").

## Cevaplar ve eşleme
| `shipmentStatusCode` | spec | Çekirdek |
|---|---|---|
| 1 | Gönderi_Hazırlandı | waiting |
| 2 / 3 / 4 | Transfer / Teslimat birimine ulaştı / Alıcı adresine yönlendirildi | shipped |
| 5 (veya `isDelivered` 1) | Teslim_Edildi | delivered |
| 6 / 7 / 8 | Teslim edilemedi / Geri geliyor / Destek gerekiyor | shipped (firma elinde) |
| başka | — | unknown |

- İptal için durum kodu spec'te YOK → iptal yalnız bizim iptal isteğimizle (`cancel`) olur.
- `trackingUrl` spec örneğinde şemasız (`www.mngkargo.com.tr/track/…`) → başına `https://` eklenir.
- `barcodes[].value`'nun biçimi spec'te YOK; açık kaynak istemciler ZPL diyor. `^XA` içeriyorsa ZPL
  etiket saklanır, içermiyorsa etiket boş kalır (takip no yine gelir). **Doğrulanmadı.**
- Hata gövdesi: spec yalnız 400/404 için ProblemDetails (`title`/`detail`); açık kaynak istemciler
  `{"error":{"code","message","description"}}` görmüş — ikisi de okunur. Resmî hata kodu listesi YOK.
- 4xx okunabilir gövde → VALIDATION (firmanın metniyle); 5xx / gövdesiz / bağlantı → geçici.

## İdempotens
Aynı `referenceId` ile ikinci `createOrder` resmî spec'te belgelenmemiş. Açık kaynak: HTTP 500,
`error.code` 3002 "BU SİPARİS NUMARASINA AİT KAYIT ZATEN VAR!". Kod bunu (ya da "zaten var" metnini)
"kayıt var" sayar → `getshipmentstatus`: gönderi numarası varsa o döner (ikinci barkod yok; etiket o
durumda gelmez), 404 ise yalnız `createbarcode` tekrarlanır. `createbarcode`'un tekrarında davranış
**doğrulanmadı**.

## Doğrulanmadı (ilk gerçek hesapta bakılacak)
1. Sandbox adresi; JWT ömrü; `/refresh` biçimi.
2. Kod 3002 ve `createbarcode` tekrarı.
3. `barcodes[].value` ZPL mi; PDF alınabiliyor mu.
4. Yalnız il/ilçe adıyla gönderim mümkün mü (şu an kod zorunlu tutuluyor).
5. Desi 1 varsayımının fiyata etkisi; `smsPreference` 0'ın kabulü.
6. Kod 20001 "VARIŞ ŞUBESİ BULUNAMADI" (açık kaynak) — geçici sayılıp yeniden denenir.

## Kimden ne gerekir
- **34Pazar (bir kez):** apizone.mngkargo.com.tr'de hesap → uygulama → Identity, Standard Command,
  Barcode Command, Standard Query, CBS Info ürünlerine abonelik (MNG onayı) → Client Id/Secret.
  Canlı için açık kaynak notu: `entegrasyon@mngkargo.com.tr`'ye uygulama adı + sabit çıkış IP'si
  (IP beyaz liste) — **resmî metinde yok, MNG'ye teyit ettirilmeli.**
- **Satıcı:** MNG müşteri numarası + kalıcı şifre (şubeden / müşteri temsilcisinden).
