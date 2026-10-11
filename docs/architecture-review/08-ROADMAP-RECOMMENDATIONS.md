# 08 · Yol haritası önerileri

İş öncelikleri ve tarihler korunur. Temel işler yeni sprint açılmadan, doğal oldukları sprinte gömülür.

## Bölüm A — S1–S12'nin kodla eşlenmesi

Karmaşıklık: S (≤1 gün) · M (2–3 gün) · L (4–5 gün) · XL (bir sprintten fazla).

### S1 · 12–16 Eki · Hakediş, kâr raporu, Paraşüt canlı, ürün sınırı

- **Var:** fiyat kuralındaki `cost_price`, `markup_*`; Paraşüt tahsilat + `books_only`; `PriceRuleCalculator` kuruş aritmetiği; plan `limits` jsonb + `EnforceQuota`.
- **Yeniden kullanılır:** `ParasutClient`, `IssueInvoice` adım-kimliği deseni (hakediş aktarımı için), kanal HTTP istemcisi (Trendyol finans API).
- **Değişmeli:** Trendyol ve Shopify `grand_total`/`line_total` tanımı (indirim sonrası net); `TrendyolOrderPollingTest` kilitli değerleri; `InvoiceLine::netUnitPrice` (BigDecimal); `DashboardController::today` (TR günü + kanonik durum).
- **Yeni:** `app/Support/Money`; `orders.state` (ADR-001); `order_lines` satış anı görüntüsü; `order_refunds`; `settlements` + `settlement_lines`; Trendyol `SupportsSettlement` (finans API); kâr raporu ekranı; `invoices` tutar kolonları.
- **Dış bağımlılık:** Paraşüt client_id/secret + sandbox; Trendyol finans API yetkisi (aynı anahtar, doğrulanmalı).
- **Mimari risk:** raporu bugünkü `orders` tutarları üzerine kurmak → yanlış ve geriye dönük değişken kâr; **önce görüntü, sonra rapor**.
- **Migration:** `orders` (+state, +discount_total, +refunded_total), `order_lines` (+7 kolon), `order_refunds`, `settlements`, `settlement_lines`, `invoices` (+tutarlar). Geriye dönük doldurma komutu (5 sipariş — ucuz).
- **Test:** 07'deki #8–#13.
- **Karmaşıklık:** **L** (P0'lar dahil XL'e yakın → Paraşüt'e hakediş aktarımı S2 başına kayabilir).

### S2 · 19–23 Eki · Trendyol ortak etiket, toplu işlem, toplama listesi

- **Var:** `Fulfillment` (+`push_*`), `RecordPanelShipment`, `PushFulfillment` iskeleti, `RequestInvoice` toplu çağrılabilir.
- **Değişmeli:** `fulfillments` → etiket kolonları (`label_status, label_format, carrier_shipment_id, label_ref, label_error`, `source` değerleri `channel_label|carrier`) — ADR-004; `Order::scopeAwaitingShipment` → `state = awaiting_shipment`; panel kargo girişine **kısmi tekil indeks** (çift tıklama).
- **Yeni:** `RequestLabel` işi (Trendyol `createCommonLabel` → `getCommonLabel`, ZPL); ZPL → PDF (A4) dönüştürme; toplu işlem çerçevesi (seçim → iş kümesi → kalem başına sonuç); toplama listesi (`external_number` ile grupla); Trendyol `cargoTrackingNumber/cargoProviderName` okuma.
- **Dış:** Trendyol hesabında TEX/Aras anlaşması.
- **Risk:** etiket bilgisini şifreli inbox gövdesinden okumak (yavaş + PII); beşinci bir durum listesi.
- **Migration:** `fulfillments` + kısmi unique; gerekirse `order_lines` ↔ paket ilişkisi için `fulfillment_lines` (yalnız toplama listesi gerektirirse).
- **Test:** #14, #15, "iptal edilmiş siparişe etiket reddedilir".
- **Karmaşıklık:** **L**.

### S3 · 26–30 Eki · HB + N11 ürün gönderme, kanalları açma

- **Var:** `SupportsCatalog` sözleşmesi, `PushListing` + önkoşul kapısı, kategori/öznitelik eşleme, `SupportsBatchStatus`, `ApprovalWorkflow` deseni (Trendyol), HB/N11 adapter'larında import/stok/fiyat/sipariş.
- **Değişmeli (önce):** **gerçek varyant grubu** (ADR-003): içe aktarma kanal grubunu tek ürün altında toplar, `option_values`/`variant_options` yazılır; Trendyol `productMainId` ürün kimliğinden; ürün SKU ↔ varyant SKU kuralı. Marka eşleme tablosu (`brand_mappings`) — HB/N11 de marka kimliği istiyor. `lifecycle_status` enum'a.
- **Yeni:** HB ve N11 `SupportsCatalog` (+ onay takibi); zaman aşımı sonrası "önce ara, sonra yarat" (SKU araması olmayan kanalda listing kimliğini toplu iş kimliğinden sahiplen).
- **Dış:** HB SIT, N11 appKey/secret, Pazarama, ÇS anahtarları + IP bildirimi.
- **Risk:** tek varyant modeliyle HB/N11 ürün açmak = beden/renk kataloğu her kanalda ayrı ürün; sonradan birleştirmek canlı ilanları bozar.
- **Migration:** `brand_mappings`; mevcut veriler için grup birleştirme komutu (opsiyonel, kiracı onayıyla).
- **Test:** #16, varyant grubu içe aktarma, HB/N11 sözleşme testleri.
- **Karmaşıklık:** **XL** — varyant grubu S3'ün ilk 2 günü; N11 ürün gönderme gerekirse S5'e taşınır (iş hedefi "HB + en az bir kanal daha canlı" korunur).

### S4 · 2–6 Kas · İade/talep + soru-cevap

- **Var:** `ApplyOrderReturn` (stok iadesi, kümülatif), HB talep okuma (yalnız `Accepted`).
- **Yeni:** `return_requests` (ADR-005: `requested → approved/rejected → received → restocked/disposed`, kalem + adet + neden + tutar, kanal talep kimliği tekil); stok **onay/varış anında** mevcut `ApplyOrderReturn` ile; Trendyol claims + HB tüm talepler; `questions` tablosu (siparişten bağımsız); `ReturnAccepted` outbox olayı.
- **Değişmeli:** HB `Accepted` filtresi adapter'dan çekirdeğe; `order_refunds` (S1) ile tutar bağı.
- **Risk:** talepleri `order_events` satırı olarak tutmak (şifreli payload sorgulanamaz) ve "iade = stoğa geri" varsayımını kalıcılaştırmak.
- **Test:** #17, "hasarlı iade stoğa dönmez".
- **Karmaşıklık:** **L**.

### S5 · 9–13 Kas · Tedarikçi XML, Excel, Woo canlı

- **Var:** CSV içe aktarma (`ImportProducts`), `CreateProduct`/`UpdateProduct`, fiyat kuralı, Woo adapter.
- **Yeni:** `product_sources` (kaynak türü, URL, alan eşleme, zamanlama, son çalışma) ve kaynak ↔ varyant bağı (`variants.source_id/source_ref`) — XML güncellemesi elle düzenlenmiş alanı ezmesin; değişiklik özeti; Excel toplu fiyat/stok (stok değişimi **defterden** `AdjustStock::setTo`); Woo `/wc-auth` sihirbazı.
- **Değişmeli:** defter koruması (FK `restrictOnDelete`, model `updating/deleting` reddi) — XML güncellemesi ürün sildiğinde geçmiş kaybolmasın.
- **Risk:** XML'i doğrudan `products` üzerine yazmak (kaynak izi yok, varyant grubu yok).
- **Karmaşıklık:** **L**.

### S6 · 16–20 Kas · Yurtiçi + Aras, faturayı kanallara yay, iade faturası

- **Var:** S2 etiket modeli, `SupportsInvoiceData/Upload`, Paraşüt adım deseni.
- **Yeni:** `SupportsCarrierShipment` (kargo firması adapter'ı, kanal adapter'ından ayrı); `invoices.kind` (`sale|return|cancel`) + `parent_invoice_id` + kısıtın `(order_id, kind, ref)` olarak genişletilmesi (ADR-007); iade faturası kalemleri `order_lines` + `return_requests`'ten (kanaldan değil); HB/N11/Shopify `SupportsInvoiceData`; kargo ve fatura yükleme durumu mutabakatı (`reconcile:orders` genişlemesi).
- **Dış:** Yurtiçi/Aras kurumsal hesap + API.
- **Risk:** iade faturasını kanaldan anlık okumak; `MaybeAutoInvoice`'a yeni ham kelime eklemek (ADR-001 sonrası gereksiz).
- **Karmaşıklık:** **L–XL**.

### S7 · 23–27 Kas · Black Friday dondurma + roller

- **Yeni:** rol modeli + policy'ler + davet (05'teki liste); denetim kapsamı genişletme + `actor_type=system`; kanal × alan sağlık telemetrisi (04 §7); inbox/outbox budama + replay penceresi; yük testi (100 eşzamanlı sipariş, tek SKU).
- **Test:** #19, eşzamanlı alım testi.
- **Karmaşıklık:** **M** (canlıya Pazartesi).

