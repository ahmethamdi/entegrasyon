# Entegrasyon — Claude için proje notları

Çok kanallı ürün, stok ve sipariş senkronizasyon SaaS'ı.
Laravel 12 · PHP 8.3 · PostgreSQL 16 · Redis + Horizon · Inertia + Vue 3 · Modüler monolit.

## Mimari kaynak

**Mimari Karar Dokümanı v2.2 · Implementation Reference — DONDURULMUŞ.**
https://claude.ai/code/artifact/0564dd35-23c6-469f-904d-160e8fcbb633
PDF: `~/Desktop/Entegrasyon-Mimari-v2.2.pdf`

Kod ile doküman çeliştiğinde **doküman esastır**. Yeni mimari turu yapılmıyor;
yalnızca kod incelemesinde çıkan somut bulgular patchlenebilir. Yeni teknoloji
veya paradigma (Kafka, mikroservis, CQRS, event sourcing, Kubernetes) önerilmez.

## Ortam — komutlar container içinde çalışır

```bash
docker compose up -d
docker compose exec app php artisan test      # 94 test yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
```

Composer bağımlılıkları PHP 8.3'e kilitli (`composer.json` → `config.platform`).
Yerel PHP 8.4 olduğu için **composer komutlarını konteyner içinde çalıştır**,
yoksa `platform_check` hatası alırsın.

Testler gerçek PostgreSQL'de koşar (`entegrasyon_test`), SQLite'ta değil —
generated column, kısmi tekil indeks ve jsonb SQLite'ta doğrulanamaz.

## İhlal edilemez kurallar

Bunlar test ile korunur. İhlal eden değişiklik reddedilmelidir.

### Stok matematiği
- `on_hand = Σ inventory_movements.on_hand_delta` — her koşulda, fazla satış dahil
- Projeksiyonda `GREATEST` / `LEAST` / clamp **yasak**
- `on_hand` ve `available` negatife düşebilir; `CHECK (on_hand >= 0)` **yok**
- Ayrı `oversold_qty` sayacı **yazılmaz** — negatif `available` tek gerçek kaynak
- Kırpma yalnızca `OutboundQuantity::forChannel()` içinde, giden yükte
- `SALE` yetersiz stokta **kabul edilir**; `RESERVATION` / `TRANSFER_OUT` **reddedilir**

### Kilit
- Çok-SKU yazan **her** yol `LockInventoryRows` kullanır: tek sorgu,
  `ORDER BY variant_id FOR UPDATE` (sipariş, iptal, iade, rezervasyon, transfer)
- `ApplyMovement` **kendi kilidini almaz** — ön koşul, testle doğrulanır

### Kiracı izolasyonu
- Bağlam yokken tenant-scoped sorgu **istisna fırlatır**, sessizce veri döndürmez
- `Queue::looping` + `JobProcessing/Processed/Failed` kancaları bağlamı temizler
- Sistem erişimi yalnızca `TenantContext::runAsSystem()` ile, açıkça
- `AdapterRegistry` container'da `bind`, **asla `singleton`**

### Fan-out ve teslim
- Fan-out outbox tüketicisinde: 1 olay → N operasyon (`listing × domain × version`)
- `InventoryBatchBuilder` yalnızca **gruplama** yapar, fan-out yapmaz
- `consumed_at` = planlama bitti, downstream başarısı **değil**
- `sync_operations.outbox_event_id` **UNIQUE değil**, yalnızca indeks

### Sürüm ve onarım
- `NORMAL_SYNC`: `synced >= event` veya `desired > event` → ele
- `REPAIR`: sürüm kapısı **atlanır**, `desired_version` **artırılmaz**
- Mutabakat karşılaştırması `max(available, 0)` ile yapılır
- `error_permanent → pending` geçişi `ListingResyncRequested` olayı üretir

### Depo
- Kiracı başına en fazla bir varsayılan — kısmi tekil indeks (DEFERRABLE değil)
- Değişim `SetDefaultWarehouse` ile, tek transaction iki adım
- "En az bir varsayılan" **DB kısıtıyla zorlanmaz**; `CreateTenant` garanti eder

## Modül sınırı

Bir domain başka bir domainin **modeline** doğrudan yazmaz, yalnızca **action**
sınıfını çağırır. Orders, `InventoryLevel` satırını güncellemez; kilidi
`LockInventoryRows` ile alır, hareketi `ApplyMovement`'a yaptırır.

## Kurulu olan

`app/Domain/`: Identity · Catalog · Inventory · Channels · Messaging · Sync
`app/Support/`: Tenancy · Uuid · Logging

22 domain tablosu, 21 model, 94 test. Stok çekirdeği (`ApplyMovement`,
`LockInventoryRows`), outbox relay ve fan-out tüketicisi yazıldı.
P0 testleri T1/T2/T3/T11/T12 ve T7/T8 yeşil. Ayrıntı için memory'deki
"Repo Durumu" dosyasına bak.

## Zaman damgası tuzağı — `now()` yerine `clock_timestamp()`

`outbox_events` zaman damgaları **saniye hassasiyetlidir**
(`datetime_precision = 0`): `19:56:25.7`'de yazılan olayın `available_at`
değeri `19:56:26`'ya **yuvarlanır** — yazıldığı andan bir saniyeye kadar
ileride olabilir.

PostgreSQL'de `now()` transaction'ın **başlama anını** döndürür ve iç
savepoint'ler dahil donmuş kalır. İkisi birleşince taze bir olay, donmuş
`now()`'a göre "geleceğe planlanmış" görünür ve o turda hiç alınmaz.

Bu yüzden relay sorgusu `available_at <= clock_timestamp()` kullanır.
Transaction içinde çalışan ve "şu ana kadar hazır olanlar" arayan **her**
sorgu aynı kuralı izlemelidir.

## Stok testi yazarken

- **Açılış stoğu ledger üzerinden girer.** `InventoryLevel::create(['on_hand' => 5])`
  ile seed etmek `on_hand = Σ on_hand_delta` eşitliğini bozar; seed IMPORT
  hareketiyle yapılır. Hareket sayısı ve `version` beklentileri açılış
  hareketlerini de sayar.
- **Eşzamanlılık testi `RefreshDatabase` ile yazılamaz** — tek transaction
  içinde kilit çekişmesi oluşmaz, test yanlış yeşile döner. `DatabaseTruncation`
  + ayrı PDO bağlantısı gerekir; bloklanma `SET lock_timeout` ile kanıtlanır.
- **`DatabaseTruncation` kendi setUp'ında boşaltır**, tearDown'da değil.
  Commit edilen artık sonraki testlere sızar; `ConcurrentSaleTest::tearDown()`
  içinde `truncateDatabaseTables()` çağrılır.

Ledger ↔ projeksiyon eşitliği `tests/Concerns/AssertsLedgerIntegrity.php`
içindeki `assertLedgerMatchesProjection()` ile doğrulanır — stok yazan her
testin sonunda çağrılır.

## Henüz yazılmadı

Adapter iş mantığı (`AdapterRegistry`, yetenek arayüzleri), `PushInventory` işi,
`InventoryBatchBuilder`, `SyncResultRecorder`, inbox işleme, sipariş alımı,
mutabakat, kimlik doğrulama ekranları.

## Sıradaki adım

Doküman §19'daki sınıf sırasına göre 13–14: `AdapterRegistry` (container'da
**`bind`**, asla `singleton`) ve yetenek arayüzleri. Ardından faz 1.6 tabloları
(`inbox_messages`, `orders`, `order_lines`, `order_events`) ve T4/T9.
Doküman §18 testlerin **önce** yazılmasını şart koşuyor.
