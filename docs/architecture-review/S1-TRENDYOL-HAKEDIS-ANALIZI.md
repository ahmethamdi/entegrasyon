# S1 · Trendyol hakediş ve Paraşüt — ön analiz ve durma raporu (11 Ekim 2026)

Durum (11 Eki akşam): **UYGULANDI — kararlar §8'de, uygulama §9'da.**
§1–§7 ilk durma raporudur (ölçülenler, belirsizlikler, öneriler); kararlar
verildikten sonra uygulama §9'a göre yapıldı.

## 1. Kaynaklar

- Resmî belge: Cari Hesap Ekstresi Entegrasyonu — `settlements`,
  `otherfinancials`, `payment-order`, `cargo-invoice/{seri}/items`
  (developers.trendyol.com, 11 Eki okundu).
- **Canlı hesap yoklaması** (11 Eki, yalnız GET, 34Pazar Trendyol test
  bağlantısı, satıcı 1328369, son 120 gün, 15 günlük pencereler, tüm türler).
  Ham yanıt sunucuda `/root/ty-finans-probe-20261011.json` (600).

## 2. Canlıda ölçülenler (belgeden farklı olanlar kalın)

| Gözlem | Sonuç |
|---|---|
| Uç noktalar | `https://apigw.trendyol.com/integration/finance/che/sellers/{id}/settlements` · `/otherfinancials` · `/payment-order` — 200, sayfa zarfı `{page,size,totalPages,totalElements,content}` |
| **`transactionType` değeri** | **Türkçe etiket döner** (`Satış`, `Ödeme`, `Komisyon Faturası`, `Gelen Havale`); sorguda enum (`Sale`) gönderilir. Eşleme iki biçimi de tanımalı. |
| **`currency` alanı** | **Belgede yok, yanıtta var** (`TRY`). |
| Pencere | 15 gün sınırı; 8 pencere × 2 uç nokta hatasız. |
| Kayıt sayısı | settlements 1 (`Satış`), otherfinancials 3 (`Ödeme`, `Komisyon Faturası`, `Gelen Havale`). |
| Satış satırı | `credit 1789`, `debt 0`, `commissionRate 19`, `commissionAmount 339.91` (= 1789 × %19), `sellerRevenue 1449.09` (= credit − komisyon), `shipmentPackageId`, `orderNumber`, `barcode`, `paymentDate` (vade), `paymentOrderId null` (henüz ödenmedi), `commissionInvoiceSerialNumber DCA2026000453682`. **Adet alanı yok.** |
| **Komisyon faturası** | otherfinancials'ta `id = DCA2026000453682`, `debt 339.91` — satış satırındaki komisyonun **AYNISI**. İkisini toplamak komisyonu **iki kez** düşer. |
| Ödeme / havale | `Ödeme` debt 1 + `Gelen Havale` credit 1 ("Masak doğrulaması") — `paymentOrderId 77214019`, payment-order `amount 1, status PAID`. |
| Borç/alacak | Satıcının cari hesabı: **alacak = satıcı lehine, borç = satıcı aleyhine** (4 türde tutarlı). |
| Sipariş eşleşmesi | Bizde Trendyol siparişi `external_id = shipmentPackageId`, `external_number = orderNumber`. Canlıdaki tek hakediş satırının siparişi (11593223084, 11 Eyl) bağlantıdan (6 Eki) **önce** → bizde yok → **eşleşmeyen** örneği. Bizdeki tek Trendyol siparişi iptal → hakediş yok. |

## 3. Alan anlamı tablosu

| Alan | Düzey | Brüt/net | KDV | Beklenen/gerçek | Doğrulama |
|---|---|---|---|---|---|
| `credit` / `debt` (Satış) | paket × barkod satırı | indirim sonrası mı önce mi **bilinmiyor** | **bilinmiyor** (TR fiyatları KDV dahil yayımlanır; API söylemiyor) | gerçek | 1 satırla canlı |
| `commissionAmount` | satır | — | **bilinmiyor** (fatura 339,91 toplam mı matrah mı?) | gerçek | 1 satırla canlı |
| `sellerRevenue` | satır | net = credit − commission | — | gerçek | 1 satırla canlı |
| `commissionRate` | satır | % | — | gerçek | canlı |
| Komisyon Faturası (otherfinancials) | dönem (çok sipariş) | toplam | **bilinmiyor** | belge | canlı — satırlardaki komisyonun belgesi, ayrı gider DEĞİL |
| `Return`, `Discount`, `Coupon`, `TyDiscount`, `Provision±`, `ManualRefund`, `Commission±`, `SellerRevenue±`, `DeliveryFee`, `PayByLink` (+ `Cancel`ler) | satır | **bilinmiyor** | **bilinmiyor** | gerçek | **YALNIZ ADI belgede, canlı örnek YOK** |
| Kargo (`DeductionInvoices` + `cargo-invoice/{seri}/items`) | fatura → `orderNumber` başına `amount` | bilinmiyor | **bilinmiyor** | gerçek | **canlı örnek YOK** |
| `Stoppage` (stopaj) | dönem | — | — | gerçek | canlı örnek YOK |
| Adet | — | — | — | — | **API'de yok** — aynı barkoddan 2 adet tek satır mı iki satır mı bilinmiyor |

