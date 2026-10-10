# 34Pazar — rakiplere göre yapılacaklar

Hazırlanma: 9 Ekim 2026. Rakip bilgileri o gün sitelerinden okundu (Entegra: entegrabilisim.com, 8 Eki; Platin360: platin360.com, 9 Eki).
34Pazar durumu depodaki koddan (`635064c`) alındı. Süre tahminleri kaba tahmindir, ölçülmedi.

## 1. Bugünkü tablo

| | **34Pazar** | **Entegra** | **Platin360** |
|---|---|---|---|
| Ödeme | **Aylık**, ücretsiz katman var | Yıllık peşin | Yıllık peşin (3 taksit) |
| Giriş fiyatı | **0 ₺** (25 ürün, 1 kanal) · 499 ₺/ay (500 ürün, 2 kanal) | 24.750 ₺/yıl, KDV dahil (3 pazar yeri) | 29.990 ₺/yıl, KDV %0 (3 pazar yeri) |
| 5 kanal | **1.499 ₺/ay ≈ 17.988 ₺/yıl** (5.000 ürün) | 39.000 ₺/yıl (sınırsız) | 39.990 ₺/yıl (tüm pazar yerleri) |
| Sınırı ne belirliyor | Ürün **ve** kanal sayısı | Pazar yeri sayısı + modül | Kullanıcı + modül, ürün sınırsız |
| TR pazar yeri (canlı) | Trendyol (HB, N11, Pazarama, ÇS kodda hazır, kapalı) | 26 | ~15 |
| eBay | **Var** | Yok | Yok |
| Etsy | **Canlı** | Var | "Yakında" |
| Amazon | Yok (başvuru gerekli) | Yalnız TR | Global |
| Altyapı | Shopify, Woo, ikas, Ticimax | 17 | 6 |
| e-fatura | **Yok** | 11 entegratör | 13 entegratör (en ucuz pakette bile) |
| Kargo entegrasyonu / etiket | **Yok** (yalnız takip no bildirimi) | 13 firma | 8 firma + toplu etiket |
| Muhasebe / ERP | **Yok** | 21 | 8 |
| Tedarikçi XML | **Yok** | Var (P1) | Var (ek ücretli) |
| İade / talep yönetimi | **Yok** (iptal stoğa döner) | Var (P2) | Var |
| Soru-cevap | **Yok** | Var | Var |
| Hakediş / mutabakat raporu | **Yok** | Var | Var (Pro) |
| Fiyat kuralı + zarar koruması | **Var** | Akıllı fiyat (P2) | Kâr/zarar sihirbazı |
| Süreli kampanya | **Var** | Belirsiz | Görünmüyor |
| AI | Yok | Otonom AI listeleme (P1) | Yalnız çeviri |

**Özet:** fiyat ve yurtdışı kanallarda öndeyiz. Türk satıcının günlük operasyonunda (fatura, kargo, iade, muhasebe) iki rakipten de geriyiz. Satış görüşmesinde ilk sorulacak şey e-fatura ve kargo.

## 2. Öncelik sırası

### P0 — Satışı engelleyenler (bunlar olmadan TR satıcısına satılmaz)

