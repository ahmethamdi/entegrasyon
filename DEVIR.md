# Devir Notu — 17 Ağustos 2026 (panel turu)

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## Tek cümlede durum

Zincirin tamamı çalışıyor (Woo siparişi → stok düşer → kanala geri yazılır),
koruma katmanı devrede ve **artık panelde görünüyor**. **221 test yeşil**
(884 assertion), CI yeşil, `main` push edilmiş.

## Bu sohbette ne yapıldı

| Commit | İş |
|---|---|
| `4317182` | **Panel**: kimlik doğrulama, kiracı bağlamı, senkron durumu ekranı |
| `df14a52` | Devir notu: koruma katmanı |
| `52702e3` | Devre kesici ve hız sınırı |
| `021d92e` | CI: Vite varlıkları build edilmeli |
| `5ccd473` | Dikey dilim: `WooCommerceAdapter` + `ChannelHttpClient` |
| `3a87b4c` | Giden yol: batch builder, `PushInventory`, `SyncResultRecorder` (**T4**) |

Uzak depo: `git@github.com:ahmethamdi/entegrasyon.git`

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
| Faz planı (1.1–1.7) ve saat tahminleri | §13 |
| P0 / P1 / P2 kararları | §17 |
| Test matrisi T1–T16 | §18 |
| Outbox / inbox · bütünlük taramaları | §6 |
| Adapter mimarisi · sorumluluk dağılımı | §7 |
| Sync operation + sürüm kapısı | §8 |
| Çakışma çözümü (alan bazlı) | §9 |
| Mutabakat | §10 |
| Yeniden deneme · devre kesici · dead letter | §12 |

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 221 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
npm run build                                 # YERELDE (container'da Node yok)
```

Komutlar **konteyner içinde** çalışır (yerel PHP 8.4, container 8.3).
Testler gerçek PostgreSQL'de, Redis gerçekten kullanılıyor (limiter/breaker).

Panel: `http://localhost:8080/` → giriş ekranına yönlendirir.

## Zincirin durumu — TAMAMI ÇALIŞIYOR

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
  → PANELDE GÖRÜNÜR (senkron sayaçları, fazla satış, kanal sağlığı)
