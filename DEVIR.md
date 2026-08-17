# Devir Notu — 17 Ağustos 2026 (PushListing + mutabakat turu)

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## Tek cümlede durum

**Dikey dilim panelden uçtan uca sürülüyor ve §10 mutabakat sıcak katmanı
çalışıyor**: ürün panelde doğuyor, panelden kanala gidiyor, stok değişimi
arkasından ulaşıyor ve kanalda sürüklenme olursa mutabakat bulup düzeltiyor.
**355 test yeşil** (1465 assertion), Pint temiz, iki random seed'de stabil.

## Bu turda ne eklendi

### 1 · §13 · faz 1.5 — ürün aktarımı (commit `718fa3b`, push edildi)

| Ne | Nerede |
|---|---|
| İçerik gönderme işi | `app/Domain/Sync/Jobs/PushListing.php` |
| Panelden gönderme | `app/Domain/Sync/Actions/PublishListing.php` |
| İçerik parmak izi | `app/Domain/Sync/Support/ContentHasher.php` |
| Tekil yük üreticisi | `app/Domain/Sync/Support/ListingPayloadBuilder.php` |
| Controller + ekran | `ProductChannelController.php`, `Products/Channels.vue` |
| Testler | `PushListingTest` (13), `PublishListingScreenTest` (10), `PanelToChannelSliceTest` (3), `JobSerializationTest` (2) |

`OpenSyncOperation` artık `desired_hash` yazıyor (doküman §8'de vardı, kodda
yoktu); `SyncResultRecorder` başarıda `synced_hash`'i ondan **kopyalar**.

### 2 · §10 mutabakat — sıcak katman

| Ne | Nerede |
|---|---|
| Migration | `database/migrations/..._create_reconciliation_tables.php` |
| Modeller | `app/Domain/Reconciliation/Models/{ReconciliationRun,ReconciliationItem}.php` |
| Aday seçimi | `app/Domain/Reconciliation/Support/CandidateSelector.php` |
| Beş adımlı akış | `app/Domain/Reconciliation/Actions/ReconcileConnection.php` |
| Onarım | `app/Domain/Reconciliation/Actions/QueueRepair.php` |
| Süpürme + komut | `Support/ReconcileActiveConnections.php`, `Console/ReconcileHotCommand.php` |
| Testler | `ReconcileInventoryTest` (13) |

`reconcile:hot` `bootstrap/app.php` içinde kayıtlı ve `routes/console.php`
içinde 5 dakikaya zamanlı; `ScheduledScansTest` ikisini **ayrı** doğruluyor.

## GERÇEK WORKER'DA BULUNAN İKİ ÖLÜMCÜL HATA

İkisi de **340 test yeşilken** duruyordu. Test paketi göremezdi: testler iş
nesnesini doğrudan kurup `handle()` çağırıyor — Redis gidiş-dönüşü de
worker'ın bağlam temizliği de hiç yaşanmıyor.

### 1 · Kuyruk işi kiracı bağlamını kurmuyordu

`Queue::looping` her iş sınırında bağlamı temizler; `handle()` bağlamsız
başladı ve ilk tenant-scoped sorgu istisna fırlattı. `PushListing` **hiç
çalışmadı**. `PushInventory` fan-out'tan gelince şanslıydı ama **seviye 2
taramasından** (`runAsSystem`, bağlam YOK) gelince düşüyordu — yani kurtarma
mekanizması sessizce ölüydü.

→ İkisi de kiracı kimliğini yükte taşıyor, `handle()` başında kuruyor,
`finally` ile bırakıyor. `TenantAwareJob` genişletilemedi: `handle()` `final`
ve parametresiz, bu işler bağımlılıklarını enjeksiyonla alıyor.

### 2 · `TenantAwareJob::$tenantId` readonly'di, iş kuyruktan OKUNAMIYORDU

```
Cannot initialize readonly property TenantAwareJob::$tenantId
from scope ConsumeOutboxEvent
```

`SerializesModels::__unserialize()` özellikleri **alt sınıfın kapsamından**
yeniden atar; PHP ana sınıfta tanımlı readonly özelliğin alt sınıf
kapsamından ilklenmesine izin vermez. Gerçek worker'da **her outbox olayı**
düşüyordu. **Bu hata bu turdan ÖNCE de vardı** — gerçek kuyruk ilk kez
çalıştırıldığı için ancak şimdi göründü.

→ `public string $tenantId`. `public protected(set)` daha iyi olurdu ama
**PHP 8.4** özelliği ve proje 8.3'e kilitli.

→ `JobSerializationTest` kuyruğa giren her işi serileştirip geri okuyor.
**Yeni bir kuyruk işi eklendiğinde oraya da eklenmeli.**

