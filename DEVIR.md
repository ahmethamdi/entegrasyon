# Devir Notu — 16 Ağustos 2026

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## Bu sohbette ne yapıldı

Tek commit, `main` dalında, çalışma ağacı temiz. **158 test yeşil**
(637 assertion), Pint temiz, dört farklı `--order-by=random` seed'inde yeşil.

| Commit | İş |
|---|---|
| `3a87b4c` | Giden yol: `InventoryBatchBuilder`, `PushInventory`, `SyncResultRecorder` — **P0 testi T4** |

Önceki turlardan: `5362a08` stok çekirdeği · `f7d9f77` outbox relay + fan-out ·
`58b6f77` adapter mimarisi · `f83e71c` sipariş alımı · `90f9add` gelen hat.

**Uzak depo yok** — `git remote` boş, push edilmedi. İstenirse kurulmalı.

## Mimari kaynak — ÖNCE BUNU YAP

**Doküman esastır.** Kod ile doküman çeliştiğinde doküman kazanır. Yeni
mimari turu yapılmıyor; Kafka, mikroservis, CQRS, event sourcing, Kubernetes
önerilmez.

PDF kalıcı: `~/Desktop/Entegrasyon-Mimari-v2.2.pdf`

Metin çıktısı her sohbette yeniden üretilmeli (scratchpad oturuma özeldir):

```bash
pdftotext -layout ~/Desktop/Entegrasyon-Mimari-v2.2.pdf /tmp/doc.txt
```

Sıradaki adımı **tahmin etme, dokümandan oku**:

| Ne arıyorsan | Nerede |
|---|---|
| Sınıf yazım sırası (14 sınıf) | §19 · "İlk yazılacak on dört sınıf" |
| İlk çalışan dikey dilim | §19 · 3 |
| P0 kararları ve bedelleri | §17 · P0 / P1 / P2 |
| Test matrisi T1–T16 | §18 · Test Acceptance Criteria |
| Stok transaction modeli | §5 |
| Outbox / inbox | §6 |
| Adapter mimarisi · sorumluluk dağılımı | §7 |
| Sync operation + sürüm kapısı | §8 |
| Mutabakat | §10 |
| Yeniden deneme · devre kesici · dead letter | §12 |

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 158 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
```

Composer/artisan komutları **konteyner içinde** çalışır (yerel PHP 8.4,
container 8.3; `composer.json` içinde platform kilidi var).

Testler gerçek PostgreSQL'de koşar (`entegrasyon_test`), SQLite'ta değil.

## Zincirin durumu

**İç yön tam**: webhook → sipariş → stok → outbox → fan-out.
**Dış yön artık bağlı**: fan-out iş atıyor, iş yükü kuruyor, kanala
gönderiyor, sonucu yazıyor.

```
ApplyMovement → outbox_events
   → OutboxRelay → InventoryLevelChangedConsumer
        → FAN-OUT: listing başına sync_operation  (operasyon sayısı = canlı listing)
        → consumed_at damgalandı, operations_planned yazıldı
        → PushInventory::dispatch  (operasyon başına AYRI iş)
             → InventoryBatchBuilder: GRUPLAMA (aynı bağlantı, tek yük)
             → adapter->pushInventory()
             → SyncResultRecorder: attempt + operation + listing_sync_states
