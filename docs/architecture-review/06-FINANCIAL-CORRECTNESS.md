# 06 · Finansal doğruluk

Yol haritası komisyon, kesinti, hakediş, kâr raporu, fatura ve muhasebe içeriyor. Bu belge paranın nasıl saklandığını ve hesaplandığını, S1'den önce neyin şart olduğunu ortaya koyar.

## 1. Saklama

| Bulgu | Sınıf |
|---|---|
| Tüm para kolonları `decimal` (sipariş 14,2; ürün/listing/kural/kampanya 12,2); float/double para kolonu yok (tek `double` metrik değeri) | EXISTS |
| Model cast'leri `decimal:2` (Laravel bunu brick/math ile yapar; bcmath imajlarda kurulu) | EXISTS |
| Para birimi: sipariş, varyant, kiracı varsayılanı, listing kanal fiyatı | PARTIAL (kalem siparişten miras — kabul edilebilir; `price_overrides` ve tutar kampanyası para birimsiz) |
| Ara değerler için 4 basamak (KDV hariç birim, komisyon oranı) | PARTIAL (kolon yok) |

## 2. Aritmetik

| Bulgu | Sınıf | Somut risk |
|---|---|---|
| Fiyat kuralı, kampanya, zarar koruması kuruş tamsayısıyla (`PriceRuleCalculator`, `Listing`, `ResolveChannelPrice`, `ReconcileConnection`) | EXISTS | — |
| `toMinor/fromMinor` mantığı en az 5 yerde kopya | PARTIAL | Tutarsızlaşma riski |
| `InvoiceDraft::grossTotal()` kuruşla | EXISTS | — |
| **`InvoiceLine::netUnitPrice()` float bölme** | RISKY | 10,00 TL × %20 × 1000 adet → net 8,3333 → Paraşüt faturası 9.999,96, tahsilat 10.000,00 → fatura 4 kuruş fazla ödenmiş görünür |
| **Shopify satır toplamı indirim öncesi** (`ShopifyOrderNormalizer.php:288`, `discount_allocations` düşülmüyor) | RISKY | Satır bazlı gelir fazla |
| **Trendyol `grand_total = packageGrossAmount`** (indirim öncesi); test bunu kilitliyor (`TrendyolOrderPollingTest.php:197-215`) | RISKY | Pano cirosu ve olası kâr raporu şişik |
| TR adapter'larında `toplam/adet` ile türetilen birim fiyat | PARTIAL | `unit_price × qty ≠ line_total` 1 kuruş; hesaplar `line_total` üzerinden yapılmalı |
| Ticimax sipariş `Tutar` alanının anlamı doğrulanmadı | RISKY | Kanal kapalı; açmadan önce doğrulanmalı |
| Sınır noktalarında float (`CreateProduct(float $price)`, CSV `(float)`) | PARTIAL | 12,2 için pratikte güvenli; string/BigDecimal daha doğru |

## 3. Para birimi

| Bulgu | Sınıf |
|---|---|
| Listing kanal fiyatı kendi para birimini taşıyor; Etsy ve Ticimax para birimi koruması | EXISTS |
| Kur dönüşümü | MISSING (bilinçli) |
| **eBay'de para birimi koruması yok** — TRY fiyat EUR/USD olarak gidebilir (`EbayAdapter::pushPrices`, `EbayProductMapper::toOfferBody`) | RISKY — eBay kapalı; **açılmadan (S9) önce** düzeltilmeli |
| Pano cirosu para birimine göre gruplu | EXISTS |
| Pano "bugün" UTC gece yarısından (TR 03:00) | RISKY (küçük) |

## 4. KDV

| Bulgu | Sınıf |
|---|---|
| Fatura KDV'si kanal satırından; oran yoksa fatura kesilmiyor | EXISTS |
| Katalog gönderiminde KDV bağlantı başına tek oran (varsayılan 20) — %1/%10 ürünler yanlış oranla gider | PARTIAL |
| `orders.tax_total` güvenilmez (Trendyol `'0'`, TR kanallarının çoğu hiç yazmıyor); KDV dahil/hariç bayrağı yok | RISKY |

## 5. İade, iptal, kesintiler

