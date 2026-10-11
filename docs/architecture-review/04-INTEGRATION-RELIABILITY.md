# 04 · Entegrasyon güvenilirliği, mutabakat, eylem merkezi, sağlık, otomasyon kancaları

## 1. Hat: Webhook → Kuyruk → Normalize → İşle → Yeniden dene → Ölü mektup

| Adım | Sınıf | Kanıt / not |
|---|---|---|
| Webhook kapısı (içerik tipi, bağlantı, hız sınırı, ham gövde imzası, tek INSERT) | EXISTS | `WebhookController`, `routes/webhooks.php` |
| İmza (Shopify/Woo/ikas HMAC, HB Basic, sabit zamanlı) | EXISTS | Webhook'suz kanallar `false` döner, yoklanır |
| Tekilleştirme (`(connection, external_event_id)`; yoksa saatlik `payload_hash`) | EXISTS | `IngestInboxMessage` |
| Replay penceresi (zaman damgası toleransı) | PARTIAL | Kalıcı tekilleştirme inbox hiç budanmadığı için çalışıyor; budama eklenirse pencere şart |
| Poison mesaj (5 deneme, takılı `processing` kurtarma) | EXISTS | `ProcessInboxMessage`, `RecoverPendingInbox` |
| **Inbox yeniden denemesinde bekleme yok** — 5 deneme saniyeler içinde biter | **RISKY** | Kısa DB kesintisi siparişi kalıcı `failed` yapar |
| `failed` inbox mesajını yeniden oynatma | MISSING | Yalnız elle SQL |
| Outbox relay (`SKIP LOCKED`), bütünlük taramaları | EXISTS | `OutboxRelay`, `outbox:detect-unconsumed` |
| Outbox/inbox budama | MISSING | Tablolar sınırsız büyüyor (yorum "7 gün" diyor, kod yok) |
| Ölü mektup (`sync_operations.dead`, `/failures`) | EXISTS | |
| **Sırasız sipariş olayları** — sipariş yokken gelen iptal/iade `processed` işaretlenip kayboluyor (✔); durum geri yazılabiliyor | **RISKY** | `OrderEventRouter::handleCancelled/handleReturned` |

## 2. Push işleri

| Konu | Sınıf |
|---|---|
| Ortak iskelet (devre kesici → hız sınırı → adapter → sınıflandır → `RetryPolicy` → release/dead, 24 sa `retryUntil`) | EXISTS |
| `RetryPolicy` (5 sn × 3^n, ±%20 jitter, 5 deneme, Retry-After) ve `ErrorClass` (8 sınıf) | EXISTS |
| Devre kesici (Redis, 10 hata → 5 dk, half-open, AUTH süresiz) | EXISTS |
| Hız sınırı | PARTIAL — yalnız push işleri jeton harcıyor; yoklama, mutabakat, sağlık, taksonomi, token yenileme görünmüyor; çok çağrılı işler tek jeton |
| Kısmi başarı (kalem kimliğiyle eşleme) | EXISTS |
| Asenkron toplu red `/failures`'a düşmüyor (yalnız ürün rozetinde) | RISKY |
| **Kayıp iş taraması** — `retrying` operasyonları, `fulfillments.push_status=pending`, `invoices pending/issuing/upload_pending` için yok | MISSING |

## 3. Token yönetimi

| Konu | Sınıf |
|---|---|
| Zamanlanmış yenileme (`SKIP LOCKED`, `withoutOverlapping`) | EXISTS |
| **Bağlantı başına hata izolasyonu** — adapter kurulumu ve kasa yazımı `try` dışında (✔ `TokenRefresher.php:171` / `:190`); başarısız satır `expires_at` sırasında başta kalıp diğer kiracıları aç bırakabiliyor | **RISKY** |
| Kalıcı ret (`invalid_grant`) `revoked_at` yazmıyor, her 15 dk yeniden deneniyor | PARTIAL |
| Kasa anahtar rotasyonu ile yenileme yarışı (okuma sırasında kilitsiz yeniden şifreleme eski token'ı geri yazabilir) | RISKY (düşük olasılık, yüksek etki — tek kullanımlık refresh token'lı Etsy/eBay) |
| Satıcıya anlık bildirim | PARTIAL (günlük e-posta + panel kartı) |

