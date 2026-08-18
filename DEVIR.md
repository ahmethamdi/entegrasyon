# Devir Notu — 18 Ağustos 2026 (Faz 2 · stok ve fiyat itme)

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## Tek cümlede durum

**Faz 2'nin ilk BEŞ maddesi kapandı**: Trendyol bağlanıyor, taksonomi
çekiliyor, eşleştirme panelden yapılıyor, ürün ön koşul kapısından geçerek
aktarılıyor, onay durumu takip ediliyor ve **stok/fiyat kanala itiliyor**.
**493 test yeşil** (1823 assertion), Pint temiz, üç random seed'de stabil.

Faz 2'de **tek madde kaldı: sipariş yoklaması** (22 sa).

## Bu turda ne eklendi

### §13 · Faz 2 · stok ve fiyat itme (`850f41d`)

"Stok ve fiyat itme — 16 sa". **Panel işi yoktu ve yapılmadı** (çalışma
sırası kararı korundu).

| Ne | Nerede |
|---|---|
| Stok itme | `TrendyolAdapter::pushInventory` |
| Fiyat itme | `TrendyolAdapter::pushPrices` |
| Uzak okuma | `fetchInventory` · `fetchPrices` · ortak `fetchRemoteRows()` |
| Adapter testleri | `TrendyolInventoryPricingTest` (16) |
| Dikey dilim | `TrendyolInventorySliceTest` (3) |

Çekirdek tarafına **hiç dokunulmadı**: `InventoryBatchBuilder`,
`PushInventory` ve `SyncResultRecorder` zaten hazırdı ve değişmeden
Trendyol'u sürüyor. Madde gerçekten yalnızca adapter gövdeleriydi.

**TEK UÇ NOKTA, İKİ YETENEK.** Woo'da stok ve fiyat `products/batch`
üzerinden ayrı alanlarla gider; Trendyol'da ikisi de
`v2/products/price-and-inventory`'dir ve kalem KISMİ güncellemeyi
destekler. Bu yüzden **stok yükü fiyat alanı TAŞIMAZ, fiyat yükü stok
alanı taşımaz**: biri diğerini ezseydi ezme sessiz ve sürekli olurdu —
stok her satışta gider, fiyat nadiren değişir. İkisi de ayrı mutasyonla
sınandı.

**KİMLİK BARKODDUR, SAYIYA ÇEVRİLMEZ.** Woo'nun `pushInventory`'si
`(int) $item['external_id']` yazar çünkü orada kimlik sayısaldır. Aynı
satır kopyalansaydı `TSH-201` gibi her barkod `0`'a düşer ve istek yanlış
ürüne giderdi — **kanal 200 döndüğü için senkron BAŞARILI görünürdü.**
Kendi testi ve kendi mutasyonu var.

**`listPrice` ZORUNLU, üstü çizili fiyat yoksa satış fiyatına düşer.**
Alan atlanırsa kanal `VALIDATION` döner, o hata KALICIDIR ve kampanyası
olmayan ürün "düzeltilemez" damgasıyla ölürdü.

**Okuma yolları ortak `fetchRemoteRows()` kullanır.** Ayrı yazılsalardı
"kimliksiz listing sorulmaz" (filtresiz istek TÜM katalogu getirirdi) ve
"başarısız yanıt YÜKSELTİLİR" (boş snapshot mutabakatta `REMOTE_MISSING`
üretirdi) kurallarının biri değişince diğeri sessizce geride kalırdı.

## Bu turda bulunan gerçek boşluk — `pushPrices`'ın ÇAĞIRANI YOK

**Woo'yu da kapsıyor ve testler yeşilken duruyor.**

`SyncDomain::PRICE` ve `PRICE_PUSH` §4'te ve enum'da var, ama çekirdekte
**fiyat operasyonu açan ya da dispatch eden hiçbir kod yok**:
`PushInventory`'nin fiyat karşılığı (`PushPrices` işi) hiç yazılmamış.
Yani iki adapter'ın da `pushPrices` gövdesi bugün **ulaşılamaz**.

Davranış yine de dürüst: `DetectStuckSyncOperations` yalnızca
`INVENTORY_PUSH` için iş atar ve başka bir `operation_type` görürse
`Log::warning` yazıp **kurtarılmış SAYMAZ**. Yani eksik olan yol, sessiz
bir yanlış değil.

**Bu maddenin kapsamı adapter gövdeleriydi ve o kapsam tamamlandı** —
fiyat senkron yolu ayrı bir çekirdek maddesidir ve dokümanın Faz 2
listesinde ayrıca yer almıyor. Sipariş yoklamasından sonra ele alınmalı.

## Mutasyonla sınandı — sekiz mutasyon, SEKİZİ DE YAKALANDI

Barkodun `(int)` yapılması · stok yüküne fiyat eklenmesi · boş yük
korumasının kalkması · `throw()`'un kalkması (itme) · `listPrice`
fallback'inin kalkması · fiyat yüküne `quantity` eklenmesi · kimliksiz
okuma korumasının kalkması · `throw()`'un kalkması (okuma).