### S8 · 30 Kas–4 Ara · AI listeleme

- **Var:** kategori + öznitelik eşleme, taksonomi, (S3 sonrası) seçenek değerleri.
- **Yeni:** AI önerisi → **taslak alanlara** yazılır, satıcı onayıyla kanonik ürüne geçer; `ai_usage` (kiracı, model, token, maliyet) kota için; kanal başına başlık/açıklama geçersiz kılma (`listing_content_overrides` — en küçük hâli: başlık + açıklama).
- **Risk:** AI çıktısını doğrudan listing payload'ına yazmak (kanonik modeli atlar, denetlenemez).
- **Karmaşıklık:** **M–L**.

### S9 · 7–11 Ara · eBay canlı, kur

- **Değişmeli (önce):** eBay para birimi koruması (Etsy `currencyFailuresFor` eşdeğeri).
- **Yeni:** eBay `SupportsOrders`; `fx_rates` (TCMB) + kalemde `fx_rate_to_try` görüntüsü; fiyat kuralına kur adımı.
- **Test:** #20.
- **Karmaşıklık:** **L**.

### S10 · 14–18 Ara · İdefix, ePttAVM, ikas/Ticimax canlı

- **Yeni:** iki adapter (sözleşme kontrol listesiyle — adapter mimarisinin kanıtı); `WarehouseResolver` (7 dağınık varsayılan depo aramasını tek yere toplar — çok depoya ucuz hazırlık); Ticimax `Tutar` alan anlamının doğrulanması; Pazarama/ÇS ürün gönderme.
- **Karmaşıklık:** **L**.

