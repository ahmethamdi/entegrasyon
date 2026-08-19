# Entegrasyon — Claude için proje notları

Çok kanallı ürün, stok ve sipariş senkronizasyon SaaS'ı.
Laravel 12 · PHP 8.3 · PostgreSQL 16 · Redis + Horizon · Inertia + Vue 3 · Modüler monolit.

## Mimari kaynak

**Mimari Karar Dokümanı v2.2 · Implementation Reference — DONDURULMUŞ.**
https://claude.ai/code/artifact/0564dd35-23c6-469f-904d-160e8fcbb633
PDF: `~/Desktop/Entegrasyon-Mimari-v2.2.pdf`

Kod ile doküman çeliştiğinde **doküman esastır**. Yeni mimari turu yapılmıyor;
yalnızca kod incelemesinde çıkan somut bulgular patchlenebilir. Yeni teknoloji
veya paradigma (Kafka, mikroservis, CQRS, event sourcing, Kubernetes) önerilmez.

## Ortam — komutlar container içinde çalışır

```bash
docker compose up -d
docker compose exec app php artisan test      # 532 test yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
```

Composer bağımlılıkları PHP 8.3'e kilitli (`composer.json` → `config.platform`).
Yerel PHP 8.4 olduğu için **composer komutlarını konteyner içinde çalıştır**,
yoksa `platform_check` hatası alırsın.

Testler gerçek PostgreSQL'de koşar (`entegrasyon_test`), SQLite'ta değil —
generated column, kısmi tekil indeks ve jsonb SQLite'ta doğrulanamaz.

## İhlal edilemez kurallar

Bunlar test ile korunur. İhlal eden değişiklik reddedilmelidir.

### Stok matematiği
- `on_hand = Σ inventory_movements.on_hand_delta` — her koşulda, fazla satış dahil
- Projeksiyonda `GREATEST` / `LEAST` / clamp **yasak**
- `on_hand` ve `available` negatife düşebilir; `CHECK (on_hand >= 0)` **yok**
- Ayrı `oversold_qty` sayacı **yazılmaz** — negatif `available` tek gerçek kaynak
- Kırpma yalnızca `OutboundQuantity::forChannel()` içinde, giden yükte
- `SALE` yetersiz stokta **kabul edilir**; `RESERVATION` / `TRANSFER_OUT` **reddedilir**

### Kilit
- Çok-SKU yazan **her** yol `LockInventoryRows` kullanır: tek sorgu,
  `ORDER BY variant_id FOR UPDATE` (sipariş, iptal, iade, rezervasyon, transfer)
- `ApplyMovement` **kendi kilidini almaz** — ön koşul, testle doğrulanır

### Kiracı izolasyonu
- Bağlam yokken tenant-scoped sorgu **istisna fırlatır**, sessizce veri döndürmez
- `Queue::looping` + `JobProcessing/Processed/Failed` kancaları bağlamı temizler
- Sistem erişimi yalnızca `TenantContext::runAsSystem()` ile, açıkça
- `AdapterRegistry` container'da `bind`, **asla `singleton`**

### Fan-out ve teslim
- Fan-out outbox tüketicisinde: 1 olay → N operasyon (`listing × domain × version`)
- `InventoryBatchBuilder` yalnızca **gruplama** yapar, fan-out yapmaz
- `consumed_at` = planlama bitti, downstream başarısı **değil**
- `sync_operations.outbox_event_id` **UNIQUE değil**, yalnızca indeks

### Bütünlük taramaları
- İki teslim zinciri, **iki ayrı tarama**; biri diğerinin kaybını göremez
- Seviye 1 (`DetectUnconsumedEvents`): `published_at` dolu + `consumed_at` NULL
  → `published_at = NULL`, `publish_attempts++`. `consumed_at`'e **dokunulmaz**
- Seviye 2 (`DetectStuckSyncOperations`): `pending` + `attempt_count = 0`
  → doğrudan yeniden dispatch. Outbox olayı **yeniden yayınlanmaz**
- Seviye 2 **deneme açmaz ve damgalamaz** — damgalasa kendi imzasını yok ederdi
- `consumed_at` doluysa olay yeniden yayınlanmaz (kalıcı hata operasyon
  seviyesinde yaşar); yeniden yayın sonsuz döngü olurdu
- İkisi de `clock_timestamp()` kullanır ve `runAsSystem()` ile tüm kiracıları görür
- Komutlar `bootstrap/app.php` içinde **açıkça kaydedilir** (domain klasörleri
  otomatik keşfedilmez) ve `routes/console.php` içinde zamanlanır — ikisi ayrı
  koşul, `ScheduledScansTest` ikisini de ayrı doğrular

### Sürüm ve onarım
- `NORMAL_SYNC`: `synced >= event` veya `desired > event` → ele
- `REPAIR`: sürüm kapısı **atlanır**, `desired_version` **artırılmaz**
- Mutabakat karşılaştırması `max(available, 0)` ile yapılır
- `error_permanent → pending` geçişi `ListingResyncRequested` olayı üretir

### Depo
- Kiracı başına en fazla bir varsayılan — kısmi tekil indeks (DEFERRABLE değil)
- Değişim `SetDefaultWarehouse` ile, tek transaction iki adım
- "En az bir varsayılan" **DB kısıtıyla zorlanmaz**; `CreateTenant` garanti eder

## Çalışma sırası — ÖNCE ÇEKİRDEK, PANEL SONA (18 Ağustos 2026)

Kullanıcının kararı: **yeni panel ekranı yazılmıyor**, Faz 2'nin kalan
maddeleri (stok/fiyat itme, sipariş yoklaması) çekirdek tarafında
bitiriliyor. Panel cilası zaten §13 · Faz 4'te listeli.

Bu, **ekran işi çıktığında tarayıcıda doğrulama** kuralını iptal etmez —
bir ekran yazılırsa yine tarayıcıda sürülür. Karar yeni ekran YAZMAMAK
üzerinedir; mevcut sekiz ekran çalışıyor ve dokunulmuyor.

## Modül sınırı

Bir domain başka bir domainin **modeline** doğrudan yazmaz, yalnızca **action**
sınıfını çağırır. Orders, `InventoryLevel` satırını güncellemez; kilidi
`LockInventoryRows` ile alır, hareketi `ApplyMovement`'a yaptırır.

## Kurulu olan

`app/Domain/`: Identity · Catalog · Inventory · Channels · Messaging · Sync ·
Reconciliation
`app/Support/`: Tenancy · Uuid · Logging

34 domain tablosu, 33 model, 551 test. Stok çekirdeği (`ApplyMovement`,
`LockInventoryRows`), outbox relay, fan-out tüketicisi, adapter mimarisi
(`AdapterRegistry` + 7 yetenek arayüzü), sipariş alımı (`IngestChannelOrder`,
`ApplyOrderReturn`, `ApplyOrderCancellation`), gelen hat (webhook → inbox →
`OrderEventRouter`), giden hat (`InventoryBatchBuilder`, `PushInventory`,
`SyncResultRecorder`) ve **gerçek WooCommerce entegrasyonu**
(`ChannelHttpClient`, `WooCommerceAdapter`, `WooOrderNormalizer`,
`WooProductMapper`), koruma katmanı (`ChannelRateLimiter`,
`CircuitBreaker`), **ürün aktarımı** (`PushListing`, `PublishListing`,
`ContentHasher`, `ListingPayloadBuilder`) ve **panel** (kimlik doğrulama,
`EstablishTenantContext`, senkron durumu ekranı, kanal bağlama akışı, ürün
yönetimi, ürün/stok listesi, ürün→kanal gönderme ekranı, **sipariş listesi
ve ayrıntısı**) yazıldı. P0 testleri
T1/T2/T3/T4/T9/T11/T12 ve T7/T8 yeşil. **Dikey dilim kapalı ve PANELDEN
sürülebilir** — `WooCommerceVerticalSliceTest` sipariş→stok→kanal zincirini,
`PanelToChannelSliceTest` ürün→kanal zincirini yürütüyor. Ayrıntı için
memory'deki "Repo Durumu" dosyasına bak.

## Gelen hat kuralları

- **HMAC ham gövde üzerinden**, JSON ayrıştırmadan **önce**. Ayrıştırıp
  yeniden serileştirmek baytları değiştirir ve imza tutmaz. Doğrulanmamış
  webhook = sahte sipariş enjeksiyonu.