## 4. Yoklama

| Konu | Sınıf |
|---|---|
| İmleç, 5 dk örtüşme, hata turunda ilerlememe | EXISTS |
| **`MAX_PAGES=50` dolunca imleç yine ilerliyor** — kalan sayfalar bir daha istenmiyor | RISKY |
| **Webhook kanalları (Shopify, Woo) hiç yoklanmıyor** — kaybolan webhook = kaybolan sipariş | RISKY |
| Kota ölçülüyor, uygulanmıyor | PARTIAL |
| İmleç tüm `settings` JSON'unu yeniden yazıyor (eşzamanlı panel değişikliği kaybolabilir) | RISKY (küçük) |

## 5. Mutabakat

| Karşılaştırma | Sınıf | Not |
|---|---|---|
| Kanal stoğu ↔ beklenen stok | EXISTS ama **RISKY** | Fark ilk turda onarılıyor (✔ `ReconcileConnection::classify`), yön ve bekleyen sipariş kontrol edilmiyor → alınmamış satışı eski yüksek stokla ezer |
| Kanal fiyatı ↔ etkin fiyat | EXISTS | Onarım yok, satıcı karar verir (`PRICE_CONFLICT`) — doğru |
| Kanal listing'i ↔ 34Pazar listing'i | PARTIAL | Yalnız "bizde var, kanalda yok" (`REMOTE_MISSING`) |
| **Kanal siparişleri ↔ 34Pazar siparişleri** | **MISSING** | |
| Kargo/takip durumu, fatura yükleme durumu | MISSING | |

### Bu yığına uyan en küçük sağlam tasarım (yeni teknoloji yok)

1. **`reconcile:orders` (30 dk, aktif her bağlantı):** `fetchOrders(now − 24 sa)` → sonuçlar **aynı `IngestInboxMessage` yolundan** `source='reconcile'` ile yazılır. Tekilleştirme kopyaları eler; yeni yazılan her satır "kaçan sipariş" demektir → `reconciliation_items(domain='ORDERS')`. Kargolandı/iptal farkları da aynı yoldan düzelir (kargo mutabakatı bedava gelir). `MAX_PAGES` kesintisinin de güvenlik ağıdır. Shopify için `fetchOrders` gövdesi yazılmalı.
2. **Onarım kapısı:** `remote < expected` (kanal satmış olabilir) → bağlantının bekleyen/işlenen inbox mesajı varsa ya da yoklama imleci gözlemden eskiyse `DRIFT_DETECTED` kalır; onarım **iki ardışık turda** görülürse açılır. `remote > expected` (fazla satış riski) bugünkü gibi hemen onarılır.
3. **Kayıp iş taraması:** `sync:detect-stuck` kardeşi — `retrying` + yaşlı, `fulfillments` pending, `invoices` pending/issuing/upload_pending satırlarını yeniden dispatch eder (hepsi idempotent).
4. Hepsi mevcut `/reconciliation` ve pano `todos()` yüzeyinde görünür.

## 6. Eylem merkezi (Action Center)

Satıcının müdahale etmesi gereken sorunlar bugün **zaten kayıtlı**, dağınık:

| Sorun | Kaynak |
|---|---|
| Ürün reddedildi / onay bekliyor / engelli | `listings.lifecycle_status`, `approval_rejection_reason` |
| Eksik öznitelik / kategori eşlemesi | `listing_sync_states` `blocked` (`PrerequisiteGate`) |
| Kalıcı gönderim hatası, fiyat reddi | `listing_sync_states.error_permanent`, `sync_operations.dead` + `sync_attempts` |
| Kargo bildirimi başarısız | `fulfillments.push_status=failed`, `push_error` |
| Fatura / yükleme başarısız | `invoices.status/upload_status`, `error/upload_error` |
| Eşleşmeyen sipariş kalemi, fazla satış | `order_lines.variant_id IS NULL`, `stock_status`, `inventory_levels.available<0` |
| Yetki süresi doldu / izin eksik | `channel_connections.health_status/status`, `channel_credentials.revoked_at/expires_at`, `DeclaresMissingAuthorization` |
| Fiyat çakışması, manuel inceleme, kanalda yok | `reconciliation_items` |

