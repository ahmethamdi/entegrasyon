# 10 · Uygulama kontrol listesi

Her madde bitince işaretlenir. "Bitti" tanımı yol haritası PDF'indekiyle aynı: testler + en az 2 mutasyon + Pint + tam paket yeşil + mümkünse gerçek hesapta deneme + canlıda `/up` ve log temiz.

## S1 öncesi / S1 ilk günler — P0

- [x] **P0-1** `OrderEventRouter::handleCancelled/handleReturned`: sipariş yoksa mesaj `processed` olmaz; sınırlı sayıda ertelenir (ör. 5 × 2 dk) ya da `adoptMissedOrder` ile sipariş sahiplenilir. Test: #1, #2; `InboundPipelineTest::event_for_unknown_order_is_marked_processed` yeni davranışa göre güncellenir.
- [ ] **P0-1b** Durum geri yazılmaz: ADR-001'deki `canTransition` (S1'de `orders.state` ile birlikte) — geçici olarak `UpdateOrderSnapshot`'ta `occurred_at` karşılaştırması. Test: #8.
- [x] **P0-2** `ReconcileConnection::classify`: `observed < expected` → bağlantıda `pending/processing` inbox mesajı varsa ya da `orders_polled_through` gözlemden eskiyse `DRIFT_DETECTED`, onarım yok; `DriftHistory` iki ardışık tur görürse onarım. `observed > expected` değişmez. Test: #3.
- [x] **P0-3** `ShopifyAdapter::fetchOrders` (GraphQL `orders(query: "updated_at:>…")`), `reconcile:orders` komutu (30 dk, bağlantı başına `runFor` + `try/catch`), aynı `IngestInboxMessage` yolu, `source='reconcile'`, yeni satırlar `reconciliation_items(domain='ORDERS')`. Test: #4.
- [x] **P0-4** `TokenRefresher` ve `PollChannelOrders`: adapter kurulumu + kasa yazımı bağlantı başına `try/catch` içinde; başarısız satır için `next_attempt_at` ya da sıralamada geri itme. Test: #5.
- [x] **P0-5** Shopify `verifyWebhookSignature`: `X-Shopify-Shop-Domain` == bağlantının `external_account_id`; `SyncSubscriptionFromShopify` bağlantı/kiracıya bağlı arama. Test: #6.
- [x] **P0-6** `ProcessInboxMessage` `backoff()` (ör. 10, 60, 300, 900 sn); `inbox:replay-failed {--connection} {--since}`; `PollChannelOrders` `MAX_PAGES` dolunca imleci son işlenen sayfanın zamanına yazar. Test: #7.
- [ ] Canlıya al (onaylı), 24 saat izle.

