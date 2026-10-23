# Aras Kargo web servisi — notlar (23 Eki 2026)

Kaynak: firmanın WSDL'i (`arascargoservice.asmx?wsdl`) ve herkese açık test sunucusunda
23 Eki 2026'da yapılan gerçek çağrılar. Gerçek müşteri hesabıyla gönderi AÇILMADI — Beta.

## Uç ve kimlik
| Ortam | Adres |
|---|---|
| Canlı | `https://customerws.araskargo.com.tr/arascargoservice.asmx` |
| Test  | `https://customerservicestest.araskargo.com.tr/arascargoservice/arascargoservice.asmx` |

- SOAP 1.1 (.NET), ad alanı `http://tempuri.org/`, `SOAPAction: "http://tempuri.org/{İşlem}"`.
- Kimlik gövdede; alan adlarının büyük/küçük harfi işleme göre DEĞİŞİR
  (`SetOrder`: `Order/UserName` + dışta `userName`; `GetArasBarcode`: `Username`; `CancelDispatch`: `userName`).
- Test sunucusunun herkese açık kullanıcısı firmanın entegrasyon belgesindedir — depoya YAZILMAZ.

## Kullanılan işlemler
| İş | İşlem | Not |
|---|---|---|
| Hesap doğrulama | `GetArasBarcode` olmayan kodla | 1001 = kimlik doğru, 999 = yanlış |
| Gönderi aç | `SetOrder` | `IntegrationCode` = `TradingWaybillNumber` = `InvoiceNumber` = bizim anahtar |
| Etiket + takip no | `GetArasBarcode` | `TrackingNumber`, `ZebraZpl/string`, `ZebraPdf/string` (base64 PDF) |
| İptal | `CancelDispatch` `integrationCode` | |

## Ölçülen cevaplar
| İşlem | Kod |
|---|---|
| SetOrder | 0 başarılı · 1000 kullanıcı/şifre yanlış · aynı IntegrationCode ile tekrar → yine 0 (ikinci gönderi yok) |
| GetArasBarcode | 0 → takip no + ZPL + PDF · 1001 kod bulunamadı · 999 kullanıcı/şifre yanlış |
| CancelDispatch | 0 başarılı · 405 daha önce iptal · -1 kayıt bulunamadı · -2 kullanıcı/şifre hatalı |

## Kurallar (kodda)
- DURUM SORGUSU YOK: `GetCargoInfo` / `GetQueryJSON` ayrı müşteri kodu istiyor, test kullanıcısı
  reddedildi; cevap biçimi doğrulanamadığı için ayrıştırıcı yazılmadı. Satıcı paketi kuryeye
  verince sipariş ekranında "Kargoya verdim" der; takip numarası (etiketle gelir) kanala gider.
- ⚠️ `GetOrderWithIntegrationCode` yanlış şifrede de BOŞ ve hatasız döner — sessiz başarı
  üreteceği için hiçbir yerde kullanılmaz.
- ⚠️ SetOrder aynı kodla TEKRARLANINCA gönderi çoğalmaz (PieceCount 1) ama Aras YENİ takip numarası
  üretir; aynı kayda tekrarlanan GetArasBarcode hep aynı numarayı verir (ölçüldü). Bu yüzden `create`
  önce GetArasBarcode sorar: 0 → mevcut kaydı döndürür, 1001 → SetOrder + GetArasBarcode.
  Yeniden deneme takip numarasını değiştirmez (test ortamında gerçek kodla doğrulandı).
- SetOrder hemen ardından barkod 1001 dönerse geçici sayılır (iş yeniden dener).

## Doğrulanmadı (ilk gerçek müşteriyle)
- Canlı hesapta `GetArasBarcode`'un aynı alanları döndürmesi (PDF dahil).
- Desi/ağırlık: şimdilik parça başına 1 kg / 1 desi gönderiliyor — sözleşmeye göre fiyatı etkileyebilir.
- Hareket servisi (`GetCargoInfo`) için gereken müşteri kodu; gelirse otomatik izleme eklenir.
