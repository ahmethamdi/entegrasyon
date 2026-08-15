# Entegrasyon

Çok kanallı ürün, stok ve sipariş senkronizasyon platformu.

**Architecture source of truth: Mimari Karar Dokümanı v2.2 · Implementation Reference.**
Bu depodaki her yapısal karar o dokümandan gelir. Doküman dondurulmuştur;
kod ile doküman çeliştiğinde doküman esastır.

---

## Teknoloji

| Katman | Seçim |
|---|---|
| Çekirdek | Laravel 12 · PHP 8.3 |
| Veritabanı | PostgreSQL 16 |
| Kuyruk | Redis 7 · Horizon |
| Panel | Inertia · Vue 3 · Vite |
| Mimari | Modüler monolit |

---

## Kurulum

```bash
git clone git@github.com:ahmethamdi/entegrasyon.git
cd entegrasyon

cp .env.example .env

docker compose up -d --build
```

İlk açılışta `app` konteyneri PHP 8.3 imajını derler; birkaç dakika sürebilir.

### Bağımlılıklar

```bash
docker compose exec app composer install
docker compose exec app php artisan key:generate

npm install
```

> Composer bağımlılıkları PHP 8.3'e sabitlenmiştir (`composer.json` →
> `config.platform`). Yerel PHP sürümünüz farklı olsa bile komutları konteyner
> içinde çalıştırın.

### Veritabanı

```bash
# Test veritabanı — ilk kurulumda bir kez
docker compose exec postgres psql -U entegrasyon -d entegrasyon \
  -c "CREATE DATABASE entegrasyon_test OWNER entegrasyon;"

docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
```

### Frontend

```bash
npm run dev      # geliştirme sunucusu
npm run build    # üretim derlemesi
```

Uygulama: <http://localhost:8080>

---

## Günlük komutlar

```bash
# Testler — GERÇEK PostgreSQL üzerinde koşar, SQLite kullanılmaz
docker compose exec app php artisan test

# Kod stili
docker compose exec app vendor/bin/pint          # düzelt
docker compose exec app vendor/bin/pint --test   # yalnızca denetle

# Kuyruk işçileri
docker compose exec app php artisan horizon
# Panel: http://localhost:8080/horizon

# Şema sıfırlama
docker compose exec app php artisan migrate:fresh --seed
```

---

## Servisler

| Servis | Host portu | Not |
|---|---|---|
| nginx | 8080 | Uygulama |
| postgres | 5433 | PostgreSQL 16 |
| redis | 6380 | `appendonly` + `noeviction` |
| app | — | PHP 8.3-FPM |

Redis'in `maxmemory-policy` değeri `noeviction` olarak sabitlenmiştir.
Varsayılan `allkeys-lru` politikası bellek baskısı altında kuyruk işlerini
sessizce siler (v2.2 · §15).

---

## Klasör yapısı

```
app/
├── Domain/
│   ├── Identity/      Tenant · User · TenantUser · CreateTenant
│   ├── Catalog/       Product · Variant · seçenekler · görseller
│   ├── Inventory/     Warehouse · InventoryLevel · InventoryMovement
│   │                  SetDefaultWarehouse · MovementKey · OutboundQuantity
│   ├── Channels/      ChannelType · ChannelConnection · CredentialVault
│   └── Messaging/     OutboxEvent
├── Support/
│   ├── Tenancy/       TenantContext · BelongsToTenant · TenantAwareJob
│   ├── Uuid/          HasUuidV7
│   └── Logging/       PayloadRedactor
└── Providers/         AppServiceProvider · QueueServiceProvider
```

Modül sınırı kuralı: bir domain başka bir domainin **modeline** doğrudan
yazmaz, yalnızca **action** sınıfını çağırır.

---

## Değişmez kurallar

Bunlar test ile korunur; ihlal eden değişiklik CI'da kırmızıya döner.

**Stok matematiği**
- `on_hand = Σ inventory_movements.on_hand_delta` — her koşulda, fazla satış dahil
- Projeksiyonda `GREATEST` / `LEAST` / clamp **yasak**
- `on_hand` ve `available` negatife düşebilir; `CHECK (on_hand >= 0)` **yok**
- Kırpma yalnızca `OutboundQuantity` içinde, giden yükte
- `SALE` yetersiz stokta kabul edilir; `RESERVATION` reddedilir

**Kiracı izolasyonu**
- Bağlam yokken tenant-scoped sorgu **istisna fırlatır**, sessizce veri döndürmez
- `Queue::looping` + `JobProcessing/Processed/Failed` kancaları bağlamı temizler
- Sistem erişimi yalnızca `TenantContext::runAsSystem()` ile, açıkça

**Depo**
- Kiracı başına en fazla bir varsayılan depo — kısmi tekil indeks
- Değişim `SetDefaultWarehouse` ile, tek transaction iki adım
- "En az bir varsayılan" kuralı **veritabanı kısıtıyla zorlanmaz**; `CreateTenant` garanti eder

---

## Test kapsamı

```
tests/
├── Feature/
│   ├── Identity/    CreateTenant · varsayılan depo garantisi
│   ├── Inventory/   şema invariantları · varsayılan depo kısıtı
│   └── Tenancy/     kiracı izolasyonu · worker bağlam sızıntısı
└── Unit/Uuid/       UUIDv7 üretimi
```

Testler `entegrasyon_test` veritabanında, gerçek PostgreSQL 16 üzerinde koşar.
Şema PostgreSQL'e özgü yapılara dayanır (generated column, kısmi tekil indeks,
`jsonb`) ve bunlar SQLite'ta doğrulanamaz.

---

## Sonraki faz

Bu tur yalnızca çekirdek iskelettir. Uygulanmamış olanlar:
`ApplyMovement`, `LockInventoryRows`, WooCommerce adapteri, outbox relay,
inbox işleme, sipariş alımı, senkron motoru, mutabakat.
