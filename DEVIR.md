# Devir Notu — 17 Ağustos 2026 (bütünlük taramaları turu)

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## Tek cümlede durum

Zincirin tamamı çalışıyor, koruma katmanı devrede, panelde görünüyor ve
**artık kayıp iş kurtarılıyor**: §6'nın iki bütünlük taraması yazıldı,
zamanlayıcıya bağlandı ve uçtan uca doğrulandı. **248 test yeşil**
(985 assertion).

## Bu sohbette ne yapıldı

§6 · iki bütünlük taraması — doküman §18'deki T5 ve T6.

| Ne | Nerede |
|---|---|
| Seviye 1 taraması | `app/Domain/Messaging/Support/DetectUnconsumedEvents.php` |
| Seviye 2 taraması | `app/Domain/Sync/Support/DetectStuckSyncOperations.php` |
| Komut kabukları | `*/Console/Detect*Command.php` |
| Zamanlama | `routes/console.php` |
| Komut kaydı | `bootstrap/app.php` |
| T5 testleri | `tests/Feature/Messaging/DetectUnconsumedEventsTest.php` (9) |
| T6 testleri | `tests/Feature/Sync/DetectStuckSyncOperationsTest.php` (9) |
| Zamanlayıcı entegrasyonu | `tests/Feature/Messaging/ScheduledScansTest.php` (6) |
| Uçtan uca kurtarma | `tests/Feature/Sync/LostWorkRecoveryTest.php` (3) |

27 yeni test (221 → 248).

## İki taramanın ayrı olma nedeni — özü

İki teslim zinciri var ve **biri diğerinin kaybını göremez**:

```
Seviye 1 — outbox teslimi:
  relay yayınladı → Redis işi düşürdü → fan-out HİÇ çalışmadı
  İmza: published_at dolu + consumed_at NULL
  Eylem: published_at = NULL, publish_attempts++   → relay tekrar alır
  Seviye 2 bunu göremez: bulacağı operasyon hiç yaratılmadı.

Seviye 2 — sync teslimi:
  fan-out çalıştı, operasyonlar açıldı → Redis PushInventory'yi düşürdü
  İmza: status = 'pending' + attempt_count = 0
  Eylem: doğrudan yeniden dispatch. Outbox olayı YENİDEN YAYINLANMAZ.
  Seviye 1 bunu göremez: consumed_at dolu, olay tarafı kusursuz görünür.
```

`LostWorkRecoveryTest::the_two_scans_do_not_overlap_in_scope` iki kaybı aynı
anda kurar ve her taramanın **yalnızca kendi** kaybını gördüğünü doğrular.

## Bu turda bulunan GERÇEK boşluk

**`inbox:recover` hiç zamanlanmamıştı.** Geçen tur yazılmış, komut kusursuz,
testleri yeşil — ve zamanlayıcıda kaydı yoktu. Dakikalık çalışması gereken
sipariş kurtarma taraması **hiç çalışmıyordu**. `routes/console.php` yalnızca
`inspire` komutunu içeriyordu.

İkinci katman: komutu zamanlamak da yetmiyor. Domain klasörlerindeki komutlar
otomatik keşfedilmiyor (Laravel yalnızca `app/Console/Commands` tarar) ve
`bootstrap/app.php` içinde açıkça kaydedilmeleri gerekiyor. Kayıt olmadan
`schedule:list` kusursuz görünüyor ama dakikası gelince artisan "böyle bir
komut yok" diyor. **`ScheduledScansTest` bu ikisini AYRI AYRI doğrular** —
biri zamanlamayı, diğeri komutun gerçekten çözülüp çalıştığını.

Sınıfın var olması onu kimsenin çağırdığı anlamına gelmez; bu turda tam
olarak bu oldu.

## Mutasyonla sınandı — hepsi öldü

