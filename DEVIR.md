# Devir Notu — 16 Ağustos 2026 (ikinci tur)

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## Bu sohbette ne yapıldı

**Dikey dilim kapandı.** §19'daki kapalı döngü baştan sona çalışıyor ve
uçtan uca testle korunuyor. **182 test yeşil** (772 assertion), Pint temiz,
üç random seed'de stabil. Uzak depo: `git@github.com:ahmethamdi/entegrasyon.git`

| Commit | İş |
|---|---|
| `5ccd473` | Dikey dilim: `WooCommerceAdapter` + `ChannelHttpClient` |
| `ce64b7b` | Devir notu ve giden hat kuralları |
| `3a87b4c` | Giden yol: batch builder, `PushInventory`, `SyncResultRecorder` — **T4** |

Önceki turlardan: `5362a08` stok çekirdeği · `f7d9f77` outbox relay + fan-out ·
`58b6f77` adapter mimarisi · `f83e71c` sipariş alımı · `90f9add` gelen hat.

## Mimari kaynak — ÖNCE BUNU YAP

**Doküman esastır.** Kod ile doküman çeliştiğinde doküman kazanır. Yeni
mimari turu yapılmıyor; Kafka, mikroservis, CQRS, event sourcing, Kubernetes
önerilmez.

PDF kalıcı: `~/Desktop/Entegrasyon-Mimari-v2.2.pdf`

Metin çıktısı her sohbette yeniden üretilmeli:

```bash
pdftotext -layout ~/Desktop/Entegrasyon-Mimari-v2.2.pdf /tmp/doc.txt
```

Sıradaki adımı **tahmin etme, dokümandan oku**:

| Ne arıyorsan | Nerede |
|---|---|
| Sınıf yazım sırası (14 sınıf) | §19 · "İlk yazılacak on dört sınıf" |
| İlk çalışan dikey dilim | §19 · 3 |
| Faz planı (1.1–1.7, saat tahminleri) | §13 |
| P0 kararları ve bedelleri | §17 |
| Test matrisi T1–T16 | §18 |
| Stok transaction modeli | §5 |
| Outbox / inbox | §6 |
| Adapter mimarisi · sorumluluk dağılımı | §7 |
| Sync operation + sürüm kapısı | §8 |
| Mutabakat | §10 |
| Yeniden deneme · devre kesici · dead letter | §12 |

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 182 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
```

Composer/artisan komutları **konteyner içinde** çalışır (yerel PHP 8.4,
container 8.3). Testler gerçek PostgreSQL'de koşar (`entegrasyon_test`).

## Zincirin durumu — KAPALI DÖNGÜ

`WooCommerceVerticalSliceTest` bu zinciri gerçek Woo yükleriyle yürütüyor:

```
Woo'da müşteri 1 adet satın alır
   ↓ webhook → HMAC (HAM gövde) → X-WC-Webhook-Delivery-ID → inbox → 202
ProcessInboxMessage → OrderEventRouter → IngestChannelOrder
   ↓ TEK TRANSACTION: orders + order_lines + LockInventoryRows
     + ApplyMovement (10 → 9, KIRPMA YOK) + outbox_events
OutboxRelay → InventoryLevelChangedConsumer
   ↓ FAN-OUT: listing başına operasyon; KAYNAK KANAL ATLANIR
PushInventory → InventoryBatchBuilder (gruplama) → WooCommerceAdapter
   ↓ wc/v3 products/batch, MUTLAK değer, manage_stock: true
   ↓ ChannelHttpClient → api_calls satırı (maskelenmiş, expires_at dolu)