- Webhook rotaları `web` grubunda **değil**: CSRF muaf ve oturumsuz. Muafiyetin
  bedeli imza doğrulamasıyla ödenir ve o zorunludur.
- **Her durumda 202** — kanal 2xx dışını başarısızlık sayıp yeniden gönderir.
- `ProcessInboxMessage` `UPDATE ... WHERE status = 'pending'` **koşullu
  geçişiyle** tek işleyiciyi seçer; kaybeden kopyalar erken çıkar.
- Tekilleştirme çifttir: birincil `external_event_id`, son çare
  `payload_hash + dedupe_window`. Hash yolu saat sınırında bölünür, bu yüzden
  yeni kanal eklerken **olay kimliği aramak ilk iş**.
- `"bu satır yeni mi"` sorusu **zaman damgasıyla cevaplanmaz** — `insertOrIgnore`
  sonrası kendi ürettiğin uuid'in geri gelip gelmediğine bak. Zaman damgaları
  saniye hassasiyetlidir.

## Sipariş kuralları

- **Sipariş asla reddedilmez veya geri alınmaz.** Pazaryeri onu kabul
  etmiştir; bu otoriter gerçektir. Stok yetmezse bakiye negatife düşer ve
  satır `OVERSOLD` işaretlenir + `OVERSELL_DETECTED` denetim olayı yazılır.
- **Eşleşmemiş SKU siparişi kaybettirmez**: `order_lines.variant_id` NULL
  olabilir, satır `PENDING` kalır, stok düşülmez. Sipariş kaybetmek stok
  tutarsızlığından kötüdür.
- **Tipe göre ayrı yollar**: `created` yeni sipariş yaratır; iptal/iade/
  güncelleme mevcut siparişi bulur ve `order_events` üzerinden işlenir. Hepsi
  tek yola girseydi güncellemeler `ON CONFLICT DO NOTHING` ile yutulurdu.
- **İdempotency çıpaları**: sipariş → `(channel_connection_id, external_id)`;
  iptal/iade → `order_events (order_id, type, external_ref)` kısmi tekilliği.
  Hareket anahtarı **olay + satır** kimliğinden türer (`MovementKey::returnOf`)
  — yalnızca olaya bağlansaydı çok kalemli iadede ikinci kalem yutulurdu.
- Çok-SKU yazan **her** yol (`IngestChannelOrder`, `ApplyOrderReturn`,
  `ApplyOrderCancellation`) `LockInventoryRows` kullanır. Bu testle korunur.

## Adapter kuralları

- `AdapterRegistry::for()` **her çağrıda yeni örnek** üretir. Container'da
  `bind`, **asla `singleton`**; registry içinde önbellek **yasak**. Gerekçe
  güvenlik: adapter bağlantı taşır, paylaşılan örnek kiracı A'nın kimlik
  bilgisini kiracı B'nin işinde kullanır.
- Adapter **yan etkisizdir**: veritabanına yazmaz, kuyruğa iş atmaz, durum
  güncellemez. Girdi alır, kanalla konuşur, `AdapterResult` döner. Durumu
  `SyncResultRecorder` yazar.
- Yetenekler `instanceof Supports*` ile okunur; panelde `if type === '...'`
  bloğu yazılmaz. `SupportsWebhooks` arayüzü **yoktur** — webhook yetenek
  değil taşıma biçimidir.
- Stok ve fiyat **mutlak değer** gönderilir, delta asla.
- Hata sınıflandırmayı **adapter** yapar (`classifyError()`), ne yapılacağına
  **çekirdek** karar verir (`RetryPolicy`). `VALIDATION` ve `AUTHENTICATION`
  kalıcıdır, diğerleri geçici.
- **Kimlik bilgisi `runAsSystem()` ile okunur.** `channel_credentials`
  kiracıya göre kapsanır ama `ChannelHttpClient` bağlam OLMADAN çağrılabilir
  (kuyruk işi, `runAsSystem` taraması, sağlık kontrolü). Kapsama burada bir
  şey korumaz — bağlantı zaten elimizdedir ve kiracısını kendisi taşır —
  yalnızca okumayı engeller ve istek **sessizce kimliksiz** gider. Kanal 401
  döner, `AUTHENTICATION` kalıcı sayılır ve listing "anahtarın yanlış"
  diyerek ölür; oysa anahtar doğrudur, hiç gönderilmemiştir.
- **Basic auth çiftinin adı kanal başına değişir, biçim aynıdır.** Woo
  `consumer_key`/`consumer_secret`, Trendyol `api_key`/`api_secret`. Adlar
  `ChannelHttpClient::BASIC_AUTH_KEY_PAIRS` içinde toplanır;
  `if ($channel === '...')` yazılmaz.
- **Yazılmamış yetenek SESSİZCE BAŞARILI DÖNMEZ.** `AdapterResult::success()`
  dönseydi operasyon tamamlandı sanılır, `synced_version` ilerler ve kanalda
  hiçbir şey değişmemişken satır "senkron" görünürdü.

## Trendyol kuralları (§14 · Faz 2)

- **Satıcı kimliği hesabın kimliğidir**, mağaza adresi değil. Woo'da hesap
  kimliği alan adıdır; Trendyol'da tek API adresi vardır ve tüm satıcılar onu
  paylaşır. Alan adı kimlik sayılsaydı her satıcı aynı `external_account_id`
  ile çakışır ve `(tenant, type, account)` tekilliği ikinciyi reddederdi.
  Kimlik ayrıca **yol üzerinde** taşınır (`/suppliers/{id}/...`).
- **Hız sınırı yanıt başlığından öğrenilir ve bağlantıya YAZILIR.** Sınır
  satıcı seviyesine göre değişir; sabit profil yüksek seviyeliyi yavaşlatır,
  düşük seviyeliyi 429'a sokar. Süreçle ölseydi her worker'ın ilk istekleri
  daima varsayılanla giderdi. Profili adapter bildirir, uygulamayı çekirdek
  yapar — `ChannelRateLimiter` değişmez.
- **Sayı olmayan sınır başlığı YOK SAYILIR.** `X-RateLimit-Limit: 600, 300`
  gerçek bir vakadır (vekil sunucu iki başlığı birleştirir); `(int)` dönüşümü
  sessizce ilk sayıya iner ve **düşük** sınır yok sayılırdı. Filtre
  `ctype_digit`'tir.
- **Webhook YOKTUR**: `verifyWebhookSignature` her zaman `false` döner.
  `true` dönmek Trendyol adına imzasız sipariş enjekte etmenin kapısını
  açardı. Sipariş yoklamayla gelir; olay kimliği sipariş numarasından türer.
- **Pazaryeri karmaşıklığı çekirdeğe dokunmaz.** Taksonomi, zorunlu
  öznitelik ve onay süreci yetenek arayüzleriyle taşınır; stok akışı
  listing'in nasıl oluştuğunu bilmez ve yalnızca `lifecycle_status = 'live'`
  kontrolü yapar.
- **Kargo kapsam dışıdır** — `SupportsFulfillment` UYGULANMAZ.
- **TEK UÇ NOKTA, İKİ YETENEK.** Stok ve fiyat aynı
  `v2/products/price-and-inventory` uç noktasına gider ve kalem KISMİ
  güncellemeyi destekler. **Stok yükü fiyat alanı TAŞIMAZ, fiyat yükü stok
  alanı taşımaz**: biri diğerini ezseydi ezme sessiz ve sürekli olurdu —
  stok her satışta gider, fiyat nadiren değişir. Uç noktayı paylaşmaları
  yetenekleri birleştirmez.
- **KİMLİK BARKODDUR VE SAYIYA ÇEVRİLMEZ.** Woo'da kimlik sayısal ürün
  kimliğidir ve o adapter `(int)` dönüşümü yapar; aynı satır Trendyol'a
  kopyalanırsa harf içeren her barkod (`TSH-201`) `0`'a düşer, istek
  yanlış ürüne gider ve **kanal 200 döndüğü için senkron BAŞARILI
  görünür**.
- **`listPrice` ZORUNLUDUR ve üstü çizili fiyat yoksa satış fiyatına
  düşer.** Alan atlanırsa kanal `VALIDATION` döner, o hata KALICIDIR ve
  kampanyasız ürün "düzeltilemez" damgasıyla ölürdü.