## 4. Durma sebepleri

1. **İade/iptal satırlarının işaret ve komisyon anlamı bilinmiyor.** `Return`
   satırında `commissionAmount` iade edilen komisyon mu, işareti ne, `debt`
   içine dahil mi? Yanlış yorum ya iadeyi ya komisyonu **iki kez** sayar
   (talimat: "refund handling could double-count money"). Ayrıca Trendyol
   iadeyi negatif hakediş olarak verirken bizim `order_refunds` kaydı da
   iade tutarı taşıyabilir — hangisinin otorite olduğu kural gerektirir (§5).
2. **KDV tabanı.** Trendyol tutarlarının KDV dahil olup olmadığı API'de yok;
   komisyon faturasının 339,91'i toplam mı matrah mı bilinmiyor; **alış
   maliyetinin (`unit_cost`) KDV tabanı üründe hiç tanımlı değil** (zarar
   koruması onu KDV dahil satış fiyatıyla örtük karşılaştırıyor). KDV hariç
   kâr hesaplamak üç yerde tahmin gerektirir (talimat: "VAT would be guessed").
3. **İndirim/kupon finansmanı.** Satış `credit`'i satıcı indirimi sonrası mı?
   `Discount` (satıcı fonlu) ile `TyDiscount` (Trendyol fonlu) satırlarının
   yönü canlıda görülmedi. Beklenen gelir (sipariş görüntüsü `lineUnitPrice`)
   ile gerçek gelir bu satırlar bilinmeden karşılaştırılamaz.
4. **Kargo kesintisi** yalnız fatura → kalem zinciriyle sipariş başına iner;
   canlı örnek yok, KDV durumu bilinmiyor.
5. **Paraşüt muhasebe eşlemesi belirsiz** (§6).
6. Canlı hesapta doğrulanabilecek tek satır var ve siparişi bizde yok —
   eşleşme ve iade yolları canlıda **doğrulanamaz**.

## 5. Önerilen model (onaya sunulur — tahmin içermeyen kısım)

**Ham defter, yorumdan ayrı.** `channel_financial_transactions` (kiracı,
bağlantı, `source` = settlements|otherfinancials, `external_id` = Trendyol
`id`, UNIQUE(bağlantı, source, external_id), `type_raw` (Türkçe etiket),
`type` (normalize enum; bilinmeyen = `unknown`, hiçbir zaman tahmin edilmez),
`debit`, `credit` (decimal, kaynakta ne varsa), `commission_amount`,
`commission_rate`, `seller_revenue` (NULL = gelmedi, 0 değil), `currency`,
`order_number`, `shipment_package_id`, `barcode`, `payment_order_id`,
`payment_date`, `transaction_date`, `commission_invoice_serial`, `raw jsonb`,
`order_id NULL`, `order_line_id NULL`, `match_status`
(matched|unmatched|ambiguous|not_order_level)). Ekleme-yalnız; düzeltme
Trendyol'da yeni satır (`…Cancel`) olarak gelir, biz de yeni satır yazarız.

**Eşleme:** `shipment_package_id` → `orders.external_id` (aynı bağlantı),
satır `barcode` → kalem; birden fazla aday = `ambiguous`, hiç yok =
`unmatched` (rapor ekranında görünür, kâra girmez). Komisyon Faturası,
Ödeme, Havale, Stopaj sipariş düzeyi DEĞİL (`not_order_level`) — kâra girmez,
ödeme/mutabakat görünümünde durur.

**Kâr (kararlar verildikten sonra):** tür başına hangi alanın kullanılacağı
(`credit−debt` mi `sellerRevenue` mi), iadede otoritenin hakediş satırı
olduğu ve `order_refunds` tutarının hakediş varken kullanılmayacağı, KDV
tabanı (aşağıda) kesinleşince formül kodda ve burada sabitlenir.

