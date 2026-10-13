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

- **ZPL / PDF kararı (kullanıcı, 12 Eki 2026):** Labelary ya da başka bir BARINDIRILAN ZPL işleme servisi KULLANILMAZ — etiket alıcının kişisel/adres verisini taşır ve üçüncü tarafa gönderilmez. Asıl ZPL saklanır ve termal yazıcı için olduğu gibi indirilir; Trendyol PDF verirse o PDF (adres) doğrudan kullanılır. ZPL → A4 PDF S2 kabul engeli DEĞİLDİR; gerekirse ileride yalnız YEREL/kendi içinde çalışan bir işleyici değerlendirilir (veri bizim denetimimizde kalır), ve yalnız `LabelDocuments` katmanına eklenir.
- **Değişmez:** ETİKET HAZIR ≠ KARGOLANDI. Etiket almak/basmak siparişin durumunu, `fulfillments`'ı ve stoğu değiştirmez; sipariş "kargo bekliyor"da kalır (regresyon testi `a_ready_label_does_not_ship_the_order_or_touch_stock`).
- **Dış bağımlılık:** Trendyol satıcı hesabı için Ortak Etiket (Common Label) yetkisi Trendyol tarafından açılmalı (11 Eki canlı: `COMMON_LABEL_NOT_ALLOWED`). Yetkiyi aşmak için kod yazılmaz.

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

## ADR-010 · Kimlik doğrulandı ≠ kanala yazmaya yetkili

