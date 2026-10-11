# 02 · Mevcut mimari

Kaynak: depo `main`, 11 Ekim 2026. Yollar depo köküne göre.

## Yığın ve dağıtım

- Laravel 12 · PHP 8.3 · PostgreSQL 16 · Redis + Horizon · Inertia + Vue 3. **Modüler monolit** (`app/Domain/*`), mikroservis yok — doğru seçim, değiştirilmemeli.
- Üretim: `docker-compose.prod.yml` (Caddy TLS, app, horizon, scheduler, outbox relay, postgres, redis), `deploy.sh` (git pull → build → migrate → sağlık). Tek sunucu.
- Mimari referans: `docs/ENTEGRASYON-V3.0.md` (kod ile çelişirse belge esas — CLAUDE.md kuralı).

## Alanlar (`app/Domain`)

| Alan | Sorumluluk | Durum |
|---|---|---|
| Identity | Kiracı, kullanıcı, üyelik, denetim kaydı, süper yönetici | EXISTS (rol modeli MISSING) |
| Billing | Planlar, Stripe + Shopify Billing, kota (`EnforceQuota`, ürün + kanal) | EXISTS |
| Catalog | Ürün, varyant, görsel, fiyat kuralı, kampanya, maliyet, içe aktarma | EXISTS (gerçek varyant RISKY) |
| Channels | Adapter sözleşmeleri, 11 adapter, kasa, HTTP istemcisi, OAuth, devre kesici, hız sınırı | EXISTS |
| Inventory | Defter, projeksiyon, kilitleme, depo | EXISTS (çok depo PARTIAL) |
| Sync | Listing, senkron durumları, operasyonlar, push işleri, taksonomi, eşleme, toplu iş takibi | EXISTS |
| Orders | Sipariş, kalem, olay, kargo; alım, iptal, iade, yönlendirme, yoklama | EXISTS (kanonik durum MISSING) |
| Messaging | Inbox/outbox, relay, tüketici | EXISTS |
| Reconciliation | Stok ve fiyat mutabakatı, sürüklenme geçmişi | PARTIAL (sipariş/kargo yok) |
| Invoicing | e-fatura (Paraşüt), PDF yükleme, muhasebe aktarımı | EXISTS (yeni; gerçek hesapta doğrulanmadı) |

## Kanal adapter yapısı

- Taban sözleşme ince: `app/Domain/Channels/Contracts/ChannelAdapter.php` (sağlık, hata sınıflandırma, hız profili, webhook doğrulama).
- **Yetenekler arayüzle beyan edilir** (`Supports*`, `Declares*`) ve `instanceof` ile sorulur; panel adapter kurmadan `is_subclass_of` kullanır. Yetenek haritası `AdapterRegistry::capabilitiesOf` + seeder bayrakları ile tutarlılık testi.
- `AdapterRegistry::for()` her çağrıda yeni örnek (kiracı sızıntısına karşı bilinçli).
- Adapter yan etki üretmez (DB yazmaz), `AdapterResult` döner; çekirdek sınıflandırır ve yeniden dener.

| Adapter | Yetenekler (özet) | Canlı |
|---|---|---|
| Trendyol | Catalog, Import, Inventory, Pricing, Orders, Taxonomy, Approval, BatchStatus, InvoiceData, InvoiceUpload | ✅ |
| Shopify | Catalog, Import, Inventory, Pricing, Orders, Fulfillment, TokenRefresh, Webhooks | ✅ |
| Etsy | Catalog, Import, Inventory, Pricing, Orders, Fulfillment, Taxonomy, TokenRefresh | ✅ |
| eBay | OfferLifecycle, Inventory, Pricing, Fulfillment, Taxonomy, TokenRefresh (**Orders yok**) | kapalı |
| Hepsiburada, N11, Pazarama, Çiçeksepeti | Import, Inventory, Pricing, Orders, BatchStatus (**Catalog yok**) | kapalı |
| WooCommerce | Catalog, Import, Inventory, Pricing, Orders, Fulfillment, Webhooks | kapalı |
| ikas, Ticimax | Import, Inventory, Pricing, Orders, Fulfillment (+ikas TokenRefresh) | kapalı |

