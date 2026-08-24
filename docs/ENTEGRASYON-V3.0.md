# ENTEGRASYON V3.0
## Multi-Channel Expansion — Implementation Reference

**Baseline:** v2.2 Implementation Reference (DONDURULMUŞ) · commit `72b7416`
**Durum:** v2.2 kod tarafı KAPALI — 923 test yeşil, Faz 1–4 bitti
**Kapsam:** Shopify · Hepsiburada · Etsy · eBay — mevcut codebase üzerine

---

## 01 · V3.0 Scope and Baseline

### Bu doküman ne DEĞİLDİR

v2.2'nin yeniden tasarımı değildir. v2.2 bugün production'da çalışan
mimaridir ve **hiçbir çekirdek kararı bu belgede yeniden açılmaz.** Stok
matematiği, outbox/inbox, fan-out, kilit stratejisi, kiracı izolasyonu,
sürüm modeli ve mutabakat motoru **olduğu gibi kalır.**

Bu belge tek bir soruya cevap verir:

> Repository'yi açtım, v2.2'nin son commit'i önümde.
> Shopify, Hepsiburada, Etsy ve eBay'i eklemek için **tam olarak neyi,
> hangi sırayla, nereye** yazacağım?

### Kanal durumu

| Kanal | v2.2 sonu | V3.0 sonu |
|---|---|---|
| WooCommerce | ✅ TAM | değişmez |
| Trendyol | ✅ TAM | değişmez |
| Hepsiburada | 🟡 istemci katmanı (`356a662`), kanal KAPALI | ✅ TAM |
| Shopify | ❌ yok | ✅ TAM |
| Etsy | ❌ yok | ✅ TAM |
| eBay | ❌ yok | ✅ TAM |

### v2.2 dokümanından bilinçli sapmalar

Üçü de **V3.0 kapsamında onaylanmış proje kararlarıdır** ve bu belgede
gerekçesiyle taşınır.
Dokümanın kendisi "kod ile doküman çeliştiğinde doküman esastır" der;
bu istisnalar kullanıcının açık talebidir.

| # | v2.2 ne diyor | V3.0 ne yapıyor | Gerekçe |
|---|---|---|---|
| 1 | Shopify = ayrı **Remix/Node servisi**, App Store için (§2, §11 · Ay 8+) | Shopify = **Laravel adapter**, Woo/Trendyol ile aynı kalıp | Satıcı kendi **custom app** anahtarıyla bağlanır. Projeye ikinci teknoloji yığını (Node) SOKULMAZ. App Store kararı verilirse §11'in servis token'ı değişmezi O ZAMAN uygulanır — şema zaten hazır (`UNIQUE(channel_type_code, external_account_id)`). |
| 2 | Hepsiburada, Etsy, eBay = **kapsam dışı** (Ay 7–9) | V3.0 kapsamı | v2.2'nin **468 saatlik genel roadmap'i** Faz 5'te bitiyor ve bu kanalları ileri fazlara bırakıyordu; V3.0 onları **ayrı bir 240 saatlik implementation kapsamına** alır (§27). Zaman çizelgesinin dışına çıkış, doküman ihlali değil. |
| 3 | §7 · **yedi** yetenek arayüzü | **sekiz** — `SupportsCatalogImport` eklendi (`99008b8`) | `SupportsCatalog`'un iki okuma metodu da YEREL kayıttan başlar; içe aktarma TERSİNİ sorar ("kanalda ne var ki bende yok"). V3.0 kapsamında onaylanmış proje kararı. |

> **468 SAAT V3.0'IN TOPLAMI DEĞİLDİR.** O rakam v2.2'nin genel
> roadmap'ine aittir ve v2.2 Faz 1–5'i kapsar. **V3.0'ın kanal
> genişletme implementation tahmini 240 saattir** (§27) ve bu belgede
> geçen tek V3.0 toplamı odur.

### V3.0'ın değişmez sınırı

> **Yeni kanal çekirdeği DEĞİŞTİRMEZ.**
> Kanal başına bir adapter (+ mapper/normalizer) yazılır. Stok matematiği,
> outbox, fan-out, kilit ve mutabakat aynı kalır. `if ($channel === '...')`
> YAZILMAZ — yetenek `instanceof` ile okunur.

Bu belgede **Core Domain'e dokunan her madde ayrıca gerekçelendirilir** ve
toplamda yalnızca **üç Core Architecture Delta** vardır (§03). §16'daki
DB/seeder maddeleri bu sayıya **dahil değildir**.

---

## 02 · V2.2 Baseline — Frozen Existing System

Bu bölüm hatırlatmadır, tekrar değil. Yeni kanal yazarken **ihlal
edilemeyecek** kurallar burada toplandı; tamamı v2.2'de gerekçelendirilmiş
ve testle korunuyor.

### Çekirdek değişmezler — V3'te de geçerli

| Alan | Kural | Yeni kanalda anlamı |
|---|---|---|
| Stok | `on_hand = Σ inventory_movements.on_hand_delta`, clamp YOK | Yeni adapter **ledger'a yazmaz**; `ApplyMovement` çağırır |
| Kırpma | Yalnızca `OutboundQuantity::forChannel()`, giden yükte | Her yeni adapter mutlak, kırpılmış değer alır |
| Kilit | Çok-SKU yazan **her** yol `LockInventoryRows` | Yeni sipariş yolu da aynı |
| Kiracı | Bağlam yoksa **istisna**, sessiz veri YOK | Adapter `runAsSystem()` ile kimlik okur |
| Adapter | `AdapterRegistry::for()` **her çağrıda yeni örnek**, `bind` | Yeni adapter'lar da singleton OLAMAZ |
| Adapter | **Yan etkisiz** — DB'ye yazmaz, kuyruğa iş atmaz | Sonucu `SyncResultRecorder` yazar |
| Fan-out | Outbox tüketicisinde: 1 olay → N operasyon | 6 kanalda 6 operasyon — kod değişmez |
| Gelen hat | **Tek** inbox (`IngestInboxMessage`), webhook + polling aynı yol | Yeni kanal ikinci hat AÇMAZ |
| HMAC | **Ham gövde üzerinden**, ayrıştırmadan ÖNCE | Webhook veya imzalı event callback kullanan **her** kanalda, o kanalın kendi doğrulama spesifikasyonuna göre (§19) |
| Webhook | **Her durumda 202** (istisna: 415, 429) | Yeni kanal da aynı |
| Sipariş | **Asla reddedilmez**; stok yetmezse negatife düşer | Etsy/eBay siparişi de |
| Hata | Sınıflandırma **adapter**'da, karar **çekirdek**te | `classifyError()` kanala özgü |
| Sürüm | `NORMAL_SYNC` kapı uygular, `REPAIR` atlar | Yeni kanal mutabakatı da REPAIR kullanır |

### Mevcut extension point'ler — V3 bunları KULLANIR

```
app/Domain/Channels/
├── Contracts/
│   ├── ChannelAdapter.php            ← her adapter'ın tabanı
│   ├── SupportsCatalog.php           ← ürün gönderme
│   ├── SupportsCatalogImport.php     ← kanaldan ürün çekme (8., v2.2'de YOK)
│   ├── SupportsInventory.php         ← stok itme/okuma
│   ├── SupportsPricing.php           ← fiyat itme/okuma
│   ├── SupportsOrders.php            ← sipariş yoklama/ayrıştırma
│   ├── SupportsTaxonomy.php          ← kategori ağacı + öznitelik
│   ├── SupportsApprovalWorkflow.php  ← onay durumu
│   └── SupportsFulfillment.php       ← kargo bildirimi
├── Registry/AdapterRegistry.php      ← bind, ASLA singleton
├── Support/
│   ├── ChannelHttpClient.php         ← HTTP + api_calls + maskeleme
│   ├── ChannelRateLimiter.php        ← Redis jeton kovası (bağlantı başına)
│   ├── CircuitBreaker.php            ← ardışık hata → devre açık
│   ├── CredentialVault.php           ← şifreli kimlik, runAsSystem
│   ├── PayloadRedactor.php           ← iki katmanlı maskeleme
│   └── ChannelErrorText.php          ← kalıcı yazılan hata metni maskesi
└── Adapters/
    ├── WooCommerce/                  ← referans: storefront
    ├── Trendyol/                     ← referans: marketplace + taksonomi
    └── Hepsiburada/                  ← V3'te tamamlanacak
```

### Yeni kanalın DOKUNMAYACAĞI yerler

`app/Domain/Inventory/` · `app/Domain/Messaging/` · `app/Domain/Sync/Support/`
(`InventoryBatchBuilder`, `PriceBatchBuilder`, `OutboundQuantity`) ·
`app/Domain/Reconciliation/Actions/` · `app/Support/Tenancy/`

Bu klasörlerde bir değişiklik gerekiyorsa **önce §03'e bakılır**; orada
listelenmemişse o değişiklik yapılmadan önce yeniden düşünülmelidir.

---

## 03 · V3 Architecture Delta

Dört kanal eklerken çekirdekte **üç** değişiklik gerekiyor. Hepsi
**genişletmedir**, davranış değiştirmez ve mevcut kanalları etkilemez.

### Delta 1 — `SupportsOfferLifecycle` (yeni · **9.** capability arayüzü)

