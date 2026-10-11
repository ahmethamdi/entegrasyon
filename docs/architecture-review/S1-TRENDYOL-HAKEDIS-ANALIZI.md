# S1 · Trendyol hakediş ve Paraşüt — ön analiz ve durma raporu (11 Ekim 2026)

Durum: **UYGULAMA BAŞLAMADI.** S1 talimatındaki durma koşulları ("finans
anlamı belirsiz", "KDV tahmin edilecek", "iade çift sayılabilir", "Paraşüt
muhasebe eşlemesi belirsiz") karşılandı. Bu belge ölçülenleri, belirsizlikleri
ve karar önerilerini kaydeder.

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
