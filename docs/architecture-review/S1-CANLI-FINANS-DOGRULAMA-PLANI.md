# S1 · Canlı finans doğrulama planı (11 Ekim 2026)

**Durum: YALNIZ PLAN. Hiçbir ticari test siparişi açık onay olmadan verilmez.**

## 0. Neden plan

İade, indirim, TY indirimi, kupon, kargo faturası ve komisyon düzeltmesi
satırlarının canlı örneği yok. Bu satırlar olmadan hiçbir Trendyol siparişinin
gerçekleşen katkısı TAMAM olamaz (S1 §9.5). Tercih edilen yol, bu işlemleri
içeren başka bir gerçek satıcı hesabına **salt okuma** erişimidir. 11 Ekim
itibarıyla böyle bir hesap **yok**. Bu nedenle kendi Trendyol ortamımızda
kontrollü senaryolar planlandı.

**Ortam:** kiracı "34Pazar Trendyol Testi", satıcı 1328369, ürün `34PZRTEST0001`
(barkod `FSSSTT-D87405`). Canlıda başka kiracı bu bağlantıyı görmez, yani
senaryolar müşteri raporlarını kirletmez (bkz. §3).

**Okuma yöntemi (her senaryoda aynı):** yalnız GET. `finance:sync` zaten 3 saatte
bir çekiyor. Ham yanıt ayrıca yoklama betiğiyle alınır (adapter'ın
`fetchFinancialPage`'i, üretim konteyneri, çıktı `/root` altında 600). Hiçbir satır
elle sınıflandırılmaz.

## 1. Senaryolar

### S0 — Ödeme bekle (eylem YOK, para/kargo YOK) ⭐ önce bu
- **Eylem:** yok. Mevcut satış satırının `paymentDate` değeri 22 Eki 2026 15:32 (TR).
- **Yaşam döngüsü:** Trendyol hakedişi öder.
- **Beklenen uç:** `otherfinancials` → `Ödeme` (veya `PaymentOrder`).
- **Gözlenecek alanlar:** `debt`, `paymentOrderId`, `paymentDate`, `description`;
  `settlements` satırındaki `paymentOrderId` dolar mı.
- **Soru:** ödeme tutarı = Σ`sellerRevenue` − (aynı dönemin kesintileri) mi?
  Satış ↔ ödeme bağı hangi alanla kurulur?
- **Sonuç:** `Ödeme` için yapısal değişmez (TD-39) ve tahsilat mutabakatı.

### A — Normal satış (gerçek para, gerçek kargo)
- **Eylem:** ayrı, gerçek bir alıcı hesabıyla düşük fiyatlı test ürününü satın al;
  satıcı panelinden kargola; teslim al.
- **Yaşam döngüsü:** Created → Picking → Shipped → Delivered → hakediş (vade sonrası).
- **Beklenen uç:** `settlements` `Satış`; kargo `otherfinancials` (`DeductionInvoices`
  / kargo faturası) veya `settlements` `DeliveryFee`.
- **Gözlenecek alanlar:** `credit`, `commissionAmount`, `sellerRevenue`,
  `shipmentPackageId`, `orderNumber`, `barcode`; kargo satırında paket/sipariş
  numarası var mı.
- **Soru:** uçtan uca eşleşme (bizde sipariş VAR iken `matched`); kargo kesintisinin
  hangi uçta, hangi türle, siparişe bağlanabilir biçimde gelip gelmediği.
- **Not:** bu senaryo D'yi (kargo) de üretir.

### B — Satıcı finanslı indirim (gerçek para, gerçek kargo)
- **Eylem:** test ürününde satıcı indirimi (ör. %10) tanımla, A gibi satın al.
- **Beklenen uç:** `settlements` `Satış` + olası `Discount`.
- **Gözlenecek alanlar:** `Satış.credit` indirim öncesi mi sonrası mı; ayrı
  `Discount` satırı varsa `debt`, `commissionAmount` (komisyon hangi tabandan).
- **Soru:** indirim satışın içinde mi, ayrı kesinti mi? Komisyon tabanı nedir?
  Bizdeki `lineGrossAmount`/`net_line_total` ile hangi alan örtüşür?

### C — Trendyol finanslı indirim / kupon (gerçek para; yalnız kampanya varsa)
- **Eylem:** Trendyol'un kendi kampanyası/kuponu ürüne uygulanıyorsa A gibi satın
  al. **Kendimiz üretemeyiz**; uygun kampanya yoksa senaryo bekler.
- **Beklenen uç:** `settlements` `TyDiscount` / `TyCoupon`.
- **Gözlenecek alanlar:** `credit` (satıcıya Trendyol'un ödediği), `Satış.credit`
  ile toplam ilişki.
- **Soru:** TY indirimi satıcının hakedişini değiştirir mi (nötr mü)?

### D — Kargo faturası
A ile birlikte üretilir. Ek soru: kargo faturası paket başına mı, dönemsel toplu
mu? Toplu ise sipariş düzeyine dağıtılmaz → gerçekleşen kargo "dönem düzeyi"
modellenmeli (karar gerekir).

### E — Tam iade (gerçek para, gerçek kargo ×2)
- **Eylem:** A'daki sipariş teslimden sonra alıcı tarafından iade; satıcı onaylar.
- **Beklenen uç:** `settlements` `Return` (+ olası komisyon iadesi), `otherfinancials`
  `ReturnInvoice`; iade kargosu kesintisi.
- **Gözlenecek alanlar:** `debt`, `commissionAmount` (işaret), `sellerRevenue`,
  `shipmentPackageId` (aynı paket mi, yeni paket mi), `barcode`.
- **Soru:** iade satırı satışı tam tersler mi (`debt = Satış.credit`)? Komisyon
  geri verilir mi? İade kargosunu kim öder?

### F — Kısmi iade (gerçek para; 2 adetlik sipariş)
- **Eylem:** aynı üründen 2 adet al, 1'ini iade et.
- **Soru:** iade satırı adet bilgisi taşımıyor (adet alanı yok). Tutar oranı
  birim fiyatla mı eşleşir? Satır düzeyi mi paket düzeyi mi?

### G — Komisyon düzeltmesi
- **Eylem:** **üretilemez.** Trendyol kategori/komisyon düzeltmesi yaptığında
  doğal olarak gelir. Bekler.
- **Beklenen uç:** `settlements` `CommissionPositive/Negative`.

## 2. Özet tablo

| # | Para | Kargo | Kendimiz üretebilir mi | Cevapladığı soru |
|---|---|---|---|---|
| S0 | Yok | Yok | Bekleme (22 Eki) | Ödeme ↔ hakediş bağı |
| A | ~ürün fiyatı + kargo | 1 | Evet | Uçtan uca eşleşme, kargo yeri |
| B | ~ürün fiyatı + kargo | 1 | Evet | İndirim tabanı, komisyon tabanı |
| C | ~ürün fiyatı + kargo | 1 | Yalnız TY kampanyası varsa | TY indirimi etkisi |
| D | (A ile) | (A ile) | Evet | Kargo faturası düzeyi |
| E | iade edilir (kargo masrafı kalır) | 2 | Evet (A'dan sonra) | İade tersi, komisyon iadesi |
| F | 2× ürün, 1'i iade | 2 | Evet | Kısmi iade orantısı |
| G | Yok | Yok | Hayır | Komisyon düzeltmesi |

**Önerilen minimum sıra:** S0 → B (A'yı da kapsar; indirimsiz kontrol için ayrıca
A) → E (B'nin siparişi üzerinde) → F. C ve G fırsat çıktıkça.

## 3. Raporu kirletmemek
- Senaryolar yalnız "34Pazar Trendyol Testi" kiracısında. Başka kiracıya etki yok.
- Test siparişleri sipariş notu/etiketiyle işaretlenir (ör. `S1-DOGRULAMA-B`),
  ürün `34PZRTEST0001` yalnız test için kullanılır.
- Ham satırlar sınıflandırıcının verdiği sınıfta kalır. VERIFIED'a yükseltme
  kod değişikliğidir (yapısal değişmezle) ve S1 §11'deki kayıt tablosuna işlenir.
- ⚠️ Trendyol'un kendi ürününü satın alma / yapay sipariş kurallarını riske atmamak
  için alıcı GERÇEK, bağımsız bir hesap olmalı ve satış gerçek olmalı. Bu, uygulama
  öncesi kullanıcı tarafından teyit edilmeli.

## 4. Onay gerektirenler
A, B, C, E, F gerçek para ve kargo içerir → **kullanıcı onayı olmadan yapılmaz**.
S0 ve G yalnız bekleme ve okumadır.