```

## Panel — bu turda eklendi

- **Kimlik doğrulama**: `RegisteredUserController` (kullanıcı + kiracı +
  varsayılan depo TEK transaction), `SessionController` (deneme sınırı
  e-posta + IP başına).
- **`EstablishTenantContext`** ara katmanı: oturumdaki kiracı kimliğine
  **asla olduğu gibi güvenilmez**, her istekte ÜYELİKTEN doğrulanır.
  Bağlam istek sonunda `finally` ile bırakılır.
- **Dashboard**: senkron sayaçları, **fazla satış tablosu** (§17 · P0 —
  negatif `available` kırpılmadan, eksik miktarla), kanal sağlığı, son
  operasyonlar (hata sınıflarıyla).
- Ekranlar: `Auth/Login`, `Auth/Register`, `Dashboard`, `Layouts/PanelLayout`.

Tarayıcıda uçtan uca doğrulandı: kayıt → giriş → dashboard gerçek veriyle.

## Sıradaki adım — SEÇİM SENİN

Panel iskeleti kuruldu ama tek ekran var. İki mantıklı yol:

1. **Kanal bağlama akışı** — Woo mağazasını panelden gerçekten bağlamak
   (`CredentialVault` yazılı, `healthCheck()` hazır). Sistemi ilk kez
   gerçek bir mağazayla uçtan uca çalıştırır. *Daha yüksek değer.*
2. **Ürün/stok listesi** — satıcının her gün bakacağı ekran; fazla satış
   uyarısı ve senkron rozeti (§13 · faz 1.2 ve 1.5 panel maddeleri).

Doküman sırası ise §6 bütünlük taramaları (`DetectUnconsumedEvents` T5,
`DetectStuckSyncOperations` T6) ve §10 mutabakat — ikisi de
`clock_timestamp()` kuralına tabi. Bunlar kurtarma mekanizmaları; sistem
üretimde çalışmaya başlamadan önce aciliyeti düşük.

## Bu projede işe yarayan çalışma biçimi

1. **Testi önce yaz, kırmızı olduğunu gör**, sonra implementasyonu yaz.
2. **Mutasyonla sına**: kodu kasten boz, testin kırmızıya döndüğünü doğrula.
3. Stok yazan her testin sonunda `assertLedgerMatchesProjection()` çağır.
4. **Entegrasyonu ayrıca sına** — sınıfın var olması onu kimsenin çağırdığı
   anlamına gelmez (`PushInventoryCircuitTest` tam bunun için).
5. **Uçtan uca test yaz** — parçalar tek tek doğruyken aralarındaki sözleşme
   yanlış olabilir.

## Mutasyonla bulunan gerçek boşluklar (tarihçe)

Hepsi testler yeşilken bulundu:

- **`ApplyMovement` outbox yüküne `origin_connection_id` yazmıyordu** —
  fan-out'un yankı bastırması üretimde hiç çalışmıyordu. `FanOutTest` yeşildi
  çünkü yükü **elle kuruyordu**.
- **`verifyWebhookSignature` kiracı bağlamı bekliyordu** — webhook bağlam
  kurulmadan önce çalışır; meşru her webhook sessizce reddedilirdi.
- **Başarıda sürüm kapısı yoktu** — bayat başarı `synced_version`'ı geri
  sarıyordu.
- **Bağlantı filtresi testi aslında tenant scope'u sınıyordu** — filtre
  kaldırılınca test yeşil kalıyordu.

## Davranışla sınanamayan kurallar (dürüst sınır)

Mutasyon hayatta kalır ve kalmalı; sahte test YAZILMADI:

- **`hash_equals` → `===`**: zamanlama saldırısı işlevsel testte görünmez.
- **Adapter'a `max($q, 0)`**: `InventoryPushItem` negatifi kurucuda
  reddettiği için ikinci kırpma her zaman işlemsizdir. Kuralı koruyan şey
  test değil, o **yapısal sınır**.
- **`regenerate()` çağrısı**: Laravel'in `SessionGuard::login()` zaten
  `session->regenerate(true)` çağırıyor (SessionGuard.php:588). İkinci çağrı
  gereksizdi ve **kaldırıldı** — satırın yük taşıdığı izlenimi veriyordu.

## Tekrar tekrar ısıran tuzaklar

- **`clock_timestamp()`** — zaman damgaları saniye hassasiyetli, `now()`
  transaction başında donar. Transaction içi "şu ana kadar hazır olanlar"
  sorgusu `clock_timestamp()` kullanır. **Sıradaki taramalar tam bu türden.**
- **Açılış stoğu ledger üzerinden girer** (IMPORT) ve o hareket de **outbox
  olayı yazar**. Olayı `movement_type` ile hedefle; sırasız `firstOrFail()`
  açılış olayını seçer.
- **Eşzamanlılık testi `RefreshDatabase` ile yazılamaz** →
  `DatabaseTruncation` + ayrı PDO; `tearDown`'da `truncateDatabaseTables()`.
- **`QUEUE_CONNECTION=sync` gerçek worker'ı taklit etmez.** Dispatch işi
  derhal çalıştırır ve kuyruk kancaları **çağıranın** bağlamını da temizler.
  Tüketici kimlikleri toplar, işleri **en sonda** atar; planlamayı sınayan
  testler `setUp`'ta **`Queue::fake()`** çağırır.
- **`ProcessInboxMessage` iki argüman alır** (`tenantId`, `inboxMessageId`);
  `app(...)` ile çözülemez, `new` ile kurulur.
- **CI'da `public/build` yoktur** (`.gitignore`). Blade render eden testler
  Vite manifest'ine muhtaç; `Tests` job'ı `npm run build` çalıştırır. Job'lar
  ayrı runner'larda çalışır, `frontend` job'ının çıktısı taşınmaz.

## Bilinen açık uç

Eski turlarda bir `--order-by=random` turunda tek test düşmüştü; dört turdur
(on iki seed) tekrar üretilemedi. Görülürse seed ile kaydedilmeli.