Her yamada `assert old in s` kullanıldı; **hiçbiri sessizce
uygulanmamış değil**. Dikey dilim testi ayrıca `quantity`'nin sabite
çevrilmesiyle sınandı ve iki testi birden kırdı — yani sahte yeşil değil.

## Dikey dilim: çekirdek GERÇEK adapter'ı sürüyor

`TrendyolInventorySliceTest` sahte adapter KULLANMAZ; sahte olan yalnızca
HTTP katmanıdır. Zincir: satış (ledger) → `OpenSyncOperation` →
`PushInventory` → `InventoryBatchBuilder` → `AdapterRegistry` → **gerçek
`TrendyolAdapter`** → HTTP → `SyncResultRecorder`.

Üç şey doğrulanıyor: (a) 20 − 3 = **17 mutlak değer olarak kanala gitti**;
(b) **fazla satışta kanala 0 gitti ama kanonik bakiye −3 KALDI** (kırpmanın
tek meşru yeri `OutboundQuantity`); (c) kanalın 400'ü `VALIDATION` → KALICI
→ operasyon `dead`.

Bu testin ayrı yazılma sebebi: `TrendyolInventoryPricingTest` adapter'ı
doğrudan çağırır, `PushInventoryTest` çekirdeği ama SAHTE adapter'la
sınar. **İkisi de yeşilken aradaki sözleşme yanlış olabilir** — bu projede
tam bu biçimde iki ölümcül hata bulundu.

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 493 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
npm run build                                 # YERELDE (container'da Node yok)
```

Panel: `/` özet · `/products` ürünler · `/products/{id}/channels` kanala
gönderme · `/orders` siparişler · `/inventory` stok · `/channels` kanallar ·
`/mappings` eşleştirme

## ÇALIŞMA SIRASI KARARI — ÖNCE ÇEKİRDEK, PANEL SONA (18 Ağustos)

**Kullanıcının açık talimatı:** "front'una en son bakarız, bir her şeyi
bitirelim." Yeni sohbette **panel/görsel işlere girme**. Panel cilası zaten
§13 · Faz 4'te listeli. Bu turda da yeni ekran yazılmadı ve bir sonraki
madde (sipariş yoklaması) de panel işi içermiyor.

Bu, ekran işi ÇIKTIĞINDA tarayıcıda doğrulama kuralını iptal ETMEZ.

**Panelde bilerek ertelenenler:** mutabakat ekranı · `RequestResync` +
T10 · onay durumu için ayrı ekran.

## Sıradaki adım

1. **Sipariş yoklaması** (22 sa) — webhook YOK, polling aynı inbox'a
   yazar. Gelen hat (`inbox_messages` → `ProcessInboxMessage` →
   `OrderEventRouter`) Woo ile çalışıyor; yoklama işi aynı inbox'a
   yazacak. Olay kimliği **sipariş numarasından türer** (§4) —
   `extractEventId()` başlıktan `null` döner, kimliği yoklama işi üretir.
   `TrendyolAdapter::fetchOrders`, `parseOrderEvent` ve
   `acknowledgeOrder` hâlâ istisna fırlatıyor. **Panel işi YOK.**
   **Faz 2 demosu bunu ister**: "Trendyol siparişi Woo stoğunu düşürüyor".

Bittiğinde **Faz 2 kapanır** ve demo verilebilir hale gelir.

Sonrasında açık kalanlar: **fiyat senkron yolu** (yukarıdaki boşluk),
mutabakat panel ekranı, `RequestResync` + T10.

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

- **`pushPrices`'ın çekirdekte çağıranı yok** — Woo dahil (bu tur).
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
- **`TrendyolAdapterTest`'teki "yazılmamış yetenek" listesi madde kapandıkça
  KÜÇÜLTÜLMELİ.** Bu turda `pushInventory`/`pushPrices` çıkarıldı; listede
  kalan: `delist`, `fetchListing`. (`fetchOrders`/`parseOrderEvent`/
  `acknowledgeOrder` gövdeleri hâlâ istisna atıyor ama o testin
  listesinde değiller — sipariş yoklaması maddesinde ele alınacaklar.)

## Bilinen açık uçlar

**1 · CI'ın 429 düzeltmesinden sonraki durumu buradan görülemiyor.** `gh`
kimlik doğrulamalı değil (`gh auth status` → "not logged into any GitHub
hosts") ve bu turda da doğrulanamadı. `gh auth login` sonrası
`gh run list` ile bakılmalı. Düzeltmenin kendisi (`6e2217e`) yerinde.

**2 · `--order-by=random` düşüşü bu turda da tekrar üretilemedi.** Üç tur
koşuldu, üçü de yeşil (seed'ler: 1787061938 · 1787062039 · 1787062087).
Toplamda ALTI ardışık temiz tur (iki oturum). PHPUnit 11'de `--seed`
seçeneği YOK; seed çıktının sonunda "Random Order Seed" satırında
raporlanır. Görülürse o satırdaki seed kaydedilmeli.

**3 · `pushPrices` çekirdekte çağrılmıyor** (yukarıda ayrıntısı). Adapter
gövdeleri hazır; eksik olan `PushPrices` işi ve fiyat operasyonu açan yol.
