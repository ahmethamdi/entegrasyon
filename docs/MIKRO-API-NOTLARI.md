# Mikro ERP — Mikro Desktop API notları (13 Kas 2026)

Durum: **MOCK_TESTED · doğrulama bekliyor · varsayılan GİZLİ.** Kod resmî belgeye göre yazıldı, belgedeki
örnek yanıt modelleriyle (`Http::fake`) sınandı. Hiçbir Mikro kurulumuna istek atılmadı.

Görünürlük: `InvoiceAccount::UNVERIFIED` → pilot için `ERP_UNVERIFIED_ENABLED=mikro`. Mikro'da platform
kimliği YOK (ApiKey kuruluma özel, satıcı girer) → tek kapı. "Beta" rozeti yok (24 Eki kararı).

## Kaynak (RESMÎ, girişsiz)
- `https://apidocs.mikro.com.tr` — uç listesi `apis.md`, her uç `.md` alan listesi, `llms.txt` sayfa dizini.
- `SiparisKaydetV2` alanları: `/apis/siparis/paths/~1api~1apimethods~1sipariskaydetv2/post.md`
- `CariListesiV3` alanları: `/apis/cari/paths/~1api~1apimethods~1carilistesiv3/post.md`
- Kod anlamları: "TABLO NO: 21" `/tablo-alan-adlari/siparisler.md` (`sip_tip 0:Talep 1:Temin`,
  `sip_cins 0:Normal Sipariş …`).
- Kimlik/şifre: `guides.md` ("Tarih + Şifre → MD5", "Gününtarihi Boşluk Şifre", örn. `2023-03-09 123asd`).
- Yanıt modelleri: `response-models/statuscode/*`, `response-models/auth/{apikey,sifre,lisans}.md`.
- Kurulum: `install.md` (v17 port 8094, v16 8084; "API local sunucuda çalışır").

## Erişim
Mikro Desktop API satıcının sunucusunda (Windows servisi). 34Pazar yalnız **https + 443** ile bağlanır
(`ErpEndpoint` → `OutboundUrlGuard`, IP sabitleme, yönlendirme izlenmez): satıcı servisi ters vekil /
tünelle dışarı açar. Mikro Jump bulut ağ geçidi (`jumpbulutapigw.mikro.com.tr`, alias + IDM token) belgede
yalnız `APILogin` için anlatılmış; veri uçlarının ağ geçidinden çağrılma yolu YOK → **bu sürümde kapsam dışı**.

## Kimlik
- V2 uçlarında her istekte `Mikro` nesnesi: `ApiKey`, `FirmaKodu`, `CalismaYili`, `KullaniciKodu`, `Sifre`.
- `Sifre` = `md5("yyyy-MM-dd" + " " + şifre)`, küçük harf hex, **İstanbul tarihiyle** her istekte yeniden →
  şifre düz (şifreli sütunda) saklanır. Saat dilimi belgede yazmıyor — **doğrulanmadı**.
- `CalismaYili` belgede uca göre string (cari/stok uçları) ya da integer (sipariş/fatura) — kod buna uyar.
- `ApiKey` Mikro'dan: "Dikey çözüm kullananlara otomatik atanır. Şirket içi yazılımlar için manuel lisans verilir."
  Başvuru: https://www.mikro.com.tr/mikro-program-api-basvuru/

## Kullanılan uçlar
| İş | Uç | Not |
|---|---|---|
| Bağlantı + cari doğrulama | `POST /Api/APIMethods/CariListesiV3` `{Mikro, CariKod, Index 0, Size "1"}` | iz bırakmaz |
| Sipariş aktar | `POST /Api/apiMethods/SiparisKaydetV2` | `evraklar[].satirlar[]` |

## Kapsam — neden SİPARİŞ, neden cari açılmaz
- Belgedeki alanlar hiçbir uçta "zorunlu" işaretli değil; `sip_tip`/`sip_cins` anlamı tablo sayfasında var,
  fatura (`FaturaKaydetV2`) için `cha_tip`, `cha_cinsi`, `cha_evrak_tip`, `sth_evraktip` gibi kodların
  **anlamı YOK** → resmî belge türü tahminle üretilmez. Satıcı siparişi Mikro'da faturaya çevirir, e-belgeyi
  Mikro keser. Kip hep "yalnız muhasebeye işle".