## Veri akışları

**Sipariş girişi:** webhook (`WebhookController`: içerik tipi → bağlantı → hız sınırı → imza → tek INSERT tekilleştirme → 202) ya da yoklama (`PollChannelOrders`, 5 dk, imleç + 5 dk örtüşme) → `inbox_messages` (`PersonalDataMask`, şifreli gövde) → `ProcessInboxMessage` (koşullu sahiplenme, 5 deneme) → `OrderEventRouter` → `IngestChannelOrder` / `ApplyOrderCancellation` / `ApplyOrderReturn` / `UpdateOrderSnapshot` / `UpdateFulfillment` → stok hareketi (`ApplyMovement`) + outbox olayı.

**Stok çıkışı:** `ApplyMovement` → `InventoryLevelChanged` (outbox, aynı transaction) → relay → `ConsumeOutboxEvent` → listing başına `sync_operations` → `PushInventory` (devre kesici → hız sınırı → adapter → sınıflandır → yeniden dene / ölü).

**Fiyat çıkışı:** `VariantPriceChanged` / kampanya tick / kural değişikliği → `Listing::effectivePrice()` → `PushPrices`.

**Listeleme:** `PublishListing` (önkoşul kapısı: kategori eşleme vb.) → `PushListing` / `PushOfferListing` → toplu iş takibi (`PollChannelBatches`) → onay takibi (`TrackApprovalStatus`).

**Fatura:** sipariş durumu/kargo → `MaybeAutoInvoice` (transaction dışında) → `IssueInvoice` (kanaldan anlık taslak → Paraşüt adımları, her adım kimliği anında yazılır → sonuç sorgusu) → `UploadInvoiceToChannel`.

## Zamanlanmış işler (`routes/console.php`)

Yoklama (5 dk), inbox kurtarma (1 dk), outbox bütünlük (10 dk), takılı senkron (5 dk), mutabakat hot/warm/cold/prices, token yenileme (15 dk), sağlık taraması (saatlik), toplu iş yoklama (5 dk), onay takibi (saatlik), kampanya tick, metrik yakalama (saatlik), uyarılar (09:00), `api_calls` budama. **Outbox/inbox budama yok.**

## Kuyruklar (Horizon)

`orders:high, inventory:high, inbox:process, outbox:consume` · `price:high, listing:default` · `reconciliation` · `listing:bulk, maintenance`. Supervisor başına süreç sayıları `config/horizon.php`.

## Gözlemlenebilirlik

`api_calls` (maskeli, süre, durum, hız sınırı kalanı), `metric_snapshots` (16 metrik, saatlik), `/metrics`, `/failures` (ölü operasyonlar), `/reconciliation`, `/approvals`, pano `todos()`; günlük uyarı e-postası. Kanal × alan başına "son başarı" toplamı ve anlık uyarı yok (bkz. 04).

## Bileşen değerlendirmesi (özet)

| Konu | Sınıf |
|---|---|
| Ürün → varyant → listing zinciri | EXISTS (varyant grubu RISKY) |
| Stok defteri, kilitleme, tekillik | EXISTS |
| Sipariş alımı tekilleştirme | EXISTS |
| Sipariş durum normalizasyonu | MISSING |
| Webhook imzası, inbox, poison mesaj | EXISTS (replay penceresi PARTIAL) |
| Push iskeleti (retry/jitter/devre/hız) | EXISTS |
| Token yenileme | EXISTS (hata izolasyonu RISKY) |
| Mutabakat | PARTIAL (yalnız stok + fiyat; onarım kapısı RISKY) |
| Kiracı izolasyonu | EXISTS (yapısal değil, konvansiyon + test) |
| Yetki/rol | MISSING |
| Denetim kaydı | PARTIAL |
| Para tipi ve aritmetik | EXISTS (sınır noktalarında float PARTIAL) |
| Finansal anlık görüntü / hakediş | MISSING |
| Kargo etiketi | MISSING |
| Fatura/muhasebe | EXISTS (tek entegratör, iade faturası MISSING) |
| Testler | EXISTS (risk boşlukları 07'de) |