| Bulgu | Sınıf |
|---|---|
| İptal/iade **adetleri** (CHECK kısıtıyla) | EXISTS |
| İade **tutarı**, kısmi para iadesi, kargo iadesi | MISSING |
| Sipariş tutarları ilk alımdan sonra güncellenmiyor | MISSING |
| Komisyon, komisyon iadesi, kargo kesintisi, hizmet bedeli, stopaj | MISSING |
| İade/iptal faturası (`invoices_order_unique` ikinci belgeyi engelliyor) | MISSING |

## 6. Maliyet, kâr, anlık görüntü

| Bulgu | Sınıf |
|---|---|
| `variants.cost_price` tek değer, üstüne yazılıyor, geçmiş yok | PARTIAL |
| **Satış anı görüntüsü** (maliyet, komisyon, KDV oranı, indirim) `order_lines`'ta yok → maliyet değişince geçmiş kâr değişir | **MISSING** |
| Hakediş tablosu/modeli/adapter yeteneği | MISSING |
| `invoices` tablosunda tutar yok → fatura ↔ sipariş ↔ hakediş mutabakatı yapılamaz | RISKY |
| Zarar koruması komisyon/kargo bilmiyor (elle `markup_amount`'a gömülüyor) | PARTIAL |

## 7. Yuvarlama politikası

Fiyat kuralı: yüzde → tutar → yuvarlama (hep yukarı ,00/,90/,99), taban tavana — EXISTS, belgelenmiş. Kanala gönderim biçimi kanaldan kanala farklı (string / float / 4 basamak net) — PARTIAL, pratikte sorunsuz. Fatura satır yuvarlama politikası yok — RISKY (bkz. 2).

## 8. Öneri — bu yığın için en küçük doğru yaklaşım (yeni paket yok)

1. **`app/Support/Money`**: decimal string ↔ kuruş tamsayı, baz puanla çarpma, kuruş dağıtma (`allocate`); bölme gerekirse brick/math `BigDecimal` (zaten kurulu, dolaylı bağımlılık — `composer.json`'a doğrudan eklemek gerekir). Beş `toMinor` kopyası buraya taşınır.
2. **`order_lines` satış anı görüntüsü:** `discount_total (14,2)`, `net_line_total (14,2)` = müşterinin ödediği, `vat_rate (5,2)`, `unit_cost (14,4) null`, `cost_currency`, `commission_rate (7,4) null`, `commission_amount (14,2) null`. `unit_cost` alım anında `IngestChannelOrder` içinde yazılır; komisyon hakedişten doldurulur.
3. **`orders`:** `discount_total`, `refunded_total`; `grand_total` tanımı "müşterinin ödediği net tutar" olarak sabitlenir (Trendyol `packageTotalPrice` + kargo, Shopify `current_total_price`) — normalizer'lar ve kilitleyen test güncellenir; Trendyol alan anlamı **gerçek paketle** doğrulanır.
4. **`order_refunds`:** `order_id, external_refund_id unique, amount, shipping_amount, currency, kind(refund|return|cancel), occurred_at` — iadenin para tarafı stoktan ayrı.
5. **Hakediş:** `settlements` (kanal, dönem, ödeme tarihi, toplam) + `settlement_lines` (tür: sale/commission/shipping/refund/commission_refund/service_fee/withholding; `order_id null`; **işaretli** `amount (14,2)`; para birimi; `external_ref` unique → idempotent içe aktarma). Kâr = `Σ settlement_lines − Σ unit_cost × net adet`.
6. **Fatura:** `invoices`'a `net_total, vat_total, gross_total, currency`; net birim fiyat BigDecimal ile; tahsilat tutarı entegratörün döndürdüğü brüt toplamdan.
7. **Kur:** şimdilik dönüşüm yok; raporlar para birimine göre ayrılır, yabancı paralı satışta kâr "kur yok" gösterilir. Gerekirse (S9) `fx_rates` (TCMB günlük) + kalemde `fx_rate_to_try` görüntüsü.
8. Rapor saat dilimi `Europe/Istanbul`; "satış sayılmaz" kümesi kanonik durumdan (ADR-001) gelir.

## S1 (hakediş + kâr raporu) başlamadan ÖNCE olması gerekenler

1, 2, 3, 4, 5 ve 8. Madde 6 S1 içinde (fatura tutarı), madde 7 S9'da. Ayrıntı: ADR-002.
