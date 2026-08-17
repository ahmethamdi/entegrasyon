# Devir Notu — 17 Ağustos 2026 (ürün/stok ekranı turu)

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## Tek cümlede durum

Satıcının her gün bakacağı ekran ayakta: fazla satış kırpılmadan görünüyor,
eksik miktar yazıyor ve **panelden düzeltilebiliyor** — düzeltme ledger'a
`MANUAL_ADJUSTMENT` olarak işleniyor ve outbox olayı üretiyor. Önceki iki
turda §6 taramaları ve kanal bağlama akışı kapanmıştı. **293 test yeşil**
(1165 assertion), Pint temiz, iki random seed'de stabil.

## Bu sohbette ne yapıldı

§13 · faz 1.2 panel maddesi + §17 · P0 "Fazla satış ekranı — eksik miktar ve
DÜZELTME YOLU gösterilmeli".

| Ne | Nerede |
|---|---|
| Düzeltme action'ı | `app/Domain/Inventory/Actions/AdjustStock.php` |
| Ekran + düzeltme controller'ı | `app/Http/Controllers/InventoryController.php` |
| Ekran | `resources/js/Pages/Inventory/Index.vue` |
| Gezinme (3 ekran) | `resources/js/Layouts/PanelLayout.vue` |
| Rotalar | `routes/web.php` |
| Ekran testleri | `tests/Feature/Inventory/InventoryScreenTest.php` (13) |
| Düzeltme testleri | `tests/Feature/Inventory/AdjustStockTest.php` (8) |
| Eşzamanlılık | `tests/Feature/Inventory/ConcurrentAdjustStockTest.php` (3) |

24 yeni test (269 → 293).

## Ekranın özü

```
Üst özet: varyant sayısı · fazla satılan · TOPLAM EKSİK ADET
  (fazla satış varsa kırmızı; SUM(-available), §10 metrik tanımıyla aynı)

Liste: SKU · elde · rezerve · SATILABILIR · senkron rozeti · [Düzelt]
  negatif available KIRPILMADAN, altında "N adet eksik"
  sıralama: available ARTAN → eylem gereken satır en üstte

Rozet sırası: HATA > YENİDEN DENENİYOR > BEKLİYOR > SENKRON > LİSTELENMEDİ

[Düzelt] → satır içi form, eksik miktar HAZIR GELİR
  → AdjustStock → LockInventoryRows + ApplyMovement(MANUAL_ADJUSTMENT)
  → outbox olayı → kanala geri yazılır
```

**Düzeltme de ledger üzerinden geçer.** Panel `inventory_levels` satırını
doğrudan güncellemez; `on_hand = Σ on_hand_delta` korunur ve düzeltmeyi kimin
ne zaman hangi notla yaptığı ledger'da kalır.

## Tarayıcıda doğrulandı

Fazla satılan varyant (`KAZAK-SIYAH-M`, bakiye −3) ile:

- özet kırmızı: "Fazla satılan 1 · Toplam eksik adet 3"
- satırda `-3` kırmızı, altında "3 adet eksik"
- [Düzelt] açıldı → **miktar 3 olarak hazır geldi** (en olası eylem)
- kaydedildi → bakiye 0, özet sıfırlandı, flash mesajı göründü
- konsol hatası yok

## Mutasyonla bulunan İKİ GERÇEK BOŞLUK

**1. `DB::table()` Eloquent global scope'una TABİ DEĞİL.**
Rozet sayıları ham sorguyla toplanıyor. `listings.tenant_id` filtresi
kaldırıldığında **hiçbir test kırılmadı** — çapraz kiracı sayımı sessizce
sızıyordu. Testin kurgusu bunu görünür kılmak için özel: B kiracısı A'nın
varyant kimliğine listing açıyor (FK kiracı sınırını zorlamıyor).
→ `sync_counts_never_include_another_tenants_listings`

**2. Rozet delisted satırları sayıyordu.**
`lifecycle_status = 'live'` filtresi kaldırıldığında test kırılmadı; tüm
testler yalnızca canlı listing yaratıyordu. Taslak/delisted satıra stok
gönderilmez (fan-out `live()` kullanır), sayılırsa rozet asla temizlenmez.
→ `sync_counts_ignore_delisted_listings`

