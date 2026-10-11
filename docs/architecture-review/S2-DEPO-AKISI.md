# S2 · Trendyol depo akışı — toplu etiket, fatura belgesi, toplama listesi (12 Ekim 2026)

**Durum: IMPLEMENTATION COMPLETE — LIVE VERIFICATION PENDING** (etiket yazma akışı canlı hesapta yetkisiz; bkz. §8).

## 1. Denetim (kod yazılmadan önce)

| Yetenek | Önce | Not |
|---|---|---|
| Trendyol sipariş/paket modeli | PARTIAL | `external_id = shipmentPackageId`; `cargoProviderName/cargoTrackingNumber` HİÇ okunmuyordu |
| Kargo firması verisi | MISSING | yalnız şifreli inbox yükünde |
| TEX / ortak etiket (`createCommonLabel`) | MISSING | yalnız ADR-004 belgesi |
| Fatura PDF | EXISTS | Paraşüt e-Arşiv/e-Fatura; PDF saklanmaz, süreli bağlantı |
| Sipariş listesi | PARTIAL | seçim/toplu işlem yok |
| Toplu işlem mimarisi | PARTIAL | `channel_batches` listing'e bağlı; siparişe uygun değil |
| PDF/dosya üretimi | MISSING | kütüphane yok |
| Kuyruk/iş, yeniden deneme, hız sınırı, devre kesici | EXISTS | `IssueInvoice` iskeleti |
| Kanonik durum (ADR-001) | EXISTS | `OrderState`, Trendyol eşlemesi adapter'da |
| Denetim kaydı | EXISTS | sipariş/kargo için AuditAction yok (eklenmedi) |
| Kiracı sınırı | EXISTS | `BelongsToTenant` + kontrolcüde çözme |
| Varyant/seçenek | PARTIAL | seçenek etiketi üreten yardımcı yoktu |
| Sebep kodu deseni | MISSING | Türkçe metin DB'ye yazılıyordu (TD-37) |

## 2. Trendyol uçları
| Uç | Yöntem | Kullanım |
|---|---|---|
| `/integration/order/sellers/{id}/v2/orders?shipmentPackageIds=` | GET | paketi anlık oku (taşıyıcı, takip no, kutu, durum) — fatura taslağıyla ortak `findPackage` |
| `/integration/sellers/{id}/common-label/{cargoTrackingNumber}` | GET | etiketi sor `{data:[{label, format}]}` |
| `/integration/sellers/{id}/common-label/{cargoTrackingNumber}` | POST `{format:"ZPL", boxQuantity}` | etiket iste (200 gövdesiz) |

Kaynak: developers.trendyol.com createCommonLabel / getCommonLabel (11 Eki okundu). Yalnız TEX ve
"Aras Kargo by Trendyol" (Trendyol ödemeli); belge PICKING/INVOICED sonrasını önerir; tek biçim ZPL.

## 3. Etiket yaşam döngüsü (`shipping_labels`, paket başına tek satır)
`pending → requested → ready | failed`
1. **Kabul (senkron):** kanonik durum `awaiting_shipment` değilse `INVALID_ORDER_STATE`; kanal `SupportsCommonLabel` değilse `CHANNEL_UNSUPPORTED`; satır `insertOrIgnore`.
2. **İş (`PrepareShippingLabel`, `orders:high`):** paketi oku → kanaldaki GÜNCEL durum, takip no, taşıyıcı uygunluğu → **önce GET** (varsa istek yok) → `requested_at` koşullu talep → **POST bir kez** → 20 sn arayla en çok 15 soru → `ready` (asıl ZPL `payload` + sha256) ya da `LABEL_NOT_READY`.
3. **Yeniden üretim:** Trendyol belgesi yeniden üretimi tanımlamıyor → modellenmedi. `failed` satırda satıcının AÇIK yeniden denemesi satırı sıfırlar.

