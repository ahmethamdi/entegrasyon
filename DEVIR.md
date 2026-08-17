# Devir Notu — 17 Ağustos 2026 (ürün yönetimi turu)

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## Tek cümlede durum

**Panel artık gerçek bir CMS**: ürün panelden yaratılıyor ve düzenleniyor
(açılış stoğu ledger üzerinden), dört sekmeli gezinme var (özet · ürünler ·
stok · kanallar). Önceki turlarda §6 taramaları, kanal bağlama ve stok ekranı
kapanmıştı. **313 test yeşil** (1252 assertion), Pint temiz, iki random
seed'de stabil.

## Bu turda ne eklendi

§13 · faz 1.2 · "Panelde ürün oluşturma, düzenleme" — dokümanın faz 1.2'de
kalan son panel maddesi.

| Ne | Nerede |
|---|---|
| Ürün yaratma | `app/Domain/Catalog/Actions/CreateProduct.php` |
| Ürün düzenleme | `app/Domain/Catalog/Actions/UpdateProduct.php` |
| SKU tekillik istisnası | `app/Domain/Catalog/Exceptions/DuplicateSkuException.php` |
| Controller | `app/Http/Controllers/ProductController.php` |
| Ekranlar | `resources/js/Pages/Products/{Index,Create,Edit}.vue` |
| Action testleri | `tests/Feature/Catalog/CreateProductTest.php` (7) |
| Ekran testleri | `tests/Feature/Catalog/ProductScreenTest.php` (13) |

20 yeni test (293 → 313). Catalog domain'inde daha önce **hiç action yoktu** —
modeller vardı, kimse çağırmıyordu; ürünler factory/tinker ile doluyordu.

### İki değişmez kural

**Açılış stoğu ledger üzerinden girer.** `CreateProduct` `InventoryLevel`
satırını yazmaz; IMPORT hareketi açar, projeksiyon ondan türer. Ürün + varyant
+ hareket TEK transaction: araya düşen hata stoksuz varyant bırakırdı.

**İçerik düzenlemesi stoğa dokunmaz.** Başlık/fiyat değişince
`inventory_movements` DEĞİŞMEZ; `content_version` artar (senkron kapısı ondan
beslenir). İçerik ve stok ayrı senkron alanlarıdır — başlık düzeltmesinin stok
hareketi yaratması ledger'ı kirletir ve gerçek fazla satışı gürültüde gizler.

### Mutasyonla bulunan boşluk — AYNI HATAYI TEKRAR ETTİM

`ProductController::stockFor()` de `DB::table()` kullanıyor ve **kiracı
filtresini açıkça yazmayı ilk seferde atlamadım ama testini yazmadım**;
mutasyonla filtre silinince hiçbir test kırılmadı. Geçen tur
`InventoryController`'da tam bunu öğrenmişken tekrarlandı.
→ `stock_totals_never_include_another_tenants_variants`

**Ders: `DB::table()` her kullanıldığında hem filtre hem TESTİ yazılmalı.**

Diğer beş mutasyon (açılış stoğunun ledger'ı atlaması, `content_version`
artmaması, ürün listesinde kırpma, SKU tekilliğinin yutulması) yakalandı.

### Tarayıcıda doğrulandı

Kayıt → boş liste ("Henüz ürün yok") → ürün ekle (KAZAK-001, 249.90, stok 10)
→ liste (Yayında rozeti, 1 varyant, toplam stok 10) → düzenle (başlık+fiyat)
→ stok ekranında fiyat 279.00 ve **stok hâlâ 10**. Konsol hatası yok.

Not: ilk tarayıcı script'im `waitForLoadState` ile beklediği için ekran
görüntülerini yönlendirme öncesi aldı ve "gönderim başarısız" izlenimi verdi.
`waitForURL` ile tekrar koşturunca ekleme/düzenleme ikisi de doğrulandı —
uygulamada sorun yoktu, ölçüm yanlıştı.

## ABONELİK — Faz 4, şimdi değil

Kullanıcı sordu, dokümanda **var**: §13 · Faz 4 · hafta 21–25 ·
"Planlar, abonelik, kota, ödeme entegrasyonu (iyzico) — 26 sa".

Şema kararı zaten alınmış:
- `tenants.plan_code` kolonu **şu an bile mevcut**
- §4 · `plans` (code PK, price_monthly, kiracısız + seed)
- §4 · `subscriptions` (`UNIQUE(tenant_id) WHERE status = 'active'`)
- §3 · klasör: `Models/ Plan · Subscription · UsageRecord`

**Neden en sonda:** abonelik kota uygular (kaç kanal, kaç ürün, kaç senkron)
ve kotanın neyi sınırladığı senkron davranışına bağlıdır. Senkron oturmadan
tanımlanan kota sonra değişir. Faz 4 demosu da bunu varsayar: "yeni kullanıcı
kaydolup ödeme yapıp senkronlayabiliyor" — senkronun çalıştığını kabul ediyor.

Yani sıra: Faz 1 bitir (PushListing) → Faz 2 Trendyol → Faz 3 güvenilirlik →
**Faz 4 abonelik/ödeme** → Faz 5 tampon.

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 313 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
npm run build                                 # YERELDE (container'da Node yok)
```

Panel: `/` özet · `/products` ürünler · `/inventory` stok · `/channels` kanallar

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

**Abonelik/ödeme Faz 4'tür** — yukarıdaki bölüme bak, şimdi yazılmamalı.

## Bu projede işe yarayan çalışma biçimi

1. **Testi önce yaz, kırmızı olduğunu gör**, sonra implementasyonu yaz.
2. **Mutasyonla sına**: kodu kasten boz, testin kırmızıya döndüğünü doğrula.
   Her turda gerçek boşluk çıkıyor.
3. **Mutasyon hayatta kalırsa SAHTE TEST YAZMA.** Ya gerçek bir test bul, ya
   yapısal sınırı belgele (bkz. `AdjustStock` kilidi).
7. **`DB::table()` yazdıysan kiracı filtresinin TESTİNİ de yaz.** İki ayrı
   turda aynı boşluk çıktı; filtreyi yazmak yetmiyor, koruması gerekiyor.
4. Stok yazan her testin sonunda `assertLedgerMatchesProjection()` çağır.
5. **Entegrasyonu ayrıca sına** — sınıfın var olması onu kimsenin çağırdığı
   anlamına gelmez (`ScheduledScansTest`).
6. **Ekran işi bittiğinde TARAYICIDA çalıştır.** Kanal turunda iki bulgu
   yalnızca orada göründü.

## Mutasyonla bulunan gerçek boşluklar (tarihçe)

Hepsi testler yeşilken bulundu:

- **`DB::table()` kiracı filtresinin testi yoktu** — İKİ ayrı turda:
  `InventoryController` rozet sayılarında ve `ProductController` stok
  toplamında. İkisi de çapraz kiracı sızdırıyordu.
- **Rozet delisted listing'leri sayıyordu.**
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

- **`AdjustStock` içindeki `LockInventoryRows` çağrısı**:
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
  açıkça yazılır VE testi yazılır. İki turda aynı boşluk çıktı.
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

Eski turlarda bir `--order-by=random` turunda tek test düşmüştü; son dört
turda yedi seed denendi ve tekrar üretilemedi. Görülürse seed ile kaydedilmeli.