## Tarayıcıda + gerçek kuyrukta doğrulandı

Sahte bir HTTPS Woo mağazası (`wc/v3` stub) ayağa kaldırıldı ve konteynerin
CA deposuna geçici sertifika kondu — **`StoreUrl` HTTPS'i zorunlu tutuyor,
kural gevşetilmedi, stub'a TLS eklendi.** Sertifika sonradan silindi.

**Ürün aktarımı:** kayıt → ürün ekle → kanal bağla (sağlık GEÇTİ, `active`)
→ "Kanala gönder" → `queue:work` → Woo'ya `GET products?sku=` (kopya
koruması) + `POST products` (`manage_stock: true`) → panelde **SENKRON**,
`external_id 4242`, `lifecycle live`. Stok düzeltmesi arkasından
`POST products/batch` ile ulaştı.

**Mutabakat:** stub kanalda 99 döndürecek şekilde ayarlandı, kanonik 17'ydi.
`php artisan reconcile:hot` → `GET products?include=4242` (toplu okuma) →
`stale_sync` adayı, `REPAIR_QUEUED`, `drift_magnitude 82` → `REPAIR`
operasyonu (`inv:{listing}:4:repair:{item}`) → worker → `POST products/batch`
ile kanala **doğru değer 17** gitti. `remote_hash` ve `last_observed_at`
damgalandı, satır `synced`.

**Sağlıksız kanal doğrulaması:** çözülemeyen alan adına bağlanan mağaza
`pending` kaldı ve gönderme listesinde **hiç görünmedi**.

Konsol hatası yok. Ekran görüntüleri scratchpad'de.

## Mutasyonla sınandı — on altı mutasyon, on altısı da yakalandı

**Ürün aktarımı (9):** `findExistingListing` kapısı · kimliğin başarıdan önce
yazılması · `synced_hash` yazımı · hash'in sürüme bağlanması · `desired_hash`
yazımı · bağlantıda kiracı scope'u · sağlıksız bağlantının kabulü · katalog
yeteneği filtresi · listing'in `live` doğması.

**Mutabakat (7):** ham kanonik değerle karşılaştırma (sonsuz onarım döngüsü) ·
`error_permanent` elemesinin kaldırılması · aday sorgusunda kiracı filtresi ·
bağlantı filtresi · `REPAIR` → `NORMAL_SYNC` · `REMOTE_MISSING`'in sürüklenme
sayılması · `REMOTE_UNREACHABLE`'ın sürüklenme sayılması. Ayrıca zamanlama ve
komut kaydı **ayrı ayrı** mutasyonla doğrulandı.