## 4. İdempotens
| Tekrar | Koruma |
|---|---|
| Çift tıklama / form yeniden gönderimi | `fulfillment_batches (tenant, idempotency_key)` tekil → aynı toplu iş |
| Aynı siparişe yeni toplu işlem | `shipping_labels_order_unique` → aynı satır, yeni iş YOK (yalnız 15 dk'dan bayat açık satır) |
| Kuyruk tekrarı / iki işçi | `UPDATE … WHERE requested_at IS NULL` talebi; kaybeden yalnız GET |
| HTTP zaman aşımı / 5xx POST'ta | `requested_at` korunur → bir daha POST yok, yalnız GET |
| Kesin 4xx ret | talep geri alınır (kanalda iz yok) |
| Fatura kes (tekrar) | mevcut `invoices_order_unique` |

## 5. Biçimler
- **ZPL (termal):** hazır etiketler `…/labels.zpl` tek dosyada, DOKUNULMADAN birleştirilir.
- **PDF:** kanal PDF adresi verirse (v3 uluslararası örnekte var) referans saklanır.
- **ZPL → A4 PDF: YOK.** Yerel güvenilir işleyici yok; harici servis (Labelary) alıcı adresini üçüncü tarafa gönderir. **Karar kullanıcıda.** Eklenecekse yalnız `LabelDocuments`'a.
- **A4 yazdırma:** toplama listesi tarayıcı yazdırma sayfası (`Fulfillment/Picking`), kütüphane eklenmedi.

## 6. Toplu işlemler, toplama listesi, fatura belgesi
- İşlemler: `labels`, `picking`, `invoice_documents` (yan etkisiz durum + PDF bağlantısı), `issue_invoices` (mevcut `RequestInvoice`, Paraşüt'e yeni finansal yazım YOK). En çok 100 sipariş.
- Kalem sonucu: `succeeded · in_progress · action_required · failed` — kuyruktaki kalemin sonucu kaynağından (etiket/fatura satırı) okunur, ekran 5 sn'de bir yenilenir.
- Kiracı: kimlikler scope altında çözülür; tek bir çözülemeyen kimlik bütün isteği reddeder; başka kiracının siparişi ile olmayan sipariş AYNI cevabı alır.
- Toplama: A) varyant başına toplam (ürün + seçenekler + SKU + barkod + adet + sipariş sayısı), B) sipariş/paket başına döküm. Adet = sipariş − iptal − iade. Eşlenmemiş kalem kanal SKU'suyla `VARIANT_UNMATCHED`.
- Fatura terimi: Paraşüt e-Arşiv/e-Fatura; Trendyol paketinde indirilebilir belge yok.

## 7. Durum kuralları (`FulfillmentPolicy`)
| İşlem | İzinli kanonik durum |
|---|---|
| Etiket iste | awaiting_shipment (+ iş içinde kanalın güncel durumu) |
| Hazır etiketi indir | awaiting_shipment, shipped |
| Fatura belgesi / kes | awaiting_shipment, shipped, delivered, partially_returned |
| Toplama / paketleme | awaiting_shipment |
NULL durum hiçbir işleme alınmaz. Ham Trendyol durumu yalnız adapter'da okunur.

## 8. Canlı doğrulama (salt okuma, 11 Eki)
- `GET v2/orders` (14 gün): 1 paket, `Cancelled`, `cargoProviderName = "Yurtiçi Kargo Marketplace"`, alanlar: `boxQuantity, deci, invoiceStatus, invoiceNumber, sellerDeliveryMethod, 3pByTrendyol` (invoiceLink YOK).
- `GET common-label/{takip}` → **400 `COMMON_LABEL_NOT_ALLOWED`** "Bu servisi kullanmak için yetkiniz bulunmamaktadır. Kategori sorumlunuz ile iletişime geçiniz." → `COMMON_LABEL_NOT_AUTHORIZED`.
- POST yapılmadı (yetki yok + uygun paket yok + geri alınamaz eylem).

**IMPLEMENTED — NOT LIVE VERIFIED:** POST/GET başarı gövdeleri, ZPL içeriği, çok kutu, TEX/Aras `cargoProviderName` metinleri (eşleşme `trendyol express|tex|aras`), etiket hazır olma süresi, 400 "kargo takip no" ve 404 gövdeleri, PDF biçimi.
**Canlı doğrulama için gerekli:** Trendyol kategori sorumlusundan ortak etiket yetkisi + TEX/Aras ile gelen gerçek bir paket (Picking/Invoiced).

## 9. Sebep kodları (`FulfillmentReason`, DB'de kod; metin `label()` ile çevrilir)
INVALID_ORDER_STATE, CHANNEL_UNSUPPORTED, CHANNEL_UNAVAILABLE, CARGO_PROVIDER_UNSUPPORTED,
COMMON_LABEL_NOT_AUTHORIZED, TRACKING_NUMBER_MISSING, SHIPMENT_NOT_FOUND, LABEL_NOT_READY,
LABEL_CREATION_FAILED, LABEL_FORMAT_UNSUPPORTED, INVOICE_DOCUMENT_MISSING, INVOICE_PENDING,
INVOICE_FAILED, INVOICE_ACCOUNT_MISSING, INVOICE_BOOKS_ONLY, VARIANT_UNMATCHED.

## 10. Kapsam dışı (bilerek)
Çok depo, WMS, kargoya teslim bildirimi (Trendyol `updatePackage`), ZPL→PDF, AuditAction eklenmesi,
işlem merkezi ekranı, S1 sınıflandırması, TD-37/38/39/41, A/B/B'.