```

Eksik olan tek halka **gerçek adapter**: şu an
`tests/Support/Channels/ProgrammableInventoryAdapter` ağa çıkmadan davranışı
sınıyor.

## Sıradaki adım

**Gerçek `WooCommerceAdapter` + `ChannelHttpClient`.** Bununla §19'daki dikey
dilim kapanır ve ilk gerçek görsel çıktı doğar: Woo'da sipariş → panelde stok
düşer → 30 sn içinde Woo'ya geri yazılır.

1. `ChannelHttpClient` — istek yürütme, zaman aşımı, `api_calls` yazımı,
   `expires_at` dolumu (2xx +7 gün, 4xx/5xx +90 gün), `PayloadRedactor` ile
   maskeleme. `AdapterRegistry::clientFor()` içinde kurulacak; imza zaten
   hazır, o sınıf dışında değişiklik gerekmemeli.
2. `WooCommerceAdapter` — `SupportsInventory` + `SupportsOrders` +
   `SupportsCatalog`. `classifyError()` gerçek Woo hata gövdesini okur.
   `maxInventoryBatchSize()` = 100 (wc/v3 batch).
3. `ChannelRateLimiter` (Redis kova) ve `CircuitBreaker` — ardışık 10 hata →
   5 dk duraklatma; `AUTHENTICATION` devreyi süresiz açar.

Doküman §18 testlerin **önce** yazılmasını şart koşuyor.

## Bu projede işe yarayan çalışma biçimi

1. **Testi önce yaz, kırmızı olduğunu gör**, sonra implementasyonu yaz.
2. **Mutasyonla sına**: kodu kasten boz, testin kırmızıya döndüğünü doğrula,
   geri al. Bu turda altı mutasyon denendi, altısı da yakalandı — ama ikisi
   ancak test eklendikten sonra (aşağıya bak).
3. Stok yazan her testin sonunda `assertLedgerMatchesProjection()` çağır.

### Bu turda mutasyonla bulunan iki gerçek boşluk

Her ikisinde de testler yeşildi ama invariantı korumuyorlardı:

- **Bağlantı filtresi kaldırıldığında çapraz kiracı testi YEŞİL kalıyordu** —
  kiracı global scope'u zaten eliyordu, yani test filtreyi değil scope'u
  sınıyordu. Sızıntının gerçek biçimi **tek kiracı içinde iki bağlantı**:
  Woo'nun yükü Trendyol'un `external_id`'lerini taşırsa kanal onları tanımaz.
  → `grouping_never_crosses_connections_within_a_tenant`
- **Başarıda sürüm kapısı hiç yoktu.** İki iş yarışıp eski olan sonra
  bittiğinde `synced_version` geri sarılıyordu. Geri sarma, kanalda doğru
  veri dururken satırı kirli gösterir ve gereksiz yeniden gönderim başlatır.
  → `stale_success_does_not_rewind_synced_version`

İkinci testi yazarken **testin kendisi de iki kez yanlış yeşile döndü**:
(a) operasyon `firstOrFail()` ile sırasız çekiliyordu, (b) `$stale` supersede'den
**önce** okunduğu için `save()` hiçbir alanı kirli görmüyor ve `UPDATE` hiç
çalışmıyordu. İkisi de "bayat operasyon gerçekten gönderildi mi" ara
iddiası eklenerek yakalandı. **Mutasyon testinin kendisi de doğrulanmalı.**

## Tekrar tekrar ısıran tuzaklar

Dördü de bu projede gerçekten yaşandı; ayrıntı `CLAUDE.md`'de ve memory'de.

- **Zaman damgaları saniye hassasiyetli** (`datetime_precision = 0`) ve
  PostgreSQL'de `now()` transaction başlangıcında donar. Transaction içi
  tarama sorgularında `clock_timestamp()` kullan. "Bu satır yeni mi" sorusunu
  **asla zaman damgasıyla cevaplama**. Bu hata iki kez tekrarlandı.
- **Açılış stoğu ledger üzerinden girer.** Testte
  `InventoryLevel::create(['on_hand' => 5])` yazmak `on_hand = Σ delta`
  eşitliğini daha başta bozar; seed `IMPORT` hareketiyle yapılır.
  *(Bu turda da ısırdı: T4 testleri stok seed'i olmadan yazıldığında yük boş
  kalıyor, `recordSkipped` çalışıyor ve adapter hiç çağrılmıyordu — test
  izolasyonu değil erken çıkışı sınıyordu.)*
- **Eşzamanlılık testi `RefreshDatabase` ile yazılamaz.** `DatabaseTruncation`
  + ayrı PDO bağlantısı gerekir; `tearDown`'da `truncateDatabaseTables()`.
- **YENİ — `QUEUE_CONNECTION=sync` gerçek worker'ı taklit etmez.** Dispatch
  işi **derhal** çalıştırır ve kuyruk kancaları (`Queue::looping`,
  `JobProcessing`) her iş sınırında kiracı bağlamını temizler — **çağıranın**
  bağlamı da yok olur. Tüketici döngüsünün ortasında iş atarsan kalan
  listing'ler bağlamsız kalır ve tenant-scoped sorgu istisna fırlatır.
  İki kuralı doğurdu:
  1. Tüketici kimlikleri **toplar**, işleri **en sonda** atar.
  2. Planlamayı sınayan testler `setUp`'ta **`Queue::fake()`** çağırır
     (`FanOutTest`, `StockChangeToOperationsTest`, `PushInventoryTest`).
     Gönderim `PushInventoryTest` içinde işler **elle** çağrılarak sınanır.

## Bilinen açık uç

Önceki turda bir `--order-by=random` turunda tek bir test düşmüştü;
o turda ve bu turda (dört seed) tekrar üretilemedi. Tekrar görülürse
seed ile kaydedilmeli.

## Ekran durumu

Şu an görülebilen tek sayfa `http://localhost:8080/` — ilk turdan kalma
iskelet Dashboard. Yazılan işlerin hiçbiri ekrana bağlı değil; doküman
ekranları bilinçli olarak sonraya bırakıyor. İlk gerçek görsel çıktı §19'daki
dikey dilim ve o artık **tek bir adım uzakta**: gerçek `WooCommerceAdapter`.

Vite build alınmadıysa: `npm run build` (yerelde, container'da Node yok).