- **Stok/fiyat itme de ASENKRONDUR** — yanıt `batchRequestId` döner.
  Kabul "gönderildi" demektir, "uygulandı" değil; farkı mutabakat turu
  yakalar.

## Sipariş güncelleme ve kargo kuralları (§13 · Faz 3)

- **İKİSİ DE STOK HAREKETİ ÜRETMEZ** (§4). Mal SATIŞTA zaten düşülmüştür;
  hareket üretselerdi aynı satış iki kez düşülür ve bakiye KALICI olarak
  bozulurdu. Testler ledger'ı önce/sonra karşılaştırarak korur.
- **GÜNCELLEME KALEMLERE DOKUNMAZ.** Kalem değişikliği stok demektir ve
  stok yalnızca iptal/iade yollarından geçer; kanalın gönderdiği kalem
  listesi burada uygulansaydı sessizce stok tutarsızlığı üretirdi.
- **NULL "DEĞİŞMEDİ" DEMEKTİR, "BOŞALT" DEĞİL.** Kanal her olayda tüm
  alanları göndermez ve boş değerin mevcut veriyi ezmesi GERİ ALINAMAZ.
  `delivered` olayı `shipped_at` taşımaz — ezseydi kargoya veriliş anı
  kaybolurdu.
- **PAKET BAŞINA TEK SATIR, DURUM İLERLER** (`(order_id, external_id)`
  tekil). Çok paketli sipariş AYRI satırlar taşır: tek satıra
  sıkıştırılsaydı ikinci paket birincinin durumunu ezer ve satıcı yarısı
  teslim olmuş siparişi "tamamen teslim" sanırdı.
- **BAYAT TEKRAR YENİ DURUMU EZMEZ.** Idempotency kapısının asıl değeri
  budur: yoklama örtüşmesi eski olayı tur tur yeniden görür ve kapı
  olmasaydı araya giren `Delivered` her turda `Shipped`'a geri ezilirdi.
- **DÜRÜST SINIR — `fulfilled` TİPİNİ HİÇBİR NORMALIZER ÜRETMİYOR.** Woo
  kargoyu ayrı webhook göndermiyor, Trendyol'da kargo §14 gereği KAPSAM
  DIŞI. Router'ın FULFILLED dalı ve paket bazlı çıpa bu yüzden davranışla
  sınanamaz; mutasyon orada hayatta kalır ve KALMALIDIR. Kanal kargo
  bildirimi göndermeye başlarsa ilk iş normalizer'a `fulfilled` tipini ve
  `payload['fulfillment']` bloğunu eklemektir.

## Sipariş yoklaması kuralları (§13 · Faz 2)

- **YOKLAMA WEBHOOK'LA AYNI İNBOX'A YAZAR** (`source = 'polling'`).
  `IngestInboxMessage` TEK gelen hattır; ikinci bir yol açılsaydı
  tekilleştirme iki kez yazılır, biri unutulurdu ve `inbox:recover` iki
  yeri bilmek zorunda kalırdı.
- **OLAY KİMLİĞİ SİPARİŞ NUMARASI + DURUMDUR** (`{orderNumber}:{status}`).
  Yalnızca numaraya bağlansaydı aynı siparişin sonraki İPTALİ birincil
  tekillik indeksine takılır ve `insertOrIgnore` tarafından **SESSİZCE
  YUTULURDU** — stok geri eklenmez, bakiye kalıcı eksik kalırdı.
  Karar 24'ün açıkça uyardığı hata biçimi budur.
- **PENCERE GERİYE BAKAR** (5 dk örtüşme) ve imleç turun **BAŞLAMA**
  anına yazılır. Bitiş anı yazılsaydı istek sürerken oluşan sipariş iki
  pencerenin arasına düşer ve HİÇ görülmezdi. Örtüşmenin bedeli yoktur:
  tekilleştirme kopyayı zaten eler.
- **BAŞARISIZ TURDA İMLEÇ İLERLEMEZ.** İlerleseydi hata anındaki pencere
  sonsuza kadar atlanır ve o siparişler bir daha hiç sorulmazdı.
- **TEK BOZUK BAĞLANTI TURU DURDURMAZ** — taksonomideki gerekçenin aynısı.
- **BİLİNMEYEN DURUM `updated` SAYILIR.** `created` var olan siparişi
  yeniden yaratmayı denerdi, `cancelled` satılmış stoğu geri eklerdi;
  ikisi de bakiyeyi bozar. `updated` stok hareketi ÜRETMEZ.
- **WEBHOOK GÖNDEREN KANAL YOKLANMAZ** — `supports_webhooks` kapısı.
  **Bu alan eager-load'da AÇIKÇA seçilmeli**; seçilmezse kapı null okur ve
  hiç çalışmaz (gerçek çalıştırmada bulundu, `adapter_class` ile aynı
  tuzak).
- **Yoklamada `signature_valid = true`** ve bu bir eksiklik değildir:
  gövdeyi kanaldan BİZ istedik ve kimlikli bir çağrıyla aldık. İmza,
  bize GÖNDERİLEN bir gövdenin sahiciliğini kanıtlar.

## Taksonomi kuralları (§13 · Faz 2)

- **Taksonomi KİRACIYA AİT DEĞİLDİR.** `channel_categories` ve
  `channel_category_attributes` `tenant_id` kolonu taşımaz: kategori ağacı
  kanalın gerçeğidir ve tüm satıcılar için aynıdır. Kiracı başına
  kopyalansaydı aynı 30 bin satır her kiracı için yeniden saklanır ve her
  kiracı ayrı ayrı çekmek zorunda kalırdı. Kiracıya ait olan
  **eşleştirmedir** (`category_mappings`) — ağaç kanalın GERÇEĞİ, eşleştirme
  satıcının KARARIDIR.
- **Yeni sürüm eskiyi SİLMEZ.** Tekillik `(channel_type_code,
  taxonomy_version, external_id)`; yeni sürüm yeni satırlar olarak yazılır.
  Eşleştirmeler eski sürüme bağlıdır ve silinseydi satıcının aylarca emek
  verdiği eşleştirmeler bir gecede yok olurdu. Sürüm bir **ayıraçtır**.
- **Sürüm İÇERİKTEN türer ve SIRALANIR.** Kanal sürüm numarası vermez;
  parmak izi ağacın şeklinden (kimlik + ad + ebeveyn) üretilir. Zaman
  karışsaydı her çekim yeni sürüm üretirdi; sıralanmasaydı kanalın döndürme
  sırası değişince ağaç aynıyken sürüm değişir ve tüm eşleştirmeler
  "yeniden doğrula" damgası yerdi.
- **Öznitelik yalnızca YAPRAK için çekilir.** Ara kategoriye ürün açılamaz;
  öznitelik istemek boşuna istek ve boşuna kotadır.
- **Başarısız yanıt sessizce boş ağaca dönüşmez** — `throw()` ile yükseltilir.
  `json()` bir 500 gövdesinde de dizi döndürür ve boş ağaç geçerli bir
  sürümle yazılırdı; panel "kategori yok" der ve aktarım sonsuza kadar
  ön koşul kapısında takılırdı.
- **Tek bozuk bağlantı tüm kanalı durdurmaz.** Tur kanal türü başına
  çalışır ama bağlantılar SIRAYLA denenir; ilk bağlantıda pes edilseydi o
  kanaldaki tüm satıcılar taksonomisiz kalır ve sorun kendi
  bağlantılarında olmadığı için hiçbiri düzeltemezdi.

## Eşleştirme kuralları (§13 · Faz 2)

- **EŞLEŞTİRME KİRACIYA AİTTİR — taksonominin AKSİNE.** `channel_categories`
  `tenant_id` taşımaz (ağaç kanalın GERÇEĞİ); `category_mappings`,
  `attribute_mappings`, `attribute_value_mappings` taşır (eşleştirme
  satıcının KARARI). İki satıcı aynı iç kategoriyi kanalın farklı
  kategorilerine bağlayabilir ve ikisi de haklıdır.
