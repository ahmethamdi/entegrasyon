# Devir Notu — 19 Ağustos 2026 (Faz 3 · iki madde bitti)

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## BURADAN DEVAM ET

```bash
docker compose up -d
docker compose exec app php artisan test      # 539 yeşil olmalı
```

**Sıradaki iş: Faz 3 · madde 2 — `RequestResync` + T10 (§18 · P1).**

Bugün kodda HİÇ karşılığı yok; tek iz `ErrorClass.php:54`'teki sözleşme
notu: "`error_permanent → pending` geçişi `ListingResyncRequested` olayı
üretmek ZORUNDADIR (§9, Karar 18)". **T10 yazılmamış tek P0/P1 testidir.**

Yapılacak: `error_permanent` durumundaki listing'i `pending`'e döndüren
action + `ListingResyncRequested` olayı. Üç kurala dikkat:
(a) **`desired_version` ARTMAZ** — artarsa sürüm kapısı gönderimi eler ve
ürün sessizce hiç gitmez (ön koşul kapısında aynı tuzak yaşandı);
(b) eski hata metni TEMİZLENİR; (c) `error_permanent` mutabakatta asla
aday değildir (`CandidateSelector`) — satır ancak bu geçişle akışa girer,
yani bu action o kilidi açan tek yoldur.

**Panel butonu ERTELENİR** (çalışma sırası kararı) — action ve olay
çekirdek tarafında yazılır, ekran Faz 4'te.

Yazdıktan sonra **GERÇEK çalıştır** — bu projede her tur ölümcül hata
yeşil testlerin altından çıktı.

Panel işine GİRME (çalışma sırası kararı). Yeni pazaryerleri (Hepsiburada,
Amazon, Etsy, eBay) SIRAYA KONDU ama **Faz 3 + Faz 4 bitmeden
başlanmıyor** — ayrıntı aşağıda.

## Tek cümlede durum

**Faz 2 kapandı, Faz 3'te iki madde bitti:** sipariş güncelleme + kargo
(`UpdateOrderSnapshot` / `UpdateFulfillment`) ve **`PruneApiCalls`**.
**539 test yeşil** (1937 assertion), Pint temiz (277 dosya), iki random
seed'de stabil.

## Bu turda ne eklendi

### §13 · Faz 3 · api_calls saklama taraması (`a452a27`)

| Ne | Nerede |
|---|---|
| Tarama | `Channels/Support/PruneApiCalls.php` |
| Komut | `Channels/Console/PruneApiCallsCommand.php` (`api-calls:prune`) |
| Kayıt | `bootstrap/app.php` · Zamanlama `routes/console.php` (04:00) |
| Testler | `PruneApiCallsTest` (7) + `ScheduledScansTest`'e 3 iddia |

**KAPATILAN BOŞLUK:** `expires_at` ilk günden beri doldurulyordu (2xx
+7 gün, 4xx/5xx +90 gün) ama **silen hiçbir şey yoktu** — saklama
politikası yalnızca bir niyetti ve en çok yazılan tablo sınırsız
büyüyordu.

**ÖLÇÜT `expires_at`, DURUM KODU DEĞİL.** Saklama süresi satır
YAZILIRKEN kararlaşır ve o alanda donar; tarama yeniden yorumlasaydı
politika iki yerde yaşar ve geçmiş satırlar yazıldıkları günün kuralıyla
değil BUGÜNÜN kuralıyla silinirdi.

**SİLME PARTİLENİR** (varsayılan 5.000) — tek dev DELETE en çok yazılan
tabloyu dakikalarca kilitler. **TUR BAŞINA ÜST SINIR VAR** (500.000):
bitene kadar dönen tarama günlük bakım penceresini saatlerce tutar ve
`withoutOverlapping` yüzünden sonraki turlar hiç başlamaz — tarama kendi
kuyruğunu kilitler. Kalan satırlar YARIN silinir.

