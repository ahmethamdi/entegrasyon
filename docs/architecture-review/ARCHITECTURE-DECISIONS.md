# Mimari karar kayıtları (ADR)

Durum: **önerildi** (11 Ekim 2026 denetimi), aksi her ADR'nin altında yazılı değilse. Her karar mevcut kodu koruyarak en küçük değişikliği seçer. Bir ADR yalnız uygulama bizi o karara gerçekten bağladığında **Kabul edildi** olur.

---

## ADR-001 · Kanonik sipariş durumu

- **Durum:** **Kabul edildi** — 11 Eki 2026, temel faz. Uygulanan: `orders.state` + `state_changed_at` (CHECK kısıtlı), `OrderState` enum + `canTransition`, `MapsOrderState` (10 sipariş kanalının hepsi; Ticimax metin değil durum kodundan), `TransitionOrderState` (koşullu UPDATE), kalem sayacından iptal/iade türetme, `orders:backfill-state`. Sapmalar: `NormalizedOrderEvent.payload['state']` yerine adapter yöntemi; `fulfillments` kaydı en az `shipped`; `OrderStateChanged` outbox olayı YAZILMADI (ADR-008, S1).
- **Karar:** `orders.state` (string, PHP `OrderState` enum) eklenir; kanalın ham `status`/`financial_status` alanları görüntü ve hata ayıklama için kalır.
- **Mevcut durum:** `orders.status` kanalın ham kelimesi (Trendyol `Created/Picking/ReadyToShip/Shipped/UnPacked`, Shopify üç alandan biri, Woo `processing/completed`). Olay tipleri (`created/updated/cancelled/returned/fulfilled`) yalnız yönlendirme içindir.
- **Sorun:** ham kelimeler adapter dışında 4 listede ve birbirini tutmuyor; pano cirosu bölünmüş/iade edilmiş paketleri sayıyor, "kargo bekliyor" `ReadyToShip`'i kaçırıyor; durum sırasız mesajlarla geri gidebiliyor. S1/S2/S4/S6/S11 ve otomasyon aynı soruyu tekrar soracak.
- **Seçenekler:** (a) listeleri tek bir yardımcıda toplamak — ham kelime çekirdekte kalır, her kanal yine çekirdeğe dokunur; (b) tam durum makinesi kütüphanesi / olay kaynaklı model — bu ölçekte aşırı; (c) **adapter'ın eşlediği tek kanonik kolon + tek geçiş kuralı**.
- **Öneri:** (c). Değerler: `awaiting_payment · awaiting_shipment · shipped · delivered · cancelled · returned · partially_returned · superseded` (Trendyol `UnPacked`). `NormalizedOrderEvent` payload'ına `state`; her adapter `STATUS_TO_STATE`; bilinmeyen → null ("değişmedi"). `OrderState::canTransition(from, to)` sırası: `awaiting_* < shipped < delivered`, `cancelled/returned/superseded` son durum — geri geçiş reddedilir, olay yine iz olarak yazılır. İptal/iade yolları kalem sayaçlarından `state`'i düzeltir. Paket durumu `fulfillments.status`'ta, iade talebi kendi tablosunda kalır.
- **Neden:** tek yazma noktası (adapter), tek okuma kolonu; sıra kuralı `occurred_at` karşılaştırmasından daha sağlam (kanal saatleri tutarsız).
- **Migration etkisi:** 1 kolon + indeks; geri doldurma komutu (bugün 5 sipariş).
- **Yol haritası etkisi:** S1 (pano, hakediş), S2 (`awaitingShipment`), S6/MaybeAutoInvoice, S11 eylem merkezi bunu kullanır. Otomasyon için `OrderStateChanged` olayı buradan yayımlanır.

## ADR-002 · Satış anı finansal görüntüsü ve hakediş modeli

