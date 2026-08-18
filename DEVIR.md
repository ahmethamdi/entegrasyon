# Devir Notu — 18 Ağustos 2026 (Faz 2 · sipariş yoklaması · FAZ 2 KAPANDI)

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## Tek cümlede durum

**FAZ 2 KAPANDI — altı maddenin altısı da bitti.** Trendyol bağlanıyor,
taksonomi çekiliyor, eşleştirme panelden yapılıyor, ürün ön koşul
kapısından geçerek aktarılıyor, onay takip ediliyor, stok/fiyat kanala
itiliyor ve **sipariş yoklamayla alınıp stoğu düşürüyor**.
**519 test yeşil** (1884 assertion), Pint temiz, üç random seed'de stabil.

**Faz 2 demosu uçtan uca çalışıyor:** yoklanan Trendyol siparişi stoğu
10→7 düşürüyor, iptal 10'a geri alıyor.

## Bu turda ne eklendi

### §13 · Faz 2 · sipariş yoklaması (`0b2a328`) — SON MADDE

"Sipariş yoklaması — 22 sa". **Panel işi yoktu ve yapılmadı.**

| Ne | Nerede |
|---|---|
| Çekme + normalizasyon | `TrendyolAdapter::{fetchOrders,parseOrderEvent}` |
| Tur | `Orders/Support/PollChannelOrders.php` |
| Komut | `Orders/Console/PollChannelOrdersCommand.php` (`orders:poll`) |
| Zamanlama | `routes/console.php` — **5 dakika** |
| Testler | `TrendyolOrderPollingTest` (11) · `PollChannelOrdersTest` (11) · `TrendyolOrderSliceTest` (4) |

**Çekirdeğe hiç dokunulmadı**: `IngestInboxMessage` → `ProcessInboxMessage`
→ `OrderEventRouter` zinciri Woo için zaten çalışıyordu ve yoklama aynı
hatta yazıyor.

**OLAY KİMLİĞİ SİPARİŞ NUMARASI + DURUMDUR** (`{orderNumber}:{status}`).
Bu turun en kritik kararı: kimlik yalnızca numaraya bağlansaydı aynı
siparişin sonraki İPTALİ birincil tekillik indeksine takılır ve
`insertOrIgnore` tarafından **SESSİZCE YUTULURDU** — stok geri eklenmez,
bakiye kalıcı eksik kalırdı. Karar 24'ün açıkça uyardığı hata biçimi.
Kullanıcıya soruldu ve onaylandı.

**PENCERE GERİYE BAKAR** (5 dk örtüşme), imleç turun **BAŞLAMA** anına
yazılır. Bitiş anı yazılsaydı istek sürerken oluşan sipariş iki
pencerenin arasına düşer ve hiç görülmezdi. **Başarısız turda imleç
İLERLEMEZ**; **tek bozuk bağlantı turu durdurmaz**.

**BİLİNMEYEN DURUM `updated`**: `created` var olan siparişi yeniden
yaratmayı denerdi, `cancelled` satılmış stoğu geri eklerdi.

**Frekans 5 dakika** (kullanıcıya soruldu): `reconcile:hot` ile aynı
ritim. Dakikalık kotayı 5 katına çıkarır ve düşük seviyeli satıcıyı
429'a sokardı.

## GERÇEK ÇALIŞTIRMADA BULUNAN HATA — webhook kapısı ölüydü

`orders:poll` gerçekten çalıştırıldı (exit 0, `schedule:list` doğrulandı)
ve Woo bağlantısına hiç dokunmadığı görüldü. Bunu teste dökünce **test
KIRMIZI döndü**: `supports_webhooks` eager-load'da SEÇİLMİYORDU
(`with('channelType:code,name,adapter_class')`), kapı null okuyup **hiç
çalışmıyordu**. Woo yalnızca `SupportsOrders` taşımadığı için atlanıyordu
— yani doğru davranış YANLIŞ sebepten geliyordu; `SupportsOrders`
uygulayan webhook'lu bir kanal her turda boşuna yoklanırdı.
**`adapter_class` ile birebir aynı tuzak** (bu ikinci kez).

Düzeltildi, testi yazıldı ve mutasyonla korunduğu doğrulandı.

## Mutasyonla sınandı — on mutasyon, ONU DA YAKALANDI

Kimlikten durumun çıkarılması · pencere örtüşmesinin kalkması ·
başarısız turda imlecin ilerlemesi · `wasRecentlyCreated` kontrolünün
kalkması · bozuk bağlantının turu durdurması · tek sayfayla yetinilmesi ·
sağlıksız bağlantı filtresinin kalkması · saniye/milisaniye · bilinmeyen
durumun `created` sayılması · okumada `throw()`'un kalkması.