| Mutasyon | Öldüren test |
|---|---|
| `clock_timestamp()` → `now()` (iki taramada) | `*_stale_only_by_wall_clock_*` (2) |
| `consumed_at IS NULL` yüklemi kaldırıldı | `permanently_failed_operations_do_not_republish_event` |
| `attempt_count = 0` → `>= 0` | `attempted_operation_is_not_flagged_as_stuck` |
| Tarama `attempt_count = 1` yazıyor | `scan_does_not_open_an_attempt` + `limit_bounds_a_single_pass` |
| Seviye 1 `consumed_at` damgalıyor | `unconsumed_event_is_detected_and_republished` |
| Seviye 2 outbox olayını yeniden yayınlıyor | `stuck_operation_is_detected_and_redispatched` |
| `status = 'pending'` → tüm durumlar | `terminal_and_dead_operations_are_never_redispatched` |
| `published_at = NULL` yazılmıyor | 3 test, uçtan uca dahil |

## Hayatta kalan mutasyon — YAPISAL SINIR, sahte test yazılmadı

**`published_at IS NOT NULL` → `1 = 1`: hiçbir test kırılmıyor.**

Cümle davranış katmıyor çünkü `NULL < clock_timestamp() - interval`
karşılaştırması `NULL` döner, SQL bunu "doğru değil" sayar ve satır zaten
elenir. PostgreSQL'de doğrulandı:

```sql
SELECT (NULL::timestamptz < clock_timestamp() - interval '5 minutes');  -- NULL
```

Cümle **iki nedenle duruyor**: (1) §6'daki sorgu tanımı böyle, (2)
`outbox_unconsumed_idx` kısmi indeksinin yüklemiyle birebir eşleşiyor ve
planlayıcının indeksi seçmesini garanti ediyor. Gerekçe kodun içinde yazılı.

