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

### ✅ V3.0 — ONAYLANDI, UYGULAMA SÜRÜYOR (25 Ağustos 2026)

**Kaynak:** `docs/ENTEGRASYON-V3.0.md` · **PDF:**
`~/Desktop/Entegrasyon-Mimari-v3.0.pdf` (47 sayfa) · commit `cdb14f4`

Kapsam: **Shopify · Hepsiburada · Etsy · eBay** → altı kanal. 240 saat.

Kullanıcı PDF'i onayladı; **kod yazımı başladı**. Doküman implementation
referansıdır ve **kod ile çelişirse doküman esastır**.

**FAZ 0 KAPANDI** (`4b1600f` · 12 sa): `listings.channel_metadata`
migration · `SupportsTokenRefresh` + `TokenRefresher` ·
`credentials:refresh` komutu + 15 dk zamanlama · `ChannelTypeSeeder`
`is_active` düzeltmesi.

**FAZ 1 · SHOPIFY SÜRÜYOR** (52 sa · ~35'i bitti) — **1010 test yeşil**:

| Slice | Ne | Commit |
|---|---|---|
| 1.1–1.2 | İstemci + GraphQL sarmalayıcı (P0-1) | `9146315` |
| 1.3 | Katalog — create/update/delist/find/fetch | `7369c5c` |
| 1.4 | Ürün içe aktarma | `e443485` |
| 1.5 | **Stok** — mutlak değer, `inventoryItemId` | `9b0b651` |
| **1.6** | **Fiyat — SIRADAKİ (3 sa)** | — |
| 1.7–1.9 | Sipariş webhook · iptal/iade/kargo · `app/uninstalled` | — |

Shopify `is_active = false` ve panelde GÖRÜNMEZ (§05 · adım 1).
`taxonomy` ve `approval` **HİÇ AÇILMAYACAK** (§04 dipnotları).

#### Faz 0–1.5'te öğrenilen — kalıcı kurallar

- **Laravel'de `skipLocked()` METODU YOKTUR.** `FOR UPDATE SKIP LOCKED`
  için `->lock('for update skip locked')` yazılır. `lockForUpdate()`
  KULLANILAMAZ: düz `FOR UPDATE` ikinci turu BEKLETİR ve ardından AYNI
  satırı ikinci kez yeniler — refresh token TEK KULLANIMLIK olduğu için
  kanal ilkini iptal eder ve bağlantı ölür. Mutasyonla doğrulandı: test
  asılı kaldı.
- **Token yenileme İSTEK ANINDA DEĞİL TARAMAYLA** (P0-5). İki koruma
  katmanı gerekir: `withoutOverlapping` (aynı komut) + `FOR UPDATE SKIP
  LOCKED` (çok sunuculu kurulum).
- **Adapter kasaya YAZMAZ** — `refreshCredentials()` `RefreshedCredentials`
  döner, `CredentialVault::store()` çağrısını ÇEKİRDEK yapar. v2.2'nin
  "adapter yan etkisizdir" kuralı.
- **`channel_metadata` SIR TAŞIMAZ** (P0-9) — kolon şifresizdir ve panele
  Inertia prop'u olarak gider. Token/secret `channel_credentials`'ta.
  KİMLİK ≠ SIR. Kaynak taramasıyla korunur.
- **Mutasyon turu asılı transaction bırakabilir** ve sonraki tam test
  koşusu `DROP TABLE`'da bloke olur (bu turda yaşandı, 600 sn timeout).
  Teşhis: `pg_stat_activity WHERE state <> 'idle'`. Temizlik:
  `pg_terminate_backend`. DB kullanıcısı **`entegrasyon`**, `postgres` değil.
- **ADAPTER İKİ FARKLI BAĞLAMDAN ÇAĞRILIR.** Kuyruk işi kendi kiracı
  bağlamını kurar; mutabakat taraması `runAsSystem()` altında koşar ve
  bağlam YOKTUR. Adapter içinde model sorgusu yapılıyorsa `runAsSystem()`
  ile sarılmalıdır — sarılmazsa mutabakat turu o bağlantıda çöker
  (`97a7eb7` hata biçimi, slice 1.5'te testte yakalandı). Ham sorgu
  KULLANILMAZ: `DB::table()` kiracı filtresini ELLE yazdırır ve o filtre
  projede BEŞ KEZ unutuldu.
- **MUTASYON TESTİ KENDİ TESTİNDE SAHTE YEŞİL YAKALAYABİLİR.** Slice
  1.5'te "silinmiş varyant sıfır okunmaz" testi mutasyonu KAÇIRDI: `null`
  düğüm zaten önceki elemeye takılıyordu ve korunan satıra HİÇ
  ulaşılmıyordu. Gerçek tuzak farklı bir şekildeydi (stok takibi kapalı
  varyant: kimlik DOLU, miktar NULL). **Mutasyon kaçtıysa test yanlış
  senaryoyu kuruyordur** — testi düzelt, kuralı değil.
- **`channel_metadata` BİRLEŞTİRİLİR, EZİLMEZ** (`PushListing::
  adoptRemoteIdentity`). eBay'in üç adımlı yayını `offer_id`'yi ilk
  adımda, `listing_id`'yi üçüncüde yazar; ezilseydi ara başarısızlıktan
  sonraki tur `offer_id`'yi kaybeder ve `25002` duplicate alınırdı.

**Doküman değişirse İKİ DOSYA BİRDEN:** önce `docs/ENTEGRASYON-V3.0.md`
(tek gerçek kaynak), sonra `docs/pdf/build-v3.sh` ile PDF yeniden
üretilir. **Yalnızca PDF'i düzeltmek YASAKTIR** — PDF bir çıktıdır ve
sonraki üretim düzeltmeyi sessizce geri alır.

## Ortam — komutlar container içinde çalışır

```bash
docker compose up -d
docker compose exec app php artisan test      # 923 test yeşil olmalı
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

Bu karar KAPSAMINI DOLDURDU: çekirdek tarafında Faz 3'ten kalan madde
kalmadı ve o tarihten sonra üç panel ekranı daha yazıldı (mutabakat,
toplu içe aktarma, başarısız işlemler). **Ekran işi çıktığında
tarayıcıda doğrulama** kuralı yürürlükte ve ÜÇ TURDA DA gerçek boşluk
buldu.

## Modül sınırı

Bir domain başka bir domainin **modeline** doğrudan yazmaz, yalnızca **action**
sınıfını çağırır. Orders, `InventoryLevel` satırını güncellemez; kilidi
`LockInventoryRows` ile alır, hareketi `ApplyMovement`'a yaptırır.

## Kurulu olan

`app/Domain/`: Identity · Catalog · Inventory · Channels · Messaging · Sync ·
Reconciliation
`app/Support/`: Tenancy · Uuid · Logging

40 tablo (çerçeve dışı), 38 model, 923 test. Stok çekirdeği (`ApplyMovement`,
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

## Hepsiburada kuralları (üçüncü kanal · `356a662`)

**DOKÜMAN BU KANALI KAPSAM DIŞI BIRAKIYOR** (§16: "iki kanal kusursuz
çalışır"; kapsam dışı tablosu "Ay 7"). Faz 4 bittiği için kullanıcının
açık kararıyla açıldı — doküman ihlali değil, zaman çizelgesinin
dışına çıkış.

- **UÇ NOKTALAR DOĞRULANMADI ve TEK YERDE TOPLANDI**
  (`HepsiburadaEndpoints`). `developers.hepsiburada.com` bot isteklerini
  403 ile reddediyor. Adapter'a serpiştirilselerdi düzeltme on yere
  dokunmak olurdu ve biri unutulunca o çağrı SESSİZCE yanlış adrese
  giderdi. **Kanal `is_active = false`** ve panelde GÖRÜNMEZ;
  aktifleştirme sırası o sınıfın başlığında.
- **`User-Agent` KİMLİK DOĞRULAMANIN PARÇASIDIR** —
  `{merchantId} - {AppName}` eksikse kanal kimlik bilgisi DOĞRU olsa
  bile 401 döner. Bu, `97a7eb7`'de yaşanan "istek sessizce kimliksiz
  gitti" hatasının başka bir biçimidir: anahtar doğru, listing
  "anahtarın yanlış" diyerek ölür. Başlık desteği `ChannelHttpClient`'a
  **GENEL** olarak eklendi — başlığı ADAPTER verir, istemci taşır ve
  `if ($channel === '...')` YAZILMAZ (basic auth çiftlerinin tek yerde
  toplanmasıyla aynı gerekçe).
- **SATICI KİMLİĞİ YOKSA İSTEK HİÇ ATILMAZ.** Boş kimlikle
  `User-Agent: " - Entegrasyon"` giderdi; kanal 401 döner,
  `AUTHENTICATION` KALICI sayılır ve sebep hiçbir yerde görünmez.
- **STOK VE FİYAT AYNI YÜKTE GİDER — TRENDYOL'UN TERSİ.** Trendyol'da
  "stok yükü fiyat alanı TAŞIMAZ" katı kuraldı çünkü biri diğerini
  sessizce ezerdi. Hepsiburada'nın uç noktası ikisini birlikte bekliyor
  ve **eksik alanı SIFIR sayabiliyor**; kanal "stok 0 veya fiyat 0 =
  satışa kapat" diye yorumluyor. Yani orada birleştirmek neyse burada
  AYIRMAK odur. `pushInventory`/`pushPrices` yazılırken mevcut değer
  okunup yük TAMAMLANMALIDIR.
- **WEBHOOK VAR** (`X-HB-Signature` HMAC) — Trendyol'un aksine. Gelen
  hat kuralları aynen geçerli: ham gövde üzerinden, ayrıştırmadan önce,
  `hash_equals` ile. **Başlık adı BÜYÜK/KÜÇÜK HARF DUYARSIZ okunur** —
  vekil sunucular başlıkları yeniden yazar ve tam eşleşme aransaydı
  MEŞRU webhook reddedilir, kanal sonsuza kadar yeniden gönderirdi.
- **WEBHOOK SIRRI YOKSA DOĞRULAMA "GEÇTİ" DEMEZ** — güvenli taraf
  REDDETMEKTİR; kabul etmek imzasız sipariş enjeksiyonuna kapı açardı.
- **EN DÜŞÜK HIZ SINIRI SEÇİLİR** (10/sn). Kova BAĞLANTI başınadır ve
  tek kova iki farklı uç nokta sınırını (listing ~30/sn, sipariş
  ~10/sn) ayrı ayrı temsil edemez. Yüksek sınır sipariş çağrılarını
  sürekli 429'a sokardı; düşük sınırın bedeli yalnızca yavaşlıktır.
  **Dinamik öğrenme YOK** (Trendyol'un aksine): kanal sınırı yanıt
  başlığında bildirmiyor ve öğrenilecek başlık yokken "öğrenme" kodu
  yazmak, hiç çalışmayan ve hiç sınanamayan bir yol bırakırdı.
- **YER TUTUCU ADIYLA DOLDURULUR, KONUMLA DEĞİL** — konumla eşleştirme
  `{merchantId}` ve `{merchantSku}` sırası değişince sessizce yanlış
  değeri yazar ve istek BAŞKA satıcının SKU'suna giderdi (toplu içe
  aktarmadaki "kolonlar ADIYLA eşlenir" kuralının aynısı).
  **Doldurulmamış yer tutucu İSTİSNA fırlatır**: geçseydi istek literal
  `{merchantSku}` içeren adrese gider ve 404'ün sebebi görünmezdi.
- **PARTİ BOYUTU 1000'DE TUTULUYOR** (ikincil kaynak 4000 diyor).
  Sınır doğrulanmadı ve aşımın bedeli ağır: kanal isteği kısmen
  işlerse hangi satırın gittiği bilinmez. Küçük parti yalnızca daha çok
  istek demektir, yanlış sonuç değil.

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

## Fiyat senkron kuralları (§7 · §13 · Faz 3)

- **TETİKLEYİCİ `UpdateProduct`'TA ve AYNI TRANSACTION İÇİNDE.** Fiyatın
  ledger'ı yoktur (stokta tetik `ApplyMovement`'ın ledger transaction'ında
  yaşar), bu yüzden olay kolonu yazan yere ait. Ayrı transaction'da
  yazılsaydı araya düşen hata fiyatı değişmiş ama olayı yazılmamış bir
  varyant bırakır ve hiçbir tarama onu görmezdi.
- **FİYAT DEĞİŞMEDİYSE OLAY YAZILMAZ** ve karşılaştırma KURUŞ ölçeğinde
  tam sayı üzerinden yapılır: `decimal(12,2)` PHP'ye STRING döner, float
  karşılaştırması iki yönden de yanıltır. Her kaydetme fiyat turu açsaydı
  kanal kotası boşa gider ve mutabakat gerçek sürüklenmeyi gürültüde
  kaybederdi.
- **FİYAT MUTLAK ve STRING taşınır** — yüzde indirim veya delta asla; para
  float taşınmaz (yuvarlama kuruş kayması üretir).
- **YÜKTE `origin_connection_id` YOKTUR.** Fiyat değişimi PANELDEN gelir,
  bir kanaldan gelmez; bastırılacak kaynak kanal yoktur ve alan yazılsaydı
  o kanal gereksizce elenirdi. (Stokta durum farklıdır: değişim sipariş
  webhook'uyla bir kanaldan gelebilir.)
- **KUYRUK `price:high`** (§15 tablosu, standard havuz, 45 sn). Uydurma
  kuyruk adı işin Redis'te sonsuza kadar beklemesi demektir ve hiçbir hata
  görünmez; `PriceSyncTest` adı Horizon yapılandırmasıyla karşılaştırır.
- **`PriceBatchBuilder` yalnızca GRUPLAMA yapar**, fan-out yapmaz —
  `InventoryBatchBuilder` ile aynı kural. Gruplama BAĞLANTI BAŞINADIR:
  aynı varyantın Woo ve Trendyol listelemeleri AYRI yüklerde gider.
- **KIRPMA YOK** — stoktan farklı. Negatif bakiye kırpması
  `OutboundQuantity`'ye özgüdür; fiyatın negatif olma hâli yoktur.
- **`PricePushBatch` OPERASYON LİSTESİ TAŞIR.** Taşımazsa
  `SyncResultRecorder` hiçbir şey yazamaz: çağrı başarılı olur,
  `synced_version` yerinde kalır ve satır her turda yeniden gönderilir.

## Fiyat çakışması kuralları (§9 · `fd8cbe1`)

v2.2'nin kod tarafındaki SON açık maddesiydi ve kapandı.

- **STOKTA ÜZERİNE YAZILIR, FİYATTA YAZILMAZ** (§9 · domain başına
  politika). Gerekçe dokümanda yazılı: satıcılar kanal panelinden
  kampanya yapıyor ve **sessizce ezmek EN SIK ŞİKAYET**. Stokta tek
  otorite biziz; fiyatta DEĞİLİZ.
- **REPAIR ADIMI FİYATTA ATLANIR ve AYRI BİR DOMAIN KOŞULU YAZILMAZ.**
  Kapı `ItemStatus::isDrift()`'tir ve `PRICE_CONFLICT` orada `false`
  döner; `ReconcileConnection` onarımı zaten açmaz.
  `if ($domain === PRICE)` yazılsaydı kural İKİ yerde yaşar ve biri
  değiştiğinde ötekinin sessizce eski kalması an meselesi olurdu.
- **`PRICE_CONFLICT`, `REMOTE_UNREACHABLE`'IN KARDEŞİ AMA GEREKÇESİ
  TERSTİR**: orada fark KANITLANMAMIŞTIR (altyapı sorunu), burada
  KANITLIDIR ve yalnızca onarım MEŞRU DEĞİLDİR.
- **İKİ DOMAIN, TEK AKIŞ** — §10'un "üç katman tek akış" kuralının
  kardeşi. Fiyat turu `ReconcileConnection`'ın KOPYASI DEĞİLDİR; aynı
  beş adımı yürütür ve yalnızca ÜÇ noktada ayrışır: hangi yetenek
  okunur (`SupportsInventory`/`SupportsPricing`), beklenen değer nereden
  gelir (kırpılmış bakiye / kanonik fiyat), fark bulununca ne yazılır.
- **KABUL EDİLEN FİYAT BİR DAHA EZİLMEZ** — `PriceBatchBuilder`
  override'lı listing'i yüke ALMAZ. Bu olmadan özellik ANLAMSIZDIR:
  satıcı "kabul ettim" der, sistem bir sonraki turda üzerine yazardı.
  Atlanan operasyonun DURUMU değişmez (`pending` kalır) — "yükte
  olmayan operasyona dokunulmaz" kuralı.
- **BAYAT OVERRIDE ELEMEZ.** Karar "89.90 mı 99.90 mı" sorusuna
  verilmişti; satıcı panelden 149.90 yaparsa o karar BAŞKA bir soruya
  verilmiştir. Yok sayılmasaydı panelden yapılan zam o kanala SESSİZCE
  hiç gitmez ve satıcı eski fiyattan satmaya devam ederdi.
- **"HANGİ FİYAT GİDER" TEK KAYNAKTIR** (`ResolveChannelPrice`):
  gönderim ve mutabakat AYNI cevabı okur. İki yerde hesaplansaydı biri
  override yollar öteki kanonik bekler ve her tur SAHTE çakışma
  raporlanırdı — satıcı kabul ettiği kampanyayı sonsuza kadar yeniden
  kabul ederdi.
- **PARA KARŞILAŞTIRMASI KURUŞ ÖLÇEĞİNDE TAM SAYIDIR** ve `round()`
  ZORUNLUDUR: `19.90 * 100` IEEE-754'te `1989.99...` olabilir, `(int)`
  cast'i onu aşağı keser. `"79.90"` ile `"79.9"` AYNI fiyattır; metin
  karşılaştırılsaydı her tur sahte çakışma üretirdi.
- **`DriftHistory` DOMAIN FİLTRELİDİR** ve bu `tenant_id` filtresinin
  aksine BUGÜN GERÇEK BİR SAVUNMADIR: aynı listing hem stok hem fiyat
  kalemi taşır. Karışsalardı bir stok `MATCHED`'ı fiyat sürüklenme
  zincirini KIRAR (sonsuz döngü emniyeti devre dışı kalır) ya da iki
  fiyat çakışması hiç sürüklenmemiş bir stok satırını üçüncü turda
  `MANUAL_REVIEW`'a düşürürdü.
- **FİYAT TURU `recently_sold` KULLANMAZ** — satış fiyatı DEĞİŞTİRMEZ
  ve o sorgu `inventory_movements` üzerinden çalışır. Koşsaydı bütçe
  fiyatı hiç değişmemiş satırlarla dolar ve gerçek çakışmalar dışarıda
  kalırdı; üstelik çakışma tam da SATMAYAN üründe uzun süre fark
  edilmeden durabilir. Soğuk katmanın dört sorguyu çalıştırmama
  kararının kardeşi: sorgu kümesi KAPSAMDAN türer.
- **`PRICE_CONFLICT` ADAY DEĞİLDİR** (`drift_detected` sorgusu onu
  içermez). Karar bekleyen satırı her turda yeniden okumak bütçeyi —
  satıcı karar verene kadar, belki günlerce — aynı satıra harcardı.
  `error_permanent` kuralının aynısı: satır ancak kullanıcı
  müdahalesiyle akışa döner.
- **ÇÖZÜLÜNCE DURUM `PRICE_CONFLICT` KALIR**, yalnızca `resolved_at`
  damgalanır: kalem o turda GERÇEKTEN çakışma bulmuştu ve `MATCHED`
  yazmak "zaten doğruydu" demek olurdu. **BEDELİ:** ekran filtresi ve
  özet sayımı `resolved_at IS NULL` kapısını AÇIKÇA taşımak zorundadır;
  yalnızca duruma bakan bir filtre satıcının kararını verdiği satırı
  ekranda bırakır ve aynı karar tekrar tekrar verilirdi.
- **`reconcile:prices` SAATLİK ve DAKİKA 30'DA.** Yanlış stokun bedeli
  fazla satıştır ve dakikalar içinde müşteriye yansır (sıcak katman beş
  dakikada); fiyat çakışmasında satıcının kampanyası ZATEN YÜRÜYOR ve
  tespit onu durdurmaz. Dakika 30 bilinçlidir: `reconcile:warm` de
  saatliktir ve `withoutOverlapping` yalnızca AYNI komutu korur.
- **YETENEĞİ OLMAYAN KANAL SESSİZCE ATLANIR** (`ReconcileActiveConnections`).
  İstisnaya bırakılsaydı her tur, `SupportsPricing` uygulamayan her
  bağlantı için bir uyarı satırı yazar ve gerçek arızalar gürültüde
  kaybolurdu.
- **KANAL FİYATI KALEMDEN OKUNUR, KANALDAN YENİDEN OKUNMAZ.** Satıcı
  ekranda gördüğü değere karar verdi; yeniden okunsaydı arada değişmiş
  bir fiyatı görmeden kabul etmiş olurdu.
- **FLASH EKRAN BAŞINA ÇİZİLİR — layout GÖSTERMEZ.** `share()` mesajı
  props'a koyar ama çizen ortak bir yer yoktur. Gerçek çalıştırmada
  bulundu: `assertSessionHas('success')` YEŞİLKEN ekranda hiçbir şey
  görünmüyordu.

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

- **ÜÇ KATMAN, TEK AKIŞ.** Sıcak (5 dk · ≤50) · ılık (saatlik · ≤300) ·
  soğuk (günlük · aktif listing'lerin %2'si, üst sınır 500). Beş adım
  (DETECT/RECORD/CLASSIFY/REPAIR/VERIFY) üçünde de AYNIDIR; değişen
  yalnızca **aday seçimi** ve **bütçe**. Akış katman başına kopyalansaydı
  üç kopya zamanla ayrışır ve `max(available, 0)` gibi bir kural birinde
  düzeltilip ötekilerde eski hâliyle kalırdı.
- **PENCERELER `ReconciliationScope`'TAN GELİR**, sorguya gömülü değil.
  Sıcak 30 dk satış / 1 sa bekleme, ılık 24 sa / 24 sa. Ilık katman sıcakla
  AYNI eşikleri kullansaydı 300'lük bütçesini sıcak turun her beş dakikada
  bir zaten baktığı satırlarla doldurur ve **hiçbir şey eklemezdi**.
- **SOĞUK KATMAN DÖRT SEBEP SORGUSUNU ÇALIŞTIRMAZ** — kapsamı "rastgele
  örneklem — uzun kuyruk"tur. Uzun kuyruk tam olarak o dört sebebin
  hiçbirine takılmayan satırdır: satmıyor, hata almamış, bekleyen işi yok,
  sürüklenme geçmişi yok. Satıcı kanal panelinden stoğu elle değiştirdiyse
  o sürüklenme sıcak/ılıkta **sonsuza kadar görünmez**. Dört sorgu soğukta
  da koşsaydı soğuk katman ılığın günlük bir kopyası olurdu.
- **SIRALAMA `last_observed_at NULLS FIRST` — "rastgele" DEĞİL, EN ESKİ.**
  §4 bu iş için açıkça `sync_states_observed_idx` tanımlar ve o indeksin
  başka kullanıcısı yoktur. `ORDER BY random()` hem indeksi kullanamaz hem
  de %2 bütçeyle bir satırın **aylarca** seçilmemesi demektir. `NULLS
  FIRST` kritiktir: hiç gözlenmemiş satır sürüklenmeye en açık olandır ve
  `NULLS LAST` olsaydı dar bütçede asla seçilmezdi.
- **SOĞUK BÜTÇE ORANSALDIR**, 500 yalnızca ÜST SINIR. Sabit 500 kullanmak
  50 listing'i olan bağlantıda **tam katalog taraması** demektir ve o
  hiçbir katmanda yoktur. Alt sınır 1 — küçük katalogda %2 sıfıra yuvarlanır
  ve soğuk katman o satıcılar için hiç çalışmazdı.
- **BÜTÇE TABANI ÖRNEKLEM HAVUZUYLA AYNI KÜMEDİR** (gerçek çalıştırmada
  bulundu). `activeListingCount()` ile örneklem sorgusu aynı yüklemleri
  taşır — `error_permanent` dahil. Ayrışsalardı kalıcı hatası çok olan
  bağlantıda "%2'sine bak" sessizce daha büyük bir orana dönerdi.
- **ONARIM DÖNGÜ EMNİYETİ — 3 TUR KURALI.** Onarım sürüm kapısını ATLAR ve
  `desired_version`'ı ARTIRMAZ; bedeli, kanal 200 dönüp değişikliği
  UYGULAMIYORSA aynı farkın her turda yeniden onarılmasıdır (sıcak katmanda
  beş dakikada bir, sonsuza kadar). İki tur onarım açar; **üçüncü ardışık
  sürüklenmede otomatik onarım DURUR** ve kalem `MANUAL_REVIEW` işaretlenir.
  Sürüklenme yine SAYILIR — emniyet onarımı durdurur, gerçeği gizlemez.
- **SAYAÇ GEÇMİŞTEN TÜRETİLİR** (`DriftHistory`), ayrı kolon YOK.
  `reconciliation_items` zaten gerçeği taşıyor; ayrı sayaç, kalem yazan her
  yolun onu da güncellemesini zorunlu kılar ve biri unutulunca iki gerçek
  kaynağı sessizce ayrışır. Sayılan şey **ARDIŞIKLIKTIR**, toplam değil:
  araya giren eşleşme zinciri KIRAR. Emniyet kalıcı ceza değildir — kanal
  düzelip bir tur eşleşince kendiliğinden kalkar.
- **`REMOTE_UNREACHABLE` NE SAYILIR NE ZİNCİRİ KIRAR.** Sayılsaydı üç kez
  düşen bir kanal hiç sürüklenmemiş listing'i onarım dışına atardı;
  zinciri kırsaydı gerçek bir sonsuz döngü tek bir ağ hatasıyla yeniden
  başlardı (gerçek kanallarda geçici hata kuraldır). O tur YOK SAYILIR.
- **`MATCHED` ile `REPAIRED` AYRIDIR.** Eşleşme bir onarımın ARDINDAN
  geldiyse `REPAIRED` yazılır: "zaten doğruydu" ile "bozuktu ve onarımımız
  tuttu" farklı şeylerdir ve ikisi tek duruma sıkıştırılsaydı onarımın işe
  yarayıp yaramadığı hiçbir yerde kayıtlı olmazdı.
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

## Ölü mektup ekranı kuralları (§12 · §13 · Faz 3 · madde 3+4)

- **§12'nin BEŞ ADIMININ İLK ÜÇÜ ZATEN ÇALIŞIYORDU** (operasyon `dead`,
  sync state `error_*`, `failed_jobs`); ekran ve buton dördüncü ve
  beşinci adımdır. Onlar olmadan ölü satır SONSUZA KADAR ölü kalır:
  `error_permanent` mutabakatta ASLA aday değildir (§10) ve o satıra
  başka hiçbir mekanizma dokunmaz.
- **DURUM YAZMAK YETMEZ** (§9 · Karar 18). Buton
  `sync_operations.status = 'pending'` YAZMAZ; `RequestResync` çağırır
  ve o, aynı transaction'da `ListingResyncRequested` yazar. Kanonik veri
  değişmediği için durum değişikliği tek başına hiçbir iş üretmez.
- **ESKİ ÖLÜ OPERASYON `dead` KALIR.** Yeniden deneme YENİ operasyon
  açar (`intent=REPAIR`, anahtar `resync:` ön ekli). Eskisini
  `pending`'e çevirmek "beş kez denendi ve öldü" denetim izini silerdi.
- **DOMAIN OPERASYON TÜRÜNDEN OKUNUR** (`SyncDomain::
  fromOperationType()`). `sync_operations`'ta `domain` kolonu YOK.
  Sabit `INVENTORY` yazılsaydı ölü bir `PRICE_PUSH` için stok senkronu
  açılır ve fiyat HİÇ gitmezdi. Tanınmayan tür NULL döner ve satır
  ATLANIR — uydurma bir domain yanlış alanı senkronlardı.
- **HATA SINIFI VE TAVSİYE GÖSTERİLİR, ÖZET KALICI/GEÇİCİ AYIRIR.**
  `AUTHENTICATION` (anahtarı yenile) ile `VALIDATION` (veriyi düzelt)
  kullanıcıya FARKLI iş yaptırır; tek sayıda birleştirilselerdi satıcı
  "hepsini yeniden dene"ye basar ve müdahale bekleyenler aynı hatayla
  yeniden ölürdü.
- **SON DENEMENİN MESAJI OKUNUR**, ilkininki değil — sıralama
  `attempt_number` üzerinden (zaman damgası saniye hassasiyetli).
- **GUZZLE GÖVDEYİ 120 KARAKTERDE KESER** ve `(truncated...)` ekler;
  `json_decode` TEK BAŞINA YETMEZ ve ham metin basılırsa satıcı
  `ürün` okur. Kırpık gövdeden `message` regex ile çekilir,
  yarım kaçış dizisi atılır, metnin yarım olduğu `…` ile belirtilir.
- **FLASH ANAHTARI `success`** — panelin paylaştığı ad budur
  (`HandleInertiaRequests::share()`). Uydurma ad Inertia'ya HİÇ
  ULAŞMAZ ve kullanıcı butonun çalıştığını göremez.

## Metrik kuralları (§11 · §13 · Faz 3 · madde 2)

- **ANLIK GÖRÜNTÜ ALINIR, PANEL CANLI SORGU YAPMAZ.** Asıl sebep sorgu
  ağırlığı değil: grafik GEÇMİŞ ister ve canlı sorgu yalnızca ŞU ANI
  verir — "artıyor mu" sorusunu asla cevaplayamaz. `metric_snapshots`
  bir ZAMAN SERİSİDİR; her tur EKLER, üzerine YAZMAZ.
- **ÖLÇÜLEMEYEN METRİK SIFIR YAZMAZ.** Veri yoksa satır HİÇ yazılmaz;
  sıfır yazılsaydı grafik "her şey mükemmel" derdi, oysa ölçüm
  YAPILAMADI. İSTİSNA `outbox_consume_gap` ve `sync_delivery_gap`:
  orada sıfır GERÇEK bir ölçümdür ve eşik zaten sıfırdır.
- **`metric_snapshots` KİRACIYA AİT DEĞİLDİR** (§4: `tenant_id` kolonu
  yok) — metriklerin çoğu sistem genelidir ve `tenant_id` zorunlu
  olsaydı onlara uydurma kiracı yazmak gerekirdi. Kapsam `scope`
  kolonunda metin: `system` · `tenant:{id}` · `connection:{id}`.
  **BEDELİ: global scope BU TABLODA ÇALIŞMAZ** ve panel filtresi elle
  yazılır.
- **KAPSAM BİÇİMİ SÖZLEŞMEDİR.** `MetricScope` tek kaynaktır ve biçim
  BEKLENEN METİNLE sınanır: yazan da okuyan da aynı yardımcıyı
  çağırdığı için önek değişse ikisi BİRLİKTE kayar, davranış testleri
  yeşil kalır ve eski satırlar HİÇ BULUNAMAZ.
- **EŞİKLER KAPSAMLIDIR** (§11): fazla satış ve ölü iş KİRACI başına,
  api gecikmesi ve 429 KANAL başına. Sistem geneli toplansaydı yüz
  kiracılı kurulumda tek kiracının sorunu gürültüde kaybolur ve
  "kiracı başına 5" kuralı hiç uygulanamazdı.
- **EŞİK `Metric::threshold()` İÇİNDE TEK KAYNAKTIR** (§11 tablosu
  birebir). Panelde yeniden tanımlansaydı biri değiştiğinde rozet
  sessizce yanlış renk gösterirdi.
- **ORAN ÖLÇÜLÜR, HAM SAYI DEĞİL** (hata oranı, sürüklenme): yüz
  denemede beş hata ile on binde beş tamamen farklı sağlık durumlarıdır.
- **`REMOTE_UNREACHABLE` SÜRÜKLENME SAYILMAZ** (§10 ile aynı kural) ama
  paydada KALIR: kontrol edilmiştir.
- **TARAMA `runAsSystem()` İLE TÜM KİRACILARI GÖRÜR.** Bağlam altında
  koşsaydı sistem metrikleri SESSİZCE yanlış çıkardı.
- **SIFIR OLAN KİRACI/KANAL İÇİN SATIR YAZILMAZ** — sorunsuz kiracılar
  tabloyu saatlik doldurur ve gerçek sinyal binlerce "0" arasında
  kaybolurdu.

## Kanaldan ürün çekme kuralları (§13 · Faz 3 · madde 5 · `99008b8`)

- **§7'YE SEKİZİNCİ YETENEK ARAYÜZÜ EKLENDİ** — `SupportsCatalogImport`.
  Bilinçli ve KULLANICI ONAYLI sapma: §13 maddeyi istiyor ama §7'de
  karşılığı yok. `SupportsCatalog`'un iki okuma metodu da YEREL kayıttan
  başlar (`findExistingListing(Variant)`, `fetchListing(Listing)`); içe
  aktarma TERSİNİ sorar — "kanalda ne var ki bende YOK". `fetchListing`
  bu iş için kullanılamaz: elde `Listing` satırı olmasını şart koşar,
  oysa içe aktarmanın amacı o satırı YARATMAKTIR.
- **`SupportsCatalog`'A EKLENMEDİ.** Trendyol onu uygular ama toplu
  listeleme orada AYRI uç noktadır. Eklenseydi Trendyol ya istisna
  fırlatırdı (panel yeteneği ayırt edemezdi) ya sessizce boş dönerdi
  (§7'nin açık yasağı). Registry anahtarı `catalog_import` ve
  `catalog`'tan AYRIDIR: bir kanal yalnızca birini destekleyebilir.
- **VAR OLAN SKU'DA STOK YAZILMAZ** — bu maddenin EN TEHLİKELİ hatası.
  Kanaldaki stok BAYAT olabilir (biz henüz göndermemişizdir ya da kanal
  uygulamamıştır). Uygulansaydı SATILMIŞ mallar bir içe aktarma turuyla
  geri gelir, bakiye sessizce bozulur ve fazla satışa yol açardı. Kanal
  stoğu YALNIZCA yeni üründe ve YALNIZCA açılış hareketi olarak yazılır —
  o an ezilecek kanonik bakiye YOKTUR. `applyUpdate()` stok parametresi
  ALMAZ (`UpdateProduct` de almaz) ve bu, kuralın koda gömülü hâlidir.
- **FİYAT `regular_price`'TAN OKUNUR, `price`'TAN DEĞİL.** `price`
  indirim varsa indirimli değeri taşır; kanonik fiyata yazılsaydı
  satıcının LİSTE fiyatı kalıcı düşer ve kampanya bitince o düşük fiyat
  TÜM kanallara yayılırdı.
- **NULL FİYAT "DEĞİŞMEDİ" DEMEKTİR, "SIFIRLA" DEĞİL** — `UpdateProduct`
  null fiyata dokunmaz. `(float)` dönüşümü yapılsaydı fiyat göndermeyen
  kanal ürünü 0.00'a düşürürdü.
- **`internal_category_id` ASLA EZİLMEZ** — satıcının eşleştirme
  çıpasıdır ve kanal verisinde karşılığı yoktur; null geçilseydi her tur
  eşleştirmeleri sessizce koparırdı.
- **SKU'SUZ ÜRÜN ATLANIR ama SAYILIR ve ADIYLA raporlanır.** Woo'da SKU
  zorunlu DEĞİLDİR. Sessizce düşseydi satıcı "50 ürünüm vardı, 47'si
  geldi" der ve sebebini bulamazdı. Kanal kimliğinden SKU UYDURULMAZ:
  satıcı aynı ürünü kendi SKU'suyla yüklediğinde KOPYA ürün doğardı.
- **SAYFA ÜST SINIRI EMNİYETTİR** (`maxImportPages()`): `hasMore` sonsuza
  kadar `true` dönen bozuk kanal turu bitirmezdi. Sınıra takılan tur
  kullanıcıya SÖYLER — sessiz kırpma yok (§13).
- **İMLEÇ OPAKTIR** (`OrderPage` ile aynı kural) ve `hasMore`
  `nextCursor !== null` ile AYNI ŞEY DEĞİLDİR: kanal son sayfada bile
  imleç döndürebilir; turu durduran `hasMore`'dur.
- **TEK BOZUK ÜRÜN TURU DÜŞÜRMEZ; SAYFA HATASI DURDURUR ama YAZILANLARI
  KORUR.** Ayrım bilinçli: ürün bozukluğu o ürüne özgüdür, sayfa
  çekilemiyorsa kanal konuşmuyordur. Tur TEK TRANSACTION'A SARILMAZ.
- **`product_imports` GENİŞLETİLDİ, İKİNCİ TABLO AÇILMADI** (`source`,
  `channel_connection_id`, `skipped_count`; `payload` nullable oldu).
  İki tablo olsaydı `status`/`errors` sözleşmesi iki yerde yaşar ve biri
  değiştiğinde diğeri sessizce eski kalırdı. Kullanıcı için ikisi de
  "bir içe aktarma turu"dur; kaynak bir KOLONDUR.

## Uyarı e-postası kuralları (§11 · §12 · `bbe2852`)

- **AYNI UYARI AYNI GÜN İKİ KEZ GİTMEZ** — bu maddenin varlık sebebi.
  Eşik aşımı KALICI bir durumdur ve her turda yeniden ölçülür; koruma
  olmasaydı aynı uyarı tur tur gider, gelen kutusu dolar ve İNSANLAR
  UYARILARI OKUMAYI BIRAKIRDI — o noktadan sonra gerçek bir olay da
  fark edilmez. Çıpa `alert_deliveries (alert_key, sent_on)` tekilliği.
- **ÇIPA GÖNDERİMDEN ÖNCE YAZILIR** ve yarışı `insertOrIgnore` çözer.
  Sonra yazılsaydı iki paralel tur aynı uyarıyı iki kez gönderir ve
  ihlal ancak e-posta gittikten SONRA fark edilirdi. "Yazdım ama
  gönderemedim" BİLİNÇLİ olarak kabul edilir: bir uyarıyı kaçırmak,
  aynı uyarıyı iki kez göndermekten iyidir.
- **`sent_on` DATE'tir, timestamp DEĞİL.** Tekillik "aynı gün" sorusunu
  cevaplamalı; timestamp saniye taşıdığı için iki gönderim asla
  çakışmaz ve kısıt hiçbir şey korumazdı.
- **TARAMA ÖLÇMEZ, `metric_snapshots`'ı OKUR.** On üç ağır toplama
  sorgusu yeniden koşsaydı iki gerçek kaynağı doğardı (turlar farklı
  anlarda çalışır) ve `percentile_cont` maliyeti iki kez ödenirdi.
- **EŞİK `Metric::threshold()` İÇİNDE TEK KAYNAK**; karşılaştırma
  `breaches()` ile yapılır, `>` / `>=` yeniden YAZILMAZ. **Eşiğe TAM
  dayanan değer AŞIM DEĞİLDİR** (§11 "büyüktür") — yoksa panel yeşilken
  e-posta giderdi.
- **SON ÖLÇÜM `id` İLE SEÇİLİR, `captured_at` İLE DEĞİL** —
  `MetricsController` ile AYNI kural (saniye hassasiyeti). `DISTINCT ON`
  kullanılır; `MAX(id)` PostgreSQL'de uuid için YOKTUR.
- **KİRACI uyarısı SAHİPLERE, SİSTEM ve BAĞLANTI uyarısı YÖNETİCİYE**
  gider. Bağlantı uyarısı (api gecikmesi, 429) satıcının
  düzeltebileceği bir şey değil, altyapı sorunudur.
- **YÖNETİCİ ADRESİ TANIMSIZSA GÖNDERİLMEZ ve ÇIPA DA YAZILMAZ** —
  yazılsaydı o günün uyarısı yanar ve adres tanımlanınca bir daha
  gönderilemezdi. Atlanan uyarı `alerts.no_recipient` ile günlüğe
  yazılır, SESSİZCE kaybolmaz.
- **DAVETİ KABUL ETMEMİŞ ÜYEYE GÖNDERİLMEZ** (`accepted_at` NULL):
  adres doğrulanmış sayılmaz ve uyarı yabancı gelen kutusuna düşerdi.
- **§12'NİN "KİRACI BAŞINA 10'DAN FAZLA ÖLÜ İŞ" MADDESİ AYRI YOL
  DEĞİLDİR** — `DEAD_OPERATIONS` metriği zaten kiracı başına ölçüyor ve
  eşiği 10. Ayrı özet yazılsaydı eşik İKİ YERDE yaşar ve ayrışırdı.
- **SAĞLAYICI KODA GÖMÜLMEZ.** Laravel'in `Mail` cephesi kullanılır;
  seçim `.env`'deki `MAIL_MAILER` ile yapılır ve KOD DEĞİŞMEZ. Yerelde
  `log` sürücüsü kullanılıyor (e-postalar `storage/logs`'a düşer).
- **Mailable `ShouldQueue` UYGULAMAZ:** tarama zaten zamanlanmış bir
  komutta çalışır; kuyruğa atmak gönderimi aynı gün çıpasından AYIRIR
  ve düşen bir iş, çıpası yazılmış ama e-postası hiç gitmemiş bir kayıt
  bırakırdı — kaydı gören kimse bunu anlayamazdı.
- **DEĞER VE EŞİK BİRLİKTE gösterilir** ("9 — eşik 5"): sayı tek başına
  bir şey söylemez. Metrik başına TAVSİYE de yazılır ve doğru ekrana
  yönlendirir (ölü mektup ekranındaki kuralın aynısı).
- **`MetricUnit::format()` SIFIRI BOŞ DİZEYE DÜŞÜRMEZ.** Kırpma
  yalnızca ONDALIK kısma uygulanır; tüm sayıya uygulanırsa `"0"` boşa
  düşer ve eşiği sıfır olan metrikler e-postada eşiksiz görünür —
  GERÇEK ÇALIŞTIRMADA bulundu ve o iki metrik tam da uyarı üreten
  metriklerdi. Aynı kırpma `10`'u `1`'e de düşürürdü.

## Güvenlik kuralları (§11 · `1cc6720` · `05b336e` · `fbf1eb7`)

Kontrol listesi `docs/GUVENLIK-KONTROL-LISTESI.md`, yedek prosedürü
`docs/YEDEK-GERI-YUKLEME.md`. Liste dokümanın §11'inden TÜRETİLDİ.

- **KALICI YAZILAN HER HATA METNİ MASKELENİR** — `ChannelErrorText` TEK
  kaynaktır ve üç kolonu birden korur (`channel_connections.last_error`,
  `sync_attempts.error_message`, `listing_sync_states.last_error`).
  Ham `$e->getMessage()` yazmak SIR SIZDIRIR: Laravel'in
  `RequestException::prepareMessage()` yanıt GÖVDESİNİN ilk 120
  karakterini mesaja gömer ve kanal 401 gövdesinde anahtarı yansıtırsa
  sır `last_error` → Inertia prop → TARAYICI zincirini izler. Kasa
  şifrelemesinin tüm anlamı kaybolur.
- **MASKELEME KİMLİK BİLGİSİNİ `runAsSystem()` İLE OKUR.** Bağlam
  bekleseydi kuyruk işinde katman 2 SESSİZCE devre dışı kalır ve
  maskeleme yapıldığı sanılırken hiçbir şey maskelenmezdi (`97a7eb7`'de
  yaşanmış hata biçimi).
- **`DB::table()` KİRACI FİLTRESİ — BEŞİNCİ KEZ ISIRDI.**
  `ProcessInboxMessage`'ın koşullu geçişinde filtre yoktu: yanlış
  eşleşmiş bir çift BAŞKA kiracının inbox satırını `processing` yapıyor,
  ardından gelen KAPSAMLI `find()` satırı bulamıyor ve iş sessizce
  çıkıyordu. Satır artık `pending` olmadığı için `inbox:recover` de
  toplamıyordu — O SİPARİŞ HİÇ İŞLENMİYORDU. Filtre DE testi DE yazılır.
- **DOĞRULAMA HATASINDA SIR OTURUMA FLASH EDİLMEZ.** Laravel'in
  varsayılan `dontFlash` listesi yalnızca `password` ailesini kapsar;
  kanal anahtarları DEĞİL. `SESSION_DRIVER=database` olduğu için anahtar
  ŞİFRESİZ BİR TABLOYA düşer. Liste `bootstrap/app.php` içinde ve bugün
  kullanılanlardan GENİŞTİR — yeni kanal eklendiğinde alan adı orada
  yoksa sızıntı sessizce geri gelir.
- **WEBHOOK KAPILARI: 415 ve 429, "her durumda 202"NİN BİLİNÇLİ
  İSTİSNALARIDIR.** O kural TANIDIĞIMIZ bir mesajın işlenmesiyle ilgili
  ve kanalın gereksiz yeniden göndermesini önler. Yanlış içerik tipi
  kanalın YAPILANDIRMA hatasıdır; sınır aşımı "yavaşla ve TEKRAR
  GÖNDER" demektir. İkisinde de 2xx dönmek mesajı SESSİZCE kaybettirir.
- **HIZ SINIRI BAĞLANTI BAŞINADIR, IP BAŞINA DEĞİL** — kanal
  webhook'ları kendi altyapısından gelir ve aynı IP yüzlerce satıcıya
  hizmet eder. Koruma katmanının "kova bağlantı başınadır" kuralıyla
  aynı gerekçe. Sınır `WebhookController::MAX_REQUESTS_PER_MINUTE`.
- **HSTS UYGULAMADA DEĞİL NGINX'TE.** Başlığı uygulama katmanından
  göndermek, uygulamanın HİÇ cevap veremediği durumlarda (500, bakım
  modu, PHP-FPM ölü) başlığın da gitmemesi demektir; HSTS'in tüm değeri
  KESİNTİSİZLİĞİNDEDİR. **Yerel vhost'a KONMAZ** — localhost'a HSTS
  göndermek geliştiricinin DİĞER localhost projelerini de kırar ve geri
  alması zordur.
- **`URL::forceScheme` YALNIZCA ÜRETİMDE.** Koşulsuz olsaydı yerel panel
  kırılırdı; test İKİ YÖNÜ DE sınar (yalnızca "üretimde açık" sınansaydı,
  koşulun kaldırılması testi kırmazdı).
- **DENETİM KAYDI DARDIR** (§11: "bu altı olay anlaşmazlık çıktığında
  sorulan sorular"). Genel bir model-observer YAZILMAZ: tablo stok
  hareketleriyle dolar, gerçek sinyal gürültüde kaybolur ve maskeleme
  yüzeyi her modele yayılır. **YAZILMAMIŞ AKIŞ İÇİN ENUM DEĞERİ
  EKLENMEZ** — hiçbir yerden yazılmayan değer, ekranda var olmayan bir
  olayı varmış gibi gösterir.
- **`RecordAuditLog` ASIL İŞİ DÜŞÜRMEZ** (`api_calls` kuralının aynısı)
  ama **ÇAĞIRANIN TRANSACTION'INA KATILIR**: geri alınan bir stok
  düzeltmesi denetim izinde olmuş gibi görünmemeli.
- **`audit_logs.action` ENUM'A CAST EDİLMEZ.** Kolon metindir; enum'dan
  kaldırılmış bir değeri taşıyan eski kayıt cast sırasında İSTİSNA
  fırlatır ve denetim ekranı o satır yüzünden tamamen açılmaz.
- **BAĞIMLILIK TARAMASI AYRI CI JOB'IDIR** — testlerin geçmesinden
  BAĞIMSIZ bir sinyaldir; `tests` içine gömülseydi kırmızı bir test
  taramayı hiç çalıştırmazdı. `composer audit` lock dosyasını okur ve
  paket İNDİRMEZ, yani diğer job'ları düşüren codeload 429 yoluna hiç
  uğramaz.

## Yük testi kuralları (§11 · `707ad44`)

`loadtest:sync` — araç kullanıcı kararıyla seçildi (k6/ab DEĞİL).

- **ÖLÇÜLEN ŞEY HTTP DEĞİL SENKRON HATTIDIR.** Satıcı panele günde
  birkaç kez bakar; senkron hattı HER siparişte ve HER stok değişiminde
  çalışır. "Saniyede kaç istek" bu ürün için yanıltıcı bir sağlık
  işaretidir: HTTP rahatken outbox kuyruğunun saatlerce geride kalması
  MÜMKÜNDÜR ve o durumda kanaldaki stok yanlıştır.
- **BÜTÜNLÜK BOZUKSA KOMUT `FAILURE` DÖNER.** Hız bir günlük satırıdır,
  `on_hand = Σ on_hand_delta` ürünün temel iddiasıdır.
- **KANALA GERÇEK İSTEK ATILMAZ** — ölçülen şey kanalın gecikmesi olurdu
  ve pazaryerinin hız sınırını yakmak üretim bağlantısını devre
  kesiciye düşürebilirdi.
- **ÖLÇÜM SORGULARI KAPSAMINI AÇIKÇA YAZAR.** Kapsamsız sorgu demo
  satırlarını ölçüme katar; gerçek çalıştırmada yayın gecikmesi p95
  **19566 saniye** çıktı.
- **ÖLÇÜMDEN ÖNCE SANİYE SINIRI BEKLENİR.** `available_at` saniye
  hassasiyetlidir ve İLERİYE yuvarlanır; relay'in
  `available_at <= clock_timestamp()` kapısı taze olayları HAKLI OLARAK
  eler. Beklemeden ölçmek kuyruk derinliğini yüz kat küçük raporlar.
- **TEMİZLİK `finally` İÇİNDE** — istisna çıkan tur kiracılarını
  veritabanında bırakır ve o çöp sonraki turun ölçümüne karışır.
  `TenantContext`'i `finally` ile bırakma kuralının aynısı.
- **BÜTÜNLÜK KONTROLÜ `return`'DEN ÖNCE ÇAĞRILIR** — PHP `return`
  ifadesini `finally`'den ÖNCE değerlendirir; `finally`'ye bırakılırsa
  rapor BOŞ `integrity` okur ve "BOZUK" der (bozukluk YOKKEN).
- **ÖLÇÜM ALIRKEN ARTIK `outbox:relay` SÜRECİ OLMAMALI.** Bu turda bir
  saatten uzun süredir çalışan İKİ artık süreç bulundu; kuyruğu sürekli
  erittikleri için tüm yayın ölçümleri anlamsız çıkıyordu
  (`ps aux | grep outbox`).

## Yedek geri yükleme kuralları (§11 · §15 · `fbf1eb7`)

- **YAZILI PROSEDÜR MADDEYİ KARŞILAMAZ.** §11 "geri yükleme EN AZ BİR
  KEZ TEST EDİLDİ" diyor; kelimeler "test edildi".
- **KAYNAK VERİTABANINA GERİ YÜKLEME YAPILMAZ** — İKİNCİ, boş bir
  konteynere yüklenir. Aksi halde "prova" bir veri kaybı olayına dönüşür.
- **EN ÖNEMLİ KONTROL KİMLİK BİLGİSİNİN ÇÖZÜLMESİDİR.** Yedek tek başına
  DEĞERSİZDİR: `channel_credentials` `APP_KEY` olmadan çözülemez ve
  prova, yedek ile anahtarın BİRLİKTE çalıştığını kanıtlayan tek şeydir.
- **GERİ YÜKLEMEK YETMEZ, DOĞRULANIR**: satır sayıları · kısmi tekil
  indeksler · generated column'lar (ve gerçekten hesapladıkları) ·
  kimlik bilgisi çözülüyor mu · ledger bütünlüğü. `pg_restore`'un
  hatasız bitmesi verinin KULLANILABİLİR olduğu anlamına gelmez.
- **SÜRE HER PROVADA ÖLÇÜLÜR VE YAZILIR** — §15'in "yedekten dönüş
  > 1 saat → yönetilen DB değerlendir" sinyali, ölçülmeyen bir süreyle
  asla tetiklenmez.
- **PROVANIN SINIRI AÇIKÇA YAZILIR.** Yerel `pg_dump` provası WAL
  arşivini, PITR'ı ve uzak depodan indirme süresini KANITLAMAZ; üretimde
  §15 pgBackRest öngörüyor ve prova orada TEKRARLANMALIDIR.

## Henüz yazılmadı

**FAZ 3 KAPANDI** — dokümanın §13 · Faz 3 listesindeki BEŞ maddenin
BEŞİ de bitti: mutabakat motoru · **metrik toplama + panel + uyarı
e-postaları** (`8e27913` + `bbe2852`) · ölü mektup ekranı + tek tıkla
yeniden deneme (`244a397`, madde 3 ve 4'ü birlikte kapatır) · toplu içe
aktarma (CSV + **kanaldan ürün çekme**, `f234303` + `99008b8`).

**FAZ 4 SÜRÜYOR** (90 sa · hafta 21–25). **Onboarding akışı BİTTİ**
(20 sa, `a118b3a`), **ABONELİK/ÖDEME MADDESİ BİTTİ** (26 sa: şema +
kota `d02b984`, Stripe tahsilat hattı `6f89fe1`) ve **PANEL CİLASININ
MOBİL + BEKLEME PARÇALARI BİTTİ** (`aba0a29`).
**PANEL CİLASI MADDESİ BİTTİ** (`aba0a29` + `26426ff`): mobil düzen,
bekleme durumları, tablo okunabilirliği ve tutarlılık turu.
**TÜRKÇE YARDIM + HATA MESAJLARI MADDESİ BİTTİ** (`7208c51` +
`8642f9f`): `lang/tr/` dil dosyaları ve `/help` ekranı.
**GÜVENLİK + YÜK TESTİ + YEDEK PROVASI MADDESİ BİTTİ** (12 sa ·
`1cc6720` + `05b336e` + `707ad44` + `fbf1eb7`). Madde ÜÇ parçalıydı
(dokümanın satırı devir notundakinden GENİŞ: "Güvenlik kontrol listesi,
yük testi, **yedek geri yükleme provası**") ve üçü de kapandı.
Kalıcı kurallar aşağıda "Güvenlik kuralları", "Yük testi kuralları" ve
"Yedek geri yükleme kuralları" başlıklarında.

**FAZ 4 KAPANDI (90/90 sa)** ve **artık madde de kalmadı**: onay
durumu ekranı `8ba3c08` ile yazıldı (toplu görünüm; rozet ve red sebebi
ürün-kanal ekranında zaten vardı). O madde dokümanın saat bütçesinde
ayrı satır taşımıyordu. Kalıcı kurallar aşağıda "Onay durumu ekranı
kuralları" başlığında. **YENİDEN AÇMA.**

**GÜVENLİK MADDESİNİ YENİDEN AÇMA.** Kod tarafında kapatılabilecek
maddeler kapandı; kalan üçü **kod tarafından zorlanamaz** ve sunucu
kurulmadan kapatılamaz (`docs/GUVENLIK-KONTROL-LISTESI.md` bunları
"⬜ SUNUCU" olarak işaretliyor): APP_KEY'in iki ayrı yerde yedeklenmesi,
PostgreSQL/Redis'in dışarıdan erişilemez olması ve yönetici hesaplarında
2FA. **2FA YAZILMADI ve bu maddenin kapsamında değildir** —
`users.two_factor_secret` kolonu Laravel iskeletinden geliyor ama akış
yok; ayrı bir özelliktir ve §13 listesinde kendi satırı YOKTUR.

**TASARIM SEANSI BİTTİ** (`62a2209` + `8f41dc7`, kullanıcı kararı).
Panel sol sidebar'a çevrildi ve ekranların içi modernleştirildi. Bu
madde **dokümanın §13 listesinde YOKTUR** — bilinçli ve kullanıcı
onaylı bir sapmadır. Kalıcı kurallar aşağıda "Tasarım sistemi
kuralları" başlığında.

**BOŞ DURUM ve GEZİNME YÜKLEMESİ MADDELERİ KAPANDI, YENİDEN AÇMA.**
Boş durum metni on üç ekranın HEPSİNDE var (`Orders/Show` ayrıntı
ekranı olduğu için istemez); gezinme yüklemesini Inertia'nın ilerleme
çubuğu zaten karşılıyor.

**⚠️ `.env`'DE CANLI STRIPE ANAHTARLARI VAR (21 Ağustos).**
`pk_live_` / `sk_live_` yazılmış durumda — TEST anahtarları DEĞİL.
Kullanıcının hesabı canlı ve gerçek ciro taşıyor; canlı anahtarla
checkout açmak **gerçek karttan gerçek para** demektir ve test kartı
`4242...` çalışmaz. `STRIPE_WEBHOOK_SECRET` hâlâ yer tutucu
(`whsec_...`), yani webhook imzası doğrulanamaz ve abonelik YAZILAMAZ.
**Düğmeler artık ETKİN** (`stripeConfigured()` anahtarı görüyor).
**`/billing` üzerinde tarayıcı doğrulaması YAPMA** — anahtarlar test
moduna çevrilene kadar (`https://dashboard.stripe.com/test/apikeys`,
URL'deki `/test/` zorunlu). **BLOKAJ DEĞİL** — diğer Faz 4 maddeleri
Stripe beklemeden yapılabilir.
**Stripe CLI KURULDU** (brew, 1.50.3) ama `stripe login` YAPILMADI
(tarayıcı ister, kullanıcı yapar).
**ÖNCE TEST MODUNA GEÇİLMELİ** — kullanıcının hesabı CANLI ve gerçek
ciro taşıyor; canlı anahtarla gerçek para çekilir. En garantili yol
`https://dashboard.stripe.com/test/apikeys` adresidir. `whsec_` o
sayfada YOKTUR; `stripe listen` verir. Anahtar tanımlanınca checkout akışı
uçtan uca doğrulanmalı (test modu `sk_test_...`, test kartı
`4242 4242 4242 4242`). Webhook'u yerelde denemek için `stripe listen
--forward-to localhost:8080/webhooks/stripe` — komut kendi `whsec_`
sırrını verir ve terminal AÇIK KALMALI. Ayrıntılı adımlar DEVIR.md'nin
"YARIN İLK İŞ" bölümünde.

**ÖDEME SAĞLAYICISI STRIPE'TIR, iyzico DEĞİL** — kullanıcı kararı
(20 Ağustos 2026). Doküman §13 · Faz 4 "iyzico" diyor; bu **bilinçli
ve kullanıcı onaylı bir sapmadır**. Şema kararı DEĞİŞMEZ
(`tenants.plan_code` zaten var; `plans` kiracısız+seed,
`subscriptions` `UNIQUE(tenant_id) WHERE status='active'`).
Sağlayıcıya özgü kimlikler (`stripe_customer_id`) tahsilat
katmanında yaşar, çekirdek kota mantığında değil. Webhook alımında
projenin gelen hat kuralları geçerli: **HMAC ham gövde üzerinden**
(`Stripe-Signature`), CSRF muaf rota, tekilleştirme olay kimliğiyle.

Yeni pazaryerleri (Hepsiburada → Amazon → Etsy → eBay) Faz 4'ten SONRA.
Shopify KAPSAM DIŞI.

`PruneApiCalls` **YAZILDI** (§13 · Faz 3, `a452a27`) — `api-calls:prune`,
günlük 04:00, partili silme + tur başına üst sınır.

`RequestResync` + `ListingResyncRequestedConsumer` **YAZILDI** (§13 · Faz 3,
`9ec5ac0`) ve **T10 ile korunuyor** — yazılmamış P0/P1 testi KALMADI.

`UpdateOrderSnapshot` ve `UpdateFulfillment` **YAZILDI** (§13 · Faz 3) ve
`OrderEventRouter`'a bağlandı.

**FİYAT SENKRON YOLU YAZILDI** (§13 · Faz 3, `d17aa8a`) — tetikleyici
`UpdateProduct` → `VariantPriceChanged` olayı → `VariantPriceChangedConsumer`
(fan-out) → `PRICE_PUSH` → `PushPrices` (kuyruk `price:high`). Gruplama
`PriceBatchBuilder`'da. `DetectStuckSyncOperations` artık PRICE'ı da
kurtarır; kurtarmadığı `MEDIA_PUSH` (o yol hiç yazılmadı).

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

## Onay durumu ekranı kuralları (§13 · Faz 4 · `8ba3c08`)

Faz 4'ün son artık maddesiydi ve kapandı. **YENİDEN AÇMA.**

- **ÜRÜN-KANAL EKRANININ KOPYASI DEĞİLDİR.** Rozet ve red sebebi orada
  ZATEN vardı; eksik olan TOPLU GÖRÜNÜMDÜ. O ekran TEK ÜRÜN için
  "hangi kanallarda ne durumda" der; bu ekran TERSİNİ sorar: "kaç
  ürünüm onay bekliyor, hangileri reddedildi". Yüz ürün gönderen satıcı
  reddedilen üçünü bulmak için yüz kanal sekmesi açamaz — red sebebi
  KAYITLIYDI ve pratikte GÖRÜNMEZDİ.
- **REDDEDİLEN EN ÜSTTE VE AYRI SAYILIR.** `rejected` kullanıcı
  müdahalesi bekler ve kendiliğinden DÜZELMEZ; `pending_approval` bir
  bekleme durumudur. Aynı kefeye konsalardı satıcı "sistem hallediyor"
  sanır ve tam olarak kendisini bekleyen satırı hiç görmezdi
  (mutabakat ekranındaki `MANUAL_REVIEW` kuralının aynısı).
- **ONAY SÜRECİ OLMAYAN KANAL BU EKRANDA HİÇ GÖRÜNMEZ** (§7). Woo
  `SupportsApprovalWorkflow` uygulamaz; yetenek `instanceof` ile
  okunur. Bir Woo listing'i `pending_approval`'a elle sokulsa bile
  listelenmez: o kanalda öyle bir hâl yoktur ve satıcı hiç gelmeyecek
  bir onayı beklerdi.
- **KANAL LİSTESİ BOŞSA EKRAN BUNU AÇIKÇA SÖYLER.** Boş tablo
  göstermek satıcıya "onay bekleyen ürünüm yok" dedirtirdi; doğru cevap
  "bu kanalda onay süreci yok"tur ve ikisi TAMAMEN FARKLI şeylerdir.
- **`adapter_class` EAGER-LOAD'DA AÇIKÇA SEÇİLİR** — seçilmezse
  registry sınıfı bulamaz, yetenek sessizce boşalır ve ekran "onay
  süreci olan kanalın yok" der (`supports_webhooks` tuzağının aynısı).
- **EKRAN SALT OKUNURDUR.** Onay kararını KANAL verir, biz yalnızca
  okuruz (`approval:track`, saatlik). Panelden "onayla" düğmesi
  koymak, kanalın kararını bizim verebileceğimiz izlenimi yaratırdı.
  Reddedilen satırın düzeltme yolu ÜRÜN ekranıdır.
- **ÖZET FİLTREDEN BAĞIMSIZDIR.** "Yalnızca reddedilenler" filtresi
  açıldığında bekleyen sayısının sıfıra düşmesi, o ürünlerin
  kaybolduğu izlenimini verirdi.
- **TANINMAYAN BAĞLANTI FİLTRESİ YOK SAYILIR, LİSTEYİ BOŞALTMAZ** —
  doğrulanmasaydı adres çubuğuna yazılan rastgele kimlik sorguyu hiç
  eşleşmeyen bir bağlantıya çevirir ve ekran sebebini söyleyemeden boş
  kalırdı.
- **MENÜDE KATALOG ALTINDA, İZLEME ALTINDA DEĞİL.** İzleme grubu
  SİSTEMİN sağlığını gösterir ve oradaki satırlar bizim bir şeyi
  beceremediğimizi söyler; onay ise ürünün kanaldaki NORMAL yaşam
  döngüsüdür. Hataların yanına konsaydı satıcı bekleyen her ürünü bir
  arıza sanardı.
- **ZAMAN DAMGALARI TEK BİÇİMDE GÖNDERİLİR.** `DB::max()` ham kolon
  metni döndürür (`"2026-08-24 14:31:09"`) ve tarayıcı onu YEREL saat
  sanar; satırlar `toIso8601String()` kullanır. Karışınca AYNI AN iki
  farklı saat görünür — gerçek tarayıcı çalıştırmasında iki saatlik
  fark ölçüldü.
- **HİÇ SORULMAMIŞ SATIR "—" DEĞİL "Henüz sorulmadı" DER.** Tire,
  satıcıya "kontrol edildi ama tarih yok" gibi okunurdu.

## Mutabakat ekranı kuralları (§13 · Faz 4)

- **`MANUAL_REVIEW` EN ÜSTTE VE AYRI SAYILIR.** O satırlarda otomatik onarım
  DURMUŞTUR ve kendiliğinden düzelmeyecektir; `DRIFT_DETECTED` ile aynı
  kefeye konsaydı satıcı "sistem hallediyor" sanır ve tam olarak müdahale
  bekleyen satırı hiç görmezdi.
- **ÜÇ SAYI, ÜÇ FARKLI EYLEM.** Elle inceleme müdahale ister, sürüklenme
  kendiliğinden onarılır, okunamayan kanal bağlantı sağlığına bakmayı
  gerektirir. `REMOTE_UNREACHABLE` sürüklenme **sayılmaz** ama **ayrı
  gösterilir** — sessizce yutulsaydı satıcı kanalının okunamadığını hiç
  bilmezdi.
- **FAZLA SATIŞTA İKİ DEĞER AYRIŞIR VE İKİSİ DE GÖSTERİLİR** (§17 · P0).
  Kanoniği −2 olan varyantta kanala giden değer 0'dır ve kanaldaki 0
  DOĞRUDUR. Yalnızca ham değer gösterilseydi satıcı olmayan bir sürüklenme
  arar; yalnızca kırpılmış değer gösterilseydi fazla satışı hiç göremezdi.
- **LISTING BAŞINA SON KALEM.** Her tur yeni kalem yazar; hepsi listelenseydi
  üç turdur sürüklenen tek listing ekranı üç satırla doldurur ve satıcı kaç
  ayrı sorunu olduğunu sayamazdı. Çözülmüş satırlar varsayılan listede yok.
- **`MAX(id)` KULLANILAMAZ** — PostgreSQL'de uuid için `max()` toplam
  fonksiyonu YOKTUR ve sorgu doğrudan patlar. `DISTINCT ON` kullanılır.
- **Ekran SALT OKUNURDUR**: sürüklenme tespiti ve onarımı zamanlanmış
  turların işidir, panelden tetiklenmez.

## Toplu içe aktarma kuralları (§13 · Faz 3 · madde 5)

- **AYRIŞTIRMA İLE YAZMA AYRIDIR.** `CsvProductParser` saf ve yan
  etkisizdir (veritabanına dokunmaz, kiracı bağlamı istemez); yazma
  `ImportProducts` action'ının işidir. Birleştirilselerdi ondalık ayırıcı,
  BOM ve kolon eşleme kuralları ancak veritabanı kurup ürün yaratarak
  test edilebilirdi.
- **TÜRKÇE EXCEL BİÇİMİ BİRİNCİ SINIF VATANDAŞTIR.** Gerçek dosya üç şeyi
  birden taşır: **BOM** (atılmazsa ilk kolonun adı `"\u{FEFF}sku"` olur ve
  dosya "sku kolonu yok" diye reddedilir), **noktalı virgül ayırıcı**
  (virgül ondalık olduğunda Excel öyle kaydeder) ve **virgüllü ondalık**
  (`(float) "1.299,90"` = **1.0** — kuruşlar değil LİRALAR düşer). Virgül
  varsa nokta BİNLİK ayırıcıdır ve atılır.
- **KOLONLAR ADIYLA EŞLENİR, KONUMLA DEĞİL.** Satıcının Excel'inde kolon
  sırası sabit değildir; konumla eşlenseydi fiyat kolonu stok sanılır ve
  500 ürün yanlış fiyatla kanala giderdi.
- **AÇILIŞ STOĞU LEDGER ÜZERİNDEN GİRER** — `CreateProduct` çağrılır,
  `inventory_levels` satırına DOKUNULMAZ. Doğrudan yazmak 500 satırlık
  dosyada 500 bozuk bakiye ve 500 sahte sürüklenme demektir.
- **VAR OLAN SKU GÜNCELLENİR ama STOK SATIRDAN YAZILMAZ.** Satıcının en
  sık işi toplu fiyat güncellemesidir. Stok yalnızca ledger yollarından
  değişir; var olan üründe uygulansaydı SATILMIŞ mallar bir dosya
  yüklemesiyle geri gelir ve bakiye kalıcı bozulurdu — sessiz, geri
  alınamaz ve fazla satışa yol açar. `applyUpdate()` stok parametresi
  ALMAZ ve `UpdateProduct` da almaz.
- **TEK BOZUK SATIR DOSYAYI DÜŞÜRMEZ** ve tur **TEK TRANSACTION'A
  SARILMAZ.** 437. satırdaki hata önceki 436 ürünü geri alsaydı kullanıcı
  her denemede baştan başlardı. Her satır kendi transaction'ında atomik.
- **BAŞLIK HATASI SATIR HATASINDAN AYRIDIR.** Zorunlu kolon hiç yoksa
  dosya HİÇ işlenmez ve tek mesajla anlatılır; 500 hata satırı basmak
  kullanıcıya "dosyandaki `fiyat` kolonu eksik" demekten kötüdür. Eksik
  kolon KULLANICININ gördüğü adla raporlanır (`fiyat`, `price` değil).
- **KUYRUK `listing:bulk`** (§15) ve `reconciliation` ile havuz PAYLAŞMAZ
  — §15'in açık kuralı: toplu içe aktarma yeni müşteri kurulumunun tam
  ortasıdır ve mutabakat turlarını atlatırsa ürünün temel iddiası tam o
  anda çalışmaz.
- **YENİDEN DENEME YOK** (`$tries = 1`): içe aktarma idempotent DEĞİLDİR.
  Yeniden denense ilk turda yaratılanlar ikinci turda GÜNCELLEME sayılır
  ve rapor yanıltır; ayrıca yarıda kalan turda hangi satırın işlendiği
  bilinmiyor. Hata satıra yazılır, yeniden yükleme kararı KULLANICININDIR.

## Stripe tahsilat kuralları (§13 · Faz 4 · `6f89fe1`)

- **ABONELİK DURUMUNUN TEK GERÇEK KAYNAĞI STRIPE'TIR.** Panel yalnızca
  checkout oturumu açar ve YÖNLENDİRİR; aboneliği **webhook yazar**.
  Panel yazsaydı ödeme alınmadan kota açılır ve kullanıcı ödeme
  sayfasında vazgeçse bile abonelik açık kalırdı.
- **İMZA HAM GÖVDE ÜZERİNDEN, AYRIŞTIRMADAN ÖNCE** — kanal
  webhook'larıyla aynı kural. Burada bedeli daha ağır: doğrulanmamış bir
  `checkout.session.completed` **ÜCRETSİZ ABONELİK** açmak demektir.
  Tolerans 300 sn (tekrar saldırısı).
- **TANINMAYAN OLAY 2xx ALIR.** Hata dönmek Stripe'a uç noktayı "bozuk"
  saydırır ve sonunda webhook'u DEVRE DIŞI bıraktırır — o noktadan sonra
  GERÇEK ödemeler de gelmez. `data.object` düz dizi gelebilir;
  `toArray()` körlemesine çağrılırsa 500 döner ve aynı sonucu doğurur.
- **TEKRAR İKİNCİ ABONELİK AÇMAZ** — çıpa `external_ref` kısmi
  tekilliği (Stripe olayları EN AZ BİR KEZ gönderilir).
- **PLAN YÜKSELTMEDE ESKİ AKTİF ABONELİK AYNI TRANSACTION'DA
  KAPATILIR.** Kapatılmasaydı `UNIQUE(tenant_id) WHERE aktif` INSERT'i
  eler ve **ödeme alınmışken abonelik AÇILMAZDI** — en kötü hata biçimi.
- **KİRACI VE PLAN `metadata` İLE TAŞINIR** (hem oturuma hem abonelik
  nesnesine yazılır — `customer.subscription.*` olayları oturum
  metadata'sını TAŞIMAZ). Yazılmazsa ödeme alınır ama abonelik açılamaz.
- **DURUM EŞLEMESİ TEK KAYNAKTIR** (`STATUS_MAP`) ve Stripe'ın
  `canceled` yazımını §4'ün `cancelled` yazımına çevirir. **İptal İKİ
  yoldan gelir**: `subscription.deleted` VEYA `subscription.updated` +
  `status: canceled`; ikincisi eşlenmezse iptal edilmiş abonelik AKTİF
  kalır ve kota vermeye devam eder (mutasyonla bulundu).
- **BİLİNMEYEN DURUM `past_due`'YA DÜŞER, `active`'e DEĞİL** — güvenli
  taraf kotayı VERMEMEKTİR.
- **ANAHTARLAR `.env`'DEN OKUNUR**, koda gömülmez. `webhook_secret`
  gizli anahtarla AYNI DEĞİLDİR; karıştırılırsa doğrulama her istekte
  başarısız olur. Anahtar yoksa ekran bunu AÇIKÇA söyler.
- **Laravel Cashier KULLANILMADI** — kendi `subscriptions` tablosunu
  dayatıyor ve §4 şemasıyla çakışırdı (kullanıcı kararı).

## Abonelik ve kota kuralları (§13 · Faz 4 · `d02b984`)

- **SAĞLAYICI STRIPE'TIR** (kullanıcı kararı) — doküman "iyzico" diyor,
  sapma onaylı. `subscriptions.external_ref` §4'te SAĞLAYICIDAN BAĞIMSIZ
  adlandırılmıştır ve `stripe_subscription_id` DEĞİLDİR: sağlayıcı
  değişirse şema değişmemelidir.
- **KOTA STOK VE SİPARİŞ AKIŞINA DOKUNMAZ** — §14'ün ön koşul kapısıyla
  AYNI tasarım hedefi. Sipariş ASLA reddedilmez (pazaryeri onu kabul
  etmiştir) ve kotası dolu kiracının stoğu güncellenmeye DEVAM EDER.
  Ödeme sorunu yüzünden stok bozmak veya sipariş kaybetmek,
  çözdüğünden büyük zarar verir. `QuotaEnforcementPathsTest` bunu
  ledger snapshot'ıyla korur.
- **KOTA YARATMAYI ENGELLER, VAR OLANI SİLMEZ.** Plan düşünce limitin
  üstündeki ürünler SİLİNMEZ ve senkronları DURMAZ; yalnızca yenisi
  eklenemez. Silmek geri alınamaz ve kanaldaki listelemeleri götürürdü.
- **LİMİT YOKSA SINIRSIZDIR, SIFIR DEĞİL.** `limits` JSONB'sinde
  bulunmayan anahtar "bu planda o kota YOK" demektir; sıfır sayılsaydı
  yeni bir kota türü eklendiği an TÜM planlar o kotada sıfıra düşer ve
  bütün kiracılar aniden engellenirdi.
- **ABONELİK YOKSA VARSAYILAN PLANA (`free`) DÜŞÜLÜR**, sınırsız
  SAYILMAZ. İPTAL EDİLMİŞ abonelik kota VERMEZ — verseydi bir kez abone
  olup iptal eden kiracı ücretli limitleri sonsuza kadar kullanırdı.
  `trialing` VERİR (deneme ücretli gibi davranmalı), `past_due` VERMEZ.
- **ANAHTAR YENİLEME KOTADAN ETKİLENMEZ.** `ConnectChannel` aynı hesabı
  `firstOrNew` ile yeniden kullanır; ayrım yapılmasaydı kotası dolu
  satıcı süresi dolmuş anahtarını güncelleyemez ve kanalı KALICI
  ölürdü — üstelik tam da ödeme yapmasını istediğimiz anda.
- **`plans` KİRACIYA AİT DEĞİLDİR** (§4: "Kiracısız, seed") —
  `channel_categories` ile aynı ayrım: katalog ÜRÜNÜN gerçeği, seçim
  satıcının kararı. Anahtar `code`, uuid değil.
- **`UNIQUE(tenant_id) WHERE aktif` KISMİ TEKİLLİKTİR.** İptal edilmiş
  abonelik SİLİNMEZ ve tarihçe olarak durur; tam tekillik konsaydı plan
  değiştiren kiracının eski satırı silinir ve gelir geçmişi kaybolurdu.
- **`external_ref` KISMİ TEKİLDİR** — webhook tekrarına karşı çıpa
  (Stripe olayları EN AZ BİR KEZ gönderilir). NULL olabilir ve birden
  çok NULL tekilliği ihlal etmez.
- **`limits` ANAHTARLARI SÖZLEŞMEDİR** ve `PlanLimitContractTest`
  BEKLENEN METİNLE sınar. Yazan (seed) ve okuyan (`limitFor`) aynı
  enum'u çağırdığı için mutasyon ikisini BİRLİKTE kaydırır, davranış
  testleri yeşil kalır ama üretimdeki satırlar eski anahtarı taşır ve
  kota SESSİZCE kalkardı.
- **`usage_records` YAZILMADI ve bu bilinçlidir** — iki kota da ANLIK
  sayımdır. §4 o tabloyu DÖNEMSEL ölçüm için tanımlar; sipariş/senkron
  başına ücretlendirmeye geçilirse yazılır.
- **KOTA AŞIMI ALAN HATASIDIR, 500 DEĞİL** (`DuplicateSkuException` ile
  aynı kalıp) ve mesaj DEĞER + LİMİT + TAVSİYE taşır.

## Onboarding kuralları (§13 · Faz 4 · `a118b3a`)

- **İLERLEME SAKLANMAZ, TÜRETİLİR.** `tenants`'ta onboarding kolonu YOK
  ve §4 de tanımlamıyor; eklenmedi. Gerekçe projenin iki yerleşik
  kararının aynısı: `is_dirty` generated column'dır (§4) ve
  `DriftHistory` sayacı ayrı kolonda TUTMAZ (§10) — ayrı sayaç, adımı
  bitiren HER yolun onu da güncellemesini zorunlu kılar ve biri
  unutulunca iki gerçek kaynağı SESSİZCE ayrışır. Burada tuzak daha
  keskin: adım "bitti" damgalanıp veri sonradan giderse (bağlantı
  sağlıksızlığa düşer, ürün silinir) kayıtlı ilerleme **YALAN söyler**.
- **KANAL ADIMI `active` İSTER, VARLIK YETMEZ.** "Sağlık kontrolü
  geçmeden bağlantı `active` olmaz" (§13 · faz 1.4). `pending` bağlantı
  kanalla HİÇ konuşamamıştır; adım kapatılsaydı kullanıcı ürün
  göndermeye başlar ve hepsi `AUTHENTICATION` ile KALICI hataya düşerdi.
- **SENKRON ADIMI `completed` İSTER, AÇILMA YETMEZ.** `pending` kuyrukta
  bekliyordur, `dead` tam olarak BAŞARISIZ olmuştur; ikisini de saymak
  ürünün temel iddiasının çalışmadığı anda "kurulum bitti" demektir.
  **`superseded` DE SAYILMAZ** — terminaldir ama hiç gönderilmemiştir (§8).
- **KİRACI KONTROLÜ KAPANIŞIN İÇİNDE YAPILIR.** `share()` `web`
  grubunda, `tenant` ise ROTA seviyesinde çalışır — yani `share()`
  bağlam kurulmadan ÖNCE çağrılır ve dışarıda okunan `$tenant` HER
  ZAMAN null olurdu (gerçek çalıştırmada bulundu). Kapanış yanıt
  üretilirken çalıştığı için bağlamı kurulmuş görür.
- **ŞERİT LAYOUT'TA YAŞAR**, tek ekranda değil: kullanıcı kurulumun
  ortasında herhangi bir ekrana gidebilir ve şerit orada da yolu
  göstermelidir.
- **KAPATMA BUTONU YOK.** Saklanan tercih ilerlemenin İKİNCİ gerçek
  kaynağı olurdu ve türetilmiş durum kararını bozardı. Dört adım
  bitince şerit kendiliğinden kaybolur; veri giderse geri gelir ve bu
  **KASITLI** davranıştır.
- **TEK ÇAĞRI DÜĞMESİ — SIRADAKİ ADIM.** Dört düğme birden göstermek
  kullanıcıya hangisinden başlayacağını sordurur; onboarding'in işi tam
  olarak bunu söylemektir.

## Tasarım sistemi kuralları (`62a2209` · `8f41dc7`)

Dokümanda YOK — kullanıcı onaylı tasarım seansının kalıcı çıktısı.

- **MARKA RENGİ TURUNCU, AMA ASLA DOLGU DEĞİL.** Ölçek
  `resources/css/app.css` → `@theme` içinde `brand-50..900`; çıpa
  `brand-600` = `#a8532b` (`app.js` ilerleme çubuğuyla AYNI).
  **Bu panelde renkli YÜZEY her zaman "durum" demektir** ve `amber-*`
  uyarı, `red-*` hata rengidir. Marka tonu dolgu olarak kullanılırsa
  satıcı onu durum sanar. Yeri: **odak halkaları, 3px'lik çubuklar,
  küçük vurgular**. Birincil buton `bg-stone-900` kalır.
- **AYIRAN ŞEY DOYGUNLUK, HUE DEĞİL.** Marka ölçeğinin hiçbir adımı
  **%59 doygunluğu aşmaz**; amber %92–95'tir. Yeni bir ton eklerken bu
  tavan korunur. Tailwind'in hazır `orange-*` ölçeği KULLANILMAZ —
  `orange-600` aynı hue ailesinde (H=21) ama S=90%, yani amber kadar
  parlaktır.
- **DURUM İŞARETİ ÇUBUKTUR, DOLGU DEĞİL** — sidebar aktif öğesi ve
  `StatCard` aynı ilkeyi paylaşır. Ölçüldü: sidebar çubuğu 108px²,
  onboarding'in amber CTA'sı 4492px² (42 kat). Renkli alan ekseninde
  yarışmaz.
- **RENK TEK SİNYAL OLAMAZ.** Aktif öğe: çubuk + `font-medium` +
  `bg-stone-100`. Stat kartı: çubuk + sayının rengi. Renk körlüğünde
  turuncu sarımsıya kayıp amber'a yaklaşır; diğer sinyaller renksiz de
  okunur.
- **GÖLGE EKLENMEZ.** Dense bir operasyon aracında kart gölgeleri,
  durum tonlarıyla (kırmızı satır, amber şerit) AYNI görsel frekansta
  yarışır. Hiyerarşi kenarlık + zemin farkıyla kurulur. İSTİSNA: Z
  ekseninde gerçekten üstte duran örtüler (mobil çekmece).
- **KÖŞE ÖLÇEĞİ**: kart/tablo `rounded-lg` (8px) · buton/input
  `rounded-md` (6px) · rozet `rounded` (4px) · yalnızca gerçekten
  dairesel olanlar `rounded-full`. Tek yarıçap her yerde jenerik
  görünür.
- **SAYFA BAŞLIĞI `Components/PageHeader.vue`'DUR** — on altı ekranın
  ortak deseni. Yeni ekranda desen KOPYALANMAZ, bileşen kullanılır;
  kopyalandığı için zaten bir kez ayrışmıştı.
- **DURUM KARTI `Components/StatCard.vue`'DUR** (`tone`: neutral ·
  good · warning · error). Nötr çubuk şeffaf DEĞİL `stone-200`:
  yalnızca kötü durumda beliren çubuk kartın okunuşunu 3px kaydırırdı.
- **`pending` ROZETİ SKY'DIR, AMBER DEĞİL.** "Bekliyor" bir uyarı
  değil normal kuyruk durumudur ve "yeniden deneniyor" ile aynı rengi
  paylaşamaz. Rozet sırası kuralı da bunu söyler: bekleyen, başarı
  dışındaki EN SAKİN durumdur.
- **SİSTEM DIŞI RENK KULLANILMAZ.** Palet: stone (nötr) · emerald
  (başarı) · sky (bilgi) · amber (uyarı) · red (hata) · brand (marka
  vurgusu). Mor bir kez sızdı ve kaldırıldı.
- **TONLU SATIRDA HOVER KENDİ AİLESİNDE KALIR** — `hover:bg-stone-50`
  kırmızı satırı griye yıkar ve satıcı sinyalden şüphe eder.
- **`focus:outline-none` YAZILMAZ.** Her etkileşimli öğe görünür odak
  halkası taşır (WCAG 2.4.7). Bir turda 31 yerde bulunup düzeltildi.
- **STICKY TABLO BAŞLIĞI ÇALIŞMAZ, DENEME.** `overflow-x-auto`
  kapsayıcısı sticky'yi kendi kutusuna hapseder: tarayıcıda ölçüldü,
  600px kaydırmada thead −304px'e gidiyor. Yalnızca kapsayıcıya sabit
  yükseklik verilirse çalışır ve o da sayfa içinde ikinci bir kaydırma
  alanı demektir (kullanıcı istemedi).
- **OTOMATİK TOPLU DÖNÜŞÜM TARAYICIDA DOĞRULANIR.** Bir dönüşüm turu
  `title="{{ product.title }}"` üretti — Vue bunu LİTERAL metin basar,
  `:title` olmalıydı. **Testler bunu görmedi**, tarayıcı gördü.

## Panel cilası kuralları (§13 · Faz 4 · `aba0a29`)

- **DAR EKRANDA MENÜ KATLANIR ve kırılma noktası `lg`'dir (1024px).**
  On menü öğesi ~900px ister; `sm` seçilseydi **768px'lik tabletler
  taşımaya devam ederdi**. Cila öncesi başlık 390px görünüm alanında
  1001px genişliyordu ve **"Siparişler"den sonraki YEDİ ekran ile
  ÇIKIŞ düğmesi erişilemezdi** — telefondan panelde gezinmek mümkün
  değildi.
- **TAŞMAYI BAŞLIK YAPAR, İÇERİK DEĞİL.** Teşhis yöntemi: başlığı
  `display:none` yapıp `documentElement.scrollWidth`'i yeniden ölç.
  İçerik sütunları zaten duyarlıdır (tablolar `overflow-x-auto`
  taşır); yeni bir ekranda taşma görülürse önce başlık elenmelidir.
- **MENÜ GEZİNMEDE KAPANIR** (`watch(currentPath)`). Kapanmasaydı
  seçilen bağlantı yeni ekranı açar ama panel açık kalıp içeriği
  örterdi.
- **İSTEK UÇARKEN DÜĞME KİLİTLENİR — kalıp: `busy` + `:disabled` +
  `…` etiketi.** `Failures`, `Products/Channels` ve `Mappings` bu
  kalıbı zaten kullanıyor; YENİ KALIP UYDURULMAZ.
- **ÖDEME DÜĞMESİ `onFinish` İLE SIFIRLANMAZ.** Checkout oturumu
  sunucuda açılıp Stripe'a YÖNLENDİRİLİR; düğme yönlendirme sırasında
  yeniden etkinleşseydi çift tıklama penceresi yeniden açılır ve her
  basış **YENİ bir checkout oturumu** yaratırdı. Yalnızca `onError`
  sıfırlar.
- **GEZİNME YÜKLEMESİ İÇİN EKRAN BAŞINA SPINNER YAZILMAZ.** Inertia'nın
  ilerleme çubuğu `app.js`'te yapılandırılmıştır
  (`progress: { color: '#A8532B' }`) ve çalışır. Yerelde görünmemesi
  hata değildir: Inertia çubuğu **250 ms geciktirir** ki hızlı yanıtta
  yanıp sönmesin. Doğrulamak için isteği yapay olarak yavaşlat.
- **TARAYICI DOĞRULAMASI `fetch`'İ DEĞİL `XMLHttpRequest`'İ
  YAVAŞLATMALI** — Inertia XHR kullanır. `fetch` sarmalanırsa istek
  hiç yavaşlamaz, bekleme durumu görünmez ve **test kodu suçlu
  olmadığı hâlde özellik bozuk sanılır** (bu turda yaşandı).
- **PANEL EKRANLARININ JS TESTİ YOKTUR** ve eklenmez: projede JS test
  koşucusu yok, Vitest eklemek YENİ PARADİGMA olurdu. Ekran işi
  **tarayıcıda** doğrulanır ve ölçüm devir notuna yazılır.
- **`overflow-x-auto` TEK BAŞINA YETMEZ — tabloya ASGARİ GENİŞLİK de
  gerekir.** Tablo `w-full` olduğu için tarayıcı sütunları dar ekrana
  SIKIŞTIRIR ve kaydırma HİÇ devreye girmez: en uzun metni taşıyan
  sütun bir şeride düşüp kelime ortasından kırpılır (`/failures`'ta
  "Hata" sütunu ~40px'e indi, 390px'te ölçüldü). `min-w-*` verilince
  sütunlar doğal boyutunu korur ve kutu KAYAR. Sayfa taşması ÜRETMEZ —
  tablo kendi kutusunun içinde kayar.
- **SKU / kimlik sütunları `whitespace-nowrap` taşır.** Kimlik kelime
  ortasından bölünürse okunmaz (`TSH−KIRMIZI−M` üç satıra bölünüyordu).
- **KART DÜZENİNE GEÇİLMEDİ ve bu bilinçli.** Tabloların görünen ilk
  sütunları zaten ÖNEMLİ olanlar; sağa kayanlar ikincil. Yedi tabloyu
  kart düzenine çevirmek maddenin istemediği bir yeniden yazım olurdu.
- **PAYLAŞILAN PROP'LAR KAPANIŞ İÇİNDE OKUNUR — İSTİSNASIZ.**
  `share()` `web` grubunda, `tenant` ara katmanı ROTA seviyesinde
  çalışır; kapanış DIŞINDA okunan `$request->attributes->get('tenant')`
  HER ZAMAN null döner. `onboarding` bunu baştan doğru yapmıştı,
  `tenant` YAPMAMIŞTI ve kiracı adı on iki ekranda BOŞ görünüyordu.
- **ÖZET EKRANI PAYLAŞILAN PROP HATALARINI MASKELER.**
  `DashboardController` kendi `tenant` prop'unu gönderip paylaşılanı
  EZER; paylaşılan prop'u sınayan test ÖZET DIŞINDA bir ekranda
  (`/channels`) koşmak ZORUNDADIR. `/` üzerinde yazılan test bozuk
  kodla bile YEŞİL kalır (mutasyonla kanıtlandı).

## Türkçe mesaj ve yardım kuralları (§13 · Faz 4 · `7208c51` · `8642f9f`)

- **VARSAYILAN DİL `config/app.php`'DE TÜRKÇEDİR**, yalnızca `.env`'de
  değil. `.env`'e yazmak yeterli görünür ama o satırı taşımayan HER
  kurulum (yeni sunucu, CI, yeni geliştirici) sessizce İngilizce mesaj
  gösterirdi. `fallback_locale` da Türkçe: çevrilmemiş anahtar
  İngilizceye DÜŞMEZ.
- **`.env` İKİNCİ SAVUNMA OLARAK MUTASYONU GİZLER.** `config()` ETKİN
  değeri döndürür; `.env` `tr` taşıdığı sürece varsayılan `en`'e
  çevrilse bile test yeşil kalır. Varsayılanı sınayan test dosyanın
  KENDİSİNİ okur (`file_get_contents(config_path('app.php'))`).
- **ALAN ADLARI `attributes` İÇİNDE ÇEVRİLİR** ve bu mesaj çevirisi
  kadar önemlidir: mesaj Türkçe ama alan adı `title` kalsaydı satıcı
  formda "title" diye bir alan ARAYAMAZDI. Ad, EKRANDAKİ ETİKETLE aynı
  olmalıdır.
- **TÜM KURALLAR ÇEVRİLİR**, yalnızca bugün kullanılanlar değil — yarın
  eklenen kural sessizce İngilizceye düşer ve bunu kimse fark etmez
  (hata ancak o alan boş bırakıldığında görünür).
- **ALANA ÖZGÜ MESAJ "NE YAPMALI" DER** (§12'nin ölü mektup ekranı
  kuralının aynısı): "SKU zorunludur — kanallarla eşleşmenin anahtarı
  budur."
- **GİRİŞ HATASI KASITLI OLARAK BELİRSİZDİR** — "hesap bulunamadı" ile
  "parola yanlış" ayrılsaydı saldırgan kayıtlı adresleri tek tek
  öğrenebilirdi.
- **YARDIM İÇERİĞİ KODDA YAŞAR** (`HelpController`), veritabanında
  değil: metin sürümlenmiş bir ÜRÜN parçasıdır ve kod değiştiğinde
  birlikte değişmelidir. Veritabanında olsaydı metin ile davranış AYRI
  zamanlarda değişir ve yeni kurulum boş yardım ekranıyla açılırdı.
- **YARDIM BÖLÜM KİMLİKLERİ SÖZLEŞMEDİR** (`/help#stok`) ve BEKLENEN
  METİNLE sınanır; yeniden adlandırma davranış testlerini yeşil bırakır
  ama dışarıdan verilen bağlantıları SESSİZCE kırar.
- **YARDIM İÇERİĞİ SİSTEMİN GERÇEK TUZAKLARINI ANLATIR**, genel bir
  "nasıl kullanılır" değil: fazla satış, eşleşmemiş SKU, kalıcı hata,
  kanal stoğunun neden yazılmadığı, kota. §17 bu ekranı DESTEK YÜKÜNÜ
  düşürmek için istiyor.

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
maddesi (sipariş listesi + fazla satış uyarısı)** kapandı. Panelde ON BEŞ
ekran var: özet · ürünler · toplu içe aktarma · ürün kanalları ·
siparişler · sipariş ayrıntısı · stok · mutabakat · başarısız işlemler ·
**sistem sağlığı** · kanallar · eşleştirme · **onaylar** · abonelik ·
**yardım**.

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

**FAZ 3 SÜRÜYOR.** Dokümanın §13 · Faz 3 listesinde BEŞ madde var ve
DÖRDÜ bitti: mutabakat motoru · toplu içe aktarmanın CSV yarısı · **ölü
mektup ekranı + tek tıkla yeniden deneme** (madde 3 ve 4'ü birlikte
kapatır — `/failures` hem hata gezgini hem ölü mektup ekranıdır) ·
**metrik toplama + sağlık ekranı** (madde 2'nin toplama+panel kısmı,
`/metrics`). Eksikler: **kanaldan ürün çekme** (~7 sa, §7'ye yeni
yetenek arayüzü gerekebilir — MİMARİ karar) ve **uyarı e-postaları**
(mail altyapısı hiç yok).

Faz durumu iddiasını devir notundan değil dokümanın §13 listesinden
doğrula: geçmişte "Faz 3 kapandı" denip yanlış çıktı.

Devir notundaki alt liste (hepsi bitti):
`UpdateOrderSnapshot` + `UpdateFulfillment`, **`PruneApiCalls`**,
**`RequestResync` + T10**, **fiyat senkron yolu** ve **ılık/soğuk
mutabakat katmanları**. **P0/P1'in tamamı yeşil; yazılmamış P0/P1 testi
kalmadı.**

**FAZ 4 KAPANDI** (hafta 21–25, **90/90 sa**). Beş maddenin beşi de
bitti: onboarding akışı (`a118b3a`) · abonelik/ödeme — şema + kota
(`d02b984`) ve Stripe tahsilat hattı (`6f89fe1`) · panel cilası
(`aba0a29` + `26426ff`) · Türkçe yardım ve hata mesajları (`7208c51` +
`8642f9f`) · **güvenlik kontrol listesi + yük testi + yedek geri yükleme
provası** (`1cc6720` + `05b336e` + `707ad44` + `fbf1eb7`).

Panelde **ON BEŞ** ekran var (`/help` ve `/approvals` dahil).
**Faz 4'ün artık maddesi de kapandı** — onay durumu ekranı `8ba3c08`.

**FİYAT ÇAKIŞMASI TESPİTİ BİTTİ** (`fd8cbe1`) — v2.2'nin kod
tarafındaki SON açık maddesiydi. Dokümanın "468 saat sonunda
production'a hazır olan" tablosundaki **tüm "TAM" satırları artık
gerçekten TAM.** Kalıcı kurallar yukarıda "Fiyat çakışması kuralları"
başlığında. **BU MADDEYİ YENİDEN AÇMA.**

**SIRADAKİ İŞ — HEPSİBURADA DOKÜMANTASYONU (kullanıcı ile BİRLİKTE).**
Kullanıcı kararı (24 Ağustos): "dökümantasyonu beraber yazalım". Uç
noktalar doğrulanmadan sonraki madde YAZILMAZ; ayrıntı DEVIR.md'nin en
üstünde.

Bekleyen diğer maddeler: Faz 5 tampon (28 sa) · Stripe'ı uçtan uca
sürmek (anahtarlar TEST moduna çevrilince) · proje ismi (~yarım saat).

**YENİ PAZARYERLERİ — BAŞLANDI (24 Ağustos 2026).**
Sıra: **Hepsiburada → Shopify → Amazon → Etsy → eBay.**

**⚠️ SHOPIFY KARARI DEĞİŞTİ.** 19 Ağustos'ta "kapsam dışı"ydı; kullanıcı
24 Ağustos'ta "shopify da bizim için çok önemli" dedi. **LARAVEL ADAPTER
olarak yazılacak, Remix uygulaması DEĞİL** (kullanıcı kararı). Bu
dokümandan BİLİNÇLİ bir sapmadır: §2 diyagramı ve §11 servis token'ı
değişmezi Shopify'ı ayrı bir Node/Remix servisi olarak öngörüyor ve o
mimari **App Store'a çıkmak için** gerekli (doküman Ay 8+ diyor).
Şimdi yazılacak olan: satıcının kendi **custom app** Admin API
anahtarıyla bağlandığı, Woo/Trendyol ile AYNI kalıpta bir adapter.
OAuth YOK, Remix YOK, **projeye ikinci teknoloji yığını (Node)
SOKULMAZ**. App Store kararı verilirse §11'in servis token'ı değişmezi
O ZAMAN uygulanır; şema hazır (`UNIQUE(channel_type_code,
external_account_id)`).

**DOKÜMAN İKİSİNİ DE KAPSAM DIŞI BIRAKIYOR** (§16: "468 saatte dört
kanal yüzeysel çalışır; iki kanal kusursuz çalışır"; kapsam dışı
tablosu "Ay 7"). 468 saatlik plan Faz 5 ile bitiyor ve bu maddeler
ONDAN SONRASIDIR — doküman ihlali değil, zaman çizelgesinin dışına
çıkış.

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

Panel tarafında kapananlar: **mutabakat ekranı** (`513480d`),
**`RequestResync` + T10** (`9ec5ac0`), **ölü mektup ekranı + tek tıkla
yeniden deneme** (`244a397`) ve **sağlık ekranı** (`8e27913`).
Faz 3'ten kalan: **kanaldan ürün çekme** ve **uyarı e-postaları**.

**Abonelik/ödeme Faz 4'tür (hafta 21–25), şimdi değil.** §13 · Faz 4:
"Planlar, abonelik, kota, ödeme entegrasyonu (iyzico) — 26 sa". Şema kararı
alınmış (`tenants.plan_code` kolonu zaten var; §4 · `plans` kiracısız+seed,
`subscriptions` `UNIQUE(tenant_id) WHERE status='active'`; §3 · `Plan`,
`Subscription`, `UsageRecord` modelleri) ama **yazılmadı ve şimdi
yazılmamalı**: kota neyi sınırladığını senkron davranışından alır, o oturmadan
tanımlanan kota sonra değişir. Faz 4 demosu da bunu varsayıyor: "yeni
kullanıcı kaydolup ödeme yapıp senkronlayabiliyor".

Doküman §18 testlerin **önce** yazılmasını şart koşuyor.
