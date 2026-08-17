# Devir Notu — 17 Ağustos 2026 (PushListing turu)

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## Tek cümlede durum

**Dikey dilim kapandı ve artık PANELDEN uçtan uca sürülebilir**: ürün panelde
yaratılıyor, panelden kanala gönderiliyor, kanalda doğuyor, sonraki stok
değişimi arkasından gidiyor. **342 test yeşil** (1414 assertion), Pint temiz,
iki random seed'de stabil.

Bu tur ayrıca **gerçek worker'da iki ölümcül hata** buldu — ikisi de 340 test
yeşilken duruyordu ve yalnızca tarayıcı + gerçek kuyruk denemesinde göründü.

## Bu turda ne eklendi

§13 · faz 1.5 · "PushListing işi, hash hesaplama" + "Panelde kanal seçimi,
gönderme akışı, senkron durumu rozeti" — faz 1.5'in açık kalan tüm uçları.

| Ne | Nerede |
|---|---|
| İçerik gönderme işi | `app/Domain/Sync/Jobs/PushListing.php` |
| Panelden gönderme | `app/Domain/Sync/Actions/PublishListing.php` |
| İçerik parmak izi | `app/Domain/Sync/Support/ContentHasher.php` |
| Tekil yük üreticisi | `app/Domain/Sync/Support/ListingPayloadBuilder.php` |
| Controller | `app/Http/Controllers/ProductChannelController.php` |
| Ekran | `resources/js/Pages/Products/Channels.vue` |
| Sahte katalog adapter'ı | `tests/Support/Channels/ProgrammableCatalogAdapter.php` |
| İş testleri | `tests/Feature/Sync/PushListingTest.php` (13) |
| Ekran testleri | `tests/Feature/Sync/PublishListingScreenTest.php` (10) |
| Panel→kanal dilimi | `tests/Feature/Sync/PanelToChannelSliceTest.php` (3) |
| Serileştirme koruması | `tests/Feature/Messaging/JobSerializationTest.php` (2) |

29 yeni test (313 → 342).

`OpenSyncOperation` artık `desired_hash` yazıyor (doküman §8'de vardı, kodda
yoktu); `SyncResultRecorder` başarıda `synced_hash`'i ondan kopyalıyor.

## GERÇEK WORKER'DA BULUNAN İKİ HATA

Testler bunları göremezdi: iş nesnesini doğrudan kurup `handle()` çağırıyorlar,
Redis gidiş-dönüşü ve worker'ın bağlam temizliği hiç yaşanmıyor.

### 1 · Kuyruk işi kiracı bağlamını kurmuyordu

`PushListing` panelden DOĞRUDAN atılıyor. Gerçek worker'da `Queue::looping`
kancası her iş sınırında bağlamı temizler; `handle()` bağlamsız başladı ve ilk
tenant-scoped sorgu istisna fırlattı. İş kuyruğa girdi, **hiç çalışmadı**.

`PushInventory` fan-out'tan geldiğinde şanslıydı (çağıran `ConsumeOutboxEvent`
bir `TenantAwareJob`) ama **seviye 2 taramasından** (`runAsSystem`, bağlam YOK)
geldiğinde aynı şekilde düşüyordu — yani kurtarma mekanizması sessizce ölüydü.
`DetectStuckSyncOperations` içindeki yorum "PushInventory bağlamı kendi kurar"
diyordu; **kurmuyordu**.

→ İkisi de kiracı kimliğini yükte taşıyor, `handle()` başında kuruyor,
`finally` ile bırakıyor. `TenantAwareJob` genişletilemedi: `handle()` metodu
`final` ve parametresiz, bu işler bağımlılıklarını enjeksiyonla alıyor.

### 2 · `TenantAwareJob::$tenantId` readonly'di ve iş kuyruktan OKUNAMIYORDU

`SerializesModels::__unserialize()` özellikleri **alt sınıfın kapsamından**
yeniden atar. PHP, ana sınıfta tanımlı bir `readonly` özelliğin alt sınıf
kapsamından ilklenmesine izin **vermez**:

```
Cannot initialize readonly property TenantAwareJob::$tenantId
from scope ConsumeOutboxEvent
```

Yani gerçek worker'da **her outbox olayı** düşüyordu. Bu hata bu turdan ÖNCE de
vardı; benim değişikliklerimle ilgisi yok, ama gerçek kuyruğu ilk kez
çalıştırdığımız için ancak şimdi göründü.

→ `public string $tenantId` (readonly değil). `public protected(set)` daha iyi
olurdu ama **PHP 8.4 özelliği** ve proje 8.3'e kilitli.

→ `JobSerializationTest` kuyruğa giren her işi serileştirip geri okuyor.
**Yeni bir kuyruk işi eklendiğinde oraya da eklenmeli.**

## Tarayıcıda + gerçek kuyrukta doğrulandı

