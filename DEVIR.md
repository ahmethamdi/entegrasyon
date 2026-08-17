# Devir Notu — 17 Ağustos 2026

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## Bu sohbette ne yapıldı

**Koruma katmanı yazıldı**: `ChannelRateLimiter` + `CircuitBreaker`, ikisi de
giden hatta bağlandı. **207 test yeşil** (835 assertion), Pint temiz, üç
random seed'de stabil, **CI yeşil**.

| Commit | İş |
|---|---|
| `52702e3` | Devre kesici ve hız sınırı: ölü kanala yüklenme durdu |
| `021d92e` | CI: testler Blade render ettiği için Vite varlıkları build edilmeli |
| `5ccd473` | Dikey dilim: `WooCommerceAdapter` + `ChannelHttpClient` |
| `3a87b4c` | Giden yol: batch builder, `PushInventory`, `SyncResultRecorder` — **T4** |

Uzak depo: `git@github.com:ahmethamdi/entegrasyon.git` · `main` push edilmiş.

## Mimari kaynak — ÖNCE BUNU YAP

**Doküman esastır.** Kod ile doküman çeliştiğinde doküman kazanır. Yeni
mimari turu yapılmıyor; Kafka, mikroservis, CQRS, event sourcing, Kubernetes
önerilmez.

```bash
pdftotext -layout ~/Desktop/Entegrasyon-Mimari-v2.2.pdf /tmp/doc.txt
```

| Ne arıyorsan | Nerede |
|---|---|
| Sınıf yazım sırası (14 sınıf) | §19 · "İlk yazılacak on dört sınıf" |
| Faz planı (1.1–1.7) | §13 |
| P0 kararları | §17 |
| Test matrisi T1–T16 | §18 |
| Outbox / inbox · bütünlük taramaları | §6 |
| Adapter mimarisi | §7 |
| Sync operation + sürüm kapısı | §8 |
| Mutabakat | §10 |
| Yeniden deneme · devre kesici · dead letter | §12 |

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 207 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
```

Komutlar **konteyner içinde** çalışır (yerel PHP 8.4, container 8.3).
Testler gerçek PostgreSQL'de koşar. **Redis testlerde gerçekten kullanılıyor**
(limiter ve breaker); `flushdb` ile setUp/tearDown'da temizleniyor.

## Zincirin durumu

Kapalı döngü çalışıyor ve artık **koruma katmanıyla birlikte**:

```
Woo siparişi → webhook (HMAC, ham gövde) → inbox → 202
  → ProcessInboxMessage → OrderEventRouter → IngestChannelOrder
  → TEK TRANSACTION: orders + LockInventoryRows + ApplyMovement + outbox
  → OutboxRelay → fan-out (kaynak kanal atlanır)
  → PushInventory
       ├─ CircuitBreaker.allows()      ← kanal ölüyse ERTELE, deneme AÇMA
       ├─ ChannelRateLimiter.attempt() ← kota boşsa ERTELE, deneme AÇMA
       ├─ InventoryBatchBuilder (gruplama)
       ├─ WooCommerceAdapter → wc/v3 products/batch (MUTLAK değer)
       ├─ ChannelHttpClient → api_calls (maskeli, expires_at dolu)
       └─ SyncResultRecorder + breaker.recordSuccess/Failure