- [ ] **TR pazar yerlerini canlıya al: Hepsiburada, N11, Pazarama, Çiçeksepeti.** Kod yazılı ve testli, kanallar kapalı. Gereken: her birinde gerçek satıcı hesabıyla uçtan uca test. HB için SIT bilgisi, N11 appKey, Pazarama clientId, ÇS API anahtarı + IP bildirimi. *Engel: test hesabı.*
- [ ] **e-fatura.** Sipariş → fatura otomatik kesilsin, PDF müşteriye ve pazar yerine gitsin (Trendyol/HB fatura yükleme uçları). İlk entegratörler: **Paraşüt** (KOBİ'de yaygın, açık API), **BirFatura** veya **EDM**. *Rakiplerde 11–13 entegratör var, biz 2–3 ile başlarız. Tahmin: L.*
  - **10 Eki — 1. dilim yazıldı (Paraşüt + Trendyol, panelden elle "Fatura kes").** Kullanıcı kararları: ilk entegratör **Paraşüt** (BirFatura ücretsiz pazaryeri entegrasyonu da sattığı için rakip); alıcı verisi **anlık çekilir, saklanmaz** (`SupportsInvoiceData`, `InvoiceBuyer` log'a düşmez). Satıcı Paraşüt'e OAuth ile bağlanır (tek 34Pazar uygulaması, şifre bize gelmez). Akış: cari → satış faturası → mükellef sorgusu → e-fatura / e-arşiv (internet satışı) → `trackable_jobs` sonucu; her adımın kimliği anında yazılır (ikinci resmî belge açılmaz). Sipariş başına tek fatura (DB kısıtı).
  - **Kalan:** ① Paraşüt'ten uygulama + test ortamı iste, gerçek hesapta doğrula (ilişki adları `invoice`/`sales_invoice`, iş durumu değerleri, `invoice_number` alanı, kalemde ürünsüz fatura) ~~② Trendyol'a PDF yükleme~~ ✅ **10 Eki — 2. dilim yazıldı:** fatura kesilince `UploadInvoiceToChannel` taze PDF bağlantısını alır, dosyayı belleğe indirir (yalnız https, ≤10 MB, `%PDF-` denetimi) ve `seller-invoice-file`'a yükler (`SupportsInvoiceUpload`, yalnız Trendyol); durum `invoices.upload_*`, panelde rozet + "Trendyol'a tekrar yükle"; 409 (paketin faturası zaten var) başarı sayılır. *Gerçek hesapta doğrulanacak:* yurt içi pakette `invoiceNumber`/`invoiceDateTime` göndermenin kabulü (belgede yalnız mikro ihracatta zorunlu), dosya alanı adı `file` (rehber metni "invoiceFile" de diyor), 409 gövdesi ~~③ otomatik kesim kuralı (ör. kargoya verilince)~~ ✅ muhasebe 1. diliminde (aşağıda) ④ HB/diğer kanallarda `SupportsInvoiceData` ⑤ iade/iptal faturası ⑥ plan sınırı (aylık fatura kotası).
- [ ] **Kargo entegrasyonu + toplu etiket.** Yurtiçi, Aras, HepsiJet (sonra MNG, Sürat, PTT). Gönderi oluştur → barkod/etiket PDF → takip no kanala kendiliğinden bildirilsin (bildirim kısmı zaten var). TR pazar yerlerinde anlaşmalı kargo varsa etiketi pazar yerinden indir. *Tahmin: L.*
- [ ] **Ürün sınırını gözden geçir.** Rakiplerde ürün sınırsız; bizde 499 ₺ planda 500 ürün var, kataloğu büyük satıcı daha ilk bakışta eler. Öneri: sınırı kanal sayısına bağla, ürünü serbest bırak ya da çok yükselt. *Karar: kullanıcı.*

### P1 — Rakiplerle eşitlenme

- [ ] **Tedarikçi XML / Excel ile ürün ve stok alma** (bayi listesi, toptancı XML'i, eşleme ekranı, zamanlanmış güncelleme). *Tahmin: M.*
- [ ] **İade ve talep yönetimi:** Trendyol claims, HB /claims, N11 iade. Onay/ret panelden, iade kabulünde stok geri gelsin. *Tahmin: M.*
- [ ] **Soru-cevap:** Trendyol ve HB müşteri soruları panelde, cevap panelden gitsin, cevaplanmamış soru uyarısı. *Tahmin: S–M.*
- [ ] **Hakediş / mutabakat raporu:** pazar yeri ödemeleri, komisyon, kargo kesintisi; "bu siparişten ne kazandım". Fiyat kuralındaki maliyet alanıyla birleşince kâr raporu olur. *Tahmin: M.*
- [ ] **Muhasebe aktarımı:** önce Paraşüt (e-faturayla aynı bağlantı), sonra Logo İşbaşı / Mikro. *Tahmin: M.*
  - **10 Eki — 1. dilim yazıldı (Paraşüt'e tam aktarım).** Panelde tek kalem "Fatura ve muhasebe" (`/settings/invoicing`), üç ayar `invoice_accounts.settings`'te: ① **otomatik aktarım** `auto_issue` kapalı / kargoya verilince / teslim edilince — tek kanca `MaybeAutoInvoice`, `OrderEventRouter` (Trendyol `Shipped`/`Delivered`, kanal kargo olayı) ve `RecordPanelShipment`'tan çağrılır; **geriye dönük fatura yok** (`auto_issue_since`, kapalı→açık geçişinde yazılır), iptal edilmişe kesilmez, tekrar zararsız ② **kip** `mode`: e-belge kes / **yalnız muhasebeye işle** (cari + satış faturası + tahsilat, e-belge ve kanala PDF yok; e-belgesini pazar yerinin hizmetinden kesen satıcı için) ③ **tahsilat** `payment_accounts`: kanal → Paraşüt kasa/banka (`GET accounts`, kayıtta yeniden doğrulanır); satış faturasından sonra `sales_invoices/{id}/payments`, tutar KDV dahil kalem toplamı (kuruş tamsayısıyla), tarih siparişin TR günü; kimlik `invoices.provider_payment_id`'ye anında yazılır (ikinci tahsilat açılmaz). **Komisyon/kargo gideri sipariş başına YAZILMAZ:** Trendyol bunları aylık e-fatura keser, Paraşüt gelen kutusuna kendiliğinden düşer — ayrıca yazılsaydı gider iki kez sayılırdı. *Gerçek hesapta doğrulanacak:* tahsilat ucunun gövdesi (`payments` türü, `account_id` niteliği, yanıtta `data.id`, TRL dışında `exchange_rate`), `accounts` alan adları (`name`, `account_type`) ve arşivli hesaplar.
  - **Kalan:** ① **Hakediş** — Trendyol hesabından bankaya aktarım (finance / settlements API) Paraşüt'te hesaplar arası transfer olarak; yukarıdaki "Hakediş / mutabakat raporu" ile aynı veri ② iade/iptal faturası (e-fatura maddesi ⑤) ③ HB ve diğer kanallarda `SupportsInvoiceData` ④ Logo İşbaşı / Mikro.
- [ ] **Toplu düzenleme:** Excel ile fiyat/stok yükle-indir. *Tahmin: S.*
- [ ] **Çok kullanıcı ve rol** (depo personeli yalnız sipariş/kargo görür). Platin360 kullanıcı sayısıyla fiyatlıyor. *Tahmin: S–M.*

### P2 — Fark yaratacaklar (rakipte yok ya da zayıf)

- [ ] **AI ile ürün listeleme:** başlık, açıklama, öznitelik doldurma ve kategori eşleme (Trendyol/HB öznitelikleri en çok vakit alan iş). Entegra'da var, Platin360'ta yalnız çeviri. Claude API ile. *Tahmin: M.*
- [ ] **Yurtdışı paketi:** eBay + Etsy (+ ileride Amazon Global), otomatik kur çevirisi (TCMB + yüzde + yuvarlama), kanal para birimi koruması (Etsy'de var, eBay'de yok). "Türkiye'den dünyaya sat" mesajı. *Tahmin: M.*
- [ ] **"Fazla satış yok" kanıt ekranı:** son 30 günde kaç sipariş, kaç stok çakışması önlendi, gecikme süresi. Stok çekirdeğimiz rakiplerden farklı; bunu ölçülebilir gösterelim. *Tahmin: S.*
- [ ] **Shopify App Store yayını** (inceleme bekliyor; Shopify satıcısına tek tıkla kurulum). *Tahmin: S + inceleme süresi.*

### Pazar yeri ve altyapı genişlemesi (P1'den sonra, talebe göre)

- [ ] Rakiplerde olup bizde olmayanlar: **İdefix, ePttAVM, MediaMarkt, Teknosa, Koçtaş, Karaca, LC Waikiki, FLO**, Boyner, Beymen, Temu, Ozon, Trendyol yurtdışı (DE/AZ/SA/BAE).
- [ ] Altyapı: **IdeaSoft**, T-Soft, OpenCart.
- [ ] Amazon: geliştirici başvurusu (metinleri hazırlanacak).

## 3. Satış ve konumlandırma

- [ ] Ana mesaj: **"Yıllık 30 bin ₺ peşin yok — aylık başla. eBay ve Etsy bugün canlı."** İki rakipte de aylık plan ve ücretsiz giriş yok.
- [ ] Sitede fiyat karşılaştırma tablosu (Rakip adı verilecekse: karşılaştırmalı reklam TR'de nesnel ve doğrulanabilir olmak şartıyla serbest; tarih ve kaynak yazılmalı. Avukata sorulmalı.)
- [ ] Ücretsiz deneme / demo akışı, ilk 3 referans müşteri ve kısa vaka yazıları (rakipler "1.000+ mağaza", "22 yıl" diyor; biz yeniyiz, somut sonuçla öne çıkmalıyız).
- [ ] "Kod yazmadan kurulum" ve "Muhasebe yakında" kararları (kullanıcıda bekliyor).

## 4. Kullanıcıdan beklenenler

- HB SIT bilgileri · N11 appKey/appSecret · Pazarama clientId/secret · Çiçeksepeti API anahtarı (+ sunucu IP 212.227.142.108 bildirimi)
- e-fatura: ~~entegratör seçimi~~ Paraşüt seçildi (10 Eki) → **Paraşüt destekten 34Pazar uygulaması (client_id/secret, redirect `https://34pazar.com/settings/invoicing/parasut/callback`) + test ortamı**
- Kargo için ilk firmalar ve anlaşmalı kargo hesapları (Yurtiçi / Aras / HepsiJet)
- Ürün sınırı kararı (P0)