- **Durum:** **Kabul edildi** — 12 Eki 2026, S4 öncesi güvenlik düzeltmesi.
- **Değişmez kural:** `AUTHENTICATED != AUTHORIZED FOR OUTBOUND WRITE` ve özellikle `CREDENTIALS PRESENT != PRODUCT PUBLISHING ENABLED`. Bağlantının var olması, kimlik bilgisinin geçerli olması, adapter yeteneği (`SupportsCatalog`/`SupportsInventory`/`SupportsPricing`/`SupportsOfferLifecycle`), kanal türünün `is_active` bayrağı ve geçmişte başarılı bir API çağrısı birbirinden AYRI kavramlardır; hiçbiri yazma izni VERMEZ ve izin bunlardan TÜRETİLMEZ.
- **Kapatılan açık (S3 canlıya alma kaydında bulundu):** `PushListing` yalnız adapter yeteneğine bakıyordu; `channel_types.is_active` yalnız panelden bağlantı açmayı engelliyordu. Betik/iç araç/doğrudan veritabanıyla yaratılan HB/N11 bağlantısı kimlik bilgisi girildiği an ilan yaratabilir, ayrıca içe aktarma ilanları `live` yazdığı için stok ve fiyatı da kanala yazardı.
- **Model:** `channel_connections` üzerinde üç kolon — `product_publish_enabled`, `price_sync_enabled`, `stock_sync_enabled` — `boolean NOT NULL DEFAULT false`, `$fillable` DIŞINDA. Genel bir izin çerçevesi YOK; HB/N11'in dışarı yazan yolları tam bu üçüdür.
- **Uygulama noktası (sunucu tarafı):** `OutboundWriteGate::allows(connectionId, tenantId, write)` — değer her çağrıda veritabanından okunur, yalnız tam `true` izin verir; `false`/`null`/satır yok/başka kiracı → hayır (fail closed). Çağıranlar: `PushListing`, `PushOfferListing` (ürün yayını), `PushInventory` (stok), `PushPrices` (fiyat) — iş ÇALIŞTIĞI anda, devre kesici/hız sınırı/arama dahil her kanal isteğinden ÖNCE; `PublishListing` (panel/forged istek: satır yaratmadan `OutboundWriteDisabled`); fan-out planlaması (`InventoryLevelChangedConsumer`, `VariantPriceChangedConsumer`: izinsiz bağlantıya operasyon açılmaz) ve `QueueRepair` (mutabakat onarımı açılmaz). Engellenen operasyon `DEAD`, deneme açılmaz, `listing_sync_states.reason_code` = `PRODUCT_PUBLISHING_DISABLED` / `PRICE_SYNC_DISABLED` / `STOCK_SYNC_DISABLED` (metin panelde çevrilir, `last_error` boş). Listing kimliği, yaşam döngüsü, belirsiz yaratma talebi ve sürüm alanları SİLİNMEZ.
- **Kuyruk ve yeniden deneme:** kapı işin başında, her `handle()` çağrısında sorulur; `release()` sonrası tekrar, `/failures` yeniden denemesi (`ListingResyncRequested`), takılı iş taraması ve bayat kuyruk işi aynı noktadan geçer.
- **Okuma serbest:** sağlık kontrolü, taksonomi, içe aktarma, onay/parti takibi, `findExistingListing`/`fetchListing` izinlere bağlı DEĞİLDİR. İzin kapalı = "yazma yok", "bağlantı yok" DEĞİL.
- **İzni değiştirme:** yalnız `SetOutboundWrite` (panel: Kanallarım → "Kanala yazma izinleri", `PUT /channels/{id}/outbound`). Kimlik doğrulaması/sağlık kontrolü hiçbir izni açmaz. `entegrasyon.outbound_write_locked_channels` (varsayılan `hepsiburada,n11`, env `OUTBOUND_WRITE_LOCKED_CHANNELS`) listesindeki kanalda AÇMA sunucuda reddedilir (`CHANNEL_VERIFICATION_PENDING`); kapatma her zaman serbest. Stok/fiyat izni açılınca canlı satırlar `RequestResync` (`outbound_write_enabled`) ile yeniden senkronlanır; yayın izni açılınca hiçbir şey otomatik gitmez.
- **Geçiş kuralı (migration `2026_10_15_000100`):** kolonlar `false` ile eklenir; `channel_type_code NOT IN ('hepsiburada','n11')` olan MEVCUT satırlarda üçü `true` yapılır (önceki davranış: kimlik = tüm yazmalar; canlıdaki Trendyol/Etsy/Shopify akışı korunur). HB/N11 satırları `false` kalır. Bu sürümden sonra yaratılan HER bağlantı (panel, OAuth, Shopify kurulumu, betik) üç izin kapalı başlar ve açık eylemle açılır.
- **Gelecek adapter'lar:** dışarı yazan her yeni yol (`delist`, `acknowledgeOrder`, kargo, fatura yükleme vb. HB/N11'de bağlandığında) önce `OutboundWriteGate`'ten geçmeli; yeni yazma türü gerekiyorsa enum'a vaka + kolon eklenir.
- **Kapsam dışı (bugün HB/N11'de çağrılmıyor):** HB/N11 `acknowledgeOrder` (çekirdekten çağrılmıyor), `delist` (istisna fırlatır); kargo bildirimi (`PushFulfillment`), fatura yükleme ve ortak etiket HB/N11'de yok (yetenek yok). Diğer kanalların bu yazmaları bu ADR ile kapılanmadı.

## ADR-011 · İade talebi ve müşteri sorusu: kanonik kayıt, ekonomik iade kimliği, müşteri hizmeti yazma izinleri

- **Durum:** **Kabul edildi** — 13 Eki 2026, S4. ADR-005'in (iade talepleri) uygulanmış hâli; ADR-010'u genişletir.
- **Değişmez kurallar:** `SAME ECONOMIC RETURN != MULTIPLE STOCK RESTORATIONS` · `RETURN REQUESTED != STOCK RESTORED` · `AUTHENTICATED != AUTHORIZED FOR CUSTOMER-SERVICE WRITE` · `QUEUED != ANSWERED`, `HTTP 200 != PUBLISHED`.
- **Kanonik kayıt:** `return_requests` (talep) + `return_request_lines` (kalem) + `channel_questions`. Ham kanal durumu (`channel_status`) ile yorum (`status`, `ReturnStatus`/`QuestionStatus`) ayrı kolonlardadır; tanınmayan durum `unknown` ve stok üretmez. Müşteri adı/kimliği saklanmaz.
- **Ekonomik iade kimliği:** `(bağlantı, external_item_id)` — Trendyol `claimItem.id` (tek birim), HB talep numarası. Stok hareketi mevcut çekirdekten (`ApplyOrderReturn`, değişmez defter) geçer; olay çıpası `return-item:{bağlantı}:{external_item_id}`. `order_events (order_id, type, external_ref)` tekilliği aynı iadenin hangi yoldan gelirse gelsin (yoklama, uzlaştırma, tekrar, farklı olay kimliği) ikinci hareketi engeller — Shopify B' hatasının (olay kimliğine bağlı anahtar) tersi.
- **Stok tetiği (hepsi birden):** kanonik `accepted` · kanal S4 yolundan stoklar (bugün yalnız Trendyol) · fiziksel varış kanıtı (`receipt_proven_at`: kalem Trendyol `WaitingInAction`'da görüldü ya da denetim kaydında var) · sipariş satırıyla kesin eşleşme · satırın stoğu gerçekten düşülmüş · satırda kalan adet > 0. Biri eksikse `restock_status = pending|blocked` + kararlı sebep kodu; ekran "stok döndü" demez.
- **Trendyol paket `Returned`:** artık stoğa dokunmaz (`returns_restocked_via_claims`); başlık ve S1'in BEKLENEN iade para kaydı aynen sürer. Stok yalnız talep yolundan. S4 hiçbir talepten GERÇEKLEŞEN finans kaydı üretmez.
- **HB:** talepler yalnız OKUNUR; karar ve S4 stok yolu kapalı (belge çelişkili; kabul fiziksel varışı kanıtlamaz). S4 öncesi HB `Accepted` stok yolu değişmedi — RISKY, TD-43. HB soruları BLOCKED (API Draft, belge statü kodlarında çelişkili, taban adresi ve cevap gövdesi belgede yok).
- **Yazma izinleri:** `channel_connections.return_decision_enabled` ve `question_answer_enabled` (`OutboundWrite::RETURN_DECISION`, `QUESTION_ANSWER`). İki ayrı izin: iade kararı para, cevap herkese açık metin — riskleri farklı. Varsayılan ve geçiş değeri `false` (HER kanalda; hiçbiri canlı doğrulanmadı). `outbound_write_locked_channels` (HB/N11) açmayı reddeder. Kapı hem panel isteğinde hem kuyruk işinin başında TAZE sorulur.
- **Dışarı yazma güvenliği:** istekten hemen önce `pending → remote_state_unknown` koşullu güncellemesi (bir kez); başarı `submitted`; kesin 4xx `failed` (yeniden denenebilir); 429 `pending`'e döner ve iş bekler; ağ/5xx → kanal YENİDEN OKUNUR, çözülemezse `remote_state_unknown` kalır, kör tekrar yok. Karar öncesi kanaldaki güncel talep okunur; kalem artık "karar bekliyor" değilse gönderilmez (`RETURN_STATE_CHANGED`). Cevap öncesi soru okunur; kanalda cevap varsa ya da cevaplanamazsa gönderilmez.
- **Sayaç:** cevapsız = `awaiting_answer` VE 34Pazar'dan kanala ulaşmış/yolda cevap yok (`ChannelQuestion::scopeUnanswered`); `remote_state_unknown` sayılır, `pending`/`submitted` sayılmaz. Pano yerel kayıttan okur, kanala istek atmaz.