- **ÜÇ SEVİYENİN ANAHTARLARI BİLİNÇLİ OLARAK FARKLIDIR.** Öznitelik
  eşleştirmesi **KATEGORİ BAŞINA** (`UNIQUE(tenant, option_definition,
  channel_category)`): aynı "Beden" elbisede ve ayakkabıda farklı
  `external_attribute_id` taşır. Değer eşleştirmesi **ÖZNİTELİK BAŞINA,
  kategori YOK** (`UNIQUE(tenant, option_value, external_attribute)`): değer
  listesi kategoriden bağımsızdır ve kategori de anahtara girseydi satıcı
  aynı "S → SMALL" kararını her kategori için yeniden verirdi.
- **YENİ SÜRÜM EŞLEŞTİRMEYİ SİLMEZ, BAYAT İŞARETLER.** `taxonomy_version`
  FK'dan okunabilirdi ama KOLON olarak tutulur: "hangi eşleştirmeler eski
  sürüme bakıyor" join'siz cevaplanır. Bayatlık `ready`'yi DÜŞÜRMEZ —
  eşleştirme hâlâ geçerlidir, yalnızca yeniden doğrulanması istenir.
- **ÜÇ KAPI, ÜÇÜ DE AYNI GEREKÇEYLE.** Yaprak olmayan kategori, kategoride
  bulunmayan öznitelik ve izinli liste dışındaki değer REDDEDİLİR: üçü de
  kanalda `VALIDATION` hatası verir, o hata **KALICIDIR** ve listing
  "düzeltilemez" damgasıyla ölür. Kaydederken yakalamak sonra yakalamaktan
  ucuzdur.
- **BOŞ İZİNLİ DEĞER LİSTESİ "HİÇBİRİ" DEĞİL "SERBEST METİN" DEMEKTİR.**
  Aksi yorumla satıcı o özniteliği asla eşleştiremezdi.
- **İç kategori tablosu YOKTUR** (§4 de istemez): `products.internal_category_id`
  serbest metindir ve ekran `products` üzerinden DISTINCT okur. Boş dize
  NULL'a çevrilir — `""` bir kategori adı değildir.
- **Eksik zorunlu öznitelik ADIYLA gösterilir**, sayıyla değil: sayı tek
  başına kullanıcıya ne yapacağını söylemez.

## Ön koşul kapısı ve onay kuralları (§14 · Faz 2)

- **KAPI STOK AKIŞINA DOKUNMAZ** — §14'ün ana tasarım hedefi. Eksik
  eşleştirmede listing `blocked` olur ve içerik gönderilmez, ama hareket,
  bakiye ve outbox olayı HİÇ etkilenmez. Test bunu snapshot
  karşılaştırmasıyla korur; kırılırsa pazaryeri karmaşıklığı çekirdeğe
  sızmış demektir.
- **Kapı ÇEKİRDEKTEDİR** (`Sync/Support/PrerequisiteGate`), `Adapters/
  Trendyol/Catalog/` altında DEĞİL. §19'un dizin ağacından bilinçli sapma:
  kapı kiracının eşleştirme tablolarını okur ve çekirdeğin listing
  durumunu belirler; Trendyol'a özgü hiçbir şey bilmez ve
  `SupportsTaxonomy` uygulayan HER kanalda çalışır. §14'ün kendi örneği de
  `instanceof SupportsTaxonomy` ile yazılmış. `ListingMapper` ise gerçekten
  kanala özgüdür ve dokümanın gösterdiği yerde durur.
- **"Hazır mı" mantığı TEK KAYNAKTIR.** Eşleştirme ekranı kapının
  `missingRequiredAttributes()` metodunu çağırır. İki yerde hesaplansaydı
  biri değiştiğinde panel "hazır" derken kapı "eksik" der ve satıcı neyi
  düzelteceğini bilemezdi.
- **İç kategorisi olmayan ürünün sebebi AYRIDIR** — "eşleşme yok" demek
  kullanıcıyı eşleştirme ekranında hiç bulunmayan bir satırı aramaya
  gönderirdi.
- **ENGEL BİR CEZA DEĞİL BEKLEME DURUMUDUR.** Eksik kapanınca satır taslağa
  döner, sync state `pending` olur ve eski hata metni TEMİZLENİR. Sürüm
  alanlarına dokunulmaz: `desired_version` artırılsaydı eksik kapandığında
  sürüm kapısı gönderimi eler ve ürün sessizce hiç gitmezdi.
- **ONAY SÜRECİ OLAN KANALDA GÖNDERİM `live` DEĞİL `pending_approval`
  YAZAR.** Doğrudan canlı işaretlenseydi henüz yayında olmayan satır
  fan-out hedefi olur ve her stok turunda hata alırdı. Canlı işareti
  `TrackApprovalStatus` kanaldan öğrenerek yazar.
- **Onay durumu TOPLU okunur** ve **yanıtta olmayan satıra DOKUNULMAZ** —
  yokluk red değildir; kanal yeni ürünü listeye hemen koymaz.
- **`approved: true` + `onSale: false` "inactive"dir**, onaylanmış değil:
  o satır kanalda GÖRÜNMEZ ve "onaylandı" demek satıcıya ürününün yayında
  olduğunu düşündürürdü.
- **Red sebebi ADIYLA saklanır ve AYRI kutuda gösterilir.** Senkron hatası
  "gönderemedik", red ise "gönderdik ama kanal beğenmedi" demektir; ikisi
  aynı alana yazılsaydı biri diğerini ezerdi.
- **`PublishListing`'in boş dizisi İKİ anlama gelir** — sürüm kapısı eledi
  (zaten gönderilmiş) veya ön koşul engelledi (hiç gönderilmedi). Panel
  ikisini AYIRT ETMEK zorundadır; "zaten güncel" demek satıcıyı eksik
  eşleştirmeden habersiz bırakır (gerçek tarayıcı çalıştırmasında bulundu).

## Resync kuralları (§9 · Karar 18 · T10)

- **DURUM DEĞİŞİKLİĞİ TEK BAŞINA HİÇBİR İŞ ÜRETMEZ.** `error_permanent →
  pending` yazmak yeterli olsaydı hiçbir şey olmazdı: kanonik veri o arada
  DEĞİŞMEDİ ve değişmeyen veriden yeni domain olayı doğmaz. Bu yüzden her
  çıkış geçişi AYNI transaction içinde `ListingResyncRequested` yazar.
- **BU GEÇİŞ SATIRI AKIŞA GERİ SOKAN TEK YOLDUR** — `error_permanent`
  mutabakatta asla aday değildir (§10), yani o satıra başka hiçbir
  mekanizma dokunmaz.
- **NİYET REPAIR.** Kanonik veri değişmediği için talebin taşıdığı sürüm
  zaten gönderilmiş olabilir; NORMAL_SYNC ile açılsaydı sürüm kapısı
  operasyonu SESSİZCE eler ve kullanıcının "yeniden dene"si hiçbir şey
  yapmazdı. REPAIR kapıyı atlar, `desired_version`'ı ARTIRMAZ ve
  `synced_version`'ı geriye ALMAZ (o alan GERÇEĞİ taşır).
- **REPAIR AYIRT EDİCİ ÇIPA İSTER.** Kapı atlandığı için anahtar tekilliği
  "aynı tetik iki kez işlenirse tek operasyon" garantisini taşıyan TEK
  mekanizmadır. Mutabakat `reconciliation_item_id`, resync OLAY KİMLİĞİ
  taşır; ikisi aynı anda verilemez ve ön ekleri AYRIDIR (`repair:` /
  `resync:`).
- **TEK GENERIC OLAY TİPİ**, ayrı taksonomi kurulmaz; sebep YÜKTE yaşar.
- **SÜRÜM YÜKTE DONAR, tüketici YENİDEN HESAPLAMAZ:** iş kuyrukta
  beklerken kanonik sürüm değişmiş olabilir ve o değişiklik KENDİ olayını
  doğurmuştur.
- **DURUM SORULMAZ, ön koşul KOYULMAZ:** "yeniden dene" geçici hatada da
  takılı bekleyen satırda da meşrudur.
- **YENİ OLAY TİPİ `ConsumeOutboxEvent`'e BAĞLANMALI.** Dal yoksa olay
  "tanınmayan tür" sayılır, sessizce consumed damgalanır ve hiçbir iş
  üretilmez — tüketiciyi doğrudan çağıran testler bunu GÖRMEZ.

## Zaman damgası tuzağı — `now()` yerine `clock_timestamp()`

`outbox_events` zaman damgaları **saniye hassasiyetlidir**
(`datetime_precision = 0`): `19:56:25.7`'de yazılan olayın `available_at`
değeri `19:56:26`'ya **yuvarlanır** — yazıldığı andan bir saniyeye kadar
ileride olabilir.

