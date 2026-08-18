# Devir Notu — 18 Ağustos 2026 (Faz 3 · sipariş güncelleme ve kargo)

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## Tek cümlede durum

**Faz 2 kapandı, Faz 3 başladı.** İlk Faz 3 maddesi bitti:
`UpdateOrderSnapshot` + `UpdateFulfillment` yazıldı ve
`OrderEventRouter`'a bağlandı. **532 test yeşil** (1919 assertion), Pint
temiz, iki random seed'de stabil.

## Bu turda ne eklendi

### §13 · Faz 3 · sipariş güncelleme ve kargo (`ab4bffe`)

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
docker compose exec app php artisan test      # 532 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
npm run build                                 # YERELDE (container'da Node yok)
```

Panel: `/` özet · `/products` ürünler · `/products/{id}/channels` kanala
gönderme · `/orders` siparişler · `/inventory` stok · `/channels` kanallar ·
`/mappings` eşleştirme

## ÇALIŞMA SIRASI KARARI — ÖNCE ÇEKİRDEK, PANEL SONA (18 Ağustos)

**Kullanıcının açık talimatı:** "front'una en son bakarız, bir her şeyi
bitirelim." Yeni sohbette **panel/görsel işlere girme**. Panel cilası zaten
§13 · Faz 4'te listeli. Bu turda da yeni ekran yazılmadı — Faz 2'nin
altı maddesinin hiçbiri panel işi içermedi.

Bu, ekran işi ÇIKTIĞINDA tarayıcıda doğrulama kuralını iptal ETMEZ.

**Panelde bilerek ertelenenler:** mutabakat ekranı · `RequestResync` +
T10 · onay durumu için ayrı ekran.

## Sıradaki adım — FAZ 3 SÜRÜYOR

Faz 3'ün ilk maddesi (`UpdateOrderSnapshot` + `UpdateFulfillment`)
kapandı. Kalanlar, hiçbirinde panel işi yok:

1. **`PruneApiCalls`** (en küçük, en düşük risk) — `api_calls` her çağrıda
   yazılıyor ve `expires_at` DOLDURULUYOR (2xx +7 gün, 4xx/5xx +90 gün)
   ama **SİLEN yok**: tablo sınırsız büyüyor. Günlük bir komut + zamanlama
   yeterli. `bootstrap/app.php` kaydı + `routes/console.php` zamanlaması
   AYRI koşullar, `ScheduledScansTest` ikisini de doğrulasın.
2. **`RequestResync` + T10** (§18 · P1) — `error_permanent → pending`
   geçişi ve `ListingResyncRequested` olayı. `desired_version`
   ARTIRILMAZ. Panel butonu ERTELENİR (çalışma sırası kararı).
3. **Ilık/soğuk mutabakat katmanları** — sıcak katman yazıldı ve
   çalışıyor; §10 bütçe tablosundaki diğer iki katman yok.

**Çekirdekte kalan bilinen boşluk: fiyat senkron yolu.** `pushPrices`
gövdeleri (Woo + Trendyol) hazır ama çağıranı yok — `PushInventory`'nin
fiyat karşılığı yazılmamış. Dokümanın Faz 3 listesinde ayrıca yer
almıyor ama gerçek bir eksik.

**Abonelik/ödeme Faz 4'tür** (hafta 21–25) — şimdi yazılmamalı.

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
Toplamda **ON BİR ardışık temiz tur** (dört oturum). PHPUnit
11'de `--seed` seçeneği YOK; seed çıktının sonunda "Random Order Seed"
satırında raporlanır. Görülürse o satırdaki seed kaydedilmeli.

**3 · `pushPrices` çekirdekte çağrılmıyor** (yukarıda ayrıntısı). Adapter
gövdeleri hazır; eksik olan `PushPrices` işi ve fiyat operasyonu açan yol.

**4 · `acknowledgeOrder` yazılmadı ve "yazılmamış yetenek" listesinde de
YOK.** Sipariş onaylama Trendyol'da kargo akışının parçasıdır ve §14
kargoyu kapsam dışı bırakır (`SupportsFulfillment` UYGULANMAZ). Yani bu
bir eksik değil, bilinçli kapsam sınırı — ama `SupportsOrders` arayüzü
metodu ilan ettiği için gövde istisna fırlatıyor.
