# 03 · Alan modeli denetimi

## A. Kanonik ürün modeli

| Bulgu | Sınıf | Kanıt / not |
|---|---|---|
| Product → Variant → Listing (variant × bağlantı tekil) | EXISTS | `2026_08_15_000400_create_catalog_tables.php`, `..._000800_create_listing_and_sync_tables.php`; `Listing` `UNIQUE(channel_connection_id, variant_id)` |
| **Ürün başına tek varyant** — `app/` içinde varyant yazan tek yer `CreateProduct` (✔ doğrulandı); içe aktarma kanal varyantı başına ayrı ürün açıyor; grup bilgisi yalnız `listings.external_parent_id`'de | **RISKY** | `Catalog/Actions/CreateProduct.php:96`, `ImportProductsFromChannel`, `ShopifyProductMapper.php:238-240`. Trendyol `productMainId = product.sku` → varyantlar ayrı ürün grubu gider (`Trendyol/Catalog/ListingMapper.php:71`) |
| Seçenek tabloları (`option_definitions/values`, `variant_options`) | PARTIAL | Şema ve FK'lar var, üretimde **hiç yazılmıyor**; yalnız testler dolduruyor. Öznitelik eşleme ekranı bu yüzden boş kalıyor |
| SKU tekilliği (kiracı başına) | EXISTS | `UNIQUE(tenant_id, sku)` products ve variants'ta ayrı; gerçek varyant gelince ürün SKU'su ↔ varyant SKU'su çakışması tanımsız |
| Barkod | EXISTS | `variants.barcode`, kısmi unique |
| Kanal dış kimlikleri | EXISTS | `external_id`, `external_parent_id`, `channel_metadata` (yalnız kimlik, birleştirerek yazılır) |
| Kategori eşleme | EXISTS | `category_mappings` + sürümlü `channel_categories`; **iç kategori serbest metin** (PARTIAL) |
| Öznitelik / değer eşleme | PARTIAL | İyi tasarlanmış ama boş seçenek tablolarına bağlı |
| Marka eşleme | MISSING | `products.brand` serbest metin; Trendyol'da isimle tam eşleşme, yoksa `ListingNotPublishable` |
| Kanal başına başlık/açıklama | MISSING | `ListingPayloadBuilder` ürünün alanlarını doğrudan kullanıyor |
| Kanal başına görsel | PARTIAL | `product_images.excluded_channels` (kanal **türü**, bağlantı değil) |
| Listing yaşam döngüsü | EXISTS (tipsiz) | `lifecycle_status` 7 değer, enum/CHECK yok |
| Listing hataları | EXISTS | `listing_sync_states.last_error/error_count`, `approval_rejection_reason` |
| Kanal başına fiyat (4 katman) | EXISTS | `channel_price`, `channel_price_rules`, `price_campaigns` (anlık hesap), `price_overrides`; tek çözücü `effectivePrice()` |
| Pazar yeri alanlarının çekirdeğe sızması | EXISTS (sınırlı) | Ürün/varyant temiz; istisna `brand` ve `variants.currency='TRY'` varsayılanı |
| Kaldırma (delist) | PARTIAL | Adapter'larda var, **çekirdekte çağıran yok**, `delisted_at` yazılmıyor |
| Sipariş kalemi eşleme önceliği | RISKY | `OrderPayloadMapper.php:68` SKU'yu listing bağından önce kullanıyor; içe aktarma tersini yapıyor. SKU yeniden kullanılırsa yanlış varyanta bağlanır |

**Değerlendirme:** pazar yerinin ürün temsili çekirdeğe sızmamış; sorun tersi — çekirdek **çok zayıf** (gerçek varyant grubu yok). Öneri ADR-003: `products` = grup, `variants` = satılabilir birim olarak zaten doğru tasarlanmış şemayı **kullanmaya başlamak**; yeni tablo gerekmiyor. İçe aktarma kanal grubunu (`external_parent_id`) tek ürün altında toplar, seçenek değerlerini yazar; Trendyol `productMainId` ürün kimliğinden gelir.

## B. Kanonik sipariş modeli

Ayrıntı ve önerilen en küçük tasarım: `ARCHITECTURE-DECISIONS.md` ADR-001.