PostgreSQL'de `now()` transaction'ın **başlama anını** döndürür ve iç
savepoint'ler dahil donmuş kalır. İkisi birleşince taze bir olay, donmuş
`now()`'a göre "geleceğe planlanmış" görünür ve o turda hiç alınmaz.

Bu yüzden relay sorgusu `available_at <= clock_timestamp()` kullanır.
Transaction içinde çalışan ve "şu ana kadar hazır olanlar" arayan **her**
sorgu aynı kuralı izlemelidir.

## Stok testi yazarken

- **Açılış stoğu ledger üzerinden girer.** `InventoryLevel::create(['on_hand' => 5])`
  ile seed etmek `on_hand = Σ on_hand_delta` eşitliğini bozar; seed IMPORT
  hareketiyle yapılır. Hareket sayısı ve `version` beklentileri açılış
  hareketlerini de sayar.
- **Eşzamanlılık testi `RefreshDatabase` ile yazılamaz** — tek transaction
  içinde kilit çekişmesi oluşmaz, test yanlış yeşile döner. `DatabaseTruncation`
  + ayrı PDO bağlantısı gerekir; bloklanma `SET lock_timeout` ile kanıtlanır.
- **`DatabaseTruncation` kendi setUp'ında boşaltır**, tearDown'da değil.
  Commit edilen artık sonraki testlere sızar; `ConcurrentSaleTest::tearDown()`
  içinde `truncateDatabaseTables()` çağrılır.

Ledger ↔ projeksiyon eşitliği `tests/Concerns/AssertsLedgerIntegrity.php`
içindeki `assertLedgerMatchesProjection()` ile doğrulanır — stok yazan her
testin sonunda çağrılır.

## Mutabakat kuralları (§10)

- **Karşılaştırma GİDEN değerle yapılır**: beklenen uzak değer
  `OutboundQuantity::forChannel()` yani `max(available, 0)`. Kanonik bakiye
  fazla satış nedeniyle negatifse kanaldaki 0 **doğrudur** ve sürüklenme
  değildir. Ham kanonik değerle karşılaştırılsaydı her fazla satış kalıcı
  sürüklenme olarak raporlanır ve **sonsuz onarım döngüsü** doğardı.
- **`error_permanent` ASLA aday değildir.** Düzeltilemeyecek listing her
  turda kontrol edilirse bütçe boşa gider ve gerçek sürüklenmeler geç fark
  edilir. Satır ancak kullanıcı müdahalesiyle `pending`'e dönünce akışa girer.
- **Dört aday sorgusu AYRI çalışır**, tek UNION değil: her biri kendi kısmi
  indeksini kullanır; tek dev sorgu planlayıcıyı zorlar ve indeks seçimini
  bozar. Birleştirme uygulama katmanında, listing başına en yüksek öncelikle.
- **Onarım sürüm kapısını ATLAR ve `desired_version`'ı ARTIRMAZ.** Mutabakat
  uzak durumu okumuş ve farkı kanıtlamıştır. Anahtar kalem kimliğini taşır
  (`inv:{listing}:{version}:repair:{item_id}`) — aynı kalem iki kez işlense
  tek operasyon oluşur ve biçim normal anahtarla çakışmaz.
- **`REMOTE_MISSING` otomatik onarım AÇMAZ** — yeniden listeleme kullanıcı
  onayı ister; sessizce yaratmak kanalda kopya ürün açardı.
- **`REMOTE_UNREACHABLE` sürüklenme DEĞİLDİR**: API hatası altyapı sorunudur
  ve fark kanıtlanmamıştır. Bilinmeyen duruma karşı yazmak yanlıştır.
- **Doğrulama AYRI turda.** Onarımdan hemen sonra okumak hem kota yer hem de
  pazaryerlerinde stok saniyeler sonra yansıdığı için yanlış sonuç verir;
  kalem sonraki turda `drift_detected` sebebiyle tekrar aday olur.
- **Uzak durum TOPLU okunur** — 50 listing tek istekte. Listing başına ayrı
  istek ölçek hesabını yüz katına çıkarırdı.
- **Kaynak kanal DAHİLDİR.** Fan-out'ta kaynak kanalın atlanması bir
  eniyilemedir, otorite devri değil; kanal kendi güncellemesini uygulamamış
  olabilir.
- `remote_hash` / `last_observed_at` burada dolar — §9'un üçüncü durumu.
  Sync state satırı yoksa **yaratılır**: hiç senkronlanmamış listing tam da
  sürüklenmeye en açık olandır ve gözlemi atmak öğrenileni çöpe atmaktır.

## Henüz yazılmadı

Mutabakat panel ekranı, ılık/soğuk mutabakat katmanları (sıcak katman
yazıldı), **fiyat senkron yolu** (`PushPrices` — adapter gövdeleri hazır,
çekirdekte çağıranı yok).

`PruneApiCalls` **YAZILDI** (§13 · Faz 3, `a452a27`) — `api-calls:prune`,
günlük 04:00, partili silme + tur başına üst sınır.

`RequestResync` + `ListingResyncRequestedConsumer` **YAZILDI** (§13 · Faz 3,
`9ec5ac0`) ve **T10 ile korunuyor** — yazılmamış P0/P1 testi KALMADI.

`UpdateOrderSnapshot` ve `UpdateFulfillment` **YAZILDI** (§13 · Faz 3) ve
`OrderEventRouter`'a bağlandı.