**Bir uyarı:** bağlantı filtresi mutasyonunda ilk denemede "hayatta kaldı"
sandım; gerçekte `python` yama metnim eşleşmemişti ve **mutasyon hiç
uygulanmamıştı**. Doğru uygulanınca yakalandı. **Mutasyonun gerçekten
uygulandığını doğrula** — yoksa yanlış "dürüst sınır" kaydedersin.

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 355 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
npm run build                                 # YERELDE (container'da Node yok)
```

Panel: `/` özet · `/products` ürünler · `/products/{id}/channels` kanala
gönderme · `/inventory` stok · `/channels` kanallar

## Sıradaki adım — SEÇİM SENİN

1. **Sipariş listesi ekranı** (§13 · faz 1.6 panel maddesi) — "panelde sipariş
   listesi ve fazla satış uyarısı". Sipariş alımı çalışıyor ama panelde
   görünmüyor; kullanıcının siparişi göreceği tek yer yok. Panelin en büyük
   boşluğu bu.
2. **Mutabakat panel ekranı** — `reconciliation_runs` / `reconciliation_items`
   yazılıyor ama hiçbir yerde gösterilmiyor. Sürüklenme bulunduğunu kullanıcı
   göremiyor; `recon_items_drift_idx` tam bu sorgu için var.
3. **Faz 2 · `TrendyolAdapter`** — ikinci kanal. Adapter mimarisi bunun için
   kuruldu; `SupportsCatalog`/`SupportsInventory` sözleşmeleri hazır ve
   `ProgrammableCatalogAdapter` ikinci kanalın nasıl sınanacağını gösteriyor.
4. **Ilık/soğuk mutabakat katmanları** (§10) — sıcak katman yazıldı; ılık
   (saatlik, 300 listing) ve soğuk (günlük, %2 örneklem) aynı
   `ReconcileConnection`'ı farklı bütçeyle çağırır, aday sorgusu genişler.

**Abonelik/ödeme Faz 4'tür** (hafta 21–25) — şimdi yazılmamalı. Gerekçe: kota
neyi sınırladığını senkron davranışından alır. Sıra: Faz 1 bitti → Faz 2
Trendyol → Faz 3 güvenilirlik → Faz 4 abonelik → Faz 5 tampon.

## Bu projede işe yarayan çalışma biçimi

1. **Testi önce yaz, kırmızı olduğunu gör**, sonra implementasyonu yaz.
2. **Mutasyonla sına** — ve **mutasyonun gerçekten uygulandığını doğrula**.
3. **Mutasyon hayatta kalırsa SAHTE TEST YAZMA** — ya gerçek test bul, ya
   yapısal sınırı belgele.
4. **`DB::table()` yazdıysan kiracı filtresinin TESTİNİ de yaz.**
5. Stok yazan her testin sonunda `assertLedgerMatchesProjection()` çağır.
6. **Entegrasyonu ayrıca sına** — sınıfın var olması onu kimsenin çağırdığı
   anlamına gelmez. Zamanlama ve komut kaydı **iki ayrı koşuldur**.
7. **Ekran işi bittiğinde TARAYICIDA çalıştır.**
8. **Kuyruk işi yazdıysan GERÇEK WORKER'DA çalıştır**
   (`queue:work --stop-when-empty`). `Queue::fake()` ve doğrudan `handle()`
   çağrısı ne serileştirmeyi ne bağlam temizliğini sınar.

## Mutasyonla / gerçek çalıştırmayla bulunan gerçek boşluklar (tarihçe)

Hepsi testler yeşilken bulundu:

- **Kuyruk işi kiracı bağlamını kurmuyordu** — `PushListing` hiç çalışmadı,
  `PushInventory` seviye 2 taramasından geldiğinde düşüyordu.
- **`TenantAwareJob::$tenantId` readonly'di** — her outbox olayı gerçek
  worker'da deserialize edilemiyordu.
- **`DB::table()` kiracı filtresinin testi yoktu** — İKİ ayrı turda.
- **Rozet delisted listing'leri sayıyordu.**
- **Eager-load'da `adapter_class` seçilmiyordu.**
- **Kurtarma taramaları zamanlayıcıya bağlanmamıştı.**
- **`ApplyMovement` outbox yüküne `origin_connection_id` yazmıyordu.**
- **`verifyWebhookSignature` kiracı bağlamı bekliyordu.**
- **Başarıda sürüm kapısı yoktu.**
- **Bağlantı filtresi testi aslında tenant scope'u sınıyordu.**

## Davranışla sınanamayan kurallar (dürüst sınır)

Mutasyon hayatta kalır ve kalmalı; sahte test YAZILMADI:

- **`AdjustStock` içindeki `LockInventoryRows` çağrısı** — `ApplyMovement`'ın
  UPDATE'i aynı satır kilidini zaten koyuyor.
- **`published_at IS NOT NULL` yüklemi** — NULL karşılaştırması satırı zaten
  eler.
- **`hash_equals` → `===`** — zamanlama saldırısı işlevsel testte görünmez.
- **Adapter'a `max($q, 0)`** — `InventoryPushItem` negatifi kurucuda reddeder.

## Tekrar tekrar ısıran tuzaklar

- **Kuyruk işi bağlamı KENDİ kurar** ve `finally` ile bırakır.
- **Ana sınıfta `readonly` promoted property + `SerializesModels` = ölüm.**
- **`DB::table()` global scope'a TABİ DEĞİLDİR** — filtre VE testi yazılır.
- **`inventory_movements` kolonu `type`, `movement_type` DEĞİL.**
- **`clock_timestamp()`** — zaman damgaları saniye hassasiyetli.
- **`Command::run()` REZERVE İMZADIR.** Mantık `Support/` altında.
- **Domain komutları otomatik keşfedilmez** — `bootstrap/app.php`.
- **`QUEUE_CONNECTION=sync` gerçek worker'ı taklit etmez.**
- **Açılış stoğu ledger üzerinden girer** (IMPORT).
- **`sync_operations.intent` ENUM'a cast edilir** — testte `->value` ile
  karşılaştırma başarısız olur.
- **Eşzamanlılık testi `RefreshDatabase` ile yazılamaz** → `DatabaseTruncation`.
- **`StoreUrl` HTTPS'i zorunlu tutar** — yerel stub'a TLS eklenir, kural
  gevşetilmez.
- **Tarayıcı testinde `waitForURL` kullan**, `waitForLoadState` değil.
- **CI'da `public/build` yoktur** — `Tests` job'ı `npm run build` çalıştırır.

## Bilinen açık uç

Eski turlarda bir `--order-by=random` turunda tek test düşmüştü; son altı
turda on bir seed denendi ve tekrar üretilemedi. Görülürse seed ile
kaydedilmeli.