**Karar (ADR-006): türetilmiş görünüm + ince bir durum tablosu.** Her kaynak kendi doğruluğunu taşıyor ve sorun çözülünce kendiliğinden kayboluyor; ayrı bir `issues` tablosu en az 10 yazma yolunda "aç/kapat" çift yazımı ister ve unutulan biri gerçekle sessizce ayrışır — bu kod tabanı tam olarak bu tür ayrışmalardan defalarca yanmış.

- Sorgu: kaynak başına bir `IssueSource` sınıfı → `(type, severity, channel, subject, reason_human, reason_code, first_seen, last_seen, retry_count, href, actions[RETRY|FIX|IGNORE|VIEW])`. `DashboardController::todos()` bu yapının ilk hâli.
- Durum: `issue_acknowledgements(tenant_id, issue_type, subject_id, state[ignored|snoozed], until, user_id)`.
- Eksik kaynaklar eklenmeli: asenkron red, fatura hataları, `blocked`, `MANUAL_REVIEW`/`REMOTE_MISSING`, iptal edilmiş bağlantı, eksik izin.
- **Uygulama günlükleri bu modele girmez** (teknik log ≠ satıcı eylemi).

## 7. Entegrasyon sağlığı (satıcı panosu)

Bugün hazır veri: `last_healthy_at`, `settings.orders_polled_through`, `listing_sync_states.last_synced_at/status`, `unresolvedDead`, `api_calls` (gecikme, 429, kota), `reconciliation_runs`, `channel_batches`, `channel_credentials`.

**Şimdi eklenmesi gereken telemetri (UI sonra):**

1. Bağlantı × alan (orders, inventory, price, listing, invoice) başına `last_success_at`, `last_failure_at`, `consecutive_failures` — ayrı sayaç tablosu yerine mevcut kayıtlar üzerinde bir görünüm yeterli; yalnız sipariş alımı için `inbox_messages(channel_connection_id, received_at)` indeksi gerekli.
2. `api_calls`'a çağrının amacı (`purpose`: poll/push/reconcile/health/refresh) — bugün yalnız URL'den çıkarılıyor; `sync_attempt_id` hiç dolmuyor.
3. Devre kesici açılış/kapanışının DB'ye iz bırakması (bugün yalnız Redis).
4. `ConnectionHealthChanged` olayı (outbox) — anlık uyarı ve eylem merkezi için.

## 8. Otomasyon motoru için gereken kancalar (motor şimdi yazılmayacak)

| Konu | Sınıf |
|---|---|
| Transactional outbox | EXISTS |
| Yayımlanan iş olayları | PARTIAL — yalnız `InventoryLevelChanged`, `VariantPriceChanged`, `ListingResyncRequested` |
| `order_events` | EXISTS (iz, yayın değil) |
| Çoklu abone | RISKY — `ConsumeOutboxEvent` tek tüketiciye `match`'liyor, `consumed_at` tek kolon; tanınmayan tür "tüketildi" sayılıyor |

**Bugün eklenecek en küçük set (P1, ilgili sprintte):** mevcut yazma noktalarında aynı transaction içinde `OutboxEvent::record()`:
`OrderReceived`, `OrderStateChanged` (ADR-001 ile), `ReturnAccepted` (S4), `ListingApprovalChanged` (S3), `StockThresholdCrossed`, `ConnectionHealthChanged`, `SyncOperationDead`, `InvoiceIssued/Failed`. Gövde şeması sabit: `tenant_id, connection_id, subject_type, subject_id, version, occurred_at`. Çoklu abone için abone başına teslim izi (`outbox_deliveries`) **motor yazılırken** (P2) eklenir; şimdi gerekmez.