**FİYAT SENKRON YOLU ÇEKİRDEKTE YOK** — `pushPrices` gövdeleri (Woo ve
Trendyol) hazır ama **çağıranı yok**: `SyncDomain::PRICE` ve `PRICE_PUSH`
şemada/enum'da var, fiyat operasyonu açan ya da dispatch eden kod yok
(`PushInventory`'nin fiyat karşılığı yazılmamış). Davranış dürüst —
`DetectStuckSyncOperations` yalnızca `INVENTORY_PUSH` için iş atar,
diğerine uyarı yazar. Dokümanın Faz 2 listesinde ayrıca yer almıyor.

**FAZ 2 KAPANDI.** `TrendyolAdapter`'ın istemci/kimlik/hız sınırı,
taksonomi, katalog aktarımı, onay durumu, stok/fiyat itme ve **sipariş
yoklaması** katmanları yazıldı. Hâlâ istisna fırlatanlar: `delist`,
`fetchListing`, `acknowledgeOrder` — **üçü de Faz 2 kapsamı dışıdır**,
eksik değil. Bu liste madde kapandıkça küçülür ve `TrendyolAdapterTest`
onu **yazılmamış olarak** doğrular (yalnızca `delist` + `fetchListing`) —
yazılan bir gövde listeden çıkarılmazsa test yanlış sebeple kırmızıya
döner.

Kategori/öznitelik **eşleştirme** ve **ön koşul kapısı** yazıldı. "Hazır
mı" mantığı `PrerequisiteGate::missingRequiredAttributes()` içinde TEK
kaynaktır ve eşleştirme ekranı onu çağırır.

Sahte adapter'lar hâlâ kullanılıyor — gerçek Woo adapter'ı onların yerini
almaz, farklı şeyleri sınarlar: `FakeAdapter` (registry yaşam döngüsü),
`FakeOrderAdapter` (gelen hat), `ProgrammableInventoryAdapter` (giden hat,
kanal başına yanıt programlanır — T4 bunu kullanır).

## Giden hat kuralları

- **Fan-out tüketicide, gruplama batch builder'da.** `InventoryBatchBuilder`
  yalnızca birleştirir; operasyon sayısı **değişmez**. Her operasyon kendi
  satırı, kendi durumu, kendi hatasıyla yaşar.
- **Sonucu `SyncResultRecorder` yazar, adapter değil.** Yazma operasyon
  bazlıdır: yükte olmayan operasyona dokunulmaz. Gruplamaya dahil olanlar
  kendi deneme kayıtlarını alır, yoksa "hiç denenmedi" görünüp seviye 2
  taramasına takılırlar.
- **Başarıda da sürüm kapısı var**: `synced_version` geriye **alınmaz**. İki
  iş yarışıp eski olan sonra bitebilir; geri sarma kanalda doğru veri
  dururken satırı kirli gösterir.
- **Boş yükte deneme AÇILMAZ.** `attempt_count = 0` kalması seviye 2
  taramasının ("worker hiç çalışmadı") anlamını korur.
- **Dispatch planlama bittikten SONRA.** Tüketici kimlikleri toplar, işleri
  en sonda atar: kuyruk kancaları her iş sınırında kiracı bağlamını temizler
  ve `sync` sürücüde iş derhal çalışır; döngü ortasında atılırsa kalan
  listing'ler bağlamsız kalır.
- **Planlamayı sınayan testler `Queue::fake()` kullanır.** Gerçek worker
  asenkrondur; `sync` sürücü o modeli taklit etmez. Gönderim
  `PushInventoryTest` içinde işler elle çağrılarak sınanır.

## Kanal entegrasyonu kuralları

- **`api_calls` her çağrıda yazılır** — başarı, hata, ağ kopması. Ağ
  hatasında da satır yazılır: sonuç **belirsizdir** ("istek gitti mi,
  işlendi mi") ve tam bu yüzden iz gerekir.
- **Günlükleme çağrıyı düşürmez.** Yan iştir; dolu disk tüm senkronu
  durdurmamalı. Hata yutulur ve uygulama günlüğüne yazılır.
- **`manage_stock` zorunlu.** Woo stok yönetimi kapalıyken `stock_quantity`
  alanını **sessizce yok sayar**; senkron başarılı görünürken hiçbir şey
  değişmez — teşhisi en zor hata sınıfı.
- **Woo iptali `order.updated` topic'iyle gelir.** Bu yüzden
  `WooOrderNormalizer` içinde **durum alanı topic'i EZER**. Yalnızca
  topic'e bakılsaydı iptal bir güncelleme sanılır, stok geri **eklenmez**
  ve bakiye kalıcı olarak eksik kalırdı.
- **Webhook doğrulaması kiracı bağlamı olmadan çalışır.** İstek anonim
  gelir ve kiracı ancak bağlantı bulunduktan sonra bilinir; kimlik bilgisi
  açıkça `runAsSystem()` ile okunur. Bağlam beklenirse **meşru her webhook
  sessizce reddedilir** ve kanal sonsuza kadar yeniden gönderir.
- **Adapter başarısızlıkta istisna fırlatır**, `AdapterResult::failure()`
  dönmez. Sınıflandırma ve yeniden deneme kararı `PushInventory`'deki tek
  `try/catch`'te toplanır.
- **`delist` silmez, taslağa çeker.** Silme geri alınamaz ve kanaldaki
  yorumları, sıralamayı, SEO geçmişini de götürür.

## api_calls saklama kuralları (§13 · Faz 3)

- **ÖLÇÜT `expires_at`, DURUM KODU DEĞİL.** Saklama süresi satır
  YAZILIRKEN kararlaşır (`ChannelHttpClient::expiryFor()`: 2xx +7 gün,
  4xx/5xx +90 gün) ve o alanda donar. `PruneApiCalls` durum kodunu yeniden
  yorumlamaz — yorumlasaydı politika iki yerde yaşar, biri değiştiğinde
  diğeri sessizce eski kuralı uygular ve geçmiş satırlar yazıldıkları
  günün kuralıyla değil bugünün kuralıyla silinirdi.
- **SİLME PARTİLENİR** (`DELETE ... WHERE id IN (SELECT ... LIMIT n)`;
  PostgreSQL DELETE üzerinde LIMIT kabul etmez). Tek dev DELETE en çok
  yazılan tabloyu dakikalarca kilitler; senkron durmaz (günlükleme hatası
  çağrıyı düşürmez) ama iz kaybolur ve iz tam olarak sorun anında gerekir.
- **TUR BAŞINA ÜST SINIR VAR.** Bitene kadar dönen tarama günlük bakım
  penceresini saatlerce tutar ve `withoutOverlapping` yüzünden sonraki
  turlar hiç başlamaz — tarama kendi kuyruğunu kilitler. Kalan satırlar
  YARIN silinir; gecikmenin bedeli diskte biraz fazla günlüktür.
- **TRANSACTION YOK ve bu bilinçlidir.** Her parti kendi başına atomiktir
  ve silinen bir günlük satırının geri alınmasına gerek yoktur. Turu tek
  transaction'a sarmak, silinen her satırın kilidini tur sonuna kadar
  tutar ve tam olarak kaçınılmak istenen kilit birikimini üretir. Bu karar
  geri alınırsa `clock_timestamp()` kuralı KRİTİK hale gelir.
- **Zamanlama günlük 04:00** — saklama süreleri gün ölçeğindedir, saatlik
  koşmak aynı işi 24 kez yapar. Taksonomi turu 03:00'te bitiyor, ikisi
  aynı bakım penceresinde üst üste binmiyor.
- **`api_calls`'un MODELİ YOK** — tablo `DB::table()` ile yazılıp okunur
  ve `id` bigserial'dır. `runAsSystem()` sarmalayıcısı bu yüzden bugün
  davranış katmaz; niyeti belgelemek ve tabloya bir gün model eklenirse
  taramanın tek kiracıya daralmaması için duruyor.

## Koruma katmanı kuralları

- **Kova ve devre bağlantı başınadır**, kiracı başına değil. Sınırı koyan
  kanaldır ve kanal mağaza hesabını tanır; aynı kiracının iki Woo mağazası
  ayrı kotaya sahiptir.
- **Jeton kovası, sabit pencere değil.** Sabit pencere sınır çizgisinde iki
  kat isteğe izin verir (pencerenin sonunda N, hemen ardından N daha).
- **Kova Lua ile atomik**, zaman `TIME` ile **Redis'ten** okunur. Oku-hesapla-yaz
  ayrı komut olsaydı iki worker aynı jetonu alırdı; PHP saatleri kayarsa kova
  olduğundan hızlı dolardı.
- **`AUTHENTICATION` devreyi tek hatada ve SÜRESİZ açar** (TTL yok). Token
  geçersizken beklemek düzeltmez; `reset()` kullanıcı müdahalesiyle çağrılır.
- **`half_open`'da sonda TEKTİR** (`SET NX`). Tüm yükü bir anda geri salmak,
  toparlanmakta olan kanalı tekrar çökertir.
- **Sayaç başarıda sıfırlanır** — *ardışık* hata sayılır, toplam değil.
- **Devre açıkken veya kota boşken DENEME AÇILMAZ.** İş `release` edilir,
  `attempt_count` **0 kalır**: o operasyon denenmedi, ertelendi.
- **İkisi de Redis erişilemezken çağrıyı GEÇİRİR.** Koruma katmanının
  erişilemezliği, korumaya çalıştığı sorundan büyük zarar vermemeli.

## Kanal bağlama kuralları

- **Sağlık kontrolü geçmeden bağlantı `active` olmaz.** Kimlik bilgisi kasaya
  yazılır (çağrıyı yapabilmek için zorunlu) ama durum `pending` kalır ve
  `last_error` panelde gösterilir. Aktif ama çalışmayan bağlantı en pahalı
  hata biçimidir: kullanıcı ürün göndermeye başlar, hepsi AUTHENTICATION ile
  kalıcı hataya düşer.
- **Sağlıksızlığa düşen bağlantı `active`'ten geri çekilir** — sağlıksız
  kanala iş atılmaz. Bağlantı **silinmez**, işaretlenir: listing ve sipariş
  geçmişi ona bağlıdır.
- **`external_account_id` normalleştirilir** (`StoreUrl`): küçük harf, şema
  ve sondaki eğik çizgi atılır. Normalleştirilmezse aynı mağaza iki farklı
  kimlikle bağlanır ve global tekillik kısıtı hiçbir şey korumaz.
- **Şemasız adres `https` varsayar** — Woo anahtar çiftini Basic auth ile
  taşır, düz HTTP'de anahtar her istekte ağda açık gider.
- **Aynı kiracı aynı mağazayı yeniden bağlarsa yeni satır AÇILMAZ** — anahtar
  yenileme akışı budur. Yeni satır `(tenant, type, account)` kısıtını ihlal
  eder ve listing'ler eski bağlantıda kalırdı.
- **Sırlar `settings` içine yazılmaz** — orası şifrelenmemiş jsonb ve panele
  olduğu gibi gider. Yalnızca `base_url` orada durur.
- **Sağlık kontrolü transaction DIŞINDA** çalışır: ağ çağrısı transaction'ı
  saniyelerce açık tutardı. Arada süreç ölürse bağlantı `pending` kalır.
- **Adapter yetenekleri eager-load'da `adapter_class` gerektirir.**
  `with('channelType:code,name')` yazılırsa `AdapterRegistry` sınıfı bulamaz
  ve yetenekler sessizce boşalır.

## Katalog kuralları

- **Açılış stoğu ledger üzerinden girer.** `CreateProduct` `InventoryLevel`
  satırını yazmaz; IMPORT hareketi açar ve projeksiyon ondan türer. Doğrudan
  yazmak `on_hand = Σ on_hand_delta` eşitliğini ürün yaratılırken bozar.
- **Ürün + varyant + açılış hareketi TEK transaction.** Ayrı olsalardı araya
  düşen hata stoksuz varyant veya varyantsız ürün bırakırdı.
- **İçerik düzenlemesi stoğa DOKUNMAZ.** İçerik ve stok ayrı senkron
  alanlarıdır; başlık düzeltmesinin stok hareketi yaratması ledger'ı kirletir.
  Stok değişimi ayrı eylem: `AdjustStock` veya sipariş/iade yolları.
- **`content_version` düzenlemede ARTAR** — senkron kapısı bundan beslenir.
  Artmazsa değişiklik kanala hiç gitmez ve panelde "senkron" görünür.
- **Fiyat varyant seviyesindedir**; tek varyantlı üründe action onu varyanta
  taşır. Çok varyantlı üründe varyant başına düzenleme ayrı ekranın işi.
- **Varsayılan varyant ürünün SKU'sunu taşır** — ilk adımda ikinci bir SKU
  sormak kullanıcıya gereksiz karar yüklüyordu.
- **SKU kiracı içinde tekildir** (`UNIQUE(tenant_id, sku)`); ihlal
  `DuplicateSkuException` ile alan hatasına çevrilir, 500 verilmez.
- Modül sınırı: Catalog, Inventory'nin **modeline** yazmaz — `LockInventoryRows`
  ile kilitler, `ApplyMovement`'a yaptırır.

## Stok ekranı kuralları

- **Negatif `available` kırpılmadan gösterilir** ve eksik miktar ayrıca
  yazılır (§17 · P0). Kırpma yalnızca `OutboundQuantity::forChannel()` içinde.
- **Düzeltme de ledger üzerinden geçer**: panel `inventory_levels` satırını
  doğrudan güncellemez, `AdjustStock` bir `MANUAL_ADJUSTMENT` hareketi yazar.
  Böylece `on_hand = Σ on_hand_delta` korunur ve düzeltmenin izi kalır.
- **`MANUAL_ADJUSTMENT` EKLER**, eksiltmez — yön hareket türünden gelir ve
  miktar pozitif olmak zorundadır. Eksiltme uygun hareket türüyle yapılır.
- **Düzeltme idempotent DEĞİLDİR ve olmamalı**: iki ayrı sayım iki ayrı
  düzeltmedir. Siparişte çıpa dış olay kimliğidir; burada öyle bir kimlik
  yoktur ve uydurmak satıcının bilerek yaptığı ikinci düzeltmeyi yutardı.
- **Rozet sırası: kalıcı hata > geçici hata > bekliyor > senkron.**
  `error_permanent` kullanıcı müdahalesi bekler; "bekliyor" demek satıcıyı
  kendiliğinden düzelecek sanmaya iter ve o satıra hiç bakmaz.
- **Rozet yalnızca CANLI listeleri sayar** — taslak/delisted satıra stok
  gönderilmez, sayılırsa rozet asla temizlenmez.
- **`DB::table()` global scope'a TABİ DEĞİLDİR.** Ham sorguda kiracı filtresi
  AÇIKÇA yazılır; yazılmazsa çapraz kiracı sayımı sızar (mutasyonla bulundu).

## Sipariş ekranı kuralları (§13 · faz 1.6)

- **Fazla satış ve eşleşmemiş SKU AYRI uyarılardır.** Fazla satışta stok
  düşmüştür ve eksik görünür; eşleşmemiş satırda (`variant_id` NULL) stoğa
  HİÇ dokunulmamıştır ve tablo "her şey yolunda" der. Satıcı eşleştirmeyi
  yapana kadar bakiye olduğundan fazla görünür — ikisi tek uyarıda
  birleştirilirse bu sessiz hâl fark edilmez.
- **Rozet sırası: fazla satış > eşleşmemiş > stok düşüldü.** İkisi aynı
  siparişte olabilir; fazla satış SATILMIŞ ve stoğu eksiye düşmüş bir
  kalemdir ve kargo çıkışı gerçekten tehlikededir.
- **Stoğu hiç düşülmemiş sipariş "uygulandı" gösterilmez** — eşleşmemiş
  satır varken rozet `PENDING`'dir.
- **Rota model bağlaması KULLANILMAZ.** `SubstituteBindings` `web`
  grubundadır ve rota seviyesindeki `tenant` ara katmanından ÖNCE çalışır;
  bağlama kullanılsaydı sorgu kiracı bağlamı kurulmadan atılır ve izolasyon
  istisnası fırlatırdı. Kimlik `string` alınır ve kontrolcüde, kiracı
  scope'unun altında aranır — yetkilendirme kimliğin tahmin edilemezliğine
  dayandırılmaz.
- **Sayım sorguları AYRI AYRI kiracı filtresi taşır.** Satır sayımı ve üst
  özet iki farklı `DB::table()` sorgusudur; her biri ayrı bir boşluktur ve
  **her biri ayrı testtir**.
- **Filtreler `whereHas` ile kurulur, `join` ile değil** — sipariş başına
  birden çok satır vardır ve `join` çok kalemli siparişi listede tekrar
  ederdi.

## Panel kuralları

- **Kiracı bağlamı ara katmanda kurulur** (`EstablishTenantContext`), `web`
  grubunda **değil**: giriş ve kayıt rotaları kiracısızdır ve bağlam kurmaya
  çalışmak onları kendi üzerlerine yönlendirirdi.
- **Oturumdaki kiracı kimliğine ASLA olduğu gibi güvenilmez.** Her istekte
  `tenant_users` üzerinden üyelik doğrulanır. `BelongsToTenant` global
  scope'u bağlamı sorgulamaz, ona **güvenir**; çerezi kurcalayan biri başka
  kiracının verisine erişirdi.
- **İstek sonunda bağlam `finally` ile bırakılır.** `TenantContext` statiktir;
  Octane veya uzun ömürlü süreçte sonraki isteğe sızardı.
- **Fazla satış panelde GİZLENMEZ** (§17 · P0). Negatif `available` kırpılmadan,
  eksik miktarla birlikte gösterilir. Kırpma yalnızca kanala giden yükte
  meşrudur; panelde gizlemek satıcıyı eksikten habersiz bırakır.
- **Inertia'ya model gönderilmez**, yalnızca görünen alanlar. Modeli olduğu
  gibi paylaşmak parola hash'i ve kimlik bilgisi sızdırır.
- **`Auth::attempt()` oturum kimliğini zaten yeniliyor**
  (`SessionGuard::login()` → `session->regenerate(true)`). Controller'a ikinci
  bir `regenerate()` **eklenmez**; garantiyi `AuthenticationTest` doğrular.

## Ürün aktarımı kuralları (§13 · faz 1.5)

- **Create mi update mi sorusu `external_id` ile cevaplanır** — ama create'ten
  ÖNCE `findExistingListing()` sorulur. Satıcı ürünü kanal panelinden açmış
  olabilir; sormadan yaratmak **kopya listeleme** üretir ve geri alınamaz
  (yorumlar, sıralama, SEO geçmişi ilk üründe kalır).
- **Listing `draft` doğar, `live` işaretini kanal onayından SONRA
  `PushListing` yazar.** Canlı işareti stok fan-out'unun hedef filtresidir;
  kanalda karşılığı olmayan satıra stok göndermek her turda hata alır.
- **`external_id` başarısızlıkta YAZILMAZ** — kanal ürünü yaratmadı; yazmak
  sonraki turda var olmayan ürüne `update` çağırtır.
- **İçerik yükünde GRUPLAMA YOK.** Stoktan farklı olarak içerik listing
  başınadır (Woo ürün uç noktası tekil çalışır); `ListingPayloadBuilder`
  `InventoryBatchBuilder`'ın karşılığı değil, tekil yük üreticisidir.
- **Hash yalnızca İÇERİKTEN türer** — sürüm, zaman veya kimlik karışmaz.
  Sürüm "hangi olay", hash "hangi içerik" sorusunu cevaplar; karışırsa içerik
  değişmeden yapılan her gönderim satırı kirli gösterir ve mutabakat gerçek
  sürüklenmeyi gürültüde kaybeder. Anahtarlar sıralanır (`ksort`), yoksa aynı
  içerik alan sırasına göre farklı hash üretir.
- **`synced_hash` YENİDEN HESAPLANMAZ, `desired_hash`'ten kopyalanır.** İş
  kuyrukta beklerken kanonik içerik değişmiş olabilir ve o değişiklik henüz
  GÖNDERİLMEDİ; yeniden hesaplayan kayıt gönderilmemiş içeriği gönderilmiş
  gösterir.
- **Sağlıksız kanala gönderilmez**: `active` olmayan bağlantı ne listelenir ne
  kabul edilir. Katalog yeteneği `instanceof SupportsCatalog` ile okunur.
- **İkinci gönderme ikinci listing satırı AÇMAZ** — `(bağlantı, varyant)`
  tekildir; akış var olan satırı yeniden kullanır. Aynı sürüm iki kez
  gönderilirse sürüm kapısı ikinci operasyonu eler.

## Kuyruk işleri — iki kural, ikisi de gerçek worker'da bulundu

- **Kuyruğa giren iş kiracı bağlamını KENDİ kurar.** `Queue::looping` kancası
  her iş sınırında bağlamı temizler; `handle()` her koşulda bağlamsız başlar.
  Bağlam yükte taşınır, başta kurulur, `finally` ile bırakılır. `PushListing`
  panelden, `PushInventory` hem fan-out'tan hem seviye 2 taramasından
  (`runAsSystem`, bağlam YOK) atılır — ikisi de kendi kurar.
- **`TenantAwareJob::$tenantId` READONLY DEĞİLDİR** — PHP kısıtı:
  `SerializesModels::__unserialize()` özellikleri ALT SINIFIN kapsamından
  yeniden atar ve PHP, ana sınıfta tanımlı readonly özelliğin alt sınıf
  kapsamından ilklenmesine izin vermez. Readonly yapılırsa iş kuyruğa yazılır
  ama **bir daha asla okunamaz**: gerçek worker'da her outbox olayı düşer.
- Testler işi doğrudan kurup `handle()` çağırdığı için bu gidiş-dönüş hiç
  yaşanmaz. `JobSerializationTest` kuyruğa giren her işi serileştirip geri
  okur — yeni bir kuyruk işi eklendiğinde oraya da eklenir.

## Sıradaki adım

§6 taramaları, §13 · faz 1.4 kanal bağlama, ürün yönetimi, ürün/stok listesi,
**§13 · faz 1.5 (`PushListing` + panelden gönderme)** ve **faz 1.6 panel
maddesi (sipariş listesi + fazla satış uyarısı)** kapandı. Panelde sekiz ekran
var: özet · ürünler · ürün kanalları · siparişler · sipariş ayrıntısı · stok ·
kanallar · **eşleştirme**.

**Dikey dilim artık PANELDEN uçtan uca sürülebilir** — `PanelToChannelSliceTest`
zinciri ürün yaratmadan kanala girmesine kadar yürütüyor ve gerçek worker'da
da doğrulandı (ürün Woo'ya gitti, stok düzeltmesi arkasından ulaştı).

**§10 mutabakat sıcak katmanı da kapandı** — sürüklenme tespiti, onarım ve
zamanlama çalışıyor; gerçek Woo adapter'ıyla doğrulandı (kanalda 99, bizde 17
→ REPAIR → kanala 17 gitti).

**FAZ 2 KAPANDI.** Altı maddenin altısı da bitti: Trendyol istemcisi,
**taksonomi**, **eşleştirme arayüzü**, **katalog aktarımı + ön koşul
kapısı + onay takibi**, **stok/fiyat itme** ve **sipariş yoklaması**.
Faz 2 demosu uçtan uca doğrulandı: yoklanan Trendyol siparişi stoğu
düşürüyor, iptal geri ekliyor.

**Faz 3 sürüyor** (güvenilirlik). ÜÇ madde kapandı:
`UpdateOrderSnapshot` + `UpdateFulfillment`, **`PruneApiCalls`** ve
**`RequestResync` + T10**. **P0/P1'in tamamı yeşil; yazılmamış P0/P1
testi kalmadı.** **Sıradaki: fiyat senkron yolu (`PushPrices`)** —
adapter gövdeleri hazır, çekirdekte çağıranı yok; tetikleyici de bu
maddenin parçası. Faz 4 abonelik/ödeme hâlâ hafta 21–25'tir ve şimdi
yazılmamalı.

**YENİ PAZARYERLERİ — SIRAYA KONDU, ŞİMDİ YAZILMIYOR (19 Ağustos 2026).**
Kullanıcının kararı: **Hepsiburada → Amazon → Etsy → eBay**. Bu maddeler
**Faz 3 ve Faz 4 bittikten sonra** ele alınır. **Shopify KAPSAM DIŞI** —
kullanıcı açıkça istemedi; memory'deki eski "Node Shopify app" kararı
artık geçerli değil.

Sıranın gerekçesi: Hepsiburada Trendyol'un modeline en yakın (taksonomi +
zorunlu öznitelik + onay), o yüzden en düşük riskli ikinci pazaryeri.
Amazon en yüksek iş değeri ama en karmaşık: SP-API feed tabanlı asenkron
aktarım (`submitFeed` → sonuç yoklaması) muhtemelen §7'ye YENİ bir
yetenek arayüzü gerektirir — bu MİMARİ bir karardır, dokümana bakmadan
yapılmaz. Etsy OAuth+PKCE ve farklı envanter modeli; eBay'de
offer/inventory item ayrımı + zorunlu politika nesneleri bağlama akışına
ekstra adım ekler.

**Yeni kanal çekirdeği DEĞİŞTİRMEZ**: kanal başına bir adapter (+ mapper/
normalizer) yazılır; stok matematiği, outbox, fan-out, kilit ve mutabakat
aynı kalır. `if ($channel === '...')` YAZILMAZ — yetenek `instanceof` ile
okunur. Kanal başına kabaca 40–60 saat (Amazon'da fazlası).

**`pushPrices`'ın ÇEKİRDEKTE ÇAĞIRANI YOK** — bu turda bulundu ve
Woo'yu da kapsıyor. `SyncDomain::PRICE` ve `PRICE_PUSH` şemada var ama
fiyat operasyonu açan/dispatch eden hiçbir kod yok: `PushInventory`'nin
fiyat karşılığı (`PushPrices` işi) yazılmamış. `DetectStuckSyncOperations`
yalnızca `INVENTORY_PUSH` için iş atar, diğerine uyarı yazar — yani
davranış dürüst, eksik olan yol. Adapter gövdeleri sözleşmeye uygun ve
hazır; **fiyat senkronu ayrı bir çekirdek maddesidir** ve dokümanın Faz 2
listesinde ayrıca yer almıyor.

Panel tarafında hâlâ açık: **mutabakat panel ekranı** (`reconciliation_items`
yazılıyor ama gösterilmiyor) ve **`RequestResync` + T10** (§18 · P1, faz
1.6'da listeli ama yazılmadı).

**Abonelik/ödeme Faz 4'tür (hafta 21–25), şimdi değil.** §13 · Faz 4:
"Planlar, abonelik, kota, ödeme entegrasyonu (iyzico) — 26 sa". Şema kararı
alınmış (`tenants.plan_code` kolonu zaten var; §4 · `plans` kiracısız+seed,
`subscriptions` `UNIQUE(tenant_id) WHERE status='active'`; §3 · `Plan`,
`Subscription`, `UsageRecord` modelleri) ama **yazılmadı ve şimdi
yazılmamalı**: kota neyi sınırladığını senkron davranışından alır, o oturmadan
tanımlanan kota sonra değişir. Faz 4 demosu da bunu varsayıyor: "yeni
kullanıcı kaydolup ödeme yapıp senkronlayabiliyor".

Doküman §18 testlerin **önce** yazılmasını şart koşuyor.