**İçe aktarma:** 15 günlük pencereler, son imleçten 3 gün geri örtüşmeli,
sayfa sayfa, her satır `insertOrIgnore`; sayfa hatası turu durdurur ama
imleç yalnız tamamlanan pencerede ilerler; bağlantı başına yalıtım (P0-4
deseni).

## 6. Paraşüt — muhasebe eşlemesi seçenekleri

Bugün: sipariş başına satış faturası + **brüt** tahsilat kanalın "Trendyol"
kasa/banka hesabına; komisyon/kargo Trendyol'un aylık e-faturasıyla gelen
kutusundan gider (bilinçli, çift sayım olmasın diye).

Hakedişte para Trendyol'dan bankaya **net** gelir (brüt − komisyon − kargo −
stopaj). Paraşüt'te "Trendyol" hesabında komisyon kadar bakiye kalır; o bakiye
ancak komisyon/kargo **alış faturası o hesaptan ödendiğinde** kapanır.

| Seçenek | Paraşüt'e yazılan | Risk |
|---|---|---|
| A | Yalnız ödeme emri: "Trendyol" hesabından **çıkış** + banka hesabına **giriş** (`accounts/{id}/debit_transactions` + `credit_transactions`, iki ayrı çağrı — atomik aktarım ucu yok) | İki yazmanın biri başarısız olursa yarım virman; komisyon bakiyesi muhasebecinin elle kapatmasına kalır |
| B | A + komisyon/kargo alış faturasını gelen kutusundan bulup "Trendyol" hesabından ödeme | Faturayı e-fatura numarasıyla bulma API'de doğrulanmadı; muhasebeci aynı faturayı elle de ödeyebilir → çift ödeme |
| C | S1'de Paraşüt'e hiçbir şey yazma; hakediş yalnız 34Pazar raporunda | Muhasebe tarafı elle kalır |

Belirsizlikler: satıcı Paraşüt'te Trendyol'u kasa/banka mı cari (müşteri)
olarak mı tutuyor (bugünkü tahsilat kasa/banka varsayıyor); 34Pazar'ın fatura
kesmediği siparişler (otomatik aktarım öncesi) aynı ödemenin içinde → hesap
bakiyesi zaten tutmaz; `PARASUT_*` sunucuda yok, sandbox yok → hiçbir yazma
doğrulanamaz.

## 7. Karar istenenler

1. **Kâr tabanı:** (a) **KDV dahil nakit katkı** — Trendyol'un verdiği
   tutarlar olduğu gibi, maliyet "KDV dahil alış" olarak tanımlanır, ekranda
   "KDV dahil" yazar; KDV tahmini yok. (b) KDV hariç — satış KDV'si sipariş
   görüntüsünden, komisyon KDV oranı ve maliyet KDV oranı satıcıdan alınır.
   Öneri: **(a)** S1 için; (b) S6/fatura işiyle.
2. **İade otoritesi:** hakediş satırı varsa iade parası oradan; yoksa
   `order_refunds` → "beklenen". Onay?
3. **Paraşüt:** A / B / C? Öneri: **C** S1'de (yalnız rapor), A muhasebeci
   onayı + sandbox ile S1 sonrası.
4. **Canlı doğrulama hesabı:** iade, indirim, kargo faturası olan gerçek bir
   Trendyol hesabına salt okuma erişim mümkün mü? Yoksa `Return`, `Discount`,
   `DeliveryFee`, kargo ve komisyon düzeltme türleri **IMPLEMENTED — NOT LIVE
   VERIFIED** kalır ve bilinmeyen tür kâra girmez.
5. Komisyon faturası DCA2026000453682 (339,91): panelde/PDF'te KDV dahil
   toplam mı (matrah 283,26 + KDV 56,65)?

## 8. Verilen kararlar (kullanıcı, 11 Eki)

1. Kâr tabanı: **Katkı (KDV dahil)** — muhasebe kârı değil; maliyet = satış
   anındaki KDV dahil alış maliyeti. KDV hariç kârlılık fatura/muhasebe aşamasında.
2. Ham defter onaylandı; ham olgu yorumdan ayrı.
3. Sınıflar: `verified` / `unverified` / `unknown`; doğrulanmamış tür gerçekleşen
   katkıya girmez, varsa gerçekleşen katkı EKSİK.
