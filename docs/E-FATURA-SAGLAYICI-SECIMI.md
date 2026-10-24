# e-Fatura sağlayıcı seçimi — Ekim 2026

Paraşüt'ün API erişimi gelmeyecek (kullanıcı kararı, 24 Eki 2026). Fiyat sayfasındaki
e-fatura sözünün doğru olması için, satıcının **kendi hesabıyla, 34Pazar'ın aracı
sözleşmesi olmadan** bağlanabildiği bir ikinci sağlayıcı arandı.

Ölçütler: herkese açık REST belgesi · test ortamı · satıcının kendi anahtarıyla
bağlanma (aracı sözleşmesi YOK) · e-fatura + e-arşiv · e-arşiv iptali · PDF ·
mükellef sorgusu · kontör modeli. BirFatura RAKİP (ücretsiz pazaryeri entegrasyonu
satıyor) — aday değil.

Tüm erişimler **24.10.2026**. "Doğrulanmadı" = belgede yazmıyor ya da belgeye
ulaşılamadı; uydurma bilgi yok.

| Sağlayıcı | Açık belge | Protokol | Test ortamı | Satıcının kendi anahtarı | e-Fatura / e-Arşiv | e-Arşiv iptali | PDF | Mükellef sorgusu | Kontör |
|---|---|---|---|---|---|---|---|---|---|
| **Nilvera** ✅ seçildi | Evet — developer.nilvera.com (OAS 3, `.md` sürümleri) | REST/JSON | Evet — `apitest.nilvera.com` (test anahtarı ayrı) | **Evet** — portalda "API Tanımları → Yeni Anahtar", `Authorization: Bearer` | Evet / Evet (`einvoice`, `earchive`) | Evet (`PUT earchive/Invoices/{uuid}/Cancel`) | Evet (dosya olarak) | Evet (`general/GlobalCompany/Check/TaxNumber/{vkn}`) | Satıcının Nilvera hesabı (`general/Credits`) |
| İzibiz | Kısmen — dev.izibiz.com.tr; içerik girişe kapalı | REST + SOAP | Evet (GitHub `izibiz/ws-client-dotnet` test servisine bağlı) | Doğrulanmadı — "müşteri / kanal / satış olarak token" türleri var, kanal modeli sözleşme gerektirebilir | Evet / Evet (menüde) | Doğrulanmadı | Doğrulanmadı | Evet (SOAP `CheckUser`) | Doğrulanmadı |
| Uyumsoft | WSDL adresleri açık (efatura-test.uyumsoft.com.tr); REST belgesi bulunamadı | SOAP | Evet (test servis adresi açık) | Kullanıcı/şifre müşterinin — doğrulanmadı | Doğrulanmadı | Doğrulanmadı | Doğrulanmadı | Doğrulanmadı | Doğrulanmadı |
| QNB eSolutions (eFinans) | Hayır — kimlik ve test adresi e-postayla isteniyor (üçüncü taraf kütüphane README'si) | SOAP | Var deniyor, doğrulanmadı | Doğrulanmadı | — | — | — | — | — |
| EDM | Ölçülemedi — developer.edmbilisim.com.tr çözülmüyor | — | — | — | — | — | — | — | — |
| Logo e-Dönüşüm | Ölçülemedi — developer.logo.com.tr çözülmüyor | — | — | — | — | — | — | — | — |
| Mysoft | Ölçülemedi — resmî belge bulunamadı (yalnız topluluk paketleri) | — | — | — | — | — | — | — | — |
| Turkcell e-Şirket | Ölçülemedi — resmî belge bulunamadı | — | — | — | — | — | — | — | — |
| Foriba / Sovos | Ölçülemedi — docs.sovos.com kurumsal; TR e-fatura API'si ayrıca bulunamadı | — | — | — | — | — | — | — | — |

## Neden Nilvera

Tek aday her ölçütü **belgeyle** karşılıyor: belge herkese açık ve makinece
okunabilir, satıcı anahtarı kendi üretiyor (34Pazar'ın uygulama anahtarı ya da
aracı sözleşmesi gerekmiyor — Paraşüt'ü kilitleyen şey buydu), e-fatura/e-arşiv/iptal/
PDF/mükellef sorgusu hazır. Kontör ve fatura sözleşmesi satıcının Nilvera hesabında;
34Pazar aracı değil.

## Uygulama (kod)

- `app/Domain/Invoicing/Providers/Nilvera/` — `NilveraClient`, `NilveraInvoiceProvider`
  (`InvoiceProvider` + yeni `ProvidesPdfContent`). Paraşüt kodu duruyor.
- ETTN bizde üretilir ve istekten ÖNCE `provider_job_id`'ye yazılır; yeniden deneme aynı
  ETTN'yi gönderir, Nilvera 409/1001-1004-1012 der → başarı (ikinci resmî belge yok).
- Muhasebe yok: "yalnız muhasebeye işle" kipi, tahsilat eşlemesi ve iade KAYDI Nilvera
  hesabında kapalı (`InvoiceAccount::keepsBooks`).
- PDF dosya olarak gelir: sipariş ekranı ve kalıcı bağlantı (`/f/{token}.pdf`) dosyayı
  akıtır, kanala yükleme dosyayı doğrudan yükler. Saklanmaz.
- Döviz faturası TCMB döviz ALIŞ kuruyla (`exchange_rates`); kur yoksa kesilmez.
- Ayarlar → Fatura ve muhasebe: API anahtarı + "test ortamı" seçimi; anahtar kaydetmeden
  önce `general/Company` ile denenir, firma unvanı/VKN oradan okunur; kalan kontör gösterilir.

## Gerçek hesapta doğrulanacaklar

- e-Arşiv `StatusCode` değerinin gönderimden sonraki seyri (biz `error` dışını kesilmiş sayıyoruz).
- Mükellef sorgusunun `Name` (etiket `urn:mail:…`) ve `Type` (`PK`/`GB`) alanları.
- Toplamların Nilvera'da yeniden hesaplanıp hesaplanmadığı (biz KDV dahil tutardan geriye hesaplıyoruz).
- e-Arşiv internet satışında taşıyıcı alanları (`TransporterName`, `TransporterRegisterNumber`):
  belge "kalem hizmet değilse zorunlu" diyor; sipariş anında taşıyıcı VKN'si yok, gönderilmiyor.
  Nilvera reddederse metni satıcıya gider; gerekirse kargo firması VKN'si ayarlara eklenir.
- Seri boşken portaldaki varsayılan serinin kullanılması.
- Test ortamı hesabının nasıl açıldığı (self-servis mi, Nilvera'dan mı istenir).

Kaynaklar: developer.nilvera.com (genel-bakis, api-anahtari-uretimi, hata-kodlari,
e-fatura-api, e-arsiv-api, genel-api, nilvera-model, kod-listeleri) · dev.izibiz.com.tr ·
github.com/izibiz/ws-client-dotnet · efatura-test.uyumsoft.com.tr · packagist.org
(muhittingulap/qnbfinansbank README).