- **Durum:** **Kabul edildi** — 11 Eki 2026, temel faz (görüntü + kesinti + iade). Uygulanan: `order_lines` satış anı görüntüsü (DB tetikleyicisiyle değişmez), `order_charges` (işaretli, `expected|actual` taban, idempotent, ekleme-yalnız), `order_refunds` + `order_refund_lines` (refund|return, tutar NULL = bilinmiyor, idempotent, ekleme-yalnız), `App\Support\Money`, `OrderContribution` hesap çekirdeği. Sapmalar: `settlement_lines` ayrı tablo yerine `order_charges(basis=actual, source=settlement)`; `settlements` başlık tablosu ve hakediş içe aktarma S1 (**S1, 11 Eki:** hakediş `order_charges`'a DEĞİL ham `channel_financial_transactions` defterine yazılır — ham olgu yorumdan ayrı, sınıflandırma `verified/unverified/unknown`; gerçekleşen katkı defterden hesaplanır, `settlements` başlık tablosu gerekmedi: ödeme emri kimliği satırda); `orders.discount_total/refunded_total` eklenmedi (türetilir); mevcut `grand_total` tanımı DEĞİŞTİRİLMEDİ (geçmiş veriyi yeniden yorumlamamak için — yeni rapor yeni kolonları okur).
- **Karar:** `order_lines` satış anı değerlerini saklar; iade tutarı ve hakediş ayrı tablolarda, işaretli tutarlarla tutulur; ortak `Money` yardımcı sınıfı.
- **Mevcut durum:** decimal kolonlar ve kuruş aritmetiği fiyat tarafında sağlam; ama siparişte maliyet/komisyon/KDV/indirim yok, Trendyol ve Shopify tutarları indirim öncesi, iade tutarı ve hakediş hiç yok, `toMinor` 5 kopya, fatura net fiyatı float.
- **Sorun:** S1 kâr raporu bugünkü verilerle yanlış çıkar ve maliyet değiştikçe geçmiş kâr değişir.
- **Seçenekler:** (a) raporu anlık hesaplamak (maliyeti bugünden okumak) — geçmiş değişken; (b) moneyphp/brick-money gibi yeni paket — gereksiz, brick/math zaten var; (c) **görüntü kolonları + `order_refunds` + `settlements/settlement_lines` + küçük `Money` sınıfı**.
- **Öneri:** (c). Ayrıntı `06-FINANCIAL-CORRECTNESS.md` §8. `grand_total` = müşterinin ödediği net tutar; hakediş içe aktarma `external_ref` ile idempotent; kâr = Σ hakediş satırları − Σ satış anı maliyet × net adet.
- **Neden:** muhasebede kabul edilen "işlem anında dondur" ilkesi; hakediş satırı türleri komisyon iadesini, kargo kesintisini, stopajı yeni kolon açmadan karşılar.
- **Migration etkisi:** 3 yeni tablo, 2 tabloya kolon; mevcut 5 sipariş için doldurma (maliyet bilinmiyorsa null — rapor "maliyet yok" gösterir).
- **Yol haritası etkisi:** S1'in ilk yarısı; S4 iade tutarı ve S6 iade faturası bu tablolara bağlanır; S9 kur görüntüsü aynı desene eklenir.

## ADR-003 · Gerçek varyant grubu (ürün = grup, varyant = satılabilir birim)

- **Durum:** **Kabul edildi** — 11 Eki 2026, temel faz 5. Uygulanan: kanal içe aktarması `external_parent_id` ile grubu TEK ürün altında toplar (aynı tur, sonraki sayfa ya da sonraki tur); arama VARYANT döndürür, fiyat (`SetVariantPrice`) ve `Listing` bağı doğru varyanta gider; `AddVariantToProduct` (açılış stoğu ledger'dan, aynı seçenek birleşimi reddedilir, ürün kotasından düşmez); `AssignVariantOptions` (kiracı düzeyi tanım, eksikse yazar, ezmez); `RemoteProduct.options` — Shopify `selectedOptions` ("Title/Default Title" atılır), Trendyol `variants[].attributes[]` (`attributeName/attributeValue`), Etsy `property_values`; Etsy başlığı artık ilan başlığı (değerler seçenekte); görseller tur sonunda ürün başına birleşik yazılır. Ürün SKU'su = grubun ilk varyantının SKU'su = Trendyol `productMainId`. Sapmalar: **birleştirme komutu YAZILMADI** — ADR-003 öncesi bölünmüş gruplar tahminle birleştirilmez (yeni kardeş ayrı ürün, başlığa seçenek eklenir, eski başlık ezilmez); Woo/ikas/Ticimax/N11/Pazarama/Çiçeksepeti/HB seçenek DEĞERİ okumuyor (gruplama çalışır, seçenek boş); panelde varyant düzenleme/ekleme ekranı yok; Shopify'a gönderim (`productSet`) hâlâ tek varyant; CSV içe aktarma grup sütunu taşımıyor. Bundle ve çok depo kapsam dışı.
- **Karar:** mevcut şema (`products` → `variants`, `option_definitions/values`, `variant_options`) **olduğu gibi kullanılmaya başlanır**; yeni tablo yok.
- **Mevcut durum:** her ürün tek varyantlı; kanal içe aktarması varyant başına ayrı ürün açıyor; seçenek tabloları yazılmıyor; Trendyol `productMainId = product.sku` → beden/renk ayrı ürün grubu.
- **Sorun:** S3 (HB/N11 ürün gönderme), S5 (XML), S8 (AI öznitelik) bu modelin üstüne kurulursa giyim/beden kataloğu desteklenemez; canlı ilanlar oluştuktan sonra birleştirmek kanal tarafında ilanları bozar.
- **Seçenekler:** (a) bugünkü gibi devam, gruplamayı yalnız `external_parent_id` ile kanal başına yapmak — her kanal kendi gruplamasını tahmin eder; (b) ayrı `product_groups` tablosu — şema zaten bunu `products` olarak taşıyor; (c) **`products`'ı grup olarak kullanmak**.
- **Öneri:** (c). İçe aktarma kanal grubunu (`external_parent_id` / Trendyol `productMainId` / Shopify ürün gid) tek ürün altında toplar, seçenek adlarını/değerlerini yazar; `CreateProduct` çok varyantlı kabul eder; Trendyol `productMainId` ürünün kimliğinden; varyant SKU zorunlu, ürün SKU'su grup kodu. Mevcut tek-varyantlı ürünler bozulmaz (tek varyantlı grup geçerli bir durum).
- **Neden:** en pahalı geriye dönük düzeltme; S3'ten önce yapıldığında ucuz (606 ürün, çoğu tek varyant).
- **Migration etkisi:** şema değişmez; veri için opsiyonel, kiracı onaylı birleştirme komutu.
- **Yol haritası etkisi:** S3'ün ilk 2 günü; N11 ürün gönderme gerekirse S5'e.

## ADR-004 · Kargo etiketi `fulfillments` üzerinde

- **Karar:** ayrı `shipments` tablosu yerine `fulfillments`'a etiket kolonları (`label_status, label_format, carrier_shipment_id, label_ref, label_error`) ve `source` değerleri (`channel_label`, `carrier`).
- **Mevcut durum:** `fulfillments` kanala bildirilen takip kaydı + `push_*` durumu; etiket yok.
- **Sorun:** etiket bir gönderidir ve gönderi açma isteği tekrar edilemez; kimlik kaybolursa ikinci gönderi açılır.
- **Seçenekler:** (a) ayrı `shipments` — temiz ama `fulfillments` ile çift kayıt ve senkron; (b) **genişletme**.
- **Öneri:** (b) + Paraşüt adım deseni: `carrier_shipment_id` istek döner dönmez yazılır; belirsiz sonuçta önce sorgu. Panel kargo için kısmi tekil indeks. Etiket dosyası saklanmaz (ZPL/PDF her istekte kanal/kargodan; gerekirse kısa ömürlü önbellek).
- **Neden:** mevcut `PushFulfillment` akışı ve ekranlar değişmeden etiketi kapsar.
- **Migration etkisi:** kolonlar + kısmi unique.
- **Yol haritası etkisi:** S2 (Trendyol ortak etiket), S6 (Yurtiçi/Aras `SupportsCarrierShipment`).

- **S2 uygulama notu (12 Eki 2026) — SAPMA:** Trendyol ortak etiketi `fulfillments` yerine ayrı `shipping_labels` tablosunda. Gerekçe (kodda ölçüldü): `Order::scopeAwaitingShipment` fulfillment'ı olan siparişi "kargo bekliyor"dan düşürür — etiketi basılmış ama kargolanmamış sipariş listeden kaybolurdu; ayrıca Trendyol ortak etiketi yeni gönderi AÇMAZ (takip no paket oluşurken atanır), mevcut paket için barkod üretir. Paraşüt adım deseni korundu (`requested_at` koşullu talep, belirsiz sonuçta önce sorgu). Etiket ASLI saklanır (`payload`): ZPL tek kaynaktır ve kanal yeniden üretimi belgelenmemiştir. S6 taşıyıcı gönderisi (`SupportsCarrierShipment`) için ADR-004'ün (b) önerisi açık kalır.

## ADR-005 · İade talebi yaşam döngüsü ayrı tabloda

- **Karar:** `return_requests` (kanal talep kimliği tekil; `requested → approved/rejected → received → restocked/disposed`; kalem, adet, neden, tutar). Stok, **onay ya da depoya varış anında** mevcut `ApplyOrderReturn` ile (talep kimliği `external_ref`).
- **Mevcut durum:** iade = anında stoğa geri; talep yaşam döngüsü yok; HB yalnız kabul edilmiş talepleri alıyor; Trendyol claims yok.
- **Seçenekler:** (a) talepleri `order_events` satırı olarak tutmak — payload şifreli, sorgulanamaz; (b) **ayrı tablo**.
- **Öneri:** (b). İade tutarı ADR-002 `order_refunds`'a; `ReturnAccepted` outbox olayı.
- **Migration etkisi:** 1 tablo (+ kalem tablosu).
- **Yol haritası etkisi:** S4; S6 iade faturası bu tabloya dayanır.

## ADR-006 · Eylem merkezi türetilmiş görünüm olarak

- **Karar:** ayrı `issues` tablosu yerine kaynak başına `IssueSource` sorgu sınıfları + ince `issue_acknowledgements` (yok say / ertele) tablosu.
- **Mevcut durum:** sorunlar 10'dan fazla kaynakta zaten kayıtlı ve çözülünce kendiliğinden kayboluyor; `DashboardController::todos()` bunun ilk hâli.
- **Seçenekler:** (a) `issues` tablosu — geçmiş, SLA, bildirim tekilleştirme kolay; bedeli her yazma yolunda aç/kapat çift yazımı, unutulan biri sessizce ayrışır; (b) **türetilmiş + onay tablosu**.
- **Öneri:** (b). Her kaynak `type, severity, channel, subject, reason_human, reason_code, first_seen, last_seen, retry_count, actions` döndürür. Geçmiş ve bildirim gerekirse (P2) türetilmiş görünümün periyodik anlık görüntüsü alınır. Uygulama logları bu modele girmez.
- **Migration etkisi:** 1 küçük tablo.
- **Yol haritası etkisi:** S11; S2–S6'da her yeni hata kaynağı (etiket, iade, fatura) kendi `IssueSource`'unu getirir.

## ADR-007 · Fatura türü ve iade faturası

- **Karar:** `invoices.kind` (`sale|return|cancel`) + `parent_invoice_id`; tekil kısıt `(order_id, kind, ref)`; iade faturası kalemleri kanaldan değil `order_lines` + `return_requests`'ten.
- **Mevcut durum:** sipariş başına tek fatura kısıtı; kalemler kanaldan anlık; faturadan sonraki iptal/iadenin muhasebe karşılığı yok.
- **Öneri:** yukarıdaki; satış faturası kanaldan anlık okunmaya devam eder (alıcı verisi saklanmaz kararı korunur), iade faturası aynı alıcı bilgisini yine kanaldan anlık okur.
- **Migration etkisi:** kolonlar + kısıt değişimi.
- **Yol haritası etkisi:** S6.

## ADR-008 · Otomasyon için olay kancaları (motor değil)

- **Karar:** bugün yalnız iş olaylarını mevcut transactional outbox'a yayımla; WHEN/IF/THEN motoru, çoklu abone teslim izi ve kural tablosu P2.
- **Mevcut durum:** outbox var, 3 olay türü; tek tüketici.
- **Öneri:** ilgili sprintte aynı transaction içinde: `OrderReceived`, `OrderStateChanged` (S1), `ListingApprovalChanged` (S3), `ReturnAccepted` (S4), `InvoiceIssued/Failed` (S6), `ConnectionHealthChanged`, `SyncOperationDead`, `StockThresholdCrossed` (S7). Sabit gövde: `tenant_id, connection_id, subject_type, subject_id, version, occurred_at`. Tanınmayan olay türü "tüketildi" sayılmasın, "atlandı" olarak işaretlensin.
- **Neden:** olaylar sonradan geriye dönük üretilemez; motor ise sonradan eklenebilir.
- **Migration etkisi:** yok (outbox mevcut).
- **Yol haritası etkisi:** her sprintte birkaç satır.

## ADR-009 · Tek depo varsayımını tek noktaya topla

- **Karar:** gerçek çok depo P2; S10'da yalnız `WarehouseResolver` (bugünkü 7 dağınık varsayılan depo araması tek sınıfa).
- **Mevcut durum:** şema `variant → warehouse → level` taşıyor (geri dönüşsüz tek-depo varsayımı yok); pratikte varsayılan depo 7 yerde aranıyor, push ve sürüm kapısı depo ayırmıyor.
- **Öneri:** resolver + sürüm kapısının depo kimliğini taşıması P2'de; şimdi yalnız toplama.
- **Migration etkisi:** yok.
- **Yol haritası etkisi:** S10, ~yarım gün.