SyncResultRecorder: synced_version = n+1, status = 'synced'
```

Fazla satış senaryosu da sınanıyor: kanonik `-2` kalır, kanala `0` gider,
ledger ↔ projeksiyon eşitliği korunur.

## Sıradaki adım

Doküman §12:

1. **`ChannelRateLimiter`** — Redis kova, `rateLimitProfile()` sözleşmesi.
   `AdapterRegistry::clientFor()` içinde dördüncü bağımlılık olarak eklenecek;
   imza hazır, o sınıf dışında değişiklik gerekmemeli.
2. **`CircuitBreaker`** — ardışık 10 hata → bağlantı 5 dk duraklatılır.
   `half_open`'da tek deneme. **`AUTHENTICATION` devreyi süresiz açar** —
   kimlik bilgisi yenilenene kadar kapalı kalır.
3. Ardından `PushListing` işi (hash hesaplama) ve panel ekranları.

Doküman §18 testlerin **önce** yazılmasını şart koşuyor.

## Bu projede işe yarayan çalışma biçimi

1. **Testi önce yaz, kırmızı olduğunu gör**, sonra implementasyonu yaz.
2. **Mutasyonla sına**: kodu kasten boz, testin kırmızıya döndüğünü doğrula,
   geri al.
3. Stok yazan her testin sonunda `assertLedgerMatchesProjection()` çağır.
4. **Uçtan uca test yaz.** Bu turdaki en değerli bulgu birim testlerin
   göremediği bir boşluktu (aşağıya bak) — parçalar tek tek doğruyken
   aralarındaki sözleşme yanlıştı.

### Bu turda bulunan iki gerçek boşluk

- **`ApplyMovement` outbox yüküne `origin_connection_id` yazmıyordu.**
  Fan-out tüketicisinin yankı bastırması **üretimde hiç çalışmıyordu**:
  Woo'dan gelen sipariş Woo'ya geri yazılırdı. `FanOutTest` yeşildi çünkü
  yükü **elle kuruyordu** — üreticinin gerçekten o alanı yazdığını hiç
  sınamıyordu. Dikey dilim testi yakaladı.
- **`verifyWebhookSignature` kiracı bağlamı bekliyordu.** Webhook bağlam
  kurulMADAN önce çalışır (istek anonimdir); kasadan okuma başarısız olur ve
  **meşru her webhook sessizce reddedilirdi**. Artık açıkça `runAsSystem()`.

### Davranışla sınanamayan iki kural (dürüst sınır)

Mutasyon hayatta kaldı ve kalmalı; sahte test yazılmadı, kodda not edildi:

- **`hash_equals` → `===`**: zamanlama saldırısı işlevsel testte görünmez.
- **Adapter'a `max($q, 0)` eklenmesi**: `InventoryPushItem` negatifi
  kurucuda reddettiği için ikinci kırpma **her zaman işlemsizdir**. Kuralı
  koruyan şey test değil, o **yapısal sınır**.

## Tekrar tekrar ısıran tuzaklar

- **Zaman damgaları saniye hassasiyetli** ve `now()` transaction başında
  donar → transaction içi taramada `clock_timestamp()`. "Bu satır yeni mi"
  sorusunu **asla zaman damgasıyla cevaplama**.
- **Açılış stoğu ledger üzerinden girer** (IMPORT hareketi). *Bu turda da
  ısırdı, yeni biçimde:* açılış hareketi de **outbox olayı yazar**, bu yüzden
  `OutboxEvent::query()->firstOrFail()` sırasız okununca **açılış olayını**
  seçiyordu — o da `origin_connection_id` taşımadığı için kaynak kanal
  elenmiyordu. Olayı `movement_type` ile hedefle.
- **Eşzamanlılık testi `RefreshDatabase` ile yazılamaz** →
  `DatabaseTruncation` + ayrı PDO; `tearDown`'da `truncateDatabaseTables()`.
- **`QUEUE_CONNECTION=sync` gerçek worker'ı taklit etmez.** Dispatch işi
  **derhal** çalıştırır ve kuyruk kancaları **çağıranın** kiracı bağlamını da
  temizler. İki kural: (1) tüketici kimlikleri toplar, işleri **en sonda**
  atar; (2) planlamayı sınayan testler `setUp`'ta **`Queue::fake()`** çağırır.
- **`ProcessInboxMessage` iki argüman alır** (`tenantId`, `inboxMessageId`) ve
  `TenantAwareJob`'dan türer; `app(...)` ile çözülemez, `new` ile kurulur.

## Bilinen açık uç

Önceki turlarda bir `--order-by=random` turunda tek bir test düşmüştü; iki
turdur (yedi seed) tekrar üretilemedi. Tekrar görülürse seed ile kaydedilmeli.

## Ekran durumu

Hâlâ tek görünür sayfa `http://localhost:8080/` — iskelet Dashboard. Ama artık
**arkadaki zincirin tamamı çalışıyor ve testle korunuyor**; panel ekranları
yalnızca var olan veriyi göstermek zorunda, iş mantığı yazmak zorunda değil.

Vite build alınmadıysa: `npm run build` (yerelde, container'da Node yok).
