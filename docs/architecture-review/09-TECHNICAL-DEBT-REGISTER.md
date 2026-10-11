# 09 · Teknik borç kaydı

Etki: Y (veri/güvenlik/para) · O (yanlış görünüm, operasyon yükü) · D (bakım). Öncelik 08'deki gruplarla aynı.

| ID | Borç | Konum | Etki | Öncelik | Hedef |
|---|---|---|---|---|---|
| TD-01 | Sipariş yokken gelen iptal/iade düşürülüyor; durum geri yazılabiliyor | `Orders/Routing/OrderEventRouter.php`, `Orders/Actions/UpdateOrderSnapshot.php`, `UpdateFulfillment.php` | Y | P0 | S1 |
| TD-02 | Mutabakat alınmamış satışı ilk turda eski stokla eziyor | `Reconciliation/Actions/ReconcileConnection.php::classify` | Y | P0 | S1 |
| TD-03 | Webhook kanallarında kaçan sipariş kurtarma yok | `Orders/Support/PollChannelOrders.php`, `ShopifyAdapter::fetchOrders` | Y | P0 | S1 |
| TD-04 | Token yenileme ve yoklamada bağlantı başına hata izolasyonu yok | `Channels/Support/TokenRefresher.php:171/190`, `PollChannelOrders.php:118` | Y | P0 | S1 |
| TD-05 | Shopify webhook mağazaya bağlı değil | `ShopifyAdapter::verifyWebhookSignature`, `SyncSubscriptionFromShopify` | Y | P0 | S1 |
| TD-06 | Inbox yeniden denemesi beklemesiz; `failed` replay yok; `MAX_PAGES` sonrası imleç ilerliyor | `Messaging/Jobs/ProcessInboxMessage.php`, `PollChannelOrders.php` | Y | P0 | S1 |
| TD-07 | Kanonik sipariş durumu yok; 4 tutarsız ham durum listesi | `Order::scopeAwaitingShipment`, `resources/js/lib/format.js`, `MaybeAutoInvoice`, `DashboardController` | O→Y | P1 | S1 |
| TD-08 | Satış anı finansal görüntüsü yok; tutarlar indirim öncesi; iade tutarı yok | `order_lines`, `TrendyolAdapter::toCanonicalOrderPayload`, `ShopifyOrderNormalizer` | Y | P1 | S1 |
| TD-09 | Fatura net birim float; fatura tutarı yerelde yok | `InvoiceLine::netUnitPrice`, `invoices` | Y (kuruş) | P1 | S1 |
| TD-10 | `toMinor/fromMinor` 5 kopya | PriceRuleCalculator, ResolveChannelPrice, ReconcileConnection, Plan, UpdateProduct | D | P1 | S1 |
| TD-11 | Pano günü UTC; ciro iptal/bölünmüş paketleri sayıyor | `DashboardController::today` | O | P1 | S1 |
| TD-12 | Panel kargo çift tıklama tekilliği yalnız uygulamada | `OrderController::ship` | O | P1 | S2 |
| TD-13 | Kayıp iş taraması yok (retrying, kargo, fatura) | `Sync/Support/DetectStuckSyncOperations.php` | Y | P1 | S2 |
| TD-14 | Ürün başına tek varyant; seçenek tabloları yazılmıyor; içe aktarma grupları bölüyor | `CreateProduct.php:96`, `ImportProductsFromChannel`, `ShopifyProductMapper`, `Trendyol/Catalog/ListingMapper.php:71` | Y (katalog) | P1 | S3 |
| TD-15 | Marka eşleme yok (isimle tam eşleşme) | `TrendyolAdapter::brandId` | O | P1 | S3 |
| TD-16 | `lifecycle_status` tipsiz; delist çekirdekten çağrılmıyor | `PushListing`, `TrackApprovalStatus` | D/O | P1 | S3 |
| TD-17 | Sipariş kalemi eşlemede SKU listing bağından önce | `OrderPayloadMapper.php:68` | Y | P1 | S3 |
| TD-18 | İade = anında stoğa geri; talep yaşam döngüsü yok; HB yalnız `Accepted` | `ApplyOrderReturn`, `HepsiburadaAdapter` | Y | P1 | S4 |
| TD-19 | Defter silmeyle kaybolabiliyor (`cascadeOnDelete`), DB değişmezliği yok | `create_inventory_tables` migration, `InventoryMovement` | Y | P1 | S5 |
| TD-20 | Sipariş başına tek fatura kısıtı iade faturasını engelliyor | `invoices_order_unique` | Y | P1 | S6 |
| TD-21 | Paraşüt POST sonrası kimlik kaydedilmeden ölürse kopya taslak | `ParasutInvoiceProvider::submit` | O | P1 | S6 (gerçek hesap doğrulamasıyla) |
| TD-22 | Rol/izin yok; `tenant_id` fillable + bağlam denetimi yok | `EstablishTenantContext`, `BelongsToTenant` | Y | P1 | S7 |
| TD-23 | Denetim kapsamı dar, yazım "en iyi çaba" | `RecordAuditLog`, `AuditAction` | O | P1 | S7 |
| TD-24 | Log maskeleme işlemcisi yok; oturum şifresiz; proxy güveni `*` | `config/logging.php`, `SESSION_ENCRYPT`, `bootstrap/app.php` | Y | P1 | S7 |
| TD-25 | Outbox/inbox budama yok | `routes/console.php` | O | P1 | S7 |
| TD-26 | Hız sınırı yalnız push trafiğini görüyor; `api_calls.sync_attempt_id` hiç dolmuyor | `ChannelRateLimiter`, `ChannelHttpClient` | O | P1 | S7 |
| TD-27 | Webhook hız sınırı imzadan önce; pasif bağlantı webhook kabul ediyor | `WebhookController` | O | P1 | S7 |
| TD-28 | Çoklu abone outbox yok; tanınmayan olay "tüketildi" | `ConsumeOutboxEvent` | D | P2 | Q1 2027 |
| TD-29 | eBay para birimi koruması yok | `EbayAdapter::pushPrices`, `EbayProductMapper` | Y | P1 | S9 (açmadan önce) |
| TD-30 | Varsayılan depo 7 yerde; sürüm kapısı depo ayırmıyor | 7 çağrı yeri, `InventoryLevelChangedConsumer` | O→Y (çok depo) | P1/P2 | S10 / Q1 |
| TD-31 | Katalogda KDV bağlantı başına tek oran | `TrendyolAdapter` `vat_rate` | Y (vergi) | P1 | S3 |
| TD-32 | Kasa rotasyonu ↔ token yenileme yarışı | `CredentialVault::read` | Y (düşük olasılık) | P2 | Q1 2027 |
| TD-33 | Stripe olay tekilleştirme/sıra yok | `StripeWebhookController` | O | P2 | Q1 2027 |
| TD-34 | Aralıklı kırılan test (ikinci PDO bağlantıları kapanmıyor) | `ConcurrentTokenRefreshTest` vb. | D | P1 | S12 |
| TD-35 | Ticimax sipariş `Tutar` anlamı doğrulanmadı | `TicimaxAdapter.php:598` | Y | P1 | S10 (açmadan önce) |
| TD-36 | `MetricsController::history` sistem metriklerini kiracılara gösteriyor | `MetricsController` | O | P2 | — |
| TD-37 | Sınıflama/eşleme sebepleri DB'de Türkçe sunum metni (alan değeri değil) — İngilizce panelde Türkçe görünür; sebep KODU + çeviri olmalı | `TrendyolFinancialTypes::classify`, `MatchFinancialTransactions`, `channel_financial_transactions.classification_reason/match_reason` | D/O | P2 | S3 |
| TD-38 | Katkı raporu dönem başına 200 siparişle kesiliyor (`truncated`); sayfalama yok | `ContributionReportController::index` | O | P2 | S4 |
| TD-39 | `Ödeme`, `Komisyon Faturası`, `Gelen Havale` VERIFIED'ı yalnız etikete bağlı; yapısal değişmez yok (S1 §11 politikası). Katkıya girmedikleri için bugün sayıyı değiştirmez | `TrendyolFinancialTypes::OBSERVED` | O | P1 | S0 ödemesi (22 Eki) gözlenince |
| TD-40 | ✅ ÇÖZÜLDÜ (S2 öncesi) — Dönem toplamında tamam sipariş 0 iken katkı kalın "0,00" görünüyor (etiketli ama kesin rakam gibi okunabilir) → "—" | `resources/js/Pages/Reports/Contribution.vue:93` | O | P2 | S2 içinde küçük düzeltme |
| TD-41 | Finans ilk turu yalnız bağlantı − 30 gün; geriye dönük doldurma/önizleme seçeneği yok (yeni kiracıda eski hakediş gelmez) | `IngestFinancialTransactions::INITIAL_LOOKBACK_DAYS`, `finance:sync` | O | P2 | ilk gerçek kiracıdan önce |
| TD-42 | Sipariş listesi satırı kanonik `state` taşımıyor (`presentRow`), rozet ham kanal durumuna düşüyor; `show()`'da `'state'` iki kez yazılmış. S3 denetimi: YALNIZ SUNUM — iş mantığı bu dizileri okumuyor, S3 bağımlı değil | `OrderController::presentRow`, `OrderController::show:117`, `Orders/Index.vue` | O | P2 | — |
