# Yurtiçi Kargo web servisi — notlar (23 Eki 2026)

Kaynak: firmanın WSDL'i (`…/ShippingOrderDispatcherServices?wsdl`) ve herkese açık
test sunucusunda 23 Eki 2026'da yapılan gerçek çağrılar. Gerçek müşteri hesabıyla
gönderi AÇILMADI — özellik Beta.

## Uç ve kimlik
| Ortam | Adres |
|---|---|
| Canlı | `https://ws.yurticikargo.com/KOPSWebServices/ShippingOrderDispatcherServices` |
| Test  | `http://testwebservices.yurticikargo.com:9090/KOPSWebServices/ShippingOrderDispatcherServices` |

- SOAP 1.1, ad alanı `http://yurticikargo.com.tr/ShippingOrderDispatcherServices`, `SOAPAction: ""`.
- Kimlik her istekte gövdede: `wsUserName`, `wsPassword`, dil (`userLanguage` / sorguda `wsLanguage`) `TR`.
- Web servis kullanıcısı satıcının Yurtiçi sözleşmesine bağlıdır; şubeden / müşteri temsilcisinden alınır.
  Test sunucusunun herkese açık kullanıcısı firmanın entegrasyon belgesindedir — depoya YAZILMAZ.

## Kullanılan işlemler
| İş | İşlem | Not |
|---|---|---|
| Hesap doğrulama | `queryShipment` olmayan anahtarla | 82519 = kimlik doğru, üst düzey 1626 = yanlış |
| Gönderi aç | `createShipment` (`ShippingOrderVO`) | `cargoKey` = bizim anahtar (34P + 12 karakter), `invoiceKey` aynı |
| Durum | `queryShipment` `keyType=0` | `shippingDeliveryDetailVO.operationStatus` |
| İptal | `cancelShipment` `cargoKeys` | yalnız kargo teslim almadan |

## Ölçülen cevaplar
| Durum | Cevap |
|---|---|
| Başarılı create | `outFlag 0`, `outResult Başarılı`, detayda yalnız anahtarlar |
| Yanlış şifre (her işlem) | `outFlag 1`, üst düzey `errCode 1626` "Web servis kullanıcı ve parola bilgileri örtüşmüyor" |
| Kısa adres | `outFlag 1`, detay `errCode 82502` "RECEIVER_ADDRESS parametresi min : 10 max : 500" |
| Aynı anahtarla 2. create | detay `errCode 60020` (gönderi zaten var) → BAŞARI sayılır |
| Durum, işlem görmemiş | `operationStatus NOP` "Kargo İşlem Görmemiş." |
| İptal | `operationStatus CNL` "Verisi İptal Edilmiştir.Kargo Çıkışı Engellendi." |
| İptal sonrası sorgu / olmayan anahtar | detay `errCode 82519` "… kargo anahtarı bulunmamaktadır." |

## Kurallar (kodda)
- Firma ETİKET VERMEZ ve gönderi açılırken TAKİP NUMARASI YOKTUR. Etiketi `YurticiLabel`
  üretir (ZPL 100×100, barkod = cargoKey). Müşterinin takip numarası (`docId`) kargo şubede
  işlem görünce durum sorgusunda gelir; o zaman kanala bildirilir (`shipping:track`, 30 dk).
- 60020 idempotens kabulü sayesinde zaman aşımı sonrası aynı istek güvenle tekrarlanır.

## Doğrulanmadı (ilk gerçek müşteriyle)
- `IND`, `ISR`, `DLV`, `ISC`, `BI` durum kodları (belgedeki adlar) — test ortamı kurye süreci içermez.
- `docId` / `trackingUrl` alanlarının gerçek gönderide dolu geldiği yer
  (`shippingDeliveryItemDetailVO`).
- Şubenin bizim ürettiğimiz Code 128 barkodu okuması.
- Canlı sunucunun hız sınırı (belgede yok).