## Öldürülen diğer mutasyonlar

| Mutasyon | Öldüren test |
|---|---|
| Panelde `max($available, 0)` (P0 ihlali) | `oversold_variant_is_shown_unclamped_with_shortfall` |
| Rozet sırası: bekliyor kalıcı hatadan önce | `permanent_error_outranks_pending_in_the_badge` |
| Düzeltme projeksiyona doğrudan yazıyor | 6 test (`assertLedgerMatchesProjection` dahil) |

## SAHTE TEST YAZMAKTAN DÖNÜLDÜ — okunması gereken bölüm

İlk yazdığım `adjustment_locks_the_row_before_writing` testi **sahteydi**.
Varsayımım "kilit alınmazsa `ApplyMovement` istisna atar" idi; mutasyonla
kilidi sildim ve **tüm testler yeşil kaldı**.

Sonra bir eşzamanlılık testi yazdım (`DatabaseTruncation` + ayrı PDO +
`lock_timeout`) ve **o da kilit silinmişken yeşil kaldı**. Sebebi araştırdım:

> `ApplyMovement` zaten `UPDATE inventory_levels` yapıyor ve PostgreSQL o
> satıra commit'e kadar tutulan bir satır kilidi koyuyor. İkinci bağlantının
> `FOR UPDATE`'i o UPDATE kilidinde bloklanıyor — `LockInventoryRows` hiç
> çağrılmasa bile. **Tek-SKU yolunda açık kilidin gözlenebilir etkisi YOK.**

Yani bu üçüncü kategori: yapısal sınır. Çağrı yine de KALIYOR ve gerekçesi
eşzamanlılık değil **sıralama**: düzeltme sipariş alımıyla aynı satırlara
yazar, çok-SKU yolları kilidi `ORDER BY variant_id` ile alır ve aynı kapıdan
geçmeyen bir yazıcı çok kalemli bir iadeyle ters sırada kilitlenip ABBA
deadlock üretir. Kuralı koruyan şey test değil, tüm yazma yollarının aynı
action'ı kullanması disiplini.