> **Uygulama notları (11 Eki 2026, temel faz):**
> - P0-1: sipariş yoksa iptal/iade `DeferUntilOrderArrives` ile ertelenir (`inbox_messages.awaiting_order_ref`, `next_attempt_at`); sipariş alınınca `WakeDeferredOrderEvents` hemen uyandırır; 48 sa sonra terminal `expired` (stok etkisi yok, alarm yok; sipariş sonra gelirse yine uyanır). Sipariş SAHİPLENİLMEZ (kısmi kalemle yaratma riski). Bağlantıdan önceki sipariş beklenmez. Testler `OutOfOrderOrderEventsTest` (11).
> - P0-1b: durum geriye gitmezliği Faz 2'de `orders.state` + `OrderState::canTransition` ile (aşağıda S1 maddesi).
> - P0-2: yeni durum `SUSPECTED_SALE`; onarım ancak önceki tur aynı düşük değeri beklemişse + bağlantıda bekleyen/işlenen inbox yoksa + sipariş alım imleci (`orders_polled_through` / `orders_reconciled_through`) ilk gözlemden yeniyse ve yarım tur yoksa açılır. İmleç yoksa (Woo/ikas) kanal hiç yukarı çekilmez. Testler `UnrecordedSaleGuardTest` (8).
> - P0-3: komut adı `reconcile:orders`; `SupportsOrderReconciliation` (yalnız Shopify). Kaçan sipariş izi `inbox_messages.source='reconcile'` + `orders.reconcile_recovered` logu (`reconciliation_items.listing_id` NOT NULL olduğu için ORDERS kalemi yazılmadı — eylem merkezi inbox'tan türetir). **İade/para iadesi kurtarma yapılmadı** (farklı olay kimliğiyle çift stok riski); Woo/ikas uygulaması yok.
> - P0-6: hata beklemesi 10 sn/1 dk/5 dk/15 dk (`InboxRetrySchedule`), `inbox:replay-failed {--connection} {--since} {--id=*} {--include-expired} {--dry-run}` (kimlikle sayfalama).
> - Mutasyon: 23/23 öldü.


## S1 — finans temeli (rapordan önce)

- [ ] ADR-001 `orders.state` + `OrderState` enum + adapter eşlemeleri (`STATUS_TO_STATE`) + geri doldurma komutu; 4 ham listeyi `state`'e çevir (`awaitingShipment`, `format.js`, `MaybeAutoInvoice::stageOf`, pano).
- [ ] `app/Support/Money` + 5 `toMinor` kopyasının taşınması; `brick/math` `composer.json`'a doğrudan.
- [ ] Migration: `order_lines` (+`discount_total, net_line_total, vat_rate, unit_cost, cost_currency, commission_rate, commission_amount`), `orders` (+`state, discount_total, refunded_total`), `order_refunds`, `settlements`, `settlement_lines`, `invoices` (+`net_total, vat_total, gross_total, currency`).
- [ ] Trendyol/Shopify net tutar tanımı + `TrendyolOrderPollingTest` güncellemesi; Trendyol alan anlamı gerçek paketle doğrulama.
- [ ] `IngestChannelOrder` alım anında `unit_cost` görüntüsü.
- [ ] `InvoiceLine::netUnitPrice` BigDecimal; test #12.
- [ ] Pano: TR günü + `state` ile ciro.
- [ ] Trendyol hakediş (`SupportsSettlement`), idempotent içe aktarma (`external_ref`); kâr raporu; testler #9–#11, #13.
- [ ] Yeni iş eylemlerine `AuditAction` (kâr raporu yok; hakediş içe aktarma `system` aktörü).

## S2

- [ ] ADR-004 `fulfillments` etiket kolonları + `source` değerleri; `RequestLabel` işi (gönderi kimliği adım sonrası yazılır).
- [ ] Panel kargo kısmi tekil indeks.
- [ ] Kayıp iş taraması (retrying / fulfillments pending / invoices pending-issuing-upload_pending).
- [ ] Toplu işlem çerçevesi + toplama listesi (`external_number`).
- [ ] Testler #14, #15.

## S3

- [ ] ADR-003 gerçek varyant grubu: içe aktarma kanal grubunu toplar, seçenek değerleri yazılır, Trendyol `productMainId` ürün kimliğinden, SKU kuralı; mevcut veri için birleştirme komutu (onaylı).
- [ ] `brand_mappings`; ürün başına KDV oranı (`variants.vat_rate` ya da ürün); `ListingLifecycle` enum.
- [ ] `OrderPayloadMapper`: listing bağı SKU'dan önce.
- [ ] HB/N11 `SupportsCatalog` + "önce ara, sonra yarat"; test #16.
- [ ] `ListingApprovalChanged` outbox olayı.

## S4

- [ ] ADR-005 `return_requests` + Trendyol claims + HB tüm talepler; stok onay/varışta; `ReturnAccepted` olayı; `questions` tablosu; test #17.

## S5

- [ ] `product_sources` + varyant kaynak bağı; XML/Excel; Excel stok değişimi defterden.
- [ ] Defter koruması: FK `restrictOnDelete`, `InventoryMovement` `updating/deleting` reddi.

## S6

- [ ] ADR-007 `invoices.kind` + `parent_invoice_id` + kısıt genişletme; iade faturası kalemleri kanonik kayıttan; test #18.
- [ ] `SupportsCarrierShipment` (Yurtiçi, Aras); HB/N11/Shopify `SupportsInvoiceData`.
- [ ] `reconcile:orders` kargo/fatura yükleme durumu karşılaştırması.

## S7

- [ ] Rol modeli, policy'ler, davet, 2FA (sahip + süper yönetici), `creating` kancası bağlam denetimi, proxy sabitleme.
- [ ] Denetim kapsamı (05 §7 listesi) + `actor_type=system`.
- [ ] Sağlık telemetrisi (04 §7), `api_calls.purpose`, `inbox_messages(channel_connection_id, received_at)` indeksi.
- [ ] Outbox/inbox budama + replay penceresi; log maskeleme işlemcisi; `SESSION_ENCRYPT`.
- [ ] Mimari izolasyon testleri (#19); 100 eşzamanlı sipariş yük testi.

## S8–S12

- [ ] S8: AI taslak + onay, `ai_usage`, `listing_content_overrides` (başlık/açıklama).
- [ ] S9: eBay para birimi koruması (açmadan önce), `fx_rates` + kalem görüntüsü, eBay siparişleri; test #20.
- [ ] S10: adapter sözleşme kontrol listesi, `WarehouseResolver`, Ticimax `Tutar` doğrulaması.
- [ ] S11: eylem merkezi (`IssueSource` + `issue_acknowledgements`), sağlık panosu, kanıt ekranı.
- [ ] S12: geri yükleme tatbikatı, mutabakat doğrulaması, aralıklı test (`DB::disconnect` + `finally`).