**TRANSACTION YOK ve bu bilinçli:** her parti kendi başına atomiktir ve
silinen günlük satırının geri alınmasına gerek yoktur. Turu sarmak,
silinen her satırın kilidini tur sonuna kadar tutar — tam olarak
kaçınılan şey.

**Zamanlama 04:00** — taksonomi 03:00'te bitiyor, ikisi aynı bakım
penceresinde üst üste binmiyor.

**Sekiz mutasyon: DÖRDÜ yakalandı, DÖRDÜ HAYATTA KALDI VE KALMALI.**
Yakalananlar: yüklem · partileme · zamanlama · artisan kaydı (son ikisi
AYRI koşullar ve AYRI testlerle yakalandı). Hayatta kalanlar ve gerekçe
(hepsi koda yazıldı, sahte test YAZILMADI):
- **`<` → `<=`** — `expires_at` hassasiyeti SIFIR (saniyeye yuvarlanır),
  `clock_timestamp()` mikrosaniye taşır: eşitlik ulaşılamaz. Boundary
  testi bu yüzden yeniden yazıldı — ilk hâli iki operatör altında da
  geçiyordu, yani **sahte yeşildi**.
- **`while` koşulu → `while (true)`** — `min()` clamp'i sınırı zaten
  uyguluyor (`LIMIT 0` → `$affected === 0` → break). Koşul boşa dönen o
  son sorguyu engellemek için duruyor.
- **`clock_timestamp()` → `now()`** — tur transaction DIŞINDA çalıştığı
  için ikisi bugün aynı. Kural, "TRANSACTION YOK" kararı bir gün geri
  alınırsa diye duruyor; donmanın gerçekliği PostgreSQL'de doğrulandı
  (`now()` dondu, `clock_timestamp()` ilerledi).
- **`runAsSystem` kaldırma** — `api_calls`'un MODELİ YOK, tablo
  `DB::table()` ile okunuyor ve global scope hiç uygulanmıyor.

**GERÇEK ÇALIŞTIRILDI:** dev veritabanında 48 satır → 3 süresi geçen
silindi, 43 mevcut + 2 canlı satır duruyor. `--chunk=2` ile partileme
gözlendi, ikinci tur `0` döndü (idempotent), `schedule:list` `0 4 * * *`
gösteriyor, log satırı yazıldı. Test satırları sonra silindi (dev DB
yine 43).

### Bir önceki tur — §13 · Faz 3 · sipariş güncelleme ve kargo (`ab4bffe`)

| Ne | Nerede |
|---|---|
| Anlık görüntü | `Orders/Actions/UpdateOrderSnapshot.php` |
| Kargo | `Orders/Actions/UpdateFulfillment.php` |
| Değer nesneleri | `Orders/Support/{OrderSnapshotEvent,FulfillmentEvent}.php` |
| Bağlama | `OrderEventRouter::{handleUpdated,handleFulfilled}` |
| Testler | `OrderSnapshotAndFulfillmentTest` (12) + dilim testine 1 |

**KAPATILAN BOŞLUK:** `OrderEventRouter`'ın `UPDATED` ve `FULFILLED`
dalları bugüne kadar **yalnızca log'luyordu**. Faz 2'de sipariş yoklaması
yazıldıktan sonra bu boşluk **CANLI** hale gelmişti: Trendyol siparişi
`Shipped`'a geçtiğinde olay inbox'a yazılıyor, işleniyor ve sessizce
düşüyordu — panel siparişi sonsuza kadar "Created" gösterirdi.

**İKİSİ DE STOK HAREKETİ ÜRETMEZ** (§4) — maddenin en önemli kuralı. Mal
SATIŞTA zaten düşülmüştür; hareket üretselerdi aynı satış iki kez
düşülür ve bakiye KALICI bozulurdu. Testler ledger'ı önce/sonra
karşılaştırarak koruyor. **Güncelleme kalemlere de dokunmaz** — kalem
değişikliği stok demektir.