Bu, DEVIR.md'nin üçüncü kategorisi: mutasyon hayatta kalır ve kalmalı.

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 248 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
npm run build                                 # YERELDE (container'da Node yok)
```

Zamanlama doğrulaması:

```bash
docker compose exec app php artisan schedule:list
docker compose exec app php artisan outbox:detect-unconsumed   # bulunan sayısı
docker compose exec app php artisan sync:detect-stuck
```

## Zamanlanmış işler — §15 tablosu

| Komut | Frekans | Ne kurtarır |
|---|---|---|
| `inbox:recover` | 1 dakika | Kuyruğa hiç girmemiş webhook — **kaybedilen şey SİPARİŞ** |
| `sync:detect-stuck` | 5 dakika | Seviye 2: Redis `PushInventory`'yi düşürdü |
| `outbox:detect-unconsumed` | 10 dakika | Seviye 1: tüketici hiç çalışmadı |

`outbox:relay` **bu listede yok ve olmamalı** — supervisor altında sürekli
çalışan bir süreç. Zamanlanırsa dakikada bir yeni sonsuz döngü başlar.
`ScheduledScansTest::the_continuous_relay_process_is_not_scheduled` korur.

## Sıradaki adım — SEÇİM SENİN

Doküman §13'e göre **tamamlanmamış en erken faz maddesi 1.4**: 1.5/1.6/1.7
zaten yazılmış durumda.

1. **Kanal bağlama akışı** (§13 · faz 1.4) — Woo mağazasını panelden
   gerçekten bağlamak. `CredentialVault` ve `healthCheck()` hazır. Dokümanın
   doğrulama ölçütü: *"gerçek Woo mağazasına bağlanıyor, sırlar loglarda
   görünmüyor"*. Sistemi ilk kez gerçek bir mağazayla uçtan uca çalıştırır.
   *En yüksek değer ve doküman sırasına uygun.*
2. **Ürün/stok listesi** — satıcının her gün bakacağı ekran; fazla satış
   uyarısı ve senkron rozeti (§13 · faz 1.2 ve 1.5 panel maddeleri). 1.4
   olmadan gerçek veriyle beslenemez.
3. **§10 mutabakat** — sürüklenme tespiti. `clock_timestamp()` kuralına tabi
   ve karşılaştırma `max(available, 0)` ile yapılır.

## Bu projede işe yarayan çalışma biçimi

1. **Testi önce yaz, kırmızı olduğunu gör**, sonra implementasyonu yaz.
2. **Mutasyonla sına**: kodu kasten boz, testin kırmızıya döndüğünü doğrula.
3. Stok yazan her testin sonunda `assertLedgerMatchesProjection()` çağır.
4. **Entegrasyonu ayrıca sına** — sınıfın var olması onu kimsenin çağırdığı
   anlamına gelmez (`ScheduledScansTest` bu turda tam bunu buldu).
5. **Uçtan uca test yaz** — parçalar tek tek doğruyken aralarındaki sözleşme
   yanlış olabilir (`LostWorkRecoveryTest`).

## Mutasyonla bulunan gerçek boşluklar (tarihçe)

Hepsi testler yeşilken bulundu:

- **Kurtarma taramaları zamanlayıcıya bağlanmamıştı** (bu tur) — `inbox:recover`
  bir tur boyunca hiç çalışmadı.
- **`ApplyMovement` outbox yüküne `origin_connection_id` yazmıyordu** —
  fan-out'un yankı bastırması üretimde hiç çalışmıyordu.
- **`verifyWebhookSignature` kiracı bağlamı bekliyordu** — meşru her webhook
  sessizce reddedilirdi.
- **Başarıda sürüm kapısı yoktu** — bayat başarı `synced_version`'ı geri sarıyordu.
- **Bağlantı filtresi testi aslında tenant scope'u sınıyordu.**

## Davranışla sınanamayan kurallar (dürüst sınır)

Mutasyon hayatta kalır ve kalmalı; sahte test YAZILMADI:

- **`published_at IS NOT NULL` yüklemi** (bu tur): NULL karşılaştırması satırı
  zaten eler. Cümleyi doküman ve kısmi indeks eşleşmesi için tutuyoruz.
- **`hash_equals` → `===`**: zamanlama saldırısı işlevsel testte görünmez.
- **Adapter'a `max($q, 0)`**: `InventoryPushItem` negatifi kurucuda reddettiği
  için ikinci kırpma her zaman işlemsizdir.
- **`regenerate()` çağrısı**: `SessionGuard::login()` zaten çağırıyor; ikinci
  çağrı **kaldırıldı**.

## Tekrar tekrar ısıran tuzaklar

- **`clock_timestamp()`** — zaman damgaları saniye hassasiyetli, `now()`
  transaction başında donar. Bu turdaki iki tarama da bu kurala tabiydi;
  pencere testi `pg_sleep(1.1)` + `date_trunc('second', now())` ile kurulur:
  damgayı donmuş `now()`'a **eşit** yaz, eşiği bir saniye ver.
- **`Command::run()` REZERVE İMZADIR.** Tarama sınıfı `Command`'dan türeyip
  `run(int, int): Collection` tanımlayamaz — fatal error. Bu yüzden mantık
  `Support/` altında sade sınıfta, komut ince kabuk (`OutboxRelay` /
  `OutboxRelayCommand` ayrımının aynısı).
- **Domain komutları otomatik keşfedilmez** — `bootstrap/app.php` →
  `withCommands()` içinde açık kayıt gerekir.
- **`QUEUE_CONNECTION=sync` gerçek worker'ı taklit etmez.** Planlamayı sınayan
  testler `Queue::fake()` çağırır; yoksa `PushInventory` derhal çalışır ve
  kuyruk kancaları çağıranın bağlamını temizler. `LostWorkRecoveryTest`'in
  seviye 1 testi tam bu yüzden bir kez kırmızıya döndü.
- **Seviye 2 taraması damgalamadığı için TÜKENMEZ**: ardışık iki tur aynı
  satırları döner. Bu bilinçli — damgalamak `attempt_count = 0` imzasını yok
  eder. `limit_bounds_a_single_pass` bunu belge olarak sabitler.
- **Açılış stoğu ledger üzerinden girer** (IMPORT) ve o hareket de outbox
  olayı yazar. Olayı `movement_type` ile hedefle.
- **Eşzamanlılık testi `RefreshDatabase` ile yazılamaz** → `DatabaseTruncation`
  + ayrı PDO; `tearDown`'da `truncateDatabaseTables()`.
- **CI'da `public/build` yoktur** — `Tests` job'ı `npm run build` çalıştırır.

## Bilinen açık uç

Eski turlarda bir `--order-by=random` turunda tek test düşmüştü; bu turda iki
seed daha denendi (`1786963531`, `1786963555`) ve tekrar üretilemedi.
Görülürse seed ile kaydedilmeli.