Sahte bir HTTPS Woo mağazası (`wc/v3` stub) ayağa kaldırıldı ve konteynerin
CA deposuna geçici sertifika kondu (`StoreUrl` HTTPS'i zorunlu tutuyor —
kural gevşetilmedi, stub'a TLS eklendi). Sertifika sonradan silindi.

Kayıt → ürün ekle → kanal bağla (sağlık kontrolü GEÇTİ, `active` oldu) →
ürün ekranından "Kanala gönder" → `queue:work` → Woo'ya
`GET products?sku=…` (kopya koruması) + `POST products`
(`manage_stock: true`) → panelde **SENKRON** rozeti, `external_id 4242`,
`lifecycle live`.

Ardından stok düzeltmesi (4 → 10 → 15) yapıldı; `outbox:relay` + `queue:work`
zinciri `POST products/batch` ile `stock_quantity: 15` gönderdi. İki alan da
`synced`, `is_dirty = false`.

**Sağlıksız kanal doğrulaması da tarayıcıda yapıldı**: çözülemeyen alan adına
bağlanan mağaza `pending` kaldı ve gönderme listesinde **hiç görünmedi**.

Konsol hatası yok. Ekran görüntüleri scratchpad'de.

## Mutasyonla sınandı — dokuzu da yakalandı

`findExistingListing` kapısı · kimliğin başarıdan önce yazılması ·
`synced_hash` yazımı · hash'in sürüme bağlanması · `desired_hash` yazımı ·
bağlantıda kiracı scope'u · sağlıksız bağlantının kabulü · katalog yeteneği
filtresi · listing'in `live` doğması.

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 342 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
npm run build                                 # YERELDE (container'da Node yok)
```

Panel: `/` özet · `/products` ürünler · `/products/{id}/channels` kanala
gönderme · `/inventory` stok · `/channels` kanallar

## Sıradaki adım — SEÇİM SENİN

1. **§10 mutabakat** — sürüklenme tespiti. Zemin hazır: `desired_hash` ve
   `synced_hash` artık doluyor, `remote_hash` bu adımda dolacak ve §9'daki
   karar tablosu (MATCHED / LOCAL_AHEAD / REMOTE_AHEAD / DIVERGED /
   REMOTE_MISSING) uygulanabilir hale gelecek. `clock_timestamp()` kuralına
   tabi; karşılaştırma `max(available, 0)` ile. `REPAIR` niyeti ve
   `OpenSyncOperation`'ın onarım yolu zaten yazılı ve T7/T8 ile korunuyor.
2. **Sipariş listesi ekranı** (§13 · faz 1.6 panel maddesi) — "panelde sipariş
   listesi ve fazla satış uyarısı". Sipariş alımı çalışıyor ama panelde
   görünmüyor.
3. **Faz 2 · `TrendyolAdapter`** — ikinci kanal. Adapter mimarisi bunun için
   kuruldu ve `SupportsCatalog`/`SupportsInventory` sözleşmeleri hazır.

**Abonelik/ödeme Faz 4'tür** (hafta 21–25) — şimdi yazılmamalı. Gerekçe:
kota neyi sınırladığını senkron davranışından alır. Sıra: Faz 1 bitti →
Faz 2 Trendyol → Faz 3 güvenilirlik → Faz 4 abonelik → Faz 5 tampon.

## Bu projede işe yarayan çalışma biçimi

1. **Testi önce yaz, kırmızı olduğunu gör**, sonra implementasyonu yaz.
2. **Mutasyonla sına**: kodu kasten boz, testin kırmızıya döndüğünü doğrula.
3. **Mutasyon hayatta kalırsa SAHTE TEST YAZMA** — ya gerçek test bul, ya
   yapısal sınırı belgele.
4. **`DB::table()` yazdıysan kiracı filtresinin TESTİNİ de yaz.**
5. Stok yazan her testin sonunda `assertLedgerMatchesProjection()` çağır.
6. **Entegrasyonu ayrıca sına** — sınıfın var olması onu kimsenin çağırdığı
   anlamına gelmez.
7. **Ekran işi bittiğinde TARAYICIDA çalıştır.** Bu turda iki ölümcül hata
   yalnızca orada göründü.
8. **YENİ: kuyruk işi yazdıysan GERÇEK WORKER'DA çalıştır.**
   `php artisan queue:work --stop-when-empty`. `Queue::fake()` ve doğrudan
   `handle()` çağrısı ne serileştirmeyi ne bağlam temizliğini sınar; ikisi de
   bu turda üretimi kıracak hata barındırıyordu.

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
- **Eşzamanlılık testi `RefreshDatabase` ile yazılamaz** → `DatabaseTruncation`.
- **`StoreUrl` HTTPS'i zorunlu tutar** — yerel stub'a TLS eklenir, kural
  gevşetilmez.
- **Tarayıcı testinde `waitForURL` kullan**, `waitForLoadState` değil.
- **CI'da `public/build` yoktur** — `Tests` job'ı `npm run build` çalıştırır.

## Bilinen açık uç

Eski turlarda bir `--order-by=random` turunda tek test düşmüştü; son beş turda
dokuz seed denendi ve tekrar üretilemedi. Görülürse seed ile kaydedilmeli.