**NULL "DEĞİŞMEDİ" DEMEKTİR, "BOŞALT" DEĞİL.** `delivered` olayı
`shipped_at` taşımaz; ezseydi kargoya veriliş anı KAYBOLURDU.

**Paket başına TEK satır, durum ilerler** (`(order_id, external_id)`
tekil); **çok paketli sipariş AYRI satırlar** taşır.

**Bayat tekrar yeni durumu EZMEZ** — idempotency kapısının asıl değeri
bu: yoklama örtüşmesi eski olayı tur tur yeniden görüyor ve kapı
olmasaydı araya giren `Delivered` her turda `Shipped`'a geri ezilirdi.

## Mutasyonla sınandı — altı mutasyon, ÜÇÜ HAYATTA KALDI

**Yakalananlar:** NULL'ın durumu ezmesi · kargoda NULL'ın ezmesi · her
olayın yeni paket satırı açması.

**İlk turda hayatta kalan idempotency kapısı GERÇEK TESTLE kapatıldı:**
mevcut testler doğal olarak idempotent bir senaryo kuruyordu (aynı durumu
iki kez yazmak). Kapının asıl değeri BAYAT TEKRARIN yeni durumu geri
almasını engellemek; o senaryo test edilmemişti. Test eklendi ve
mutasyonu gerçekten kırdığı doğrulandı.

**İKİSİ HAYATTA KALDI VE KALMALI — DÜRÜST SINIR:** router'ın `FULFILLED`
dalı ve paket bazlı olay çıpası. Sebep: **hiçbir normalizer `fulfilled`
tipi ÜRETMİYOR** — Woo kargoyu ayrı webhook göndermiyor, Trendyol'da
kargo §14 gereği KAPSAM DIŞI (`SupportsFulfillment` uygulanmaz). O olayı
üreten bir kaynak olmadığı için davranış testi YAZILAMAZ; sahte test
yazmak var olmayan bir akışı varmış gibi gösterirdi. Gerekçe
`UpdateFulfillment` başlığına yazıldı. **Kanal kargo bildirimi göndermeye
başlarsa ilk iş normalizer'a `fulfilled` tipini ve
`payload['fulfillment']` bloğunu eklemektir.**

**Router bağlantısı ayrıca sınandı:** `UPDATED` dalı eski ölü haline
(`=> null`) çevrildiğinde dilim testi kırmızıya döndü — yani eylem
sınıflarının kendi testleri yeşilken router onları hiç çağırmıyor olma
ihtimali kapatıldı.

### Bir önceki turlar

**Faz 2 · sipariş yoklaması (`0b2a328`):** `fetchOrders` +
`parseOrderEvent` + `PollChannelOrders` + `orders:poll` (5 dakikalık).
Olay kimliği `{siparişNo}:{durum}`; pencere geriye bakar; başarısız turda
imleç ilerlemez. Gerçek çalıştırmada `supports_webhooks` eager-load
hatası bulundu (kapı ölüydü).