4. Satış yalnız gözlenen davranış için VERIFIED.
5. Komisyon faturası ile satış komisyonu iki kez düşülmez.
6. İade: doğrulanmış hakediş iadesi → gerçekleşen; yoksa iç kayıt → beklenen; asla ikisi.
7–8. İndirim, kargo, diğer: ham saklanır, anlam doğrulanmadan katkıya girmez.
9. Eşleme: paket numarası, bağlantı sınırı içinde; emin değilse eşleşmemiş.
10. Beklenen ve gerçekleşen ayrı; eksik gerçekleşen son rakam değildir.
11. **Paraşüt: seçenek C — S1'de finansal yazma YOK.**
12. Komisyon faturası KDV'si tersine hesaplanmaz.
13. Canlı örneği olmayan sınıflar: **IMPLEMENTED — NOT LIVE VERIFIED**.

## 9. Uygulama

### 9.1 Şema
`channel_financial_transactions` (`2026_10_12_000100`): ham olgu kolonları
(`source, external_id, type_raw, debt, credit, commission_amount,
commission_rate, seller_revenue, currency, order_number, shipment_package_id,
barcode, transaction_date, payment_date, payment_order_id, receipt_id,
commission_invoice_serial, description, raw jsonb, raw_hash`) **DB
tetikleyicisiyle değişmez**; yorum kolonları (`type, classification,
classification_reason, order_id, match_status, match_reason, matched_at`)
güncellenebilir. Tekil: `(channel_connection_id, source, external_id)`.
CHECK: `classification ∈ {verified, unverified, unknown}`, `match_status ∈
{matched, unmatched, ambiguous, not_order_level}`.

### 9.2 İçe aktarma (`finance:sync`, 3 saatte bir)
- Uçlar: `GET …/finance/che/sellers/{id}/settlements` ve `/otherfinancials`,
  `transactionTypes` = kaynağın tüm enum listesi, `startDate/endDate` ms,
  `page`, `size=500`. HTTP hatası sayfayı boş SAYMAZ (`throw`).
- Pencere 15 gün; ilk tur bağlantı tarihinden 30 gün geri; sonraki turlar
  imleçten **3 gün örtüşmeli**. İmleç (`finance_ingested_through`) yalnız
  pencere tüm kaynak ve sayfalarıyla bitince ilerler; tur başına en çok 12
  pencere, kaynak başına en çok 50 sayfa.
- İdempotens: `insertOrIgnore` + tekil indeks. Aynı kimlik **farklı içerikle**
  gelirse (`raw_hash`) satır ezilmez, `conflicts` sayılır ve uyarı yazılır.
  Aynı içerikle gelirse yalnız yorum güncellenir (yeniden sınıflama).
- Bağlantı başına yalıtım; devre kesici açıksa atlanır.

### 9.3 Sınıflandırma (`TrendyolFinancialTypes`)
| Ham etiket | Tür | Sınıf | Sipariş düzeyi |
|---|---|---|---|
| `Satış` | sale | **verified** yalnız: borç 0, alacak > 0, komisyon+oran dolu, hakediş = alacak − komisyon (kuruş), TRY, paket dolu; aksi **unverified** (sebebiyle) | evet |
| `Ödeme` | payout | verified | hayır |
| `Komisyon Faturası` | commission_invoice | verified (belge; katkı bileşeni değil) | hayır |
| `Gelen Havale` | incoming_transfer | verified | hayır |
| Belgedeki 34 İngilizce enum (`Sale`, `Return`, `Discount`, `TyDiscount`, `DeliveryFee`, `Commission±`, `Stoppage`, `DeductionInvoices`…) | eşlenen tür | **unverified** | settlements türleri evet, otherfinancials hayır |
| Başka her etiket | NULL | **unknown** | paket/sipariş no varsa evet |

Gözlenen tüm ham `transactionType` değerleri: `Satış`, `Ödeme`,
`Komisyon Faturası`, `Gelen Havale` (iki canlı yoklama, 11 Eki 12:00 ve 14:23 UTC).

### 9.4 Eşleme (`MatchFinancialTransactions`, ham defterden ayrı)
1. Paket: aynı bağlantıda `orders.external_id = shipmentPackageId`
   (`orders_external_unique` bağlantı içinde tekil). İşlemin sipariş numarası
   siparişinkiyle çelişirse `ambiguous`. Paket var ama sipariş yoksa
   `unmatched` — sipariş numarasına DÜŞÜLMEZ (aynı siparişin başka paketi ayrı kayıttır).
2. Paket yoksa sipariş numarası: tam bir sipariş → `matched`; birden çok → `ambiguous`.
Eşleşmeyen/belirsiz işlemler her turda yeniden denenir (gecikmeli sipariş),
raporda "Eşleşmeyen hakediş işlemleri" olarak görünür, katkıya girmez.