```

## Sıradaki adım

Doküman §6 · **bütünlük taramaları** (§18 · T5 ve T6):

1. **`DetectUnconsumedEvents`** — seviye 1: `published_at` dolu ama
   `consumed_at` boş ve eski olaylar. Relay dispatch sonrası öldüyse olay
   kaybolmuş olur; tarama onu yeniden yayınlar.
2. **`DetectStuckSyncOperations`** — seviye 2: `status = 'pending'` ve
   `attempt_count = 0` ve eski. Redis işi kaybettiyse worker hiç çalışmamıştır.
   **Kısmi indeks zaten var** (`sync_ops_never_attempted_idx`).
   Bu tarama olayı YENİDEN YAYINLAMAZ — o zincir zaten tamamlanmıştı.

İkisi de **`clock_timestamp()`** kuralına tabidir (aşağıya bak).

Ardından mutabakat (§10) veya `PushListing` + panel ekranları.

## Bu projede işe yarayan çalışma biçimi

1. **Testi önce yaz, kırmızı olduğunu gör**, sonra implementasyonu yaz.
2. **Mutasyonla sına**: kodu kasten boz, testin kırmızıya döndüğünü doğrula.
   *Bu turda dokuz mutasyonun dokuzu da yakalandı.*
3. Stok yazan her testin sonunda `assertLedgerMatchesProjection()` çağır.
4. **Entegrasyonu ayrıca sına.** Sınıfın var olması onu kimsenin çağırdığı
   anlamına gelmez — `PushInventoryCircuitTest` tam bunun için yazıldı.
5. **Uçtan uca test yaz.** Parçalar tek tek doğruyken aralarındaki sözleşme
   yanlış olabilir; geçen turdaki iki gerçek boşluk böyle bulundu.

## Tekrar tekrar ısıran tuzaklar

- **`clock_timestamp()`** — zaman damgaları saniye hassasiyetli ve `now()`
  transaction başında donar. Transaction içi "şu ana kadar hazır olanlar"
  sorgusu `clock_timestamp()` kullanır. **Sıradaki iki tarama tam da bu
  türden sorgular yazacak.**
- **Açılış stoğu ledger üzerinden girer** (IMPORT hareketi) ve o hareket de
  **outbox olayı yazar**. Olayı `movement_type` ile hedefle; sırasız
  `firstOrFail()` açılış olayını seçer.
- **Eşzamanlılık testi `RefreshDatabase` ile yazılamaz** →
  `DatabaseTruncation` + ayrı PDO; `tearDown`'da `truncateDatabaseTables()`.
- **`QUEUE_CONNECTION=sync` gerçek worker'ı taklit etmez.** Dispatch işi
  derhal çalıştırır ve kuyruk kancaları **çağıranın** kiracı bağlamını da
  temizler. Tüketici kimlikleri toplar, işleri **en sonda** atar; planlamayı
  sınayan testler `setUp`'ta **`Queue::fake()`** çağırır.
- **`ProcessInboxMessage` iki argüman alır** (`tenantId`, `inboxMessageId`);
  `app(...)` ile çözülemez, `new` ile kurulur.
- **CI'da `public/build` yoktur** (`.gitignore`). Blade render eden testler
  Vite manifest'ine muhtaçtır; `Tests` job'ı `npm run build` çalıştırır.
  Job'lar ayrı runner'larda çalışır, `frontend` job'ının çıktısı taşınmaz.

## Davranışla sınanamayan kurallar (dürüst sınır)

Mutasyon hayatta kalır ve kalmalı; sahte test yazılmadı, kodda not edildi:

- **`hash_equals` → `===`**: zamanlama saldırısı işlevsel testte görünmez.
- **Adapter'a `max($q, 0)`**: `InventoryPushItem` negatifi kurucuda
  reddettiği için ikinci kırpma **her zaman işlemsizdir**. Kuralı koruyan
  şey test değil, o **yapısal sınır**.

## Bilinen açık uç

Eski turlarda bir `--order-by=random` turunda tek test düşmüştü; üç turdur
(on seed) tekrar üretilemedi. Görülürse seed ile kaydedilmeli.

## Ekran durumu

Hâlâ tek görünür sayfa `http://localhost:8080/` — iskelet Dashboard. Arkadaki
zincirin tamamı çalışıyor ve testle korunuyor; panel ekranları yalnızca var
olan veriyi göstermek zorunda, iş mantığı yazmak zorunda değil.

Vite build alınmadıysa: `npm run build` (yerelde, container'da Node yok).