### S11 · 21–25 Ara · Kanıt ekranı, onboarding, App Store

- **Yeni:** eylem merkezi (ADR-006: türetilmiş `IssueSource`'lar + `issue_acknowledgements`); kanal sağlık panosu (S7 telemetrisi üstüne); "fazla satış yok" ekranı (`inventory_movements` + `OVERSELL_DETECTED` + senkron gecikmesi); App Store gönderimi.
- **Karmaşıklık:** **L**.

### S12 · 28 Ara–1 Oca · Satışa hazırlık

- **Yeni:** yedekten geri yükleme tatbikatı; mutabakat doğrulaması (sipariş/stok/fiyat/kargo turu temiz); aralıklı test düzeltmesi; belgeler.
- **Karmaşıklık:** **M**.

## Bölüm B — Öncelik grupları

### P0 — Yol haritası devam etmeden ÖNCE (S1'in ilk 2–3 günü)

| # | İş | Neden P0 | Boyut |
|---|---|---|---|
| P0-1 | Sırasız sipariş olayları: sipariş yoksa iptal/iade ertelenir (pending'e geri, sınırlı bekleme) ya da sahiplenilir; durum geri yazılmaz | Canlıda stok kalıcı bozulabiliyor | S |
| P0-2 | Mutabakat onarım kapısı (`remote < expected` → bekleyen inbox / yoklama gecikmesi varsa bekle, 2 tur kuralı) | Fazla satışı büyütebiliyor | S |
| P0-3 | `reconcile:orders` (30 dk) + Shopify `fetchOrders` | Webhook kaybı = sipariş kaybı; Shopify canlı | M |
| P0-4 | `TokenRefresher` + `PollChannelOrders` bağlantı başına `try/catch`; başarısız satır sırayı tıkamasın | Kiracı A → kiracı B etkisi | S |
| P0-5 | Shopify webhook ↔ mağaza alan adı eşleşmesi; abonelik güncellemesi bağlantıya bağlı | Yanlış kiracıya işleme | S |
| P0-6 | Inbox yeniden denemesine bekleme (`backoff`), `inbox:replay-failed` komutu; `MAX_PAGES` dolunca imleç ilerlemesin | Sessiz sipariş kaybı | S |

### P1 — S1–S12 içinde (sprint eşlemesi yukarıda)

Kanonik sipariş durumu (S1) · para yardımcı sınıfı + satış anı görüntüsü + iade tutarı + hakediş tabloları (S1) · fatura tutarları + BigDecimal net (S1) · pano günü/ciro düzeltmesi (S1) · etiket kolonları + panel kargo tekilliği + kayıp iş taraması (S2) · gerçek varyant grubu + marka eşleme + lifecycle enum (S3) · iade talebi modeli + iş olayları (S4) · ürün kaynağı + defter koruması (S5) · fatura türü + iade faturası + kargo/fatura mutabakatı (S6) · roller + denetim + sağlık telemetrisi + budama (S7) · AI taslak + kullanım kaydı + içerik geçersiz kılma (S8) · eBay para birimi koruması + kur görüntüsü (S9) · `WarehouseResolver` (S10) · eylem merkezi + sağlık panosu (S11) · geri yükleme tatbikatı (S12).

### P2 — 2027 Q1

Gerçek çok depo (konum ↔ depo eşleme, depo başına push, sürüm kapısının depo ayırması) · rezervasyon (ödeme bekleyen sipariş) · paket ürün (bundle) · otomasyon motoru (WHEN/IF/THEN) + çoklu abone outbox · Logo İşbaşı / Mikro · BirFatura/EDM · Amazon · Trendyol yurt dışı · gerçek paralel yük testi altyapısı · webhook replay zaman penceresi (inbox budaması kalıcılaşınca) · süper yönetici denetimi.

### P3 — Sonra

Postgres RLS · olay kaynaklı (event sourcing) herhangi bir şey · gelen stok / satın alma siparişi · tam PIM (kanal başına görsel seti, çok dilli içerik) · mağaza devir/itiraz akışı · toplu yeniden şifreleme komutu (rotasyon gerekirse öne çekilir).

## Bölüm C — Revize yol haritası (tarihler aynı)

| Sprint | İş hedefi (değişmedi) | Eklenen temel iş |
|---|---|---|
| **S1** 12–16 Eki | Hakediş + kâr, Paraşüt canlı, ürün sınırı | **P0-1…P0-6** (ilk 2–3 gün) · `orders.state` · Money · satış anı görüntüsü · `order_refunds` · hakediş tabloları · fatura tutarları. *Kapsam kısma:* kâr raporu yalnız Trendyol; Paraşüt'e hakediş aktarımı S2 başına kayabilir |
| **S2** 19–23 Eki | Ortak etiket, toplu işlem, toplama listesi | Etiket = `fulfillments` genişlemesi (ADR-004) · gönderi kimliği adım sonrası yazılır · panel kargo kısmi tekil indeks · kayıp iş taraması (kargo/fatura/retrying) |
| **S3** 26–30 Eki | HB + N11 ürün gönderme, kanalları aç | **Önce gerçek varyant grubu** (ADR-003) · marka eşleme · lifecycle enum · "önce ara, sonra yarat" · `ListingApprovalChanged` olayı. *Kapsam kısma:* N11 ürün gönderme S5'e kayabilir |
| **S4** 2–6 Kas | İade/talep + soru-cevap | `return_requests` yaşam döngüsü (ADR-005) · `ReturnAccepted` olayı · iade tutarı `order_refunds`'a |
| **S5** 9–13 Kas | XML, Excel, Woo | Ürün kaynağı izi · XML varyant grubunu yazar · defter koruması (FK + model) |
| **S6** 16–20 Kas | Yurtiçi/Aras, fatura her kanalda, iade faturası | `invoices.kind` (ADR-007) · kalemler kanonik kayıttan · kargo/fatura durum mutabakatı |
| **S7** 23–27 Kas | Black Friday dondurma + roller | Rol/policy/davet · denetim kapsamı + sistem aktörü · sağlık telemetrisi · budama · yük testi · mimari izolasyon testleri |
| **S8** 30 Kas–4 Ara | AI listeleme | AI taslak alanları + onay · `ai_usage` · başlık/açıklama geçersiz kılma |
| **S9** 7–11 Ara | eBay canlı, kur | eBay para birimi koruması (açmadan önce) · `fx_rates` + görüntü |
| **S10** 14–18 Ara | Yeni kanallar | Adapter sözleşme kontrol listesi · `WarehouseResolver` · Ticimax tutar doğrulaması |
| **S11** 21–25 Ara | Kanıt ekranı, onboarding, App Store | Eylem merkezi (türetilmiş) · sağlık panosu |
| **S12** 28 Ara–1 Oca | Satışa hazırlık | Geri yükleme tatbikatı · mutabakat doğrulaması · aralıklı test |

**Sonuç:** yol haritası gerçekçi olarak sürebilir. Sıkışma noktaları S1 (P0 + finans temeli) ve S3 (varyant grubu); ikisinde de iş hedefi korunup alt kapsam bir sonraki sprinte kaydırıldı. Asıl takvim riski kod değil, dış hesaplar (HB/N11/ÇS/Pazarama, Paraşüt, kargo).