### 9.5 Formüller (`ContributionCalculator`) — hepsi KDV DAHİL, işaretli
**Beklenen:**
Satış = Σ net_line_total × (adet − iade kaydıyla karşılanmayan iptal) / adet ·
İade = −Σ order_refunds.amount · Komisyon = Σ beklenen commission(+_refund),
kayıt yoksa BİLİNMİYOR · Kargo = Σ beklenen shipping_fee, yoksa BİLİNMİYOR ·
Diğer = Σ beklenen service_fee/withholding/other (yoksa 0) · Maliyet =
−Σ unit_cost × (adet − iptal − iade) · Katkı = toplam, **yalnız hiçbir bileşen
bilinmiyor değilse**.

**Gerçekleşen:** Satış = Σ(alacak − borç) eşleşmiş VERIFIED `sale` ·
Komisyon = −Σ commissionAmount aynı satırlar · İade = iade/iptal varsa
BİLİNMİYOR (doğrulanmış iade türü yok), yoksa 0 · Kargo = **BİLİNMİYOR**
(doğrulanmadı) · Diğer = eşleşmiş unverified/unknown varsa BİLİNMİYOR, yoksa
0 · Maliyet = satış anı görüntüsü · Katkı = yalnız tamamsa.
Sonuç: **bugün hiçbir Trendyol siparişinin gerçekleşen katkısı TAMAM
olamaz** (kargo doğrulanmadı) — rapor "eksik" ve "bilinenler: X" gösterir.

**Çift sayım korumaları:** (1) Komisyon faturası sipariş düzeyi değil; bir
strateji bağlasa bile doğrulanmış katkı-dışı tür olarak düşülmez. (2) İç iade
kaydı yalnız beklenende; gerçekleşende iade bilinmiyor → ikisi asla toplanmaz.
(3) Doğrulanmamış `Return`/düzeltme satırı satışı/komisyonu değiştirmez.
(4) Beklenen yalnız `expected` kesintileri okur.

### 9.6 Rapor
`/reports/contribution` (menü: Modüller → Katkı raporu): dönem (TR saati),
sipariş başına iki satır (beklenen/gerçekleşen), bileşenler, "bilinmiyor"
vurgusu, durum (Tamam/Eksik/Hakediş yok), dönem toplamı (bileşenler yalnız
bilinen değerler + bilinmeyen sayısı; katkı yalnız tamam siparişlerden),
eşleşmeyen işlemler listesi. `/reports/contribution/{sipariş}`: "neden bu
rakam?" — her bileşen, eksiklik sebepleri ve kaynak kayıtlar (satır görüntüsü,
maliyet görüntüsü ve yakalanma anı, iade kayıtları, eşleşmiş ham işlemler,
hariç tutulanlar ve nedeni).

### 9.7 Paraşüt
**BLOCKED — ACCOUNTING MAPPING / ENVIRONMENT VALIDATION REQUIRED.** S1'de
Paraşüt'e finansal yazma yok (karar 11). Mevcut fatura/tahsilat akışı değişmedi.

### 9.8 Doğrulama durumu
| Sınıf | Durum |
|---|---|
| Satış (`Satış`, gözlenen biçim) | **LIVE VERIFIED** (1 satır, 11 Eki) |
| Ödeme, Komisyon Faturası, Gelen Havale | **LIVE VERIFIED** (tanıma; katkı dışı) |
| İade, indirim, kupon, TY indirim/kupon, provizyon, manuel iade, komisyon ±, hakediş ±, teslimat ücreti, PayByLink, stopaj, kargo/kesinti faturası, diğer | **IMPLEMENTED — NOT LIVE VERIFIED** (saklanır, katkıya girmez) |
| Uçtan uca canlı eşleşme | **NOT LIVE VERIFIED** — canlı tek satışın siparişi bizde yok |
| Üretimde `finance:sync` | **ÇALIŞTIRILMADI** — S1 kodu canlıya alınmadı |

### 9.9 Bilinen sınırlar
- Adet alanı yok; katkı paket (sipariş) düzeyinde, satır düzeyinde değil.
- Kargo, indirim, iade anlamı doğrulanmadan gerçekleşen katkı TAMAM olmaz.
- `match_reason` / `classification_reason` DB'de Türkçe tanı metni (İngilizce panelde de Türkçe görünür).
- Rapor dönem başına en çok 200 sipariş.
- Canlıda `currency` alanı var ama belgede yok; yoksa satış unverified olur.
