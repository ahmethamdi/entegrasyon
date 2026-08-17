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
docker compose exec app php artisan test      # 355 test yeşil olmalı
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

## Modül sınırı

Bir domain başka bir domainin **modeline** doğrudan yazmaz, yalnızca **action**
sınıfını çağırır. Orders, `InventoryLevel` satırını güncellemez; kilidi
`LockInventoryRows` ile alır, hareketi `ApplyMovement`'a yaptırır.

## Kurulu olan

`app/Domain/`: Identity · Catalog · Inventory · Channels · Messaging · Sync ·
Reconciliation
`app/Support/`: Tenancy · Uuid · Logging

29 domain tablosu, 28 model, 355 test. Stok çekirdeği (`ApplyMovement`,
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
yönetimi, ürün/stok listesi, ürün→kanal gönderme ekranı) yazıldı. P0 testleri
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

`TrendyolAdapter`, `UpdateOrderSnapshot`, `UpdateFulfillment`,
`PruneApiCalls`, sipariş listesi ekranı, mutabakat panel ekranı, ılık/soğuk
mutabakat katmanları (sıcak katman yazıldı).

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

§6 taramaları, §13 · faz 1.4 kanal bağlama, ürün yönetimi, ürün/stok listesi
ve **§13 · faz 1.5 (`PushListing` + panelden gönderme)** kapandı. Panelde beş
ekran var: özet · ürünler · ürün kanalları · stok · kanallar.

**Dikey dilim artık PANELDEN uçtan uca sürülebilir** — `PanelToChannelSliceTest`
zinciri ürün yaratmadan kanala girmesine kadar yürütüyor ve gerçek worker'da
da doğrulandı (ürün Woo'ya gitti, stok düzeltmesi arkasından ulaştı).

**§10 mutabakat sıcak katmanı da kapandı** — sürüklenme tespiti, onarım ve
zamanlama çalışıyor; gerçek Woo adapter'ıyla doğrulandı (kanalda 99, bizde 17
→ REPAIR → kanala 17 gitti).

1. **Sipariş listesi ekranı** (§13 · faz 1.6 panel maddesi) — "panelde sipariş
   listesi ve fazla satış uyarısı". Sipariş alımı çalışıyor ama panelde
   görünmüyor; kullanıcının siparişi göreceği tek yer yok.
2. **Mutabakat panel ekranı** — `reconciliation_runs` / `reconciliation_items`
   yazılıyor ama hiçbir yerde gösterilmiyor. Sürüklenme bulunduğunu kullanıcı
   göremiyor.
3. **Faz 2 · `TrendyolAdapter`** — ikinci kanal; adapter mimarisi hazır.

**Abonelik/ödeme Faz 4'tür (hafta 21–25), şimdi değil.** §13 · Faz 4:
"Planlar, abonelik, kota, ödeme entegrasyonu (iyzico) — 26 sa". Şema kararı
alınmış (`tenants.plan_code` kolonu zaten var; §4 · `plans` kiracısız+seed,
`subscriptions` `UNIQUE(tenant_id) WHERE status='active'`; §3 · `Plan`,
`Subscription`, `UsageRecord` modelleri) ama **yazılmadı ve şimdi
yazılmamalı**: kota neyi sınırladığını senkron davranışından alır, o oturmadan
tanımlanan kota sonra değişir. Faz 4 demosu da bunu varsayıyor: "yeni
kullanıcı kaydolup ödeme yapıp senkronlayabiliyor".

Doküman §18 testlerin **önce** yazılmasını şart koşuyor.
