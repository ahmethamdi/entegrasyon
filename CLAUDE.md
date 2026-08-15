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
docker compose exec app php artisan test      # 41 test yeşil olmalı
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

`app/Domain/`: Identity · Catalog · Inventory · Channels · Messaging
`app/Support/`: Tenancy · Uuid · Logging

16 domain tablosu, 16 model, 41 test. Ayrıntı için memory'deki
"Repo Durumu" dosyasına bak.

## Henüz yazılmadı

`ApplyMovement`, `LockInventoryRows`, adapter iş mantığı, outbox relay,
inbox işleme, sipariş alımı, senkron motoru, mutabakat, kimlik doğrulama ekranları.

## Sıradaki adım

`ApplyMovement` + `LockInventoryRows` ve P0 testleri (T1, T2, T11, T12).
Doküman §18 testlerin **önce** yazılmasını şart koşuyor.