> **NUMARALANDIRMA.** v2.2 sonunda **sekiz** capability arayüzü vardı
> (§02'deki `Contracts/` listesi). `SupportsOfferLifecycle`, bunlara
> eklenen **9.** capability arayüzüdür; `SupportsTokenRefresh` (Delta 3)
> ise V3.0 kapsamında eklenen **10.** support/capability arayüzüdür.
> **V3.0 sonunda toplam on arayüz olur** ve ikisi de mevcutların
> davranışını DEĞİŞTİRMEZ — yalnızca genişletir.

**Yalnızca eBay için.** eBay'de bir ürünün yayına girmesi **üç adımdır**:

```
inventory item (SKU)  →  offer (fiyat + miktar + politika)  →  published listing
     PUT                      POST /offer                      POST /offer/{id}/publish
   idempotent               offer_id döner                    listing_id döner
```

`SupportsCatalog::createListing()` bu zinciri **tek çağrıda** varsayar ve
`external_id` döner. eBay'de üç ayrı kimlik doğar ve **ikisi kalıcıdır**
(`offer_id`, `listing_id`).

**Neden mevcut arayüz yetmiyor:** `createListing()` içinde üç çağrıyı
zincirlemek mümkün ama **ara başarısızlık kurtarılamaz**: offer yaratıldı,
publish 429 aldı → `external_id` yazılmaz (v2.2 kuralı: "başarısızlıkta
yazılmaz") → sonraki tur **ikinci bir offer yaratır** ve eBay `25002`
(duplicate offer) döner. Kalıcı hata; listing "düzeltilemez" damgasıyla ölür.

```php
interface SupportsOfferLifecycle
{
    /** SKU'yu envantere yazar — idempotent (PUT). */
    public function upsertInventoryItem(Listing $l, ListingPayload $p): AdapterResult;

    /** Offer yaratır veya günceller; offer_id döner. */
    public function upsertOffer(Listing $l, ListingPayload $p): AdapterResult;

    /** Offer'ı yayına alır; listing_id döner. */
    public function publishOffer(Listing $l): AdapterResult;

    /** Yayından kaldırır — SİLMEZ (v2.2 · delist kuralı). */
    public function withdrawOffer(Listing $l): AdapterResult;
}
```

**Registry anahtarı:** `offer_lifecycle`. Panel bu yeteneği görünce
`PushListing` yerine `PushOfferListing` işini kullanır (§13).

> **Bu arayüz eBay'e ÖZGÜ DEĞİLDİR.** Amazon SP-API'nin feed tabanlı akışı
> (`submitFeed` → `getFeedResult`) de aynı "çok adımlı, ara kimlikli"
> deseni taşır. Arayüz o yüzden `SupportsEbayOffer` değil
> `SupportsOfferLifecycle` adını taşıyor.

### Delta 2 — `listings.channel_metadata` (JSONB, nullable)

**Shopify, Etsy ve eBay için.** Üçünde de **birden çok kalıcı uzak kimlik**
var ve `external_id` + `external_parent_id` yetmiyor (§07'de her kimlik tek
tek analiz edildi).

```sql
ALTER TABLE listings ADD COLUMN channel_metadata jsonb;
```

**Neden kolon başına ayrı alan değil:** her kanal farklı kimlik taşır
(`offer_id`, `inventory_item_gid`, `offering_id`) ve kolon eklemek altı
kanalın beşinde NULL duran bir şema üretirdi. Ayrıca kimlikler **çekirdek
tarafından sorgulanmaz** — yalnızca adapter okur ve yazar. JSONB tam bu iş
içindir (`settings` kolonuyla aynı gerekçe).

**Neden ayrı tablo değil:** listing başına tek satır olurdu ve her okuma
bir JOIN eklerdi; kardinalite 1:1.

> **DEĞİŞMEZ KURAL — `channel_metadata` SIR TAŞIMAZ.**
> Kolon şifresiz ve panele Inertia prop'u olarak gidebilir. Token, secret
> ve imza `channel_credentials`'ta yaşar (v2.2 · "sırlar `settings` içine
> yazılmaz" kuralının aynısı). Kimlik ≠ sır.

### Delta 3 — `channel_credentials.expires_at` GERÇEKTEN KULLANILIR

**Şema değişikliği YOK** — kolon v2.2 §4'te zaten tanımlı ve
`INDEX(expires_at) WHERE revoked_at IS NULL` da var. Ama **bugün hiçbir kod
onu okumuyor**: Woo (kalıcı anahtar) ve Trendyol (kalıcı anahtar) süre
dolumu bilmiyor.

Shopify (offline token — süresiz ama iptal edilebilir), Etsy (**1 saat**) ve
eBay (**2 saat** access + 18 ay refresh) için token yenileme **zorunludur**.

Yeni bileşen — çekirdekte, kanala özgü DEĞİL. `SupportsTokenRefresh`,
V3.0 kapsamında eklenen **10.** support/capability arayüzüdür
(9.'su Delta 1'in `SupportsOfferLifecycle`'ı):

```
app/Domain/Channels/Support/TokenRefresher.php     ← ne zaman yenilenmeli
app/Domain/Channels/Contracts/SupportsTokenRefresh.php
app/Domain/Channels/Console/RefreshExpiringTokensCommand.php  (credentials:refresh)
```

```php
interface SupportsTokenRefresh
{
    /** Yeni kimlik bilgisi döner; VAULT'A YAZMAZ (adapter yan etkisizdir). */
    public function refreshCredentials(): RefreshedCredentials;

    /** Süre dolmadan kaç saniye önce yenilensin. */
    public function refreshLeadSeconds(): int;
}
```

> **DEĞİŞMEZ KURAL — YENİLEME İSTEK ANINDA DEĞİL, TARAMAYLA YAPILIR.**
> İstek anında yenilemek şu tuzağı doğurur: aynı bağlantı için paralel
> koşan iki iş aynı anda yeniler, ikisi de yeni token alır ve **kanal
> ilkini iptal eder** (Etsy ve eBay refresh token'ı tek kullanımlıktır).
> Tarama tek süreçte koşar (`withoutOverlapping`) ve `SELECT ... FOR UPDATE`
> ile satırı kilitler.
>
> **Süresi dolmuş token'la yapılan çağrı `AUTHENTICATION` döner ve o KALICI
> hatadır** — yani yenileme başarısızlığı listing'i "anahtarın yanlış"
> damgasıyla öldürür. Bu yüzden tarama sıklığı en kısa TTL'in (Etsy, 1 saat)
> **dörtte biri** seçildi: 15 dakika.

### Çekirdekte DEĞİŞMEYENLER — açıkça

| Bileşen | Neden dokunulmuyor |
|---|---|
| `ApplyMovement`, `LockInventoryRows` | Yeni kanal ledger'a yazmaz |
| `InventoryBatchBuilder`, `PriceBatchBuilder` | Yalnızca gruplama; kanal sayısından bağımsız |
| `OutboxRelay`, `ConsumeOutboxEvent` | Fan-out zaten N kanal |
| `ReconcileConnection` | Domain + yetenek parametreli (`fd8cbe1`) |
| `IngestInboxMessage`, `OrderEventRouter` | Tek gelen hat; yeni kanal aynı yola yazar |
| `ChannelRateLimiter`, `CircuitBreaker` | Profil adapter'dan gelir |
| Tüm `Inventory/` domaini | **Hiçbir dosya** |

---

## 04 · Six-Channel Capability Matrix

Bu tablo **gerçektir** — Woo/Trendyol/Hepsiburada sütunları koddan
doğrulandı, diğer üçü V3 hedefidir.

| Capability | Interface | Woo | Trendyol | Shopify | Hepsiburada | Etsy | eBay |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|
| Catalog | `SupportsCatalog` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Product Import | `SupportsCatalogImport` | ✅ | ❌ | ✅ | ❌ | ✅ | ❌ |
| Inventory | `SupportsInventory` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Pricing | `SupportsPricing` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Orders | `SupportsOrders` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Fulfillment | `SupportsFulfillment` | ✅ | ❌ | ✅ | ✅ | ✅ | ✅ |
| Taxonomy | `SupportsTaxonomy` | ❌ | ✅ | ❌ | ✅ | ✅ | ✅ |
| Approval Workflow | `SupportsApprovalWorkflow` | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ |
| **Offer Lifecycle** | `SupportsOfferLifecycle` | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| **Token Refresh** | `SupportsTokenRefresh` | ❌ | ❌ | ⚠️ | ❌ | ✅ | ✅ |
| Webhooks | *(taşıma, arayüz YOK)* | ✅ | ❌ | ✅ | ✅ | ❌ | ⚠️ |
| Polling | *(taşıma)* | ❌ | ✅ | ❌ | ❌ | ✅ | ✅ |
| Returns | `order_events` · RETURN | ✅ | ✅ | ✅ | ✅ | ⚠️ | ✅ |
| Cancellation | `order_events` · CANCEL | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Listing Creation | `SupportsCatalog::createListing` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅* |

**Dipnotlar — dürüst sınırlar:**

- **Shopify · Token Refresh ⚠️** — offline access token **süresiz** ama
  uygulama kaldırılınca (`app/uninstalled`) geçersizleşir.
  `SupportsTokenRefresh` UYGULANMAZ; bunun yerine webhook `revoked_at`
  yazar (§07, §20).
- **eBay · Webhooks ⚠️** — eBay **Notification API** sunar ama sipariş için
  değil (hesap kapanma, politika ihlali). Sipariş **yoklamayla** gelir.
- **Etsy · Returns ⚠️** — Etsy API'si iade için ayrı uç nokta vermiyor;
  iade satıcı tarafından işlenir ve `receipt` durumu değişir. Yoklama bunu
  `updated` olarak görür → **stok hareketi ÜRETMEZ** (v2.2 kuralı). Gerçek
  iade elle girilir. **Bu bir eksiklik değil, kanalın sınırıdır.**
- **eBay · Listing Creation ✅\*** — `SupportsCatalog` uygulanır ama gövdesi
  `SupportsOfferLifecycle` zincirini çağırır (§13).
- **Taxonomy · Shopify ❌** — Shopify'da kategori zorunlu değil;
  `product_type` serbest metin. Taksonomi arayüzü uygulanmaz —
  **uygulanırsa panelde çalışmayan bir sekme açılır.**

---

## 05 · Shared Channel Extension Pattern

Dört kanalın dördü de aynı iskeleti takip eder. **Bu bölüm bir şablondur**;
sonraki bölümler yalnızca kanala özgü farkları anlatır.

### Klasör iskeleti (kanal başına)

```
app/Domain/Channels/Adapters/{Channel}/
├── {Channel}Adapter.php            ← ChannelAdapter + Supports*
├── {Channel}Endpoints.php          ← TÜM uç noktalar TEK yerde
├── {Channel}OrderNormalizer.php    ← ham gövde → NormalizedOrderEvent
├── {Channel}ProductMapper.php      ← ListingPayload → kanal formatı
├── {Channel}ErrorClassifier.php    ← gövde → ErrorClass  (opsiyonel)
└── {Channel}Auth.php               ← OAuth/token akışı  (gerekiyorsa)
```

> **UÇ NOKTALAR TEK YERDE TOPLANIR** (`{Channel}Endpoints`). Adapter'a
> serpiştirilirse düzeltme on yere dokunmak olur ve biri unutulunca o çağrı
> **sessizce yanlış adrese** gider. Hepsiburada'da bu karar zaten alındı
> (`356a662`); yeni kanallarda da uygulanır.
>
> **Doldurulmamış yer tutucu İSTİSNA fırlatır** — geçseydi istek literal
> `{sku}` içeren adrese gider ve 404'ün sebebi görünmezdi.

### Kanal ekleme kontrol listesi — 12 adım

| # | Adım | Dosya / yer | Not |
|---|---|---|---|
| 1 | `channel_types` satırı | `ChannelTypeSeeder` | **`is_active = false` ile başla** |
| 2 | Endpoints sınıfı | `{Channel}Endpoints.php` | Doğrulanmamışsa sınıf başlığında YAZ |
| 3 | Adapter iskeleti | `{Channel}Adapter.php` | `ChannelAdapter` + sağlık + `classifyError` |
| 4 | Kimlik doğrulama | `ChannelHttpClient` / `{Channel}Auth` | Basic → `BASIC_AUTH_KEY_PAIRS`; OAuth → ayrı |
| 5 | Hız sınırı profili | `rateLimitProfile()` | **En düşük sınır seçilir** |
| 6 | Bağlama akışı | `ConnectChannel` (mevcut) | Sağlık kontrolü geçmeden `active` OLMAZ |
| 7 | Katalog | `SupportsCatalog` + Mapper | `findExistingListing` ÖNCE sorulur |
| 8 | Stok | `SupportsInventory` | **Mutlak değer**, delta asla |
| 9 | Fiyat | `SupportsPricing` | String taşınır, float ASLA |
| 10 | Sipariş | `SupportsOrders` + Normalizer | Webhook veya yoklama → **aynı inbox** |
| 11 | Mutabakat | `fetchInventory` / `fetchPrices` | Zaten yazıldı — otomatik çalışır |
| 12 | **Kanalı aç** | `is_active = true` | Gerçek hesapla sağlık kontrolü GEÇTİKTEN sonra |

> **ADIM 1 VE 12 AYRIDIR ve bu bilinçlidir.** Doğrulanmamış adrese istek
> atan bağlantı, kanal 200 dönerse **"senkron BAŞARILI" gösterir ve hiçbir
> şey gitmemiş olur** — teşhisi en zor hata sınıfı.
>
> **SEEDER TUZAĞI:** `db:seed --class=ChannelTypeSeeder` çalıştırıldığında
> elle açılmış kanallar `false`'a döner (Trendyol'da yaşandı, `356a662`).
> V3'te seeder **var olan `is_active` değerini KORUR** (§16 · DB Delta 4).

### Henüz implement edilmemiş capability için örnek davranış

Aşağıdaki örnek **kanaldan bağımsızdır** ve herhangi bir kanalın henüz
yazılmamış yetenek gövdesi için geçerlidir. Bir kanalın §04 capability
matrisindeki **hedef** durumu ✅ olsa bile, o gövde yazılana kadar
davranış budur.

```php
public function pushSomeCapability(...): AdapterResult
{
    throw new RuntimeException(
        'Bu kanal için ilgili capability henüz uygulanmadı. '.
        'AdapterResult::success() dönmek operasyonu tamamlandı gösterir, '.
        '`synced_version` ilerler ve kanalda hiçbir şey değişmemişken satır '.
        '"senkron" görünür.'
    );
}
```

> **YAZILMAMIŞ YETENEK SESSİZCE BAŞARILI DÖNMEZ** — v2.2'nin açık yasağı.
> **Arayüz de İLAN EDİLMEZ**: çalışmayan yetenek panelde çalışmayan sekme
> demektir.

---

## 06 · Shopify Implementation

### 06.1 · Mimari karar — Remix DEĞİL, Laravel adapter

v2.2 §2 ve §11 Shopify'ı **ayrı bir Node/Remix servisi** olarak öngörüyor
ve o mimari **App Store'a çıkmak için** gereklidir (doküman Ay 8+ diyor).

**V3.0 bunu yapmıyor.** Kullanıcı kararı (24 Ağustos 2026): satıcının kendi
**custom app** Admin API anahtarıyla bağlandığı, Woo/Trendyol ile **aynı
kalıpta** bir adapter. OAuth YOK, Remix YOK, **ikinci teknoloji yığını
SOKULMAZ.**

| Konu | v2.2 (App Store yolu) | V3.0 (custom app yolu) |
|---|---|---|
| Kurulum | Shopify App Store → OAuth | Satıcı admin'den custom app açar |
| Kimlik | OAuth access token, servis token'ı ile Core'a | **Admin API access token**, `channel_credentials` |
| Kiracı çözümü | `ResolveTenantFromShopDomain` middleware | **Gerekmez** — bağlantı zaten kiracıya ait |
| Servis sınırı | JWT (`iss`, `aud`, `sub`, `jti`) | **Yok** — tek uygulama |
| Webhook | Remix alır, Core'a iletir | **Core doğrudan alır** (Woo ile aynı) |
| Node bağımlılığı | Var | **Yok** |

> **§11'İN SERVİS TOKEN'I DEĞİŞMEZİ İPTAL EDİLMEDİ, ERTELENDİ.**
> App Store kararı verilirse o değişmez **olduğu gibi** uygulanır ve şema
> hazırdır: `channel_connections` üzerindeki
> `UNIQUE(channel_type_code, external_account_id)` kısıtı, shop domain'den
> kiracı çözümünün tekil olmasını bugünden garanti ediyor.
>
> O gün geldiğinde **bu adapter atılmaz**: Remix uygulaması yalnızca OAuth
> ve App Bridge yüzeyi olur, ürün/stok/sipariş işi yine bu adapter'dan
> geçer.

### 06.2 · Bağlantı ve kimlik

**`external_account_id` = shop domain** (`magaza.myshopify.com`).

> **Woo'daki alan adı kuralının aynısı ama sebebi FARKLI.** Woo'da alan adı
> hesap kimliğidir çünkü her mağaza kendi sunucusundadır. Shopify'da tek API
> ana bilgisayarı yoktur — her mağaza **kendi alt alan adına** sahiptir ve o
> ad kalıcı kimliktir. Mağaza özel alan adı (`www.magazam.com`) kullansa
> bile `.myshopify.com` adresi DEĞİŞMEZ.
>
> **NORMALLEŞTİRME ZORUNLU** (`StoreUrl`, mevcut): küçük harf, şema ve
> sondaki eğik çizgi atılır. Yapılmazsa aynı mağaza iki kimlikle bağlanır ve
> `UNIQUE(channel_type_code, external_account_id)` **hiçbir şey korumaz**.

**Kimlik bilgisi:** `X-Shopify-Access-Token` başlığı (Basic auth DEĞİL).

```php
// ChannelHttpClient'a EKLENECEK: başlık desteği ZATEN GENEL (356a662).
// Adapter başlığı verir, istemci taşır, `if ($channel === '...')` YAZILMAZ.
protected function authHeaders(): array
{
    return ['X-Shopify-Access-Token' => $this->credential('access_token')];
}
```

> **KİMLİK BİLGİSİ `runAsSystem()` İLE OKUNUR.** `ChannelHttpClient` bağlam
> OLMADAN çağrılabilir (kuyruk işi, tarama, sağlık kontrolü). Kapsama burada
> bir şey korumaz — bağlantı zaten elimizdedir — yalnızca okumayı engeller
> ve istek **sessizce kimliksiz** gider; kanal 401 döner, `AUTHENTICATION`
> kalıcı sayılır ve listing "anahtarın yanlış" diyerek ölür. Oysa anahtar
> doğrudur, hiç gönderilmemiştir (`97a7eb7`'de yaşandı).

**API sürümü sabittir ve `Endpoints` içinde tek yerde durur:**

```php
final class ShopifyEndpoints
{
    /**
     * API SÜRÜMÜ SABİTTİR ve çeyrek dönemde bir güncellenir.
     * `unstable` veya sürümsüz istek ASLA atılmaz: Shopify sürümsüz
     * çağrıyı en eskiye düşürür ve alanlar habersizce kaybolur.
     */
    public const API_VERSION = '2026-01';

    public const GRAPHQL = 'admin/api/{version}/graphql.json';
    public const WEBHOOKS = 'admin/api/{version}/webhooks.json';
}
```

### 06.3 · GraphQL Admin API — REST DEĞİL

Shopify REST Admin API'yi **kullanımdan kaldırıyor**; yeni geliştirme
GraphQL üzerinden yapılır.

**Bu, projede ilk GraphQL kanalıdır ve `ChannelHttpClient` DEĞİŞMEZ:**
GraphQL tek bir POST isteğidir; sorgu gövdede taşınır.

```php
// ShopifyAdapter içinde — istemci genel kalır
private function gql(string $query, array $variables = []): Response
{
    return $this->client->post(ShopifyEndpoints::graphql(), [
        'query' => $query,
        'variables' => $variables,
    ]);
}
```

> **GRAPHQL 200 DÖNER AMA BAŞARISIZ OLABİLİR — EN TEHLİKELİ FARK.**
> REST'te hata HTTP kodudur; GraphQL'de **her şey 200'dür** ve hata gövdede
> `errors` / `userErrors` altında yaşar. `$response->throw()` bunu GÖRMEZ.
>
> Kontrol edilmezse: `SyncResultRecorder` başarı yazar, `synced_version`
> ilerler ve **kanalda hiçbir şey değişmemişken satır "senkron" görünür**.
> Bu, projenin en pahalı hata biçimidir (Woo'nun `manage_stock` tuzağının
> aynısı).

```php
/** HER GraphQL yanıtı buradan geçer — istisnasız. */
private function assertNoGraphqlErrors(Response $r, string $operation): array
{
    $body = $r->json();

    // Taşıma/şema hatası
    if (! empty($body['errors'])) {
        throw new ShopifyGraphqlException($operation, $body['errors']);
    }

    // İş kuralı hatası — mutation başına AYRI alan (`productUpdate`
    // → `userErrors`). Yol adapter tarafından verilir.
    return $body['data'] ?? [];
}
```

### 06.4 · Katalog — product / variant / inventory item

Shopify'ın veri modeli **üç kimlik** taşır ve üçü de kalıcıdır:

```
Product (gid://shopify/Product/123)
   └── ProductVariant (gid://shopify/ProductVariant/456)
          └── InventoryItem (gid://shopify/InventoryItem/789)
                 └── InventoryLevel @ Location (gid://shopify/Location/12)
```

**Kanonik modelle eşleme:**

| Shopify | Bizim | Nerede saklanır |
|---|---|---|
| `ProductVariant` gid | listing kimliği | `listings.external_id` |
| `Product` gid | üst ürün | `listings.external_parent_id` ✅ *(v2.2'de zaten var)* |
| `InventoryItem` gid | stok yazma hedefi | `listings.channel_metadata->>'inventory_item_gid'` |
| `Location` gid | depo | `channel_connections.settings->>'location_gid'` |

> **`inventory_item_gid` NEDEN AYRI SAKLANIR:** stok yazma
> `inventorySetOnHandQuantities` mutation'ı **variant gid'i kabul etmez**,
> `inventoryItemId` ister. Her stok itmesinde variant → inventory item
> çevrimi için ek bir GraphQL sorgusu atmak, **stok yolunu iki katına
> çıkarır** — ve o yol projenin en kritik yoludur (`inventory:high`, 45 sn).
> Kimlik listing yaratılırken bir kez okunur ve donar.
>
> **`location_gid` BAĞLANTIDA, LISTING'DE DEĞİL:** bir mağazanın stok
> konumu tüm ürünler için aynıdır. Listing başına saklamak aynı değeri
> 5.000 satıra kopyalardı ve konum değişince hepsini güncellemek gerekirdi.

**Çok konumlu mağaza — dürüst sınır:**

> Shopify bir mağazada **birden çok konum** (mağaza, depo) destekler.
> V3.0 **tek konum** varsayar: bağlama akışında satıcıya konum seçtirilir ve
> `settings.location_gid` yazılır. Çok konumlu stok, v2.2'nin **çok depo
> arayüzü** maddesiyle aynı kaderi paylaşır: şema hazır
> (`inventory_levels.warehouse_id`), arayüz **talep gelince**.
>
> Seçim yapılmazsa **bağlantı `active` OLMAZ** — sağlık kontrolü konum
> yokluğunu hata sayar. Varsayılan konumu sessizce seçmek, iki depolu bir
> satıcının stoğunu **yanlış depoya** yazardı.

### 06.5 · Stok

```graphql
mutation SetOnHand($input: InventorySetOnHandQuantitiesInput!) {
  inventorySetOnHandQuantities(input: $input) {
    inventoryAdjustmentGroup { createdAt }
    userErrors { field message code }
  }
}
```

- **MUTLAK değer** (`setOnHandQuantities`), delta DEĞİL
  (`inventoryAdjustQuantities` da var — **KULLANILMAZ**).
- Batch: tek mutation'da çok kalem → `maxInventoryBatchSize() = 250`.
- `userErrors` **kontrol edilir** (§06.3).

> **DELTA MUTATION'I YASAKTIR VE SEBEBİ v2.2'DE YAZILI:** kaybolan veya iki
> kez işlenen bir istek stoğu KALICI olarak kaydırır. Shopify'ın delta API'si
> daha "verimli" görünür; bu görüntü aldatıcıdır.
>
> **`inventoryItemId` YOKSA İSTEK HİÇ ATILMAZ.** Boş kimlikle mutation
> `userErrors` döner ve o hata `VALIDATION` yani KALICIDIR — listing
> "düzeltilemez" damgasıyla ölür. Kimlik eksikse listing yeniden
> yaratılmalıdır (Hepsiburada'daki "satıcı kimliği yoksa istek atılmaz"
> kuralının aynısı).

### 06.6 · Sipariş — webhook

**Konu → olay eşlemesi:**

| Shopify konusu | Bizim tip | Stok etkisi |
|---|---|---|
| `orders/create` | `created` | SALE — stok düşer |
| `orders/updated` | `updated` | **YOK** |
| `orders/cancelled` | `cancelled` | Stok geri eklenir |
| `refunds/create` | `returned` | Stok geri eklenir |
| `fulfillments/create` · `fulfillments/update` | `fulfilled` | **YOK** |

> **İPTAL AYRI KONUDA GELİR — WOO'NUN TERSİ.** Woo'da iptal
> `order.updated` içinde gelir ve `WooOrderNormalizer` **durum alanının
> topic'i EZMESİNİ** gerektirir. Shopify'da `orders/cancelled` **ayrı bir
> konudur**; ezme kuralı burada UYGULANMAZ ve uygulanırsa `updated`
> olaylarını yanlışlıkla iptal sanma riski doğar.
>
> **İADE `refunds/create` KONUSUNDADIR, `orders/updated` DEĞİL.** Yalnızca
> sipariş konusu dinlenseydi iade **hiç görülmez** ve stok geri eklenmezdi
> — bakiye kalıcı eksik kalırdı.

**Doğrulama:**

- HMAC: `X-Shopify-Hmac-Sha256`, **ham gövde** üzerinden,
  `hash_hmac('sha256', $raw, $secret, true)` → base64 → `hash_equals`.
- Olay kimliği: `X-Shopify-Event-Id` — v2.2 §6 tablosunda **zaten kayıtlı**.
- Ayrıca `X-Shopify-Shop-Domain` başlığı bağlantıyı bulur.

> **WEBHOOK SIRRI YOKSA DOĞRULAMA "GEÇTİ" DEMEZ** — güvenli taraf
> REDDETMEKTİR (Hepsiburada'daki kuralın aynısı).
>
> **BAŞLIK ADI BÜYÜK/KÜÇÜK HARF DUYARSIZ OKUNUR** — vekil sunucular
> başlıkları yeniden yazar; tam eşleşme aransaydı MEŞRU webhook reddedilir
> ve kanal sonsuza kadar yeniden gönderirdi.

### 06.7 · Uygulama kaldırma — `app/uninstalled`

Satıcı custom app'i silerse **token sessizce geçersizleşir**. Bu konu
dinlenmezse: her istek 401 alır, `AUTHENTICATION` kalıcı sayılır ve
satıcının tüm listing'leri **teker teker ölür**; panel "anahtarınız yanlış"
der ama satıcı hiçbir şey değiştirmemiştir.

**Davranış:**

```
app/uninstalled webhook
  → channel_credentials.revoked_at = now()
  → channel_connections.status = 'inactive'
  → last_error = 'Uygulama Shopify mağazasından kaldırıldı.'
```

> **BAĞLANTI SİLİNMEZ, İŞARETLENİR** — v2.2 kuralı: listing ve sipariş
> geçmişi ona bağlıdır. Satıcı uygulamayı yeniden kurarsa `ConnectChannel`
> aynı satırı `firstOrNew` ile yeniden kullanır (anahtar yenileme akışı) ve
> **kotadan etkilenmez**.

### 06.8 · Hız sınırı — GraphQL maliyet tabanlı

Shopify GraphQL'de sınır **istek sayısı değil, sorgu maliyetidir**:
1.000 puanlık kova, saniyede 50 puan yenilenir. Yanıt gövdesinde gelir:

```json
{"extensions":{"cost":{"requestedQueryCost":12,"throttleStatus":
  {"maximumAvailable":1000.0,"currentlyAvailable":988.0,"restoreRate":50.0}}}}
```

> **`ChannelRateLimiter` DEĞİŞMEZ.** Kova bağlantı başınadır ve jeton
> sayar; Shopify'da bir "jeton" **bir puan** olarak yorumlanır. Profil
> adapter'dan gelir (`rateLimitProfile()`), uygulama çekirdekte kalır —
> Trendyol'un "sınır yanıttan öğrenilir" kararının aynısı.
>
> **SINIR YANIT GÖVDESİNDEN ÖĞRENİLİR VE BAĞLANTIYA YAZILIR.** Shopify Plus
> mağazalarında kova 2.000 puandır; sabit profil Plus müşterisini yavaşlatır,
> standart mağazayı 429'a sokar.
>
> **SAYI OLMAYAN DEĞER YOK SAYILIR** (`ctype_digit` süzgeci) — Trendyol'da
> vekil sunucunun iki başlığı birleştirmesiyle yaşandı.

429 geldiğinde `Retry-After` **yoktur**; `throttleStatus.currentlyAvailable`
ve `restoreRate` ile bekleme süresi hesaplanır.


---

## 09 · Hepsiburada Implementation

### 09.1 · Bugünkü durum — istemci katmanı YAZILDI

`356a662` ile Trendyol'un Faz 2'deki ilk maddesiyle **aynı kapsam** bitti:
istemci, kimlik doğrulama, sağlık kontrolü, hata sınıflandırma, hız sınırı,
webhook imzası. **20 test.**

**Kanal `is_active = false` ve panelde GÖRÜNMÜYOR.**

| Parça | Durum |
|---|---|
| `HepsiburadaAdapter` — istemci, kimlik, sağlık | ✅ `356a662` |
| `HepsiburadaEndpoints` — 13 sabit | ⚠️ **DOĞRULANMADI** |
| `classifyError`, `rateLimitProfile` | ✅ |
| `verifyWebhookSignature` (`X-HB-Signature`) | ✅ |
| Stok/fiyat itme | ❌ istisna fırlatıyor |
| Sipariş yoklama | ❌ istisna fırlatıyor |
| Katalog, taksonomi | ❌ **arayüz İLAN DA EDİLMEDİ** |

### 09.2 · ÖN KOŞUL — uç nokta doğrulaması

> **BU MADDE KODLA KAPATILAMAZ.**
> `developers.hepsiburada.com` bot isteklerini **403**,
> `listing-external.hepsiburada.com/docs` **401** ile reddediyor. Yollar
> ikincil kaynaklardan derlendi.
>
> **Doğrulama yapılmadan Faz 2 BAŞLAMAZ.** Doğrulanmamış adrese istek atan
> bağlantı, kanal 200 dönerse "senkron BAŞARILI" gösterir ve hiçbir şey
> gitmemiş olur.

**Kullanıcıdan alınacaklar** (tarayıcıdan kopyalanacak):

| # | Ne | Nerede kullanılacak |
|---|---|---|
| 1 | Listing **tekil** fiyat/stok güncelleme — uç nokta + payload | `LISTING_UPDATE` |
| 2 | Listing **toplu** güncelleme + `trackingId` yoklaması | `LISTING_BULK_UPDATE`, `LISTING_BULK_STATUS` |
| 3 | Kategori ağacı + kategori bazlı **zorunlu öznitelikler** | `CATEGORIES`, `CATEGORY_ATTRIBUTES` |
| 4 | Sipariş/paket listeleme + **webhook imza doğrulama biçimi** | `ORDER_PACKAGES`, `verifyWebhookSignature` |
| 5 | Ürün açma (`PRODUCT_IMPORT`) + `trackingId` akışı | `PRODUCT_IMPORT*` |
| 6 | Gerçek **hız sınırı** başlıkları (varsa) | `rateLimitProfile()` |

**Doğrulama sırası** (`HepsiburadaEndpoints` sınıf başlığında da yazılı):
1. Her sabiti resmî dokümanla karşılaştır
2. `HepsiburadaAdapterTest`'teki beklenen metinleri güncelle
3. Gerçek satıcı hesabıyla sağlık kontrolü çalıştır
4. `ChannelTypeSeeder` → `is_active = true`

### 09.3 · Trendyol'dan devralınanlar

Hepsiburada Trendyol'un modeline **en yakın** kanaldır; bu yüzden V3'ün
**en düşük riskli** ikinci maddesi.

| Desen | Trendyol'da | Hepsiburada'da |
|---|---|---|
| Satıcı kimliği | `supplierId`, yol üzerinde | `merchantId`, **`User-Agent` içinde** |
| Taksonomi | kategori + zorunlu öznitelik | **aynı** |
| Onay süreci | `SupportsApprovalWorkflow` | **aynı** |
| Ön koşul kapısı | `PrerequisiteGate` (çekirdek) | **aynı, değişmez** |
| Asenkron aktarım | `batchRequestId` yoklaması | `trackingId` yoklaması |

> **`PrerequisiteGate` ÇEKİRDEKTEDİR ve `SupportsTaxonomy` uygulayan HER
> kanalda çalışır.** Hepsiburada için **tek satır kod yazılmaz** — kapı
> kiracının eşleştirme tablolarını okur ve Trendyol'a özgü hiçbir şey
> bilmez. Aynı şey `TrackApprovalStatus` ve onay durumu ekranı için de
> geçerli (`8ba3c08`).

### 09.4 · Trendyol'dan AYRILDIĞI kritik noktalar

Bunlar `356a662`'de tespit edildi ve kayıtlı:

**1 · `User-Agent` kimlik doğrulamanın parçasıdır**

`{merchantId} - {AppName}` eksikse kimlik bilgisi DOĞRU olsa bile kanal
**401** döner.

> Bu, `97a7eb7`'de yaşanan "istek sessizce kimliksiz gitti" hatasının başka
> bir biçimidir: anahtar doğru, listing "anahtarın yanlış" diyerek ölür.
> Başlık desteği `ChannelHttpClient`'a **GENEL** olarak eklendi — başlığı
> ADAPTER verir, istemci taşır ve `if ($channel === '...')` YAZILMAZ.
>
> **SATICI KİMLİĞİ YOKSA İSTEK HİÇ ATILMAZ.** Boş kimlikle
> `User-Agent: " - Entegrasyon"` giderdi; kanal 401 döner ve sebep hiçbir
> yerde görünmez.

**2 · Stok ve fiyat AYNI yükte gider — Trendyol'un TERSİ**

> Trendyol'da "stok yükü fiyat alanı TAŞIMAZ" **katı kuraldı** çünkü biri
> diğerini sessizce ezerdi. Hepsiburada'nın uç noktası ikisini birlikte
> bekliyor ve **eksik alanı SIFIR sayabiliyor**; kanal "stok 0 veya fiyat 0
> = satışa kapat" diye yorumluyor.
>
> Yani orada **birleştirmek** neyse burada **AYIRMAK** odur.
> `pushInventory`/`pushPrices` yazılırken **mevcut değer okunup yük
> TAMAMLANMALIDIR.**

Bu, V3'ün Hepsiburada fazındaki **en riskli maddedir** ve testle korunur
(§29 · P0-H1).

**3 · Webhook VAR — Trendyol'un aksine**

`X-HB-Signature` HMAC. Gelen hat kuralları aynen geçerli: ham gövde
üzerinden, ayrıştırmadan önce, `hash_equals` ile.
**Başlık adı büyük/küçük harf duyarsız okunur.**

**4 · En düşük hız sınırı seçilir (10/sn)**

> Kova **bağlantı** başınadır ve tek kova iki farklı uç nokta sınırını
> (listing ~30/sn, sipariş ~10/sn) ayrı ayrı temsil edemez. Yüksek sınır
> sipariş çağrılarını sürekli 429'a sokardı; düşük sınırın bedeli yalnızca
> yavaşlıktır.
>
> **Dinamik öğrenme YOK** (Trendyol'un aksine): kanal sınırı yanıt
> başlığında bildirmiyor ve öğrenilecek başlık yokken "öğrenme" kodu
> yazmak, hiç çalışmayan ve hiç sınanamayan bir yol bırakırdı.

**5 · Parti boyutu 1000'de tutuluyor**

İkincil kaynak 4000 diyor. Sınır doğrulanmadı ve aşımın bedeli ağır: kanal
isteği kısmen işlerse hangi satırın gittiği bilinmez.

### 09.5 · Yazılacaklar

| Slice | İş | Yeni dosya |
|---|---|---|
| 2.1 | Uç nokta doğrulaması + kanalı açma | *(kod yok — kullanıcıyla)* |
| 2.2 | Taksonomi | `SupportsTaxonomy` uygulaması |
| 2.3 | Katalog + onay | `HepsiburadaProductMapper`, `SupportsCatalog`, `SupportsApprovalWorkflow` |
| 2.4 | Stok + fiyat (**birleşik yük**) | `pushInventory`, `pushPrices`, `fetchInventory`, `fetchPrices` |
| 2.5 | Sipariş — webhook | `HepsiburadaOrderNormalizer` |
| 2.6 | İptal / iade / paket | normalizer genişletme |
| 2.7 | Kargo | `SupportsFulfillment` |

---

## 11 · Etsy Implementation

### 11.1 · Etsy'nin farkı — el yapımı/vintage pazarı

Etsy diğer beş kanaldan **veri modeli olarak** ayrılır ve bu ayrım
adapter'da soğurulur.

```
Shop
 └── Listing (listing_id)                    ← ilan; başlık, açıklama, fotoğraf
      └── Inventory
           └── Product (product_id)          ← varyant kombinasyonu; SKU BURADA
                └── Offering (offering_id)   ← fiyat + miktar + is_enabled
```

> **ÜÇ SEVİYE, İKİSİ BİZDE YOK.** Bizim modelimiz `Product → Variant →
> Listing`; Etsy'de `Listing → Product → Offering`. Adlar **çakışıyor ve
> anlamları TERS**: Etsy'nin "Listing"i bizim ürünümüz, Etsy'nin "Product"ı
> bizim varyantımız.
>
> **DÖNÜŞÜM MAPPER'DA YAPILIR, ÇEKİRDEK MODEL DEĞİŞMEZ** (kullanıcının açık
> talebi). Etsy'nin variation modelini Core'a zorlamak, altı kanalın
> beşinde anlamsız bir seviye açardı.

**Eşleme:**

| Etsy | Bizim | Nerede |
|---|---|---|
| `listing_id` | üst ürün | `listings.external_parent_id` |
| `product_id` | varyant karşılığı | `listings.external_id` |
| `offering_id` | fiyat/stok yazma hedefi | `channel_metadata->>'offering_id'` |
| `shop_id` | hesap | `channel_connections.external_account_id` |

> **`external_id` = `product_id`, `listing_id` DEĞİL.** Bizde listing satırı
> **varyant başınadır** (`UNIQUE(channel_connection_id, variant_id)`).
> `listing_id` yazılsaydı üç varyantlı bir ürünün üç listing satırı **aynı
> `external_id`'yi** taşır ve
> `UNIQUE(channel_connection_id, external_id)` kısıtı ikincisini
> **reddederdi**.

### 11.2 · Kimlik — OAuth 2 + PKCE, token 1 SAAT

| Konu | Değer |
|---|---|
| Akış | OAuth 2.0 Authorization Code + **PKCE** |
| Access token | **1 saat** |
| Refresh token | 90 gün |
| Scopes | `listings_r listings_w transactions_r shops_r email_r` |
| Başlık | `Authorization: Bearer {token}` + `x-api-key: {keystring}` |

> **`SupportsTokenRefresh` ZORUNLUDUR** (§03 · Delta 3). 1 saatlik token,
> saatlik koşan mutabakat turunu bile aşar: yenileme olmadan **her ikinci
> tur 401 alır** ve `AUTHENTICATION` KALICI sayılır — listing'ler
> "anahtarın yanlış" damgasıyla toplu ölür.
>
> **İKİ AYRI KİMLİK BAŞLIĞI VARDIR.** `x-api-key` uygulamanın kimliğidir ve
> **yenilenmez**; `Bearer` satıcının kimliğidir ve yenilenir. İkisi
> karıştırılırsa yenileme çalışır ama istek yine 401 alır.

**PKCE — projede ilk:**

`code_verifier` yetkilendirme isteğiyle token isteği arasında **saklanmak
zorundadır** (oturumda; kalıcı depoya YAZILMAZ — tek kullanımlık sırdır).

### 11.3 · Stok ve fiyat — TEK uç nokta, TÜM envanter

```
PUT /v3/application/listings/{listing_id}/inventory
```

> **EN TEHLİKELİ MADDE: BU ÇAĞRI TÜM ENVANTERİ EZER.**
> Etsy kısmi güncelleme desteklemez — gövde, o listing'in **bütün**
> `products` ve `offerings` dizisini taşır. Tek varyantın stoğunu
> güncellemek için **diğer varyantların mevcut değerleri de gönderilmelidir.**
>
> Gönderilmezse: diğer varyantlar **silinir**. Üç varyantlı bir üründe
> birinin stoğunu güncelleyen bir istek, ötekilerin ikisini **kanaldan
> kaldırır** — sessiz, geri alınamaz ve satıcı bunu ancak siparişler
> kesilince fark eder.

**Zorunlu akış — oku-birleştir-yaz:**

```
1. GET  listings/{listing_id}/inventory      ← mevcut TÜM envanter
2. Bizim değişikliğimizi ilgili offering'e uygula
3. PUT  listings/{listing_id}/inventory      ← TAM gövde
```

> **BU, "MUTLAK DEĞER GÖNDERİLİR" KURALININ İHLALİ DEĞİLDİR.** Gönderilen
> değer hâlâ mutlaktır; okunan şey **bizim yazmadığımız kardeş
> varyantlardır**. Fark şudur: Woo'da yük **bizim** gerçeğimizi taşır,
> Etsy'de yük **kanalın** gerçeğini de taşımak zorundadır.
>
> **YARIŞ PENCERESİ VARDIR ve kabul edilir:** okuma ile yazma arasında
> satıcı Etsy panelinden kardeş varyantı değiştirirse o değişiklik ezilir.
> Pencere saniyelerdir ve mutabakat turu farkı sonraki turda yakalar.
> Alternatif (varyant başına kilit) **kanal tarafında yoktur**.

**Gruplama sonucu:** `maxInventoryBatchSize()` **listing başına 1** —
`InventoryBatchBuilder` operasyonları yine birleştirir ama adapter her
`external_parent_id` için ayrı çağrı yapar.

> **BU BİR PERFORMANS SORUNU DEĞİL, KANALIN ŞEKLİDİR.** 5.000 varyantlı bir
> mağazada 5.000 değil, **ürün sayısı kadar** istek atılır (varyantlar
> gruplanır). Woo'nun 100'lük batch'iyle karşılaştırılamaz ama Etsy'nin
> hız sınırı da farklıdır (§21).

### 11.4 · Sipariş — receipt / transaction, YOKLAMA

Etsy webhook **sunmaz**. Sipariş yoklamayla gelir — Trendyol kalıbı.

```
GET /v3/application/shops/{shop_id}/receipts?min_created={ts}&limit=100
```

| Etsy | Bizim |
|---|---|
| `receipt_id` | `orders.external_id` |
| `transaction_id` | `order_lines.external_line_id` |
| `transactions[].sku` | `order_lines.sku` |
| `status` (`paid`, `completed`, `canceled`) | `orders.status` |

> **OLAY KİMLİĞİ `{receipt_id}:{status}`** — Trendyol'daki kuralın aynısı.
> Yalnızca `receipt_id`'ye bağlansaydı aynı siparişin sonraki İPTALİ
> birincil tekillik indeksine takılır ve `insertOrIgnore` tarafından
> **SESSİZCE YUTULURDU** — stok geri eklenmez, bakiye kalıcı eksik kalırdı.
>
> **PENCERE GERİYE BAKAR** (5 dk örtüşme) ve imleç turun **BAŞLAMA** anına
> yazılır. **BAŞARISIZ TURDA İMLEÇ İLERLEMEZ.**
>
> **YOKLAMADA `signature_valid = true`** ve bu eksiklik değildir: gövdeyi
> kanaldan BİZ istedik ve kimlikli bir çağrıyla aldık.

**İade — dürüst sınır:**

> Etsy API'si iade için ayrı uç nokta vermiyor. Satıcı iadeyi Etsy panelinden
> işler ve `receipt` durumu değişir; yoklama bunu `updated` olarak görür ve
> **stok hareketi ÜRETMEZ** (v2.2 · "bilinmeyen durum `updated` sayılır").
>
> Bu **doğru davranıştır**: `returned` sayılsaydı satılmış stok geri
> eklenir ve bakiye bozulurdu. Gerçek iade panelden elle girilir.
> **`SupportsFulfillment` uygulanır** ama iade tipi normalizer'dan ÜRETİLMEZ.

### 11.5 · Taksonomi — seller taxonomy + properties

```
GET /v3/application/seller-taxonomy/nodes
GET /v3/application/seller-taxonomy/nodes/{taxonomy_id}/properties
```

Trendyol/Hepsiburada kalıbı birebir geçerli: ağaç kanalın GERÇEĞİ
(`channel_categories`, kiracısız), eşleştirme satıcının KARARI
(`category_mappings`, kiracıya ait). **Sürüm içerikten türer ve sıralanır.**

> **ONAY SÜRECİ YOKTUR** — `SupportsApprovalWorkflow` UYGULANMAZ. Etsy'de
> ilan yayınlanır yayınlanmaz canlıdır. Uygulansaydı panelde hiç dolmayacak
> bir sekme açılırdı.

---

## 13 · eBay Implementation

### 13.1 · Inventory Item → Offer → Published Listing

eBay'in yayın modeli **üç adımlıdır** ve bu, V3'ün tek çekirdek arayüz
eklemesinin (§03 · Delta 1) sebebidir.

```
┌──────────────────┐   PUT /inventory_item/{sku}         idempotent
│  Inventory Item  │   ← SKU, başlık, açıklama, görsel, aspects
└────────┬─────────┘      kimlik = SKU'nun KENDİSİ (uzak id YOK)
         │
┌────────▼─────────┐   POST /offer                       offer_id döner
│      Offer       │   ← fiyat, miktar, marketplace, kategori,
└────────┬─────────┘      merchantLocationKey, politikalar
         │
┌────────▼─────────┐   POST /offer/{offer_id}/publish    listing_id döner
│ Published Listing│   ← eBay'de görünen ilan
└──────────────────┘
```

**Kimlik eşlemesi:**

| eBay | Bizim | Neden |
|---|---|---|
| SKU | *(zaten var)* | `variants.sku` — uzak kimlik DEĞİL |
| `offer_id` | `channel_metadata->>'offer_id'` | **stok/fiyat yazma hedefi** |
| `listing_id` | `listings.external_id` | satıcının gördüğü ilan |
| `merchantLocationKey` | `settings->>'merchant_location_key'` | bağlantı başına tek |

> **`external_id` = `listing_id`, `offer_id` DEĞİL.** `external_id` "kanalda
> görünen ürün"dür; panel onu link olarak gösterir ve mutabakat onunla
> sorgular. `offer_id` bir **ara kimliktir** ve satıcı onu hiçbir yerde
> görmez.
>
> **AMA STOK VE FİYAT `offer_id` İLE YAZILIR** — bu yüzden ikisi de kalıcı
> olarak saklanmak zorundadır. `offer_id` kaybedilirse listing'e bir daha
> stok gönderilemez ve **yeniden yaratmak `25002` duplicate hatası** verir.

### 13.2 · Ara başarısızlık — Delta 1'in asıl gerekçesi

```
upsertInventoryItem  ✅  (idempotent, PUT)
upsertOffer          ✅  offer_id = 8912345 → channel_metadata'ya YAZILIR
publishOffer         ❌  429
```

**Delta 1 olmasaydı:** `createListing()` istisna fırlatır, `external_id`
yazılmaz (v2.2 kuralı), sonraki tur **baştan başlar** → `POST /offer`
ikinci kez çağrılır → eBay `25002` döner → `VALIDATION` → **KALICI hata**.

**Delta 1 ile:** her adım kendi sonucunu yazar ve sonraki tur
`channel_metadata`'ya bakıp **kaldığı yerden** devam eder.

```
if (metadata.listing_id)  → güncelle (upsertOffer)
elseif (metadata.offer_id) → publishOffer          ← kaldığı yer
else                       → tam zincir
```

> **BU, IDEMPOTENCY'NİN KANAL TARAFINDAKİ KARŞILIĞIDIR.** Projede
> idempotency çıpası hep bizim tarafımızdaydı (`MovementKey`,
> `external_event_id`); eBay'de çıpa **kanalın verdiği ara kimliktir** ve
> saklanmazsa idempotency kaybolur.

### 13.3 · Kimlik — OAuth 2, token 2 SAAT

| Konu | Değer |
|---|---|
| Akış | Authorization Code (user token) |
| Access token | **2 saat** |
| Refresh token | **18 ay** |
| Scopes | `sell.inventory sell.account sell.fulfillment` |
| Sandbox | ayrı ana bilgisayar + ayrı kimlik |

> **`SupportsTokenRefresh` ZORUNLUDUR.** 2 saat, günlük soğuk mutabakat
> turunu aşar.
>
> **REFRESH TOKEN 18 AY SONRA ÖLÜR ve yenilenemez** — satıcı yeniden
> yetkilendirmek zorundadır. `expires_at` bunu takip eder ve panel
> **süre dolmadan ÖNCE** uyarır (§20). Uyarmasaydı bağlantı bir sabah
> sessizce ölür ve satıcı sebebini bulamazdı.

### 13.4 · Stok ve fiyat

```
POST /sell/inventory/v1/bulk_update_price_quantity     ← 25 offer/istek
```

- **Stok ve fiyat AYNI çağrıda** — Hepsiburada gibi, Trendyol'un tersi.
- Hedef `offerId`; SKU **değil**.
- `maxInventoryBatchSize() = 25` (eBay'in katı sınırı).

> **KISMİ BAŞARI MÜMKÜNDÜR.** Yanıt `responses[]` dizisi döner ve her
> kalem kendi `statusCode`'unu taşır. Tek bir 200 gövde kodu **hepsinin
> başarılı olduğu anlamına GELMEZ**.
>
> `SyncResultRecorder` operasyon bazlı yazar (v2.2 kuralı: "yükte olmayan
> operasyona dokunulmaz") — adapter, kalem başına sonucu **operasyon
> kimliğiyle eşleştirmek zorundadır**. Eşleştirilmezse başarısız kalemler
> "senkron" damgası yer ve stok kanalda yanlış kalır.

### 13.5 · Taksonomi — aspects

```
GET /commerce/taxonomy/v1/category_tree/{tree_id}
GET /commerce/taxonomy/v1/category_tree/{tree_id}/get_item_aspects_for_category
```

eBay'in "aspect"leri, Trendyol'un "zorunlu öznitelik"lerinin karşılığıdır.
`SupportsTaxonomy` **birebir** uygulanır; `PrerequisiteGate` değişmeden
çalışır.

> **KATEGORİ AĞACI MARKETPLACE BAŞINADIR** (`EBAY_US`, `EBAY_DE`, `EBAY_GB`).
> `taxonomyVersion()` marketplace kimliğini **içermek zorundadır**; içermezse
> ABD ağacıyla eşleştirilen bir kategori Almanya'ya gönderilir ve
> `VALIDATION` alır.
>
> V3.0 **bağlantı başına tek marketplace** varsayar
> (`settings.marketplace_id`). Çok pazarlı satıcı ikinci bir bağlantı açar —
> `UNIQUE(tenant, type, account)` buna izin verir çünkü hesap kimliği
> farklıdır.

### 13.6 · Sipariş — YOKLAMA

```
GET /sell/fulfillment/v1/order?filter=lastmodifieddate:[{since}..]
```

| eBay | Bizim |
|---|---|
| `orderId` | `orders.external_id` |
| `lineItems[].lineItemId` | `order_lines.external_line_id` |
| `orderFulfillmentStatus` | `orders.status` |

> **eBay Notification API SİPARİŞ İÇİN DEĞİLDİR.** Hesap kapanma ve politika
> ihlali bildirir. Sipariş **yalnızca yoklamayla** gelir; `supports_webhooks
> = false` yazılır ve `PollChannelOrders` kapısı bunu okur.
>
> **BU ALAN EAGER-LOAD'DA AÇIKÇA SEÇİLMELİ** — seçilmezse kapı null okur ve
> yoklama hiç çalışmaz (gerçek çalıştırmada bulunmuş tuzak).

**İade:** eBay iade için **ayrı API** sunar (`/post-order/v2/return`).
V3.0'da **kapsam içi** — Etsy'nin aksine burada gerçek bir uç nokta var.


---

## 16 · Database Delta — V2.2 → V3.0

> **Bu bölüm tüm şemayı yeniden yazmaz.** Yalnızca dört yeni kanalın
> gerektirdiği değişiklikler listelenir. v2.2 şeması ihtiyacı karşılıyorsa
> **NO SCHEMA CHANGE REQUIRED** yazılır ve mevcut yapının nasıl kullanılacağı
> anlatılır.
>
> **BU BÖLÜMDEKİ MADDELER CORE ARCHITECTURE DELTA DEĞİLDİR.** Core
> Architecture Delta sayısı **üçtür** ve üçü de §03'te tanımlıdır
> (`SupportsOfferLifecycle` · `listings.channel_metadata` ·
> `channel_credentials.expires_at`'in kullanılması). Aşağıdaki maddeler
> **veritabanı / seeder kapsamındadır** ve numaralandırmaları §03'ün
> Delta numaralarıyla **ilgisizdir**; karışmasın diye "DB Delta" olarak
> adlandırılırlar.

### DB Delta 1 — `listings.channel_metadata`

| | |
|---|---|
| **Tablo** | `listings` *(mevcut)* |
| **Değişiklik** | `+ channel_metadata jsonb NULL` |
| **Kullanan** | Shopify, Etsy, eBay |
| **Neden** | Üçünde de birden çok **kalıcı** uzak kimlik var; `external_id` + `external_parent_id` yetmiyor (§07) |
| **Migration** | `ALTER TABLE listings ADD COLUMN channel_metadata jsonb;` |
| **Index** | **YOK** — çekirdek bu alanı sorgulamaz, yalnızca adapter okur |
| **Rollback** | Kolon düşer; Shopify/Etsy/eBay listing'leri stok yazamaz hâle gelir (Woo/Trendyol/Hepsiburada **etkilenmez**) |

**Kanal başına içerik:**

```jsonc
// Shopify
{"inventory_item_gid": "gid://shopify/InventoryItem/789"}
// Etsy
{"offering_id": 4512345678}
// eBay
{"offer_id": "8912345", "marketplace_id": "EBAY_DE"}
```

> **SIR TAŞIMAZ** — kolon şifresiz ve panele gidebilir. Token ve secret
> `channel_credentials`'ta yaşar.

### DB Delta 2 — `channel_credentials` yenileme alanları

| | |
|---|---|
| **Tablo** | `channel_credentials` *(mevcut)* |
| **Değişiklik** | **NO SCHEMA CHANGE REQUIRED** |
| **Kullanan** | Shopify, Etsy, eBay |

v2.2 §4 zaten tanımlıyor: `expires_at`, `refreshed_at`, `revoked_at`,
`scope`, `key_version` ve `INDEX(expires_at) WHERE revoked_at IS NULL`.

**Eksik olan şema değil, onu OKUYAN koddur** (§03 · Delta 3):
`TokenRefresher` + `credentials:refresh` komutu.

> **İNDEKS TAM BU SORGU İÇİN ZATEN VAR** ve bugüne kadar hiç kullanılmadı —
> v2.2 onu ileri görüşle tanımlamış. V3 onu kullanan ilk fazdır.

### DB Delta 3 — `channel_types` yeni satırlar

| | |
|---|---|
| **Tablo** | `channel_types` *(mevcut)* |
| **Değişiklik** | 3 yeni **satır** (şema DEĞİŞMEZ) |
| **Migration** | Seeder — `ChannelTypeSeeder` |

```
shopify      · kind=storefront   · supports_webhooks=true  · is_active=FALSE
etsy         · kind=marketplace  · supports_webhooks=false · is_active=FALSE
ebay         · kind=marketplace  · supports_webhooks=false · is_active=FALSE
```

> **HEPSİ `is_active = false` DOĞAR** — §05'in 12 adımlı listesindeki 1. ve
> 12. adım ayrımı.

### DB Delta 4 — Seeder `is_active`'i EZMEZ *(DB/Seeder değişikliği · hata düzeltmesi)*

> **BU DEĞİŞİKLİK CORE ARCHITECTURE DELTA DEĞİLDİR.** `ChannelTypeSeeder`
> içindeki bir hata düzeltmesidir; çekirdek mimariye dokunmaz ve §03'ün
> üç Core Delta'sının sayısını **değiştirmez**.

| | |
|---|---|
| **Dosya** | `ChannelTypeSeeder` |
| **Sorun** | `db:seed` çalıştırıldığında elle açılmış kanallar `false`'a döner |
| **Kanıt** | `356a662` — **Trendyol kapandı**, elle SQL ile geri açıldı |
| **Çözüm** | `updateOrCreate`'in güncelleme kümesinden `is_active` **çıkarılır**; yalnızca yaratılışta kullanılır |
| **Aciliyet** | V3'te **kritik** — altı kanalda bu tuzak altı kez ısırır |

### DB Delta 5 — `channel_connections.settings` yeni anahtarlar

| | |
|---|---|
| **Değişiklik** | **NO SCHEMA CHANGE REQUIRED** — `settings` zaten JSONB |

| Kanal | Anahtar | İçerik |
|---|---|---|
| Shopify | `location_gid` | Stok konumu |
| eBay | `merchant_location_key` | Offer için zorunlu |
| eBay | `marketplace_id` | `EBAY_US` / `EBAY_DE` … |
| eBay | `fulfillment_policy_id`, `payment_policy_id`, `return_policy_id` | Offer için zorunlu üçlü |
| Etsy | `shop_id` | Yol üzerinde taşınır |

> **SIRLAR `settings` İÇİNE YAZILMAZ** — orası şifrelenmemiş jsonb ve panele
> olduğu gibi gider (v2.2 kuralı). Bunların hepsi **yapılandırmadır**, sır
> değil.
>
> **eBay POLİTİKA ÜÇLÜSÜ BAĞLAMA AKIŞINDA SEÇTİRİLİR.** Eksikse offer
> `VALIDATION` alır ve o **KALICIDIR** — listing "düzeltilemez" damgasıyla
> ölür. Bu yüzden sağlık kontrolü üçünün varlığını **şart koşar**;
> yoksa bağlantı `active` OLMAZ.

### DB Delta 6 — `orders` / `order_lines`

| | |
|---|---|
| **Değişiklik** | **NO SCHEMA CHANGE REQUIRED** |

Dört kanalın dördü de mevcut alanlara sığıyor:

| İhtiyaç | Mevcut alan |
|---|---|
| Sipariş kimliği | `orders.external_id` |
| Satır kimliği | `order_lines.external_line_id` |
| Sipariş numarası | `orders.external_number` |
| Paket/kargo | `fulfillments` tablosu *(v2.2'de var)* |

> **eBay/Etsy sipariş kimlikleri metindir ve SAYIYA ÇEVRİLMEZ.** Trendyol'da
> yaşanan tuzağın aynısı: `(int)` dönüşümü harf içeren her kimliği `0`'a
> düşürür ve **kanal 200 döndüğü için senkron BAŞARILI görünür**.

### DB Delta 7 — `listing_sync_states.domain`

| | |
|---|---|
| **Değişiklik** | **NO SCHEMA CHANGE REQUIRED** |

`CONTENT | PRICE | INVENTORY | MEDIA` dört domain yeni kanallar için de
yeterli. eBay'in üç adımlı yayını **ayrı domain açmaz** — o bir
`CONTENT` operasyonunun iç adımıdır.

### Özet

| # | Değişiklik | Tip |
|---|---|---|
| DB Delta 1 | `listings.channel_metadata` | **ALTER TABLE** |
| DB Delta 2 | `channel_credentials` | değişiklik YOK |
| DB Delta 3 | `channel_types` 3 satır | seeder (veri) |
| DB Delta 4 | Seeder `is_active` düzeltmesi | kod |
| DB Delta 5 | `settings` anahtarları | değişiklik YOK |
| DB Delta 6 | `orders` / `order_lines` | değişiklik YOK |
| DB Delta 7 | `listing_sync_states` | değişiklik YOK |

**Toplam: bir fiziksel DB şema değişikliği** — tek bir kolon
(`listings.channel_metadata`). Yedi maddenin kalan altısı ya veri/kod
kapsamındadır ya da hiçbir değişiklik gerektirmez. v2.2'nin şeması dört
yeni kanalı neredeyse değişmeden karşılıyor — bu, §4'ün kanal-agnostik
tasarlanmış olmasının doğrudan sonucudur.

> **Bu yedi madde DB/seeder kapsamındadır ve Core Architecture Delta
> DEĞİLDİR** — Core Delta sayısı §03'teki **üçtür**.

---

## 07 · Channel Identifier Strategy

Her uzak kimlik için **yeni kolon açılmaz.** Karar ağacı:

```
Kimlik "kanalda görünen ürün"ü mü işaret ediyor?
  ├─ EVET → listings.external_id
  └─ HAYIR
      ├─ Üst ürün mü?  → listings.external_parent_id
      ├─ Hesap mı?     → channel_connections.external_account_id
      ├─ Bağlantı başına TEK mi? → settings (JSONB)
      └─ Listing başına, yazma için zorunlu mu? → channel_metadata (JSONB)
```

### Kanal kanal analiz

| Kanal | Kimlik | Nereye | Neden |
|---|---|---|---|
| **Shopify** | `variant_gid` | `external_id` | Kanalda görünen satılabilir birim |
| | `product_gid` | `external_parent_id` | Üst ürün — **alan v2.2'de zaten var** |
| | `inventory_item_gid` | `channel_metadata` | Stok mutation'ı **bunu ister**; her itmede sorgulamak kritik yolu iki katına çıkarır |
| | `location_gid` | `settings` | Mağaza başına tek |
| **eBay** | `listing_id` | `external_id` | Satıcının gördüğü ilan |
| | `offer_id` | `channel_metadata` | **Stok/fiyat yazma hedefi**; kaybedilirse `25002` duplicate |
| | SKU | *(yok)* | `variants.sku` zaten var — uzak kimlik DEĞİL |
| | `merchantLocationKey` | `settings` | Bağlantı başına tek |
| **Etsy** | `product_id` | `external_id` | Varyant karşılığı |
| | `listing_id` | `external_parent_id` | Üst ilan |
| | `offering_id` | `channel_metadata` | Fiyat/stok yazma hedefi |
| | `shop_id` | `external_account_id` | Hesap kimliği |
| **Hepsiburada** | merchant SKU | `external_id` | Listing kimliği |
| | `merchantId` | `external_account_id` | Hesap |
| | kategori kimliği | `channel_categories` | **Zaten var** |
| | paket kimliği | `fulfillments.external_id` | **Zaten var** |

### Sonuç

| Alan | Yeni kolon? |
|---|---|
| `external_id` | ❌ mevcut |
| `external_parent_id` | ❌ mevcut — **üç kanal kullanıyor** |
| `external_account_id` | ❌ mevcut |
| `settings` | ❌ mevcut JSONB |
| `channel_metadata` | ✅ **tek yeni kolon** |

> **`external_parent_id` V2.2'DE YAZILDI VE BUGÜNE KADAR HİÇ KULLANILMADI.**
> Woo'da varyant kimliği tek başına yeterli, Trendyol'da barkod düz. V3'te
> üç kanal onu kullanıyor — ileri görüşün karşılığını verdiği yer.

---

## 17 · Cross-Channel Inventory Fan-out

### Senaryo — Etsy'de 2 ürün satıldı

SKU-123 altı kanalda listeli. **v2.2 fan-out kodu DEĞİŞMEZ.**

```
[1] Etsy yoklama turu (orders:poll, 5 dk)
     EtsyAdapter::fetchOrders(since, cursor)
     → OrderPage(receipts[])

[2] IngestInboxMessage                          ← TEK gelen hat
     source = 'polling'
     external_event_id = '{receipt_id}:paid'    ← numara+durum
     signature_valid = true                     ← gövdeyi BİZ istedik
     → inbox_messages (pending)

[3] ProcessInboxMessage                          (inbox:process)
     UPDATE ... WHERE status='pending' AND tenant_id=?   ← koşullu geçiş
     → EtsyOrderNormalizer::parseOrderEvent()
     → NormalizedOrderEvent(type: 'created')

[4] OrderEventRouter                             ← tipe göre AYRI yol
     'created' → IngestChannelOrder

[5] IngestChannelOrder                           (orders:high, 60 sn)
     LockInventoryRows([variant_id])             ← ORDER BY variant_id FOR UPDATE
     ApplyMovement(SALE, -2, MovementKey::saleOf(...))
     → inventory_movements  on_hand_delta = -2
     → inventory_levels     projeksiyon         ← clamp YOK
     → outbox_events        InventoryLevelChanged
                            payload.origin_connection_id = {etsy_connection}

[6] OutboxRelay (sürekli süreç)
     available_at <= clock_timestamp()           ← now() DEĞİL
     → ConsumeOutboxEvent

[7] InventoryLevelChangedConsumer                ← FAN-OUT BURADA
     listing × domain × version
     6 canlı listing − 1 kaynak (Etsy) = 5 operasyon

     ┌─ Woo         → sync_operations  inv:{L1}:{v}
     ├─ Trendyol    → sync_operations  inv:{L2}:{v}
     ├─ Shopify     → sync_operations  inv:{L3}:{v}
     ├─ Hepsiburada → sync_operations  inv:{L4}:{v}
     └─ eBay        → sync_operations  inv:{L5}:{v}
     ✗ Etsy         → ATLANIR (origin_connection_id)

[8] PushInventory × 5                            (inventory:high, 45 sn)
     InventoryBatchBuilder                       ← YALNIZCA gruplama
     OutboundQuantity::forChannel()              ← max(available, 0)
     → adapter->pushInventory(batch)
     → SyncResultRecorder                        ← durumu ÇEKİRDEK yazar
```

### Kaynak kanal atlaması — bir ENİYİLEME, otorite devri DEĞİL

> Etsy `origin_connection_id` olduğu için **anlık geri push yapılmaz** —
> kanal bu değişikliği zaten biliyor.
>
> **AMA MUTABAKAT ETSY'Yİ DE KONTROL EDER** (v2.2 §10: "kaynak kanal
> DAHİLDİR"). Kanal kendi güncellemesini uygulamamış olabilir; atlama
> yalnızca **anlık** yankıyı önler, doğrulamayı değil.

### Diğer kaynak kanallar — aynı akış

| Kaynak | Geliş | Olay kimliği | Atlanan | Fan-out |
|---|---|---|---|---|
| WooCommerce | webhook | `X-WC-Webhook-Delivery-ID` | Woo | 5 |
| Trendyol | yoklama | `{orderNumber}:{status}` | Trendyol | 5 |
| Shopify | webhook | `X-Shopify-Event-Id` | Shopify | 5 |
| Hepsiburada | webhook | `X-HB-Event-Id` | Hepsiburada | 5 |
| Etsy | yoklama | `{receipt_id}:{status}` | Etsy | 5 |
| eBay | yoklama | `{orderId}:{status}` | eBay | 5 |

> **ALTI SATIRIN ALTISI DA AYNI KODDAN GEÇER.** Fan-out tüketicisi kanal
> saymaz; `lifecycle_status = 'live'` olan listing'leri sayar.
>
> **BİR KANALIN HATASI DİĞER BEŞİNİ ETKİLEMEZ:** her operasyon kendi satırı,
> kendi durumu ve kendi hatasıyla yaşar. eBay token'ı dolmuşsa yalnızca eBay
> operasyonu `error_permanent` olur.

### Fazla satış — altı kanalda

```
available = -3  (fazla satış)
OutboundQuantity::forChannel() → max(-3, 0) = 0
→ altı kanala da 0 gider
→ kanonik bakiye NEGATİF kalır         ← clamp YOK
→ panelde eksik miktar GÖSTERİLİR      ← §17 · P0
→ mutabakat kanaldaki 0'ı DOĞRU sayar  ← karşılaştırma giden değerle
```


---

## 19 · Webhook / Polling Matrix

> **HEPSİ AYNI `inbox_messages` HATTINA GİRER.** İkinci bir olay işleme
> sistemi AÇILMAZ — v2.2 kuralı: tek hat açılsaydı tekilleştirme iki kez
> yazılır, biri unutulurdu ve `inbox:recover` iki yeri bilmek zorunda
> kalırdı.

| Olay | Woo | Trendyol | Shopify | Hepsiburada | Etsy | eBay |
|---|---|---|---|---|---|---|
| `order.created` | 🔔 `order.created` | 🔄 | 🔔 `orders/create` | 🔔 | 🔄 | 🔄 |
| `order.updated` | 🔔 `order.updated` | 🔄 | 🔔 `orders/updated` | 🔔 | 🔄 | 🔄 |
| `order.cancelled` | 🔔 *(updated içinde)* | 🔄 | 🔔 `orders/cancelled` | 🔔 | 🔄 | 🔄 |
| `order.returned` | 🔔 | 🔄 | 🔔 `refunds/create` | 🔔 | ⚠️ *(yok)* | 🔄 |
| `fulfillment.updated` | 🔔 | ❌ | 🔔 `fulfillments/*` | 🔔 | 🔄 | 🔄 |
| `product/listing changed` | ❌ | ❌ | 🔔 `products/update` | ❌ | ❌ | ❌ |
| `app.uninstalled` | ❌ | ❌ | 🔔 `app/uninstalled` | ❌ | ❌ | ❌ |

🔔 webhook · 🔄 yoklama · ⚠️ kanal sınırı · ❌ desteklenmiyor

### Olay kimliği kaynağı — tekilleştirmenin çıpası

| Kanal | Birincil kimlik | Kaynak |
|---|---|---|
| Woo | `X-WC-Webhook-Delivery-ID` | başlık |
| Shopify | `X-Shopify-Event-Id` | başlık |
| Hepsiburada | `X-HB-Event-Id` | başlık |
| Trendyol | `{orderNumber}:{status}` | **türetilmiş** |
| Etsy | `{receipt_id}:{status}` | **türetilmiş** |
| eBay | `{orderId}:{status}` | **türetilmiş** |

> **TÜRETİLMİŞ KİMLİKTE DURUM ZORUNLUDUR.** Yalnızca numaraya bağlansaydı
> aynı siparişin sonraki İPTALİ birincil tekillik indeksine takılır ve
> `insertOrIgnore` tarafından **SESSİZCE YUTULURDU** — stok geri eklenmez,
> bakiye kalıcı eksik kalırdı. v2.2 · Karar 24'ün açıkça uyardığı hata
> biçimi budur.
>
> **SON ÇARE `payload_hash + dedupe_window`** — hash yolu saat sınırında
> bölünür, bu yüzden yeni kanal eklerken **olay kimliği aramak İLK İŞTİR.**

### `supports_webhooks` bayrağı

```
woocommerce  true    trendyol  false
shopify      true    etsy      false
hepsiburada  true    ebay      false
```

> **YOKLAMA KAPISI BU ALANI OKUR** ve **eager-load'da AÇIKÇA seçilmelidir**;
> seçilmezse kapı null okur ve **hiç çalışmaz** (gerçek çalıştırmada
> bulundu, `adapter_class` ile aynı tuzak).

### Yoklama sıklığı

| Kanal | Sıklık | Pencere | Gerekçe |
|---|---|---|---|
| Trendyol | 5 dk | 30 dk geriye | mevcut |
| Etsy | 5 dk | 30 dk geriye | sipariş hacmi düşük ama gecikme satıcıyı etkiler |
| eBay | 5 dk | 30 dk geriye | aynı |

> **PENCERE GERİYE BAKAR ve imleç turun BAŞLAMA anına yazılır.** Bitiş anı
> yazılsaydı istek sürerken oluşan sipariş iki pencerenin arasına düşer ve
> **HİÇ görülmezdi.** Örtüşmenin bedeli yoktur: tekilleştirme kopyayı eler.
>
> **BAŞARISIZ TURDA İMLEÇ İLERLEMEZ.**

---

## 20 · Authentication & Credential Lifecycle

| Kanal | Tip | Access TTL | Refresh | `SupportsTokenRefresh` |
|---|---|---|---|---|
| WooCommerce | Basic (ck/cs) | ∞ | — | ❌ |
| Trendyol | Basic (key/secret) | ∞ | — | ❌ |
| Hepsiburada | Basic + `User-Agent` | ∞ | — | ❌ |
| Shopify | `X-Shopify-Access-Token` | ∞ *(iptal edilebilir)* | — | ❌ ⚠️ |
| **Etsy** | OAuth2 + PKCE | **1 saat** | 90 gün | ✅ |
| **eBay** | OAuth2 | **2 saat** | **18 ay** | ✅ |

### Yenileme taraması

```
credentials:refresh   → her 15 dakikada
```

> **SIKLIK EN KISA TTL'İN DÖRTTE BİRİDİR** (Etsy 1 saat → 15 dk). Yarısı
> seçilseydi tek bir başarısız tur token'ın ölmesine yeterdi; dörtte bir,
> **üç deneme hakkı** verir.

**Akış:**

```
1. SELECT ... WHERE revoked_at IS NULL
     AND expires_at < now() + lead_seconds
     FOR UPDATE SKIP LOCKED          ← paralel tur aynı satırı almaz
2. adapter->refreshCredentials()      ← adapter YAN ETKİSİZ, vault'a yazmaz
3. CredentialVault::store()           ← ÇEKİRDEK yazar
4. refreshed_at = now(), expires_at = yeni
```

> **YENİLEME İSTEK ANINDA YAPILMAZ.** Paralel iki iş aynı anda yenilerse
> ikisi de yeni token alır ve **kanal ilkini iptal eder** (Etsy ve eBay
> refresh token'ı tek kullanımlıktır). `FOR UPDATE SKIP LOCKED` bunu
> engeller.
>
> **BAŞARISIZ YENİLEME BAĞLANTIYI ÖLDÜRMEZ, İŞARETLER:** `last_error`
> yazılır ve tarama sonraki turda yeniden dener. `revoked_at` yalnızca kanal
> "bu token geçersiz" dediğinde yazılır.

### Yeniden yetkilendirme uyarısı

| Kanal | Sinyal | Ne zaman |
|---|---|---|
| eBay | refresh token 18 ay | **30 gün kala** panel + e-posta |
| Etsy | refresh token 90 gün | **14 gün kala** |
| Shopify | `app/uninstalled` | anında |

> **UYARMASAYDIK BAĞLANTI BİR SABAH SESSİZCE ÖLÜRDÜ** ve satıcı sebebini
> bulamazdı. Uyarı `alerts:dispatch` turuna eklenir — **yeni bir bildirim
> sistemi AÇILMAZ** ve `alert_deliveries` çıpası aynı uyarıyı günde iki kez
> göndermeyi engeller.

### Kimlik bilgisi izolasyonu — değişmez

> `AdapterRegistry::for()` **her çağrıda yeni örnek** üretir; container'da
> `bind`, **asla `singleton`**. Gerekçe güvenlik: adapter bağlantı taşır,
> paylaşılan örnek kiracı A'nın kimlik bilgisini kiracı B'nin işinde
> kullanır. **Altı kanalda da geçerli.**

---

## 21 · Rate Limits / Batching / Retry

| Kanal | Model | Sınır | Batch | Kaynak |
|---|---|---|---|---|
| WooCommerce | istek/dk | sunucuya bağlı | 100 | sabit profil |
| Trendyol | istek/sn | satıcı seviyesi | 1000 | **yanıt başlığı** |
| Hepsiburada | istek/sn | **10** *(en düşük)* | 1000 | sabit |
| **Shopify** | **maliyet puanı** | 1000 puan · 50/sn | 250 | **yanıt gövdesi** |
| **Etsy** | istek/sn + gün | 10/sn · 10.000/gün | **1** *(listing başına)* | başlık |
| **eBay** | istek/gün | ~5.000/gün/uç nokta | **25** | başlık |

### Shopify — maliyet tabanlı, projede ilk

`extensions.cost.throttleStatus` gövdededir. `ChannelRateLimiter`
**değişmez**: bir "jeton" = bir puan.

> **SINIR YANITTAN ÖĞRENİLİR VE BAĞLANTIYA YAZILIR** (Trendyol kararının
> aynısı). Shopify Plus'ta kova 2.000 puandır.
>
> **SAYI OLMAYAN DEĞER YOK SAYILIR** — `ctype_digit` süzgeci.

### Etsy — günlük kota kritik

10.000 istek/gün **hesap başınadır**. Envanter yazma listing başına ayrı
çağrı gerektirdiği için (§11.3) bu sınır **gerçek bir tavandır**:

```
1.000 ürünlü mağaza · günde 3 stok değişimi = 3.000 istek
+ yoklama (5 dk × 288 tur)                  =   288
+ mutabakat                                 =   ~600
                                            = ~3.900  ✅ sığar

5.000 ürünlü mağaza · günde 3 değişim       = 15.000  ❌ AŞAR
```

> **BU BİR ÖLÇEK SINIRIDIR VE V3'TE AÇIKÇA KAYITLIDIR.** 5.000+ ürünlü Etsy
> mağazası için stok itmeleri **gruplanmalı** (aynı listing'in birden çok
> varyantı tek çağrıda) — `InventoryBatchBuilder` bunu zaten yapar; adapter
> `external_parent_id`'ye göre gruplar.
>
> Yetmezse tetikleyici: **günlük kotanın %80'i aşılınca** panelde uyarı ve
> stok itme sıklığının düşürülmesi (P2).

### eBay — kısmi başarı

```json
{"responses":[
  {"sku":"A","statusCode":200},
  {"sku":"B","statusCode":400,"errors":[{"errorId":25001}]}
]}
```

> **TEK 200 GÖVDE KODU HEPSİNİN BAŞARILI OLDUĞU ANLAMINA GELMEZ.** Adapter
> kalem başına sonucu **operasyon kimliğiyle eşleştirmek zorundadır**;
> eşleştirilmezse başarısız kalemler "senkron" damgası yer.

### Retry — değişmez

`RetryPolicy` **değişmez**. `VALIDATION` ve `AUTHENTICATION` kalıcı,
diğerleri geçici. Sınıflandırmayı **adapter** yapar.

| Kanal | Kalıcı sayılan | Not |
|---|---|---|
| Shopify | GraphQL `userErrors` · `INVALID` | 200 içinde gelir |
| Etsy | 400 + `invalid_*` | 401 → yenileme dener, sonra kalıcı |
| eBay | `25xxx` iş kuralı hataları | `25002` duplicate offer |

> **DEVRE KESİCİ `AUTHENTICATION`'DA TEK HATADA VE SÜRESİZ AÇILIR** (TTL
> yok). Token geçersizken beklemek düzeltmez. **AMA token yenileme
> devreye girmeden önce denenmelidir** — yenileme başarılıysa devre
> `reset()` ile kapanır.
>
> **BU, V3'ÜN CIRCUIT BREAKER'A EKLEDİĞİ TEK DAVRANIŞTIR** ve genel
> yazılır: `SupportsTokenRefresh` uygulayan kanalda 401 → önce yenile.

---

## 22 · Reconciliation V3

> **MUTABAKAT MOTORU DEĞİŞMEZ.** Beş adım (DETECT/RECORD/CLASSIFY/REPAIR/
> VERIFY), üç katman (sıcak/ılık/soğuk), iki domain (INVENTORY/PRICE), üç
> tur emniyeti ve `desired/synced/remote` modeli **olduğu gibi kalır.**
>
> Yeni kanal için yazılan **tek şey** `fetchInventory` / `fetchPrices`
> gövdeleridir. Bunlar zaten `SupportsInventory` / `SupportsPricing`
> sözleşmesinde var.

### Remote okuma — kanal başına

| Kanal | Stok okuma | Fiyat okuma |
|---|---|---|
| Woo | `GET products?include=` | `regular_price` aynı yanıt |
| Trendyol | `GET products?barcode=` | aynı yanıt |
| Hepsiburada | `GET listings?merchantSku=` | aynı yanıt |
| **Shopify** | GraphQL `inventoryLevels(locationIds:)` | `variants.price` |
| **Etsy** | `GET listings/{id}/inventory` | aynı yanıt |
| **eBay** | `GET /inventory_item/{sku}` veya `bulk_get` | `GET /offer?sku=` |

> **TOPLU OKUMA ZORUNLU** — 50 listing tek istekte. Listing başına ayrı
> istek ölçek hesabını **yüz katına** çıkarırdı.
>
> **ETSY'DE TOPLU OKUMA YOKTUR** ve bu kanalın sınırıdır: envanter
> `listing_id` başına okunur. Mutabakat bütçesi (sıcak 50) Etsy'de **50
> ürüne kadar** ayrı istek demektir. Günlük kota hesabı bunu içerir (§21).

### Fiyat çakışması — altı kanalda

`fd8cbe1` ile yazılan §9 politikası **değişmeden** yeni kanallarda çalışır:

```
INVENTORY → fark bulununca sessizce üzerine yaz
PRICE     → ÜZERİNE YAZMA, PRICE_CONFLICT, kullanıcı seçer
```

> **BU KURAL ALTI KANALDA DA GEÇERLİDİR.** Etsy ve eBay'de kampanya yapmak
> Trendyol'dan bile yaygındır; sessizce ezmek **en sık şikayet**.
>
> `reconcile:prices` komutu `SupportsPricing` uygulayan **her** kanalı
> gezer; yeni kanal için **tek satır kod yazılmaz.**

### `REMOTE_MISSING` — eBay'in özel durumu

> eBay'de listing sona erebilir (`ENDED`) ama `offer_id` yaşamaya devam
> eder. `fetchInventory` boş dönerse kalem `REMOTE_MISSING` olur ve
> **otomatik onarım AÇILMAZ** (v2.2 kuralı: yeniden listeleme kullanıcı
> onayı ister).
>
> **Doğru davranıştır:** sessizce yeniden yayınlamak, satıcının bilerek
> sonlandırdığı bir ilanı geri açardı.

---

## 23 · Queue & Capacity Impact

> **YENİ KUYRUK SİSTEMİ İCAT EDİLMEZ.** v2.2'nin 13 worker / 4 havuz yapısı
> kullanılır; kapasite hesabı bu yapı üzerinden yapılır.

### Mevcut yapı — v2.2 §12

| Havuz | Kuyruk | Worker |
|---|---|---|
| critical | `orders:high`, `inventory:high`, `inbox:process` | 6–8 |
| standard | `price:high`, `listing:default` | 2–3 |
| reconciliation | `reconciliation` | 1 |
| background | `listing:bulk`, `maintenance` | 1 |

### Senaryo A — 100 kiracı × 3 kanal × 5.000 listing

```
Listing sayısı        : 100 × 3 × 5.000 = 1.500.000
Bağlantı sayısı       : 300

STOK FAN-OUT
  Sipariş/gün         : 100 × 200 = 20.000
  Fan-out (3 kanal−1) : 20.000 × 2 = 40.000 operasyon/gün
  Gruplama (50'lik)   : ~800 API çağrısı/gün
  Kuyruk yükü         : 40.000 / 86.400 sn ≈ 0,5 iş/sn      ✅

MUTABAKAT
  Sıcak (5 dk × 288)  : 300 bağlantı × 288 = 86.400 tur/gün
  Toplu okuma         : tur başına 1 istek = 86.400 istek/gün
  Tek worker          : 86.400 / 86.400 = 1 tur/sn          ⚠️ SINIRDA

YOKLAMA
  3 yoklamalı kanal   : 300 × (288/gün) ≈ 86.400 tur/gün    ✅
```

**Sonuç: v2.2 yapısı yeterli.** Tek uyarı mutabakat worker'ında.

### Senaryo B — 500 kiracı × 4 kanal × 10.000 listing

```
Listing sayısı        : 500 × 4 × 10.000 = 20.000.000
Bağlantı sayısı       : 2.000

STOK FAN-OUT
  Sipariş/gün         : 500 × 300 = 150.000
  Fan-out (4−1)       : 450.000 operasyon/gün
  Kuyruk yükü         : 450.000 / 86.400 ≈ 5,2 iş/sn
  8 worker × ~2 iş/sn = 16 iş/sn kapasite                   ✅

MUTABAKAT
  Sıcak turu          : 2.000 × 288 = 576.000 tur/gün
  Tek worker          : 6,7 tur/sn gerekir                  ❌ AŞILDI

VERİTABANI
  inventory_movements : 150.000 × 2 satır × 365 ≈ 110M/yıl  ❌ P2 EŞİĞİ
  api_calls           : ~200M/yıl (7 gün saklama sonrası ~4M) ✅
```

### Tetiklenen P2 maddeleri — v2.2 §17'den

| Madde | Tetikleyici | Senaryo B'de |
|---|---|---|
| `inventory_movements` partition | > 20M satır veya > 10 GB | ✅ **tetiklenir** |
| Kiracı bazlı kuyruk bölümlemesi | toplu kuyruk p95 > 30 dk | ⚠️ olası |
| Ayrı veritabanı sunucusu | DB > 80 GB | ✅ **tetiklenir** |
| PgBouncer | bağlantı > 150 | ✅ **tetiklenir** |
| Okuma replikası | raporlama yazmayı yavaşlatınca | ⚠️ olası |

### V3'ün tek kuyruk önerisi — mutabakat worker'ı

> **`reconciliation` havuzu 1'den 2'ye çıkarılır** — senaryo A'da sınırda,
> B'de yetmiyor.
>
> **AMA İZOLASYON KORUNUR:** havuz ayrı kalır ve `listing:bulk` ile
> paylaşmaz. v2.2'nin açık kuralı: toplu içe aktarma yeni müşteri
> kurulumunun tam ortasıdır ve mutabakat turlarını atlatırsa ürünün temel
> iddiası tam o anda çalışmaz.
>
> Bu **yeni bir kuyruk değil**, mevcut havuzun `maxProcesses` değeridir.

### Yeni kuyruk AÇILMAZ

| Yeni iş | Hangi kuyruk | Neden |
|---|---|---|
| `credentials:refresh` | `maintenance` | Zamanlanmış tarama, gecikmeye toleranslı |
| Shopify webhook | `inbox:process` | Mevcut hat |
| eBay offer publish | `listing:default` | İçerik aktarımı |
| Etsy envanter okuma | `inventory:high` | Stok yolunun parçası |

> **UYDURMA KUYRUK ADI, İŞİN REDIS'TE SONSUZA KADAR BEKLEMESİ DEMEKTİR** ve
> hiçbir hata görünmez. Adlar `config/horizon.php`'de; `PriceSyncTest` bu
> eşleşmeyi zaten sınıyor ve **yeni işler de oraya eklenir.**


---

## 24 · Security Delta

> v2.2 §11 **değişmez.** Kontrol listesi (`docs/GUVENLIK-KONTROL-LISTESI.md`)
> geçerliliğini korur. Bu bölüm yalnızca **yeni kanalların eklediği yüzeyi**
> anlatır.

### Yeni saldırı yüzeyleri

| # | Yüzey | Risk | Karşılık |
|---|---|---|---|
| 1 | 3 yeni webhook uç noktası | Sahte sipariş enjeksiyonu | HMAC **ham gövde** üzerinden, ayrıştırmadan ÖNCE |
| 2 | OAuth callback (Etsy, eBay) | Yetkilendirme kodu çalınması | `state` parametresi + PKCE (Etsy) |
| 3 | Refresh token saklama | Uzun ömürlü sır | `channel_credentials` şifreli; **`settings`'e ASLA** |
| 4 | `channel_metadata` | Sır sızıntısı | **Yalnızca kimlik** — token/secret YASAK |
| 5 | Shopify GraphQL hata gövdesi | Sır yansıması | `ChannelErrorText` maskesi — **zaten var** |

### OAuth callback — projede ilk

```
GET /channels/{connection}/oauth/callback?code=...&state=...
```

> **`state` DOĞRULAMASI ZORUNLUDUR.** Doğrulanmazsa saldırgan kendi
> yetkilendirme kodunu kurbanın oturumuna enjekte eder ve **kurbanın
> kiracısına kendi mağazasını bağlar** (CSRF'in OAuth'taki biçimi).
>
> `state` oturumda saklanır, tek kullanımlıktır ve **karşılaştırma
> `hash_equals` ile** yapılır.
>
> **CALLBACK ROTASI `web` GRUBUNDADIR** — webhook rotalarının aksine.
> Oturum gerekir çünkü `state` oradan okunur ve kullanıcı kimliği bilinmek
> zorundadır.

### `PayloadRedactor` — yeni anahtarlar

```php
// EKLENECEK
'x-shopify-access-token', 'x-shopify-hmac-sha256',  // v2.2'de hmac VAR
'x-hb-signature', 'x-api-key', 'code_verifier',
'refresh_token',                                     // v2.2'de VAR
```

> **`dontFlash` LİSTESİ DE GENİŞLETİLİR** (`bootstrap/app.php`). Doğrulama
> hatasında kanal anahtarları oturuma flash edilirse ve `SESSION_DRIVER=
> database` ise anahtar **şifresiz bir tabloya** düşer. Liste bugün
> kullanılanlardan GENİŞTİR; yeni kanal eklenince alan adı orada yoksa
> sızıntı sessizce geri gelir.

### Webhook hız sınırı

> Sınır **bağlantı başınadır, IP başına DEĞİL** — kanal webhook'ları kendi
> altyapısından gelir ve aynı IP yüzlerce satıcıya hizmet eder.
> `WebhookController::MAX_REQUESTS_PER_MINUTE` **değişmez**; yeni kanallar
> aynı kapıdan geçer.

### Kiracı izolasyonu — altı kanalda

> **`DB::table()` KİRACI FİLTRESİ BEŞ KEZ ISIRDI** (v2.2 kaydı). Yeni kanal
> yazarken ham sorgu kullanılıyorsa filtre **açıkça yazılır ve testi de
> yazılır**.
>
> Yeni yüzey: `channel_metadata` okuyan sorgular. Adapter kendi
> bağlantısının listing'lerini okur ve `Listing::query()` global scope'a
> tabidir — **ham sorgu kullanılmaz.**

---

## 25 · Observability Delta

> v2.2 §11'in 13 metriği **değişmez.** Yeni kanallar mevcut metriklere
> **otomatik** dahil olur: `MetricScope` kanal başına ölçüyor
> (`connection:{id}`).

### Yeni metrikler — üç tane

| Metrik | Kapsam | Eşik | Neden |
|---|---|---|---|
| `token_expiring_soon` | bağlantı | > 0 | Yenilenemeyen refresh token, bağlantıyı **sessizce** öldürür |
| `token_refresh_failures` | bağlantı | > 3 / gün | Yenileme çalışmıyorsa 401 dalgası gelir |
| `channel_daily_quota_used` | bağlantı | > %80 | Etsy'nin günlük kotası **gerçek bir tavandır** (§21) |

> **ÖLÇÜLEMEYEN METRİK SIFIR YAZMAZ** (v2.2 kuralı). Kanal kota bilgisi
> vermiyorsa satır **hiç yazılmaz**; sıfır yazılsaydı grafik "her şey
> mükemmel" derdi.
>
> **EŞİK `Metric::threshold()` İÇİNDE TEK KAYNAKTIR.**

### Uyarı e-postaları

Üç yeni metrik `alerts:dispatch` turuna **otomatik** girer — kod
değişikliği yok. Kapsam **bağlantı** olduğu için uyarı **yöneticiye** gider
(v2.2 kuralı: bağlantı uyarısı satıcının düzeltebileceği bir şey değil).

> **İSTİSNA — TOKEN UYARISI SATICIYA GİDER.** Yeniden yetkilendirmeyi
> **yalnızca satıcı** yapabilir; yöneticiye gitmesi hiçbir işe yaramazdı.
> Bu, `Metric::alertAudience()` içinde açıkça yazılır.

### Panel

`/metrics` ekranı **değişmez** — kanal başına filtre zaten var.
`/channels` ekranına **token durumu rozeti** eklenir:

```
🟢 Geçerli        🟡 14 gün içinde dolacak
🔴 Yeniden yetkilendirme gerekli
```

---

## 26 · Migration / Rollout Strategy

### Kanal açma sırası — `is_active` kapısı

Her kanal **kapalı doğar** ve şu sıradan geçer:

```
1. Adapter yazılır, testler yeşil          → is_active = false
2. Uç noktalar doğrulanır                  → is_active = false
3. GERÇEK hesapla sağlık kontrolü geçer    → is_active = false
4. Tek kiracıda uçtan uca sürülür          → is_active = false
5. Kanal açılır                            → is_active = true
```

> **4. ADIM ATLANAMAZ.** Bu projede **her turda** gerçek çalıştırma yeşil
> testlerin altından bir hata çıkardı. Yeni kanalda bu olasılık daha
> yüksektir çünkü sözleşme dış bir sistemin.

### Geri alma

| Sorun | Aksiyon | Etki |
|---|---|---|
| Kanal yanlış davranıyor | `is_active = false` | Yeni bağlantı açılamaz; **mevcutlar çalışmaya devam eder** |
| Bağlantı bozuk | `status = 'inactive'` | O bağlantı işlem almaz |
| Adapter hatalı | Deploy geri alınır | `channel_metadata` kalır — **veri kaybı yok** |
| Migration geri | `channel_metadata` düşer | Yalnızca 3 yeni kanal etkilenir |

> **`is_active = false` MEVCUT BAĞLANTILARI DURDURMAZ** — yalnızca panelde
> yeni bağlantı açılmasını engeller. Acil durdurma için bağlantı bazında
> `status = 'inactive'` kullanılır.

### Kademeli açılış

```
Faz N.9 (production hardening)
  1. Kanal açılır, YALNIZCA demo kiracıda bağlantı kurulur
  2. 48 saat gerçek trafik izlenir (metrikler + hata oranı)
  3. Beş pilot kiracıya açılır
  4. Genel açılış
```

---

## 27 · Implementation Roadmap

> **SIRA GEREKÇESİ:** Hepsiburada'nın istemci katmanı yazılı ama **uç
> noktaları doğrulanmadı** ve doğrulama kullanıcıya bağlı — bloke. Shopify
> ise resmî dokümantasyonu açık, doğrulama sorunu yok. Bu yüzden **Shopify
> önce gelir.**

| Faz | Kanal | Saat | Bağımlılık |
|---|---|---|---|
| **1** | Shopify | 52 | — |
| **2** | Hepsiburada | 38 | ⚠️ uç nokta doğrulaması |
| **3** | Etsy | 56 | — |
| **4** | eBay | 64 | — |
| **5** | Six-Channel Hardening | 30 | Faz 1–4 |
| | **TOPLAM** | **240** | |

### Faz 0 — Ortak altyapı (12 saat, Faz 1'e dahil)

| Slice | İş | Dosya | Saat |
|---|---|---|---|
| 0.1 | `listings.channel_metadata` migration | migration + model cast | 2 |
| 0.2 | `SupportsTokenRefresh` + `TokenRefresher` | 3 dosya | 5 |
| 0.3 | `credentials:refresh` komutu + zamanlama | komut + `routes/console.php` + `bootstrap/app.php` | 3 |
| 0.4 | Seeder `is_active` düzeltmesi + testi | `ChannelTypeSeeder` | 2 |

**Testler:** `TokenRefreshTest` (P0), `ChannelTypeSeederTest` (P1)

### Faz 1 — Shopify (52 saat)

| Slice | İş | Ana dosyalar | Saat |
|---|---|---|---|
| 1.1 | Bağlantı + kimlik + sağlık | `ShopifyAdapter`, `ShopifyEndpoints` | 8 |
| 1.2 | GraphQL istemci sarmalayıcı + hata kontrolü | `ShopifyAdapter::gql`, `assertNoGraphqlErrors` | 5 |
| 1.3 | Katalog — create/update/delist | `ShopifyProductMapper`, `SupportsCatalog` | 8 |
| 1.4 | Ürün içe aktarma | `SupportsCatalogImport` | 5 |
| 1.5 | Stok | `SupportsInventory` | 6 |
| 1.6 | Fiyat | `SupportsPricing` | 3 |
| 1.7 | Sipariş webhook | `ShopifyOrderNormalizer` | 8 |
| 1.8 | İptal / iade / kargo | normalizer + `SupportsFulfillment` | 5 |
| 1.9 | `app/uninstalled` + mutabakat + production | webhook + `fetchInventory`/`fetchPrices` | 4 |

**P0 testleri:** GraphQL `userErrors` yakalanır · webhook HMAC · iptal ayrı
konu · stok mutlak

### Faz 2 — Hepsiburada (38 saat)

| Slice | İş | Saat |
|---|---|---|
| 2.0 | ⚠️ **Uç nokta doğrulaması** *(kullanıcıyla)* | — |
| 2.1 | Uç noktaları düzelt + test metinlerini güncelle | 4 |
| 2.2 | Taksonomi | 6 |
| 2.3 | Katalog + onay durumu | 8 |
| 2.4 | **Stok + fiyat (birleşik yük)** | 8 |
| 2.5 | Sipariş webhook | 6 |
| 2.6 | İptal / iade / paket | 4 |
| 2.7 | Kargo + mutabakat + kanalı açma | 2 |

**P0 testleri:** birleşik yük **her iki alanı da** taşır · `User-Agent`
eksikse istek atılmaz · webhook başlığı harf duyarsız

### Faz 3 — Etsy (56 saat)

| Slice | İş | Saat |
|---|---|---|
| 3.1 | OAuth2 + PKCE + callback | 10 |
| 3.2 | Token yenileme entegrasyonu | 4 |
| 3.3 | Taksonomi (seller taxonomy + properties) | 8 |
| 3.4 | Katalog — listing/product/offering eşleme | 10 |
| 3.5 | **Stok — oku-birleştir-yaz** | 8 |
| 3.6 | Fiyat | 4 |
| 3.7 | Sipariş yoklama (receipt/transaction) | 8 |
| 3.8 | İptal + mutabakat + production | 4 |

**P0 testleri:** envanter yazma **kardeş varyantları korur** · token
yenileme · olay kimliği `{receipt_id}:{status}`

### Faz 4 — eBay (64 saat)

| Slice | İş | Saat |
|---|---|---|
| 4.1 | OAuth2 + token yenileme | 8 |
| 4.2 | Bağlantı + politika/konum seçimi | 6 |
| 4.3 | **`SupportsOfferLifecycle` arayüzü + `PushOfferListing` işi** | 10 |
| 4.4 | Inventory item + offer + publish zinciri | 12 |
| 4.5 | Taksonomi (kategori + aspects) | 8 |
| 4.6 | Stok + fiyat (bulk, kısmi başarı) | 8 |
| 4.7 | Sipariş yoklama | 6 |
| 4.8 | İade + kargo | 4 |
| 4.9 | Mutabakat + production | 2 |

**P0 testleri:** ara başarısızlık **kaldığı yerden devam eder** · kısmi
başarı doğru operasyona yazılır · `offer_id` kalıcı

### Faz 5 — Six-Channel Hardening (30 saat)

| Slice | İş | Saat |
|---|---|---|
| 5.1 | Altı kanal fan-out testleri (P0) | 8 |
| 5.2 | Kuyruk kapasite ölçümü (`loadtest:sync` genişletme) | 6 |
| 5.3 | Mutabakat worker'ı 2'ye çıkarma + ölçüm | 3 |
| 5.4 | Yeni metrikler + uyarılar | 5 |
| 5.5 | Panel: token rozeti, kanal filtreleri | 4 |
| 5.6 | Yardım ekranı + Türkçe mesajlar | 4 |

---

## 28 · P0 / P1 / P2

### P0 — kod yazılmadan ÖNCE test yazılır

| # | Madde | Yanlışsa bedeli |
|---|---|---|
| P0-1 | Shopify GraphQL `userErrors` **kontrol edilir** | 200 döner, `synced_version` ilerler, kanalda hiçbir şey değişmez |
| P0-2 | Etsy envanter yazma **kardeş varyantları korur** | Diğer varyantlar kanaldan **silinir** — geri alınamaz |
| P0-3 | eBay ara başarısızlık **kaldığı yerden devam eder** | `25002` duplicate → kalıcı hata → listing ölür |
| P0-4 | Hepsiburada birleşik yük **her iki alanı taşır** | Eksik alan **0 sayılır** → ürün satışa kapanır |
| P0-5 | Token yenileme **tek süreçte** (`FOR UPDATE SKIP LOCKED`) | Paralel yenileme → kanal ilk token'ı iptal eder |
| P0-6 | Yeni webhook'lar **ham gövde** HMAC | Sahte sipariş enjeksiyonu |
| P0-7 | Olay kimliği **durum içerir** | İptal `insertOrIgnore` ile yutulur → stok geri eklenmez |
| P0-8 | Altı kanal fan-out → **6 bağımsız operasyon** | Bir kanalın hatası diğerlerini durdurur |
| P0-9 | `channel_metadata` **sır taşımaz** | Token panele → tarayıcıya sızar |
| P0-10 | OAuth `state` doğrulaması | Saldırgan kurbanın kiracısına kendi mağazasını bağlar |

### P1 — kanal açılmadan önce

| # | Madde |
|---|---|
| P1-1 | eBay kısmi başarı doğru operasyona yazılır |
| P1-2 | Shopify `app/uninstalled` → bağlantı işaretlenir, **silinmez** |
| P1-3 | Seeder `is_active`'i ezmez |
| P1-4 | eBay politika üçlüsü eksikse bağlantı `active` olmaz |
| P1-5 | Shopify konum seçilmemişse bağlantı `active` olmaz |
| P1-6 | Etsy günlük kota metriği ölçülür |
| P1-7 | Token süre dolumu **önceden** uyarır |
| P1-8 | `PayloadRedactor` yeni anahtarları maskeler |

### P2 — gerçek kullanım geldikten sonra

| Madde | Tetikleyici |
|---|---|
| `inventory_movements` partition | > 20M satır *(v2.2 eşiği)* |
| Mutabakat worker 2 → 4 | Sıcak tur p95 > 5 dk |
| Etsy stok itme sıklığı düşürme | Günlük kota > %80 |
| Shopify çok konumlu stok | Müşteri talebi |
| eBay çok pazarlı bağlantı UI | Müşteri talebi |
| Ortak sipariş normalizer soyutlaması | **Altıncı** kanalda aynı desen — v2.2 "üçüncü" diyordu, **erteleme gerekçesi §29'da** |

> **ORTAK NORMALIZER SOYUTLAMASI HÂLÂ AÇILMIYOR.** v2.2 tetikleyicisi
> "üçüncü kanalda aynı desen tekrar görülünce" idi ve altıncı kanaldayız.
> **Ama desen tekrar ETMİYOR:** Woo iptali `updated` içinde, Shopify'da ayrı
> konu, Etsy'de iade **hiç yok**, eBay'de ayrı API. Ortak soyutlama bu farkları
> `if` bloklarıyla soğurur ve **tam olarak kaçınmak istediğimiz şeye** dönerdi.

---

## 29 · V3 Test Acceptance Criteria

> v2.2'nin 16 testi **yeniden yazılmaz** — hepsi yeşil kalmak zorundadır.
> Bunlar **ek** testlerdir.

### Cross-channel fan-out (P0)

| # | Test | İddia |
|---|---|---|
| T-V3-1 | Shopify siparişi → diğer 5 kanala stok fan-out | 5 operasyon, Shopify atlanır |
| T-V3-2 | Etsy siparişi → diğer 5 kanala fan-out | 5 operasyon, Etsy atlanır |
| T-V3-3 | eBay siparişi **iki kez** gelirse stok **bir kez** düşer | `external_event_id` tekilliği |
| T-V3-4 | Hepsiburada yoklama örtüşmesi → **tek** order event | `{orderId}:{status}` çıpası |
| T-V3-5 | 6 listing fan-out → **6 bağımsız operasyon** | Her biri kendi durumu |
| T-V3-6 | Kaynak kanal atlanır ama **mutabakat onu yine kontrol eder** | Eniyileme ≠ otorite devri |

### Kanal izolasyonu (P0)

| # | Test | İddia |
|---|---|---|
| T-V3-7 | eBay token'ı dolmuş → **diğer 5 kanal çalışmaya devam eder** | Operasyon bazlı hata |
| T-V3-8 | Shopify 429 → **yalnızca Shopify** retrying | Kova bağlantı başına |
| T-V3-9 | Etsy listing hatası → Woo/Trendyol **etkilenmez** | — |
| T-V3-10 | Bir kanalın devre kesicisi açık → diğerleri geçer | — |

### Kanal davranışı (P0)

| # | Test | İddia |
|---|---|---|
| T-V3-11 | Shopify GraphQL `userErrors` → **operasyon BAŞARISIZ** | 200 gövde başarı sayılmaz |
| T-V3-12 | Etsy envanter yazma → **kardeş varyantlar korunur** | Oku-birleştir-yaz |
| T-V3-13 | eBay publish 429 → sonraki tur **publish'ten devam** | `offer_id` kalıcı |
| T-V3-14 | Hepsiburada stok yükü **fiyat alanını da taşır** | Eksik alan 0 sayılır |
| T-V3-15 | Token yenileme **paralel koşamaz** | `FOR UPDATE SKIP LOCKED` |

### İzolasyon (P0 — v2.2 kuralları, yeni kanallarda)

| # | Test | İddia |
|---|---|---|
| T-V3-16 | Kategori eşleştirme kiracıya kapsanır | 6 kanalda |
| T-V3-17 | Kiracı izolasyonu — yeni kanal sorguları | `DB::table()` filtreleri |
| T-V3-18 | Kimlik bilgisi izolasyonu | Kiracı A'nın token'ı B'de kullanılmaz |
| T-V3-19 | Adapter örneği paylaşılmaz | `bind`, asla `singleton` |
| T-V3-20 | `channel_metadata` panele giderken **sır içermez** | — |

### P1

| # | Test |
|---|---|
| T-V3-21 | eBay kısmi başarı → doğru operasyona yazılır |
| T-V3-22 | Shopify `app/uninstalled` → bağlantı işaretlenir, silinmez |
| T-V3-23 | Seeder `is_active`'i ezmez |
| T-V3-24 | OAuth `state` uyuşmazsa callback reddedilir |
| T-V3-25 | Etsy iade → **stok hareketi ÜRETMEZ** (`updated` sayılır) |
| T-V3-26 | Yeni kanallar `JobSerializationTest`'e eklenir |

### P2

| # | Test |
|---|---|
| T-V3-27 | 6 kanal × 1.000 listing yük testi (`loadtest:sync`) |
| T-V3-28 | Mutabakat 2.000 bağlantıda bütçe içinde kalır |

---

## 30 · Final V3 Implementation Decision

### Karar

**V3.0, v2.2'nin üzerine dört kanal ekler ve çekirdeğe üç noktada dokunur.**

| Konu | Karar |
|---|---|
| Mimari | v2.2 modüler monolit **değişmez** |
| Yeni teknoloji | **YOK** — Node/Remix sokulmaz |
| Core Architecture Delta | **3** — §03 (`SupportsOfferLifecycle` · `listings.channel_metadata` · `expires_at`'in kullanılması) |
| Yeni çekirdek arayüz | **1** — `SupportsOfferLifecycle` (eBay) · **9.** capability arayüzü |
| Yeni çekirdek bileşen | **1** — `TokenRefresher` (Etsy, eBay); `SupportsTokenRefresh` **10.** arayüz |
| **Fiziksel DB şema değişikliği** | **1 kolon** — `listings.channel_metadata` (§16 · DB Delta 1) |
| Yeni kuyruk | **YOK** |
| Yeni event sistemi | **YOK** — tek inbox |
| Tahmini süre | **240 saat** |

### Değişmezler — V3 sonunda da geçerli

```
on_hand = Σ inventory_movements.on_hand_delta          clamp YOK
Kırpma yalnızca OutboundQuantity::forChannel()
Çok-SKU yazan her yol LockInventoryRows
Bağlam yoksa istisna, sessiz veri YOK
AdapterRegistry::for() her çağrıda yeni örnek
Adapter yan etkisizdir
Fan-out tüketicide, gruplama batch builder'da
Tek gelen hat, tek tekilleştirme
HMAC ham gövde üzerinden
Sipariş asla reddedilmez
Stok mutlak değer, delta asla
Yazılmamış yetenek sessizce başarılı dönmez
if ($channel === '...') YAZILMAZ
```

### V3 sonunda satılabilir olan

> "Altı kanalda — WooCommerce, Trendyol, Shopify, Hepsiburada, Etsy ve
> eBay — stoğunuz her zaman tutar. Bir kanalda satış olduğunda diğer beşi
> saniyeler içinde güncellenir. Tutmadığında sistem beş dakika içinde fark
> eder ve düzeltir. Fiyatı kanal panelinden değiştirdiyseniz sistem onu
> ezmez, size sorar."

### Bu doküman ne zaman güncellenir

| Durum | Aksiyon |
|---|---|
| Kod incelemesinde somut bulgu | Patch — doküman güncellenir |
| Yeni kanal (Amazon, N11…) | **V3.1** — bu belge şablon |
| Shopify App Store kararı | **V4** — §11 servis token'ı uygulanır |
| Çekirdek değişikliği gerekiyor | **Önce buraya yazılır**, sonra kodlanır |

> **KOD İLE DOKÜMAN ÇELİŞTİĞİNDE DOKÜMAN ESASTIR** — v2.2'den devralınan
> kural. İstisna: V3.0 kapsamında açıkça onaylanmış proje kararları (§01).

---

*ENTEGRASYON V3.0 · Multi-Channel Expansion Implementation Reference*
*Baseline: v2.2 (DONDURULMUŞ) · commit `72b7416` · 923 test yeşil*
*Final documentation consistency revision*
