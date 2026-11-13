# Logo ERP (Tiger / Wings) — Logo Objects REST Servis notları (13 Kas 2026)

Durum: **MOCK_TESTED · doğrulama bekliyor · varsayılan GİZLİ.** Kod Logo'nun resmî belgesindeki
örneklere göre yazıldı, örnek yanıtlarla (`Http::fake`) sınandı. Hiçbir Logo kurulumuna istek atılmadı.

Görünürlük (iki kapı): `LogoApp::configured()` (34Pazar'ın Logo çözüm ortağı `LOGO_CLIENT_ID` /
`LOGO_CLIENT_SECRET`) + `InvoiceAccount::UNVERIFIED` (pilot için `ERP_UNVERIFIED_ENABLED=logo`).
"Beta" rozeti yok (24 Eki kararı); açıkken ekranda "gerçek kurulumda henüz denenmedi" notu.

## Hangi arayüz, neden
| Arayüz | Buluttan erişim | Belge | Karar |
|---|---|---|---|
| **Logo Objects REST Servis** (Tiger 3 / Enterprise / Wings) | satıcı sunucusunu https ile dışarı açarsa | resmî (docs.logo.com.tr — alan adı 13 Kas'ta NXDOMAIN, Wayback kopyası) | **SEÇİLDİ** |
| Logo Objects (COM/DLL) | yok — Windows'ta yerel | resmî | SaaS'tan çağrılamaz |
| j-Platform REST | j-Guar ürünleri için | resmî | Tiger/Go değil |
| Go 3 / Go Wings | REST sayfası "uyumsuz" diyor, başka sayfa "Tiger3/GO3" | çelişkili | **doğrulanmadı** |

## Kaynak (Wayback)
- Ana sayfa: `docs.logo.com.tr/public/wua/logo-objects-rest-servis` (arşiv 2024-12-13)
- "Logo REST Kütüphane" — token isteği/yanıtı örneği
- "Logo REST Data Nesnesi Arayüzleri" — `POST api/v1/salesOrders` tam istek/yanıt örneği
- "Logo REST Resource" — kaynak adları (`salesOrders`, `salesInvoices`, `Arps`, `items` …)
- "Postman Kullanım Örnekleri" — `ClientID:ClientSecret` base64
- "Servis başlamaz…" — "Çözüm ortaklarımıza ClientID ve ClientSecret bilgileri oluşturmaktayız"

## Kimlik
- `POST {adres}/api/v1/token`, `Authorization: Basic base64(ClientID:ClientSecret)`,
  gövde `grant_type=password&username=…&password=…&firmno=…` (belge örneği birebir; örnek başlıkta
  `Content-Type: application/json` yazıyor ama gövde form — kod form-urlencoded gönderir, **doğrulanmadı**).
- Yanıt: `access_token`, `token_type: bearer`, `expires_in` (sn; örnekte 119), `refresh_token`.
  Kod `expires_in − 30` sn önbellekler (60 sn altıysa hiç), 401'de bir kez yeni token alır.
- Dönem numarası token isteğinde YOK (belgede yalnız firma no) — gerekirse **doğrulanmadı**.

## Kapsam — neden satış SİPARİŞİ
- Belgedeki tek eksiksiz yazma örneği `salesOrders`. Alanlar birebir oradan:
  `INTERNAL_REFERENCE: 0`, `NUMBER: "~"` (numarayı Logo verir), `DATE`, `ARP_CODE`, `CURRSEL_TOTAL: 1`,
  `TRANSACTIONS.items[]`: `TYPE 0`, `MASTER_CODE`, `QUANTITY`, `PRICE`, `VAT_RATE`, `UNIT_CODE`,
  `UNIT_CONV1/2: 1`, `EDT_CURR: 1`. Yanıtta `INTERNAL_REFERENCE` (Logo LOGICALREF) → `invoices.provider_invoice_id`.
- `salesInvoices`: belgede gövde örneği ve fatura türü (`TYPE`) kodları YOK → tahminle resmî belge türü
  üretilmez. Satıcı siparişi Logo'da kendi akışıyla irsaliye/faturaya çevirir; e-belgeyi Logo e-Fatura keser.
- `Arps` (cari kartı) alanları yalnız resmî olmayan kaynaklarda → **cari açılmaz**. Satıcı Logo'daki pazar yeri
  carisinin kodunu ayara yazar (ör. `120.TRENDYOL`); alıcının kişisel verisi Logo'ya GİTMEZ.
- `MASTER_CODE` = kanal kalemindeki SKU (Logo malzeme kodu SKU ile aynı olmalı). SKU'suz kalem → VALIDATION.
- `PRICE` = KDV HARİÇ birim fiyat (`InvoiceLine::netUnitPrice`). Logo'nun PRICE'ı KDV hariç yorumladığı
  **doğrulanmadı**. `TIME` alanı (örnekte paketli sayı) gönderilmez — zorunlu mu **doğrulanmadı**.
- Kip HER ZAMAN "yalnız muhasebeye işle" (`InvoiceAccount::mode`): `poll` çağrılmaz, kanala dosya yüklenmez.
- İptal / iade: ERP kaydı uzaktan SİLİNMEZ; talep anında "Logo'dan yapın" diye reddedilir.

## Erişim ve güvenlik
- Servis satıcının sunucusunda (örnek portlar 9010 / 32001). 34Pazar yalnız **https + 443** ile
  bağlanır (`ErpEndpoint` → `OutboundUrlGuard`): satıcı ters vekil / tünelle dışarı açar. İç ağ adresi,
  tek etiketli ad (`postgres`), `.local` vb. kayıtta; DNS'in iç IP'ye dönmesi istekte reddedilir
  (IP sabitleme, yönlendirme izlenmez).
- Kimlik `invoice_accounts.credentials` (şifreli), cari kodu/birim `settings`. Ekrana şifre dönmez.

## Doğrulanmadı (ilk gerçek kurulumda)
1. Token gövde biçimi (form) ve hata gövdesi (JSON alanları belgede yok; `Message` / `error_description` okunur).
2. `PRICE` KDV hariç mi; `TIME` gerekli mi; `CURRSEL_TOTAL`/`EDT_CURR` anlamı (örnekten kopya).
3. Go 3 / Go Wings uyumu.
4. Tekrar güvenliği: POST yanıtı ile kimliğin kaydı arasında iş ölürse ikinci sipariş açılabilir
   (Paraşüt'teki aynı pencere) — Logo'da doğal anahtar alanı belgede yok.

## Kimden ne gerekir
- **34Pazar (bir kez):** Logo çözüm ortaklığı → Logo'nun ürettiği ClientID / ClientSecret; çözüm ortağı
  projeleri için satıcıda "Logo Objects Run-Time" lisansı gerekir (belge).
- **Satıcı:** Logo REST servisi kurulu ve https ile dışarıya açık adres, Logo kullanıcı adı + şifre,
  firma numarası, pazar yeri cari kodu; malzeme kodları SKU ile aynı.