**Faz 2 · stok/fiyat itme (`850f41d`):** tek uç nokta iki yetenek; kimlik
barkod ve `(int)`'e çevrilmez; `listPrice` zorunlu.

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 539 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
npm run build                                 # YERELDE (container'da Node yok)
```

Panel: `/` özet · `/products` ürünler · `/products/{id}/channels` kanala
gönderme · `/orders` siparişler · `/inventory` stok · `/channels` kanallar ·
`/mappings` eşleştirme

## ÇALIŞMA SIRASI KARARI — ÖNCE ÇEKİRDEK, PANEL SONA (18 Ağustos)

**Kullanıcının açık talimatı:** "front'una en son bakarız, bir her şeyi
bitirelim." Yeni sohbette **panel/görsel işlere girme**. Panel cilası zaten
§13 · Faz 4'te listeli. Karar hâlâ yürürlükte: Faz 2'nin altı maddesinde
de, Faz 3'ün ilk maddesinde de yeni ekran yazılmadı ve sıradaki Faz 3
maddelerinin hiçbiri panel işi içermiyor.

Bu, ekran işi ÇIKTIĞINDA tarayıcıda doğrulama kuralını iptal ETMEZ.

**Panelde bilerek ertelenenler:** mutabakat ekranı · `RequestResync` +
T10 · onay durumu için ayrı ekran.

## YOL HARİTASI — NE BİTTİ, NE KALDI (19 Ağustos 2026)

### Bitti

**Çekirdek:** stok ledger'ı (`ApplyMovement`, `LockInventoryRows`), outbox
relay + fan-out, adapter mimarisi (7 yetenek arayüzü), sipariş alımı,
gelen hat (webhook → inbox → router), giden hat (`InventoryBatchBuilder`,
`PushInventory`, `SyncResultRecorder`), koruma katmanı (`ChannelRateLimiter`,
`CircuitBreaker`), ürün aktarımı (`PushListing`, `PublishListing`),
§6 bütünlük taramaları (iki seviye), §10 mutabakat SICAK katmanı,
ön koşul kapısı + onay takibi, sipariş yoklaması, sipariş güncelleme +
kargo, **api_calls saklama taraması** (`PruneApiCalls`).

**Kanallar (2):** WooCommerce (tam) · Trendyol (taksonomi, katalog, onay,
stok/fiyat itme, sipariş yoklaması).

**Panel (8 ekran):** özet · ürünler · ürün oluştur/düzenle · ürün-kanal ·
siparişler · sipariş ayrıntısı · stok · kanallar · eşleştirme.

**Testler:** 539 yeşil (1937 assertion), 58 test dosyası. P0'dan
T1/T2/T3/T4/T5/T6/T7/T8/T9/T11/T12 yeşil. **T10 hâlâ yazılmamış** —
yazılmamış tek P0/P1 testi ve sıradaki maddenin parçası.

### Kaldı — FAZ 3 (güvenilirlik), sırayla

1. ~~**`PruneApiCalls`**~~ — **BİTTİ** (`a452a27`), ayrıntı yukarıda.
2. **`RequestResync` + T10** (§18 · P1) — `error_permanent → pending`,
   `ListingResyncRequested` olayı, `desired_version` ARTMAZ. **T10 yazılmamış
   tek P0/P1 testi.** Panel butonu ERTELENİR. **SIRADAKİ İŞ.**
3. **Fiyat senkron yolu** — `pushPrices` gövdeleri (Woo + Trendyol) HAZIR
   ama çağıranı YOK: `PushInventory`'nin fiyat karşılığı yazılmamış.
   Dokümanın Faz 3 listesinde ayrıca yok ama gerçek eksik.
4. **Ilık/soğuk mutabakat katmanları** — sıcak katman çalışıyor; §10 bütçe
   tablosundaki diğer iki katman yok.

**Trendyol'da kapsam dışı bırakılanlar** (eksik DEĞİL): `delist`,
`fetchListing`, `acknowledgeOrder` — kargo §14 gereği kapsam dışı.

### Kaldı — FAZ 4 (panel + abonelik)

- **Panel cilası** (§13 · Faz 4, 20 sa): boş durumlar, yükleniyor, mobil düzen.
- **Mutabakat panel ekranı** — `reconciliation_items` yazılıyor, gösterilmiyor.
- **Onay durumu için ayrı ekran** (rozet + red sebebi ürün-kanal ekranında var).
- **Abonelik/ödeme** (hafta 21–25): planlar, kota, iyzico. Şema kararı
  alınmış, YAZILMADI ve şimdi yazılmamalı — kota neyi sınırladığını
  senkron davranışından alır.

### KULLANICI KARARI — YENİ PAZARYERLERİ (19 Ağustos 2026)

**Kullanıcının açık talebi:** "sadece trendyol woocommerce shopify
istemiyorum; hepsiburada, amazon, ebay, etsy gibi platformlar da olsun —
ama önce bunları bitirelim, sadece sıraya koy."

**Bu maddeler FAZ 3 VE FAZ 4 BİTTİKTEN SONRA ele alınır. Şimdi
yazılmıyor.** Sıra (kullanıcı aksini söylemedikçe):

1. **Hepsiburada** — TR pazarı, Trendyol'a en yakın model (taksonomi +
   zorunlu öznitelik + onay süreci). Trendyol'un `ListingMapper` ·
   `TaxonomyClient` · `TrackApprovalStatus` kalıbı doğrudan örnek olur;
   en düşük riskli ikinci pazaryeri.
2. **Amazon (SP-API)** — en büyük iş değeri, en yüksek karmaşıklık: LWA
   OAuth + rate limit modeli farklı, feed tabanlı asenkron aktarım
   (`submitFeed` → `getFeedResult` yoklaması), FBA/FBM ayrımı. Muhtemelen
   §7'ye yeni bir yetenek arayüzü gerekir (feed durumu yoklama) — bu
   MİMARİ bir karardır ve dokümana bakılmadan yapılmaz.
3. **Etsy** — OAuth 2.0 + PKCE, taksonomi yerine "taxonomy_id" + shop
   section modeli; envanter uç noktası varyant bazlı ve Woo'dan farklı.
4. **eBay** — en farklı model: Inventory API (offer/inventory item ayrımı)
   + politika nesneleri (payment/return/fulfillment policy) bağlantı
   kurulumunda zorunlu. `channel_connections.settings` bunu taşıyabilir
   ama bağlama akışı ekstra adım ister.

**Shopify BU LİSTEDE DEĞİL** — kullanıcı açıkça istemedi. Memory'deki
"Teknoloji Kararları" notu "Laravel + Node Shopify app" diyor; o karar
artık geçerli değil ve Shopify kapsam dışıdır.

**MİMARİ SÖZ:** yeni kanal eklemek çekirdeği DEĞİŞTİRMEMELİ. Kanal başına
yazılması gereken şey bir adapter + (varsa) mapper/normalizer'dır; stok
matematiği, outbox, fan-out, kilit ve mutabakat aynı kalır. Yeni bir kanal
çekirdeğe dokunmayı gerektiriyorsa **önce dokümana bakılır** (§7 yetenek
arayüzleri), `if ($channel === '...')` YAZILMAZ. Amazon'un feed modeli bu
sözü en çok zorlayacak maddedir.

**Her yeni kanal için gereken asgari iş** (Trendyol turlarından ölçü):
istemci + kimlik + hız sınırı (≈8 sa) · taksonomi varsa (≈12 sa) ·
eşleştirme zaten YAZILDI ve kanaldan bağımsız · katalog aktarımı + onay
(≈16 sa) · stok/fiyat itme (≈8 sa) · sipariş yoklaması veya webhook
(≈16 sa). Yani kanal başına kabaca **40–60 saat**, Amazon'da daha fazla.

## Demo verisi panelde duruyor

`demo@entegrasyon.local` / `demo12345` — gezilebilir bir kiracı.
6 ürün, 2 kanal bağlantısı, `demo-v3` taksonomisi (4 yaprak) ve
**bilinçli olarak KISMİ** eşleştirmeler: `mutfak` eşleşmedi ·
`kadin-elbise` zorunlu öznitelik eksik (Renk) · `tisort` hazır.
`TSH-201` fazla satış taşıyor (bakiye −3).

Bu veri commit'lerde DEĞİL, yalnızca yerel veritabanında.

## Bu projede işe yarayan çalışma biçimi

1. **Testi önce yaz, kırmızı olduğunu gör**, sonra implementasyonu yaz.
2. **Mutasyonla sına** — ve **mutasyonun testi gerçekten KIRDIĞINI doğrula**.
   Python yamalarında `assert old in s` kullan.
3. **Mutasyon hayatta kalırsa SAHTE TEST YAZMA** — ya gerçek test bul, ya
   yapısal sınırı belgele.
4. **`Http::fake()` her adrese cevap verir** — "bozuk yapılandırma"
   senaryosu fake altında bozuk DEĞİLDİR. Gerçek hata kodu kullan.
5. **Kalıcılık sınarken Eloquent'e güvenme** — kimlik haritası aynı bellek
   nesnesini geri verir. **Ham satırı oku.**
6. **`DB::table()` yazdıysan kiracı filtresinin TESTİNİ de yaz.**
7. Stok yazan her testin sonunda `assertLedgerMatchesProjection()` çağır.
8. **Ekran işi bittiğinde TARAYICIDA çalıştır.**
9. **Kuyruk işi / komut yazdıysan GERÇEK ÇALIŞTIR.**
10. **Adapter yazdıysan BAĞLAM DIŞINDA çağırmayı da sına.**
11. **Testte "işi çalıştır" derken reflection'a sapma.**
12. **Adapter gövdesi yazdıysan ÇEKİRDEĞİN ONU SÜRDÜĞÜNÜ de sına** —
    adapter testi + sahte adapterlı çekirdek testi ikisi de yeşilken
    aradaki sözleşme yanlış olabilir (bu turda dikey dilim testi bu
    yüzden yazıldı).

## Mutasyonla / gerçek çalıştırmayla bulunan gerçek boşluklar (tarihçe)

Hepsi testler yeşilken bulundu:

- **`supports_webhooks` eager-load'da seçilmiyordu** — webhook kapısı ölüydü.
- **`pushPrices`'ın çekirdekte çağıranı yok** — Woo dahil.
- **Engellenen gönderim "zaten güncel" diyordu** (panelde).
- **Onay yanıtında olmayan satır "reddedildi" sayılabiliyordu** (mutasyon).
- **Tek bozuk bağlantı tüm kanalın taksonomisini durduruyordu.**
- **Başarısız yanıt sessizce boş kategori ağacı yazıyordu.**
- **Kimlik bilgisi bağlam dışında hiç gönderilmiyordu** — Woo dahil.
- **`DB::table()` kiracı filtresinin testi yoktu** — DÖRT ayrı turda.
- **Kalıcılık testi Eloquent kimlik haritası yüzünden sahte yeşildi.**
- **Kuyruk işi kiracı bağlamını kurmuyordu.**
- **`TenantAwareJob::$tenantId` readonly'di.**
- **Kanal ilişkisi eager-load edilmiyordu** (lazy loading kapalı).
- **Rozet delisted listing'leri sayıyordu.**
- **Eager-load'da `adapter_class` seçilmiyordu.**
- **Kurtarma taramaları zamanlayıcıya bağlanmamıştı.**
- **`ApplyMovement` outbox yüküne `origin_connection_id` yazmıyordu.**
- **`verifyWebhookSignature` kiracı bağlamı bekliyordu.**
- **Başarıda sürüm kapısı yoktu.**

## Davranışla sınanamayan kurallar (dürüst sınır)

Mutasyon hayatta kalır ve kalmalı; sahte test YAZILMADI:

- **Router'ın `FULFILLED` dalı ve kargo olay çıpası** — hiçbir normalizer
  `fulfilled` tipi ÜRETMİYOR (Woo ayrı webhook göndermiyor, Trendyol'da
  kargo §14 gereği kapsam dışı). O olayı üreten kaynak olmadan davranış
  testi yazılamaz.
- **`SyncTaxonomy` içindeki `runAsSystem`** — `ChannelCategory`
  `BelongsToTenant` kullanmıyor, global scope hiç uygulanmıyor.
- **`AdjustStock` içindeki `LockInventoryRows` çağrısı** — `ApplyMovement`'ın
  UPDATE'i aynı satır kilidini zaten koyuyor.
- **`published_at IS NOT NULL` yüklemi** — NULL karşılaştırması satırı zaten
  eler.
- **`hash_equals` → `===`** — zamanlama saldırısı işlevsel testte görünmez.
- **Adapter'a `max($q, 0)`** — `InventoryPushItem` negatifi kurucuda reddeder.
- **`ctype_digit` yerine yalnızca `(int)`, `"sınırsız"` girdisiyle** —
  `(int) "sınırsız"` zaten `0`. Kural `"600, 300"` ile sınanır.
- **`PruneApiCalls`'ta `expires_at < ` → `<=`** — kolon saniye
  hassasiyetli (`datetime_precision = 0`), `clock_timestamp()` mikrosaniye
  taşır; eşitlik ulaşılamaz.
- **`PruneApiCalls`'ta `while ($deleted < $maxRows)` → `while (true)`** —
  `min($chunkSize, $maxRows - $deleted)` clamp'i sınırı zaten uyguluyor;
  bütçe dolunca `LIMIT 0` hiçbir satır silmez ve döngü break'ten çıkar.
- **`PruneApiCalls`'ta `clock_timestamp()` → `now()`** — tur transaction
  DIŞINDA çalışıyor, ikisi bugün aynı. Kural "TRANSACTION YOK" kararı geri
  alınırsa diye duruyor.
- **`PruneApiCalls`'ta `runAsSystem` kaldırma** — `api_calls`'un modeli
  YOK, global scope hiç uygulanmıyor (`SyncTaxonomy` ile aynı biçim).

## Tekrar tekrar ısıran tuzaklar

- **`Http::fake()` her adrese cevap verir.**
- **İkinci `Http::fake()` ilkini DEĞİŞTİRMEZ** — `Http::sequence()` kullan.
- **Adapter bağlam DIŞINDA çağrılabilir** — kimlik `runAsSystem()` ile okunur.
- **`DB::table()` global scope'a TABİ DEĞİLDİR** — filtre VE testi yazılır.
- **Kalıcılık testinde Eloquent kimlik haritası yanıltır** — ham satır oku.
- **Rota model bağlaması kiracı bağlamından ÖNCE çalışır.**
- **ENUM'a cast edilen alan ekrana `->value` ile gider.**
- **Lazy loading KAPALI** — ilişki kullanacaksan eager-load et.
- **Kuyruk işi bağlamı KENDİ kurar** ve `finally` ile bırakır.
- **Ana sınıfta `readonly` promoted property + `SerializesModels` = ölüm.**
- **Statik fabrika ile örnek metodu AYNI ADI paylaşamaz** (PHP).
- **`inventory_movements` kolonu `type`, `movement_type` DEĞİL.**
- **`channel_connections` kolonu `label`, `name` DEĞİL.**
- **`api_calls` zaman kolonu `called_at`** — `created_at` YOK.
- **`RemoteListing` parametresi `url`**, `externalUrl` DEĞİL.
- **`ErrorClass` case'i `RATE_LIMITED`**, `RATE_LIMIT` DEĞİL.
- **`SyncOperationStatus`'ta `FAILED` YOK** — kalıcı hata `DEAD`.
- **`OpenSyncOperation` `Sync\Actions\` altında** (`Support\` değil) ve
  parametresi `eventVersion`; dönüşü NULLABLE.
- **`TenantContext` metodu `runFor()`**, `run()` DEĞİL.
- **`MissingTenantContextException` `Support\Tenancy\Exceptions\` altında.**
- **`assertLedgerMatchesProjection()` ÜÇ argüman alır** (tenant, depo,
  varyant).
- **`(channel_type_code, external_account_id)` GLOBAL tekildir.**
- **`clock_timestamp()`** — zaman damgaları saniye hassasiyetli.
- **`Command::run()` REZERVE İMZADIR.** Mantık `Support/` altında.
- **Domain komutları otomatik keşfedilmez** — `bootstrap/app.php`.
- **`QUEUE_CONNECTION=sync` gerçek worker'ı taklit etmez.**
- **Açılış stoğu ledger üzerinden girer** (IMPORT).
- **Eşzamanlılık testi `RefreshDatabase` ile yazılamaz** → `DatabaseTruncation`.
- **`StoreUrl` HTTPS'i zorunlu tutar** — yerel stub'a TLS eklenir.
- **CI'da `public/build` yoktur** — `Tests` job'ı `npm run build` çalıştırır.
- **CI'da `codeload` 429'u** — `--prefer-source` ile kaynak yoldan denenir.
- **Eager-load'da OKUNACAK HER ALAN AÇIKÇA SEÇİLMELİ.** `adapter_class`
  bir kez, `supports_webhooks` bir kez daha bu yüzden sessizce null okundu
  ve kapı hiç çalışmadı. `with('rel:a,b')` yazdıysan o metotta okunan
  alanların hepsi listede mi diye BAK.
- **`(channel_type_code, external_account_id)` GLOBAL tekil** — aynı test
  içinde iki kez `connection()` çağırmak kısıtı ihlal eder (bu turda
  yaşandı).
- **SINIR TESTİ YAZDIYSAN İKİ OPERATÖR ALTINDA DA GEÇMEDİĞİNİ DOĞRULA.**
  `PruneApiCalls`'ta "tam sınırdaki satır silinmez" testi yazıldı ve yeşil
  geçti — ama `<` → `<=` mutasyonu altında DA geçti, yani hiçbir şey
  sınamıyordu. Sebep: satır bir GÜN sonrasına kuruluydu, oysa iki
  operatörün farkı yalnızca TAM EŞİTLİKTE görünür. Gerçek sınırı kurmaya
  çalışınca kolonun saniye hassasiyeti çıktı ve eşitliğin ulaşılamaz
  olduğu anlaşıldı. **Sınır testi kurarken farkın göründüğü ÖLÇEĞİ kullan**
  (gün değil saniye) ve mutasyonla doğrula.
- **`api_calls`'un MODELİ YOK** — tablo `DB::table()` ile yazılıp okunuyor,
  `insertGetId()` bigserial döndürür.
- **`TrendyolAdapterTest`'teki "yazılmamış yetenek" listesi madde kapandıkça
  KÜÇÜLTÜLMELİ.** Önceki turda `pushInventory`/`pushPrices`, bu turda
  `fetchOrders`/`parseOrderEvent` çıkarıldı. **Listede kalan: `delist`,
  `fetchListing`** — ikisi de Faz 2 kapsamı dışı. `acknowledgeOrder`
  gövdesi hâlâ istisna atıyor ama o testin listesinde hiç olmadı
  (kargo §14'te kapsam dışı).

## Bilinen açık uçlar

**1 · CI'ın 429 düzeltmesinden sonraki durumu buradan görülemiyor.** `gh`
kimlik doğrulamalı değil (`gh auth status` → "not logged into any GitHub
hosts") ve bu turda da doğrulanamadı. `gh auth login` sonrası
`gh run list` ile bakılmalı. Düzeltmenin kendisi (`6e2217e`) yerinde.

**2 · `--order-by=random` düşüşü bu turda da tekrar üretilemedi.** İki tur
daha koşuldu, ikisi de yeşil (seed'ler: 1787087064 · 1787087092).
Toplamda **ON ÜÇ ardışık temiz tur** (beş oturum). Bu turun
seed'leri: 1787125986 · 1787126017. PHPUnit
11'de `--seed` seçeneği YOK; seed çıktının sonunda "Random Order Seed"
satırında raporlanır. Görülürse o satırdaki seed kaydedilmeli.

**3 · `pushPrices` çekirdekte çağrılmıyor** (yukarıda ayrıntısı). Adapter
gövdeleri hazır; eksik olan `PushPrices` işi ve fiyat operasyonu açan yol.

**4 · `acknowledgeOrder` yazılmadı ve "yazılmamış yetenek" listesinde de
YOK.** Sipariş onaylama Trendyol'da kargo akışının parçasıdır ve §14
kargoyu kapsam dışı bırakır (`SupportsFulfillment` UYGULANMAZ). Yani bu
bir eksik değil, bilinçli kapsam sınırı — ama `SupportsOrders` arayüzü
metodu ilan ettiği için gövde istisna fırlatıyor.