| Bulgu | Sınıf |
|---|---|
| Sipariş/kalem/olay/kargo tabloları, tekillik çıpaları, CHECK (`cancelled+returned ≤ quantity`) | EXISTS |
| `NormalizedOrderEvent` tipleri (created/updated/cancelled/returned/fulfilled) — yönlendirme için | EXISTS |
| Kanaldan bağımsız yaşam döngüsü (`orders.status` kanalın ham kelimesi) | **MISSING** |
| Ham kelimeler adapter dışında 4 listede (`Order::scopeAwaitingShipment`, `resources/js/lib/format.js`, `MaybeAutoInvoice`, `DashboardController::today`) ve birbirini tutmuyor — `ReadyToShip` "kargo bekliyor"da yok; ciro `UnPacked`/`Returned`/`failed`'i sayıyor | **RISKY** |
| `external_id` anlamı kanala göre değişiyor (Trendyol = paket, sipariş no `external_number`'da) | RISKY (belgelenmeli; toplama listesi `external_number` ile gruplanmalı) |
| Durum geri gidebilir (`UpdateOrderSnapshot` sıra kontrolü yok) | RISKY |
| İade talebi yaşam döngüsü (talep → onay/ret → teslim → stoğa) | MISSING |
| İade = anında stoğa geri koyma; Shopify `no_restock` kalemi iade sayacına hiç girmiyor | RISKY |
| İade/para iadesi tutarı | MISSING |
| `order_lines.quantity_fulfilled` ölü kolon | PARTIAL |
| Kargo kalem bazında (`fulfillment_lines`) | MISSING |

**Tehlikeli doğrudan bağımlılıklar:** `MaybeAutoInvoice::stageOf` (`shipped/delivered`), pano cirosu, "kargo bekliyor" kapsamı, Vue rozetleri. Her yeni kanal bu listeleri büyütür.

## C. Stok defteri

| Bulgu | Sınıf |
|---|---|
| Değişmez defter + projeksiyon + outbox aynı transaction'da | EXISTS |
| Hareket tekilliği (`UNIQUE(tenant_id, idempotency_key)`, deterministik anahtarlar) | EXISTS |
| Kaynak bağı (`source_type/source_id`, `channel_connection_id`), not, `on_hand_after` | EXISTS |
| "23 → 17 neden?" sorgusu | EXISTS (`movements_variant_idx`) |
| Aktör kolonu | PARTIAL (panel düzeltmesinde kullanıcı `source_id`'ye yazılıyor + `audit_logs`) |
| Değişmezlik DB'de korunmuyor; **variant/warehouse silinince defter `cascadeOnDelete` ile siliniyor** | **RISKY** |
| Fiziksel / rezerve / satılabilir | EXISTS (rezerve hiç kullanılmıyor, her zaman 0) |
| Gelen stok | MISSING (gerekmiyor — P3) |
| Kilitleme (`LockInventoryRows` sıralı `FOR UPDATE`, kilitlenmede 3 deneme) | EXISTS |
| Fazla satış kabul + işaretleme + yalnız çıkışta kırpma | EXISTS |
| Mutabakat yerel stoğu düzeltmiyor (`RECONCILIATION_ADJUSTMENT` hiç yazılmıyor) | EXISTS (bilinçli: yerel defter otorite) |

**Değerlendirme:** dört değerin hepsi (fiziksel/rezerve/satılabilir/gelen) bugün gerekmiyor; mevcut model yeterli. Yapılacak tek şey ledger'ı korumak: FK'ları `restrictOnDelete`'e çevirmek (varyant/ depo silme zaten yumuşak silmeyle yapılmalı) ve `InventoryMovement` modeline `updating/deleting` reddi (P1, S5).

## D. Depo soyutlaması

| Bulgu | Sınıf |
|---|---|
| `warehouses` tablosu, kiracı başına tek varsayılan | EXISTS |
| Pratikte tek depo: varsayılan depo 7 yerde ayrı ayrı aranıyor (`OrderEventRouter`, `ApplyOrderCancellation`, `ApplyOrderReturn`, `ResolveUnmatchedOrderLines`, `InventoryBatchBuilder`, `ProductController`, `InventoryController`); push yalnız varsayılan deponun seviyesini okuyor | RISKY |
| Sürüm kapısı depo ayırmıyor (`inventory_levels.version` listing'in INVENTORY sürümü olarak kullanılıyor) | RISKY (ikinci depo eklenince itme sırası bozulur) |
| Kanal konumu ↔ depo eşleme (Shopify tek `location_gid`) | PARTIAL |

**Geri dönüşsüz bir tek-depo varsayımı yok** — şema `variant → warehouse → level` taşıyor. Yapılması gereken ucuz önlem: varsayılan depo çözümünü tek bir `WarehouseResolver`'a toplamak (S10, P1). Gerçek çok depo P2.

## E. Paket / kit ürün

| Bulgu | Sınıf |
|---|---|
| Bundle/BOM | MISSING |

Eklemek **pahalı değil** ama dört yer değişir: sipariş kalemi tek varyanta tek SALE (`MovementKey::sale($line->id)` → bileşen başına `sale:{line}:{component}` olmalı), iptal/iade/eşleşmemiş kalem yolları, fan-out tüketicisi (bileşen değişince paketin türetilmiş stoğu yeniden itilmeli), `InventoryBatchBuilder` (paketin kendi seviyesi olmadığından sessizce atlar). Şema: `variant_components(bundle_variant_id, component_variant_id, quantity)` + `variants.kind`; türetilmiş stok **saklanmamalı**. Bugünkü şema bunu engellemiyor → P2.

## Özet risk sırası

1. Gerçek varyant yok (S3/S5/S8 öncesi karar — ADR-003).
2. Kanonik sipariş durumu yok (S1 — ADR-001).
3. Defter silmeyle kaybolabiliyor (S5).
4. Varsayılan depo dağınık (S10).
5. Marka/içerik kanal başına değil; delist yarım (S3).