Testler artık kilidin varlığını değil GÖZLENEBİLİR olanı doğruluyor:
commit'ten önce ikinci yazıcının giremediğini, commit sonrası okuyucunun
bayat bakiye görmediğini ve ledger toplamının projeksiyona eşit kaldığını.
Sınıf başlığında bu sınır açıkça yazılı.

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 293 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
npm run build                                 # YERELDE (container'da Node yok)
```

Panel: `/` özet · `/inventory` stok · `/channels` kanallar (gezinme ayakta)

## Sıradaki adım — SEÇİM SENİN

1. **`PushListing` işi + panelden gönderme akışı** (§13 · faz 1.5) — açık
   kalan tek uç. Ürün panelde görünüyor, kanal bağlanıyor, ama ürünü kanala
   gönderen iş yok: `listings` satırı hâlâ elle yaratılıyor. Bu bittiğinde
   dikey dilim panelden uçtan uca sürülebilir hale gelir.
   *Doküman sırasına en uygun ve en yüksek değer.*
2. **§10 mutabakat** — sürüklenme tespiti; `clock_timestamp()` kuralına tabi,
   karşılaştırma `max(available, 0)` ile. Kurtarma mekanizması.
3. **Sipariş listesi ekranı** (§13 · faz 1.6 panel maddesi) — "panelde
   sipariş listesi ve fazla satış uyarısı".

## Bu projede işe yarayan çalışma biçimi

1. **Testi önce yaz, kırmızı olduğunu gör**, sonra implementasyonu yaz.
2. **Mutasyonla sına**: kodu kasten boz, testin kırmızıya döndüğünü doğrula.
   Bu turda iki gerçek boşluk böyle bulundu.
3. **Mutasyon hayatta kalırsa SAHTE TEST YAZMA.** Ya gerçek bir test bul, ya
   yapısal sınırı belgele. Bu turda ikinci eşzamanlılık testi de yeşil
   kalınca sebebi araştırıldı ve sınır yazıldı — test uydurulmadı.
4. Stok yazan her testin sonunda `assertLedgerMatchesProjection()` çağır.
5. **Entegrasyonu ayrıca sına** — sınıfın var olması onu kimsenin çağırdığı
   anlamına gelmez (`ScheduledScansTest`).
6. **Ekran işi bittiğinde TARAYICIDA çalıştır.** Kanal turunda iki bulgu
   yalnızca orada göründü.

## Mutasyonla bulunan gerçek boşluklar (tarihçe)

Hepsi testler yeşilken bulundu:

- **`DB::table()` sorgusunda kiracı filtresi yoktu** (bu tur) — rozet
  sayıları çapraz kiracı sızdırıyordu.
- **Rozet delisted listing'leri sayıyordu** (bu tur).
- **Eager-load'da `adapter_class` seçilmiyordu** — panel yetenekleri sessizce
  boşalıyordu ve `catch` sebebi gizliyordu.
- **Kurtarma taramaları zamanlayıcıya bağlanmamıştı** — `inbox:recover` bir
  tur boyunca hiç çalışmadı.
- **`ApplyMovement` outbox yüküne `origin_connection_id` yazmıyordu.**
- **`verifyWebhookSignature` kiracı bağlamı bekliyordu.**
- **Başarıda sürüm kapısı yoktu.**
- **Bağlantı filtresi testi aslında tenant scope'u sınıyordu.**

## Davranışla sınanamayan kurallar (dürüst sınır)

Mutasyon hayatta kalır ve kalmalı; sahte test YAZILMADI:

- **`AdjustStock` içindeki `LockInventoryRows` çağrısı** (bu tur):
  `ApplyMovement`'ın UPDATE'i aynı satır kilidini zaten koyuyor. Çağrı,
  çok-SKU yollarıyla kilit SIRALAMASINI paylaşmak için duruyor.
- **`published_at IS NOT NULL` yüklemi**: NULL karşılaştırması satırı zaten
  eler. Doküman ve `outbox_unconsumed_idx` eşleşmesi için tutuluyor.
- **`hash_equals` → `===`**: zamanlama saldırısı işlevsel testte görünmez.
- **Adapter'a `max($q, 0)`**: `InventoryPushItem` negatifi kurucuda reddeder.
- **`regenerate()` çağrısı**: `SessionGuard::login()` zaten çağırıyor; ikinci
  çağrı **kaldırıldı**.

## Tekrar tekrar ısıran tuzaklar

- **`DB::table()` global scope'a TABİ DEĞİLDİR** — ham sorguda kiracı filtresi
  açıkça yazılır. (Bu tur bulundu.)
- **`inventory_movements` kolonu `type`, `movement_type` DEĞİL.** Testte
  yanlış yazınca "Undefined column" alırsın.
- **`clock_timestamp()`** — zaman damgaları saniye hassasiyetli, `now()`
  transaction başında donar.
- **`Command::run()` REZERVE İMZADIR.** Mantık `Support/` altında sade
  sınıfta, komut ince kabuk.
- **Domain komutları otomatik keşfedilmez** — `bootstrap/app.php` →
  `withCommands()`.
- **`QUEUE_CONNECTION=sync` gerçek worker'ı taklit etmez.** Stok yazan ekran
  testleri de `Queue::fake()` çağırır: hareket outbox olayı yazar, relay
  tüketiciyi derhal çalıştırır ve kuyruk kancaları bağlamı temizler.
- **Açılış stoğu ledger üzerinden girer** (IMPORT) — `InventoryLevel::create`
  ile seed etmek eşitliği bozar.
- **Eşzamanlılık testi `RefreshDatabase` ile yazılamaz** → `DatabaseTruncation`
  + ayrı PDO; `tearDown`'da `truncateDatabaseTables()`.
- **Tarayıcı testinde `networkidle` yetmeyebilir** — var olmayan alan adının
  DNS denemesi uzun sürer; `waitForURL(..., { timeout: 90000 })` kullan.
- **CI'da `public/build` yoktur** — `Tests` job'ı `npm run build` çalıştırır.

## Bilinen açık uç

Eski turlarda bir `--order-by=random` turunda tek test düşmüştü; son üç turda
beş seed denendi ve tekrar üretilemedi. Görülürse seed ile kaydedilmeli.