**BİRİ İLK TURDA HAYATTA KALDI:** pencere örtüşmesi. Sebep testin
zayıflığıydı — imleç zaten 10 dakika geçmişte olduğu için `subMinutes(5)`
silinse de "başlangıç imleçten geride" iddiası doğru kalıyordu. Sahte
test yazılmadı; iddia **örtüşme MİKTARINI** ölçecek şekilde
güçlendirildi ve mutasyonu gerçekten kırdığı doğrulandı.

## Faz 2 demosu uçtan uca doğrulandı

`TrendyolOrderSliceTest` **kuyruk sahtesi KULLANMAZ**; zincir gerçek
sınıflarla yürür: yoklama → `IngestInboxMessage` → `ProcessInboxMessage`
→ `OrderEventRouter` → `IngestChannelOrder` → `ApplyMovement` → ledger.

Dört şey doğrulanıyor: (a) yoklanan sipariş stoğu **10→7 düşürdü**;
(b) iptal **10'a geri aldı**; (c) eşleşmemiş barkod siparişi
KAYBETTİRMEDİ (satır `PENDING`, stoğa hiç dokunulmadı); (d) aynı
siparişin ikinci turda yeniden yoklanması stoğu iki kez düşürmedi.

### Bir önceki tur: stok ve fiyat itme (`850f41d`)

Stok/fiyat aynı `v2/products/price-and-inventory` uç noktasına gider ve
kalem KISMİ güncelleme destekler; **stok yükü fiyat alanı taşımaz, fiyat
yükü stok alanı taşımaz**. **Kimlik BARKODDUR ve `(int)`'e çevrilmez.**
`listPrice` zorunlu, üstü çizili fiyat yoksa satış fiyatına düşer. Sekiz
mutasyonun sekizi de yakalandı; `TrendyolInventorySliceTest` çekirdeğin
gerçek adapter'ı sürdüğünü doğruluyor.

## Hâlâ açık — `pushPrices`'ın ÇEKİRDEKTE ÇAĞIRANI YOK

**Woo'yu da kapsıyor.** `SyncDomain::PRICE` ve `PRICE_PUSH` şemada ve
enum'da var, ama fiyat operasyonu açan ya da dispatch eden hiçbir kod
yok: `PushInventory`'nin fiyat karşılığı yazılmamış. İki adapter'ın da
`pushPrices` gövdesi bugün **ulaşılamaz**.

Davranış dürüst: `DetectStuckSyncOperations` yalnızca `INVENTORY_PUSH`
için iş atar, diğerine uyarı yazar ve kurtarılmış SAYMAZ. **Fiyat senkron
yolu ayrı bir çekirdek maddesidir** ve dokümanın Faz 2 listesinde yok.

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 519 yeşil olmalı
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

## Sıradaki adım — FAZ 2 BİTTİ, SIRADA FAZ 3

Faz 2'nin altı maddesinin altısı da kapandı ve demo verilebilir.

**Doküman sırası: Faz 3 (güvenilirlik).** Yazılmamış olanlar:
`UpdateOrderSnapshot`, `UpdateFulfillment`, `PruneApiCalls`,
`RequestResync` (+ T10), ılık/soğuk mutabakat katmanları (sıcak katman
yazıldı).

**Çekirdekte kalan bilinen boşluk: fiyat senkron yolu** (yukarıda).
Adapter gövdeleri hazır; eksik olan `PushPrices` işi ve fiyat operasyonu
açan yol.

**Panelde bilerek ertelenenler:** mutabakat ekranı · `RequestResync` +
T10 · onay durumu için ayrı ekran. Çalışma sırası kararı gereği bunlara
kullanıcı "artık front'a bakalım" diyene kadar girilmiyor.

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

**2 · `--order-by=random` düşüşü bu turda da tekrar üretilemedi.** Üç tur
daha koşuldu, üçü de yeşil (seed'ler: 1787066180 · 1787066230 ·
1787066267). Toplamda **DOKUZ ardışık temiz tur** (üç oturum). PHPUnit
11'de `--seed` seçeneği YOK; seed çıktının sonunda "Random Order Seed"
satırında raporlanır. Görülürse o satırdaki seed kaydedilmeli.

**3 · `pushPrices` çekirdekte çağrılmıyor** (yukarıda ayrıntısı). Adapter
gövdeleri hazır; eksik olan `PushPrices` işi ve fiyat operasyonu açan yol.

**4 · `acknowledgeOrder` yazılmadı ve "yazılmamış yetenek" listesinde de
YOK.** Sipariş onaylama Trendyol'da kargo akışının parçasıdır ve §14
kargoyu kapsam dışı bırakır (`SupportsFulfillment` UYGULANMAZ). Yani bu
bir eksik değil, bilinçli kapsam sınırı — ama `SupportsOrders` arayüzü
metodu ilan ettiği için gövde istisna fırlatıyor.