- Cari: satıcının Mikro'daki pazar yeri carisi (`sip_musteri_kod`, ayar `customer_code`). Alıcının kişisel
  verisi Mikro'ya GİTMEZ.
- Satır alanları (belge örneğiyle aynı ad): `sip_tarih` (dd.MM.yyyy), `sip_tip "0"` (Talep — müşteriden
  alınan sipariş olarak yorumlandı, **doğrulanmadı**), `sip_cins "0"` (Normal), `sip_evrakno_seri` (ayar, boş
  olabilir), `sip_musteri_kod`, `sip_stok_kod` (= SKU), `sip_miktar`, `sip_b_fiyat` (KDV HARİÇ birim fiyat —
  **doğrulanmadı**), `sip_tutar` (net × adet), `sip_vergi_pntr` (eşleme), `sip_vergisiz_fl 0`,
  `sip_birim_pntr 1` (belge örneği), `sip_depono` (ayar, varsayılan 1).
- `sip_vergi_pntr` firmanın KDV tablosundaki SIRA numarasıdır; belgede oran karşılığı YOK → satıcı ayarda
  yazar: `20:4,10:3,1:2,0:1`. Eşlenmemiş oran → VALIDATION (firmaya gidilmez).
- `evrak_aciklamalari[0].aciklama` = "{Kanal} {sipariş no}" (izlenebilirlik, 50 karakter).
- Gönderilmeyen: `seriler`, `user_tablo`, `varyant`, `sip_stok_sormerk`, `sip_evrakno_sira` (otomatik
  verildiği **doğrulanmadı**).

## Yanıt
- Zarf: `{"result":[{"StatusCode":200,"Data":{…},"ErrorMessage":"","IsError":false}]}`.
- `IsError: true` ya da StatusCode ≠ 200 → istisna. 401 / "Geçersiz ApiKey!" / "Şifre Hatalı!" / 401-05
  lisans–MAG → AUTHENTICATION; 400-01 ve satır hataları ("N. Satır : …") → VALIDATION (Mikro'nun metni);
  okunamayan gövde / 5xx → geçici.
- Sipariş kaydında `Data` içeriği (GUID / evrak no) belgelenmemiş: `Data`'da GUID ya da adı guid/evrak/sira
  içeren ilk değer `provider_invoice_id` olur; yoksa `kayit:{invoices.ref}` (kaydedildi işareti — yeniden
  deneme ikinci sipariş açmaz).

## Doğrulanmadı (ilk gerçek kurulumda)
1. `sip_tip 0` = satış (alınan) siparişi mi; `sip_b_fiyat` KDV hariç mi.
2. Sipariş yanıtında `Data` içeriği; `sip_evrakno_sira` otomatik mi.
3. Şifre hash'inde saat dilimi (gece yarısı geçişi).
4. Tekrar güvenliği: yanıt ile kaydın arasında iş ölürse ikinci sipariş açılabilir (Paraşüt/Logo ile aynı
   pencere); belgede doğal anahtar yok — ileride `SqlVeriOkuV2` ile açıklama araması düşünülebilir.
5. Hız sınırı (resmî olmayan kaynak: saatte 100 auth) — sipariş başına tek istek atılır.

## Kimden ne gerekir
- **Satıcı:** Mikro v16/v17 + Mikro Desktop API kurulu ve https ile dışarı açık adres; ApiKey (Mikro'dan,
  başvuru formu), firma kodu, çalışma yılı, kullanıcı kodu (genelde SRV) + şifre; pazar yeri cari kodu;
  KDV → vergi işaretçisi eşlemesi; depo no; (isteğe bağlı) evrak serisi. Stok kodları SKU ile aynı.
  Lisans: Run/Jump/Fly için MAG aktif ya da kiralama süresi devam ediyor olmalı (belge 401-05).
