# Devir Notu — 18 Ağustos 2026 (Faz 2 · eşleştirme)

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## Tek cümlede durum

**Faz 2'nin ilk üç maddesi kapandı**: Trendyol bağlanabiliyor, kategori ağacı
çekilip sürümleniyor ve **artık panelden eşleştiriliyor**. **440 test yeşil**
(1709 assertion), Pint temiz, dört random seed'de stabil.

## Bu turda ne eklendi

### §13 · Faz 2 · kategori ve öznitelik eşleştirme (commit `777c71e`)

"Kategori ve öznitelik eşleştirme arayüzü — 28 sa". Katalog aktarımının ön
koşulu; §14'ün `PrerequisiteGate`'i buradaki kararları okuyacak.

| Ne | Nerede |
|---|---|
| Şema | `database/migrations/..._create_mapping_tables.php` |
| Modeller | `Channels/Models/{CategoryMapping,AttributeMapping,AttributeValueMapping}.php` |
| Action'lar | `Channels/Actions/Save{CategoryMapping,AttributeMapping,AttributeValueMapping}.php` |
| Panel | `Http/Controllers/CategoryMappingController.php`, `Pages/Mappings/Index.vue` |
| Testler | `CategoryMappingTest` (15) + `CategoryMappingScreenTest` (11) |

Rotalar: `/mappings` · `POST /mappings/category` · `POST /mappings/attribute` ·
`POST /mappings/attribute-value`. Gezinmede "Eşleştirme" sekmesi var.

**EŞLEŞTİRME KİRACIYA AİTTİR — TAKSONOMİNİN AKSİNE.** `channel_categories`
`tenant_id` TAŞIMAZ (ağaç kanalın gerçeği); bu üç tablo TAŞIR (eşleştirme
satıcının kararı). İki satıcı aynı iç kategoriyi kanalın farklı
kategorilerine bağlayabilir ve ikisi de haklıdır. Test bunu İKİ YÖNDE
doğruluyor: eşleştirme sızmaz, ağaç paylaşılır.

**ÜÇ SEVİYENİN ANAHTARLARI BİLİNÇLİ OLARAK FARKLIDIR.**
- Kategori: `UNIQUE(tenant_id, internal_category_id, channel_type_code)`
- Öznitelik: `UNIQUE(tenant_id, option_definition_id, channel_category_id)` —
  **KATEGORİ BAŞINA**, çünkü aynı "Beden" elbisede ve ayakkabıda farklı
  `external_attribute_id` taşır.
- Değer: `UNIQUE(tenant_id, option_value_id, external_attribute_id)` —
  **ÖZNİTELİK BAŞINA, kategori YOK**, çünkü değer listesi kategoriden
  bağımsızdır. Kategori de anahtara girseydi satıcı aynı "S → SMALL"
  kararını her kategori için yeniden vermek zorunda kalırdı.

**YENİ SÜRÜM EŞLEŞTİRMEYİ SİLMEZ, BAYAT İŞARETLER.** Taksonomi maddesindeki
"sürüm bir ayıraçtır, imha emri değil" kuralının eşleştirme tarafındaki
karşılığı. `taxonomy_version` FK'dan okunabilirdi ama KOLON olarak tutuluyor:
"hangi eşleştirmeler eski sürüme bakıyor" join'siz cevaplanıyor.

**ÜÇ KAPI, ÜÇÜ DE AYNI GEREKÇEYLE.** Yaprak olmayan kategori, kategoride
bulunmayan öznitelik ve izinli liste dışındaki değer REDDEDİLİR: üçü de
kanalda `VALIDATION` hatası verirdi ve o hata **KALICIDIR** — listing
"düzeltilemez" damgasıyla ölürdü. Hatayı kaydederken yakalamak sonra
yakalamaktan ucuzdur.

**BOŞ İZİNLİ DEĞER LİSTESİ "HİÇBİRİ" DEĞİL "SERBEST METİN" DEMEKTİR.** Aksi
yorumla satıcı o özniteliği asla eşleştiremezdi.

### İç kategori alanı eklendi (yan iş ama zorunluydu)

`products.internal_category_id` §4'te vardı ama **hiçbir yerde
yazılmıyordu** — her ürün NULL taşıyordu ve eşleştirmenin çıpası oydu.
`CreateProduct` + `UpdateProduct` + ürün formları (create/edit) güncellendi.
Boş dize NULL'a çevriliyor: `""` bir kategori adı değildir ve eşleştirme
ekranında adsız bir satır olarak belirirdi.

**Ayrı bir iç kategori tablosu YOK** (§4 de istemiyor): serbest metindir ve
ekran `products` üzerinden DISTINCT okur. Satıcının gerçekte kullandığı
değerler tek doğru kaynaktır.

## Mutasyonla sınandı — sekiz mutasyon, sekizi de yakalandı

Yaprak kapısı · `BelongsToTenant` · **`DB::table()` kiracı filtresi** ·
izinli değer kapısı · ekrandaki yaprak filtresi · bayatlık işareti ·
öznitelik varlık kapısı · çapraz kiracı seçenek tanımı.

**Her mutasyonun GERÇEKTEN uygulandığı doğrulandı** (`assert old in s` +
tek eşleşme kontrolü). Bu tur hiçbiri hayatta kalmadı.

`DB::table()` kiracı filtresi boşluğu bu projede DÖRT turda çıkmıştı; bu kez
filtre VE testi baştan yazıldı ve mutasyon testi kırdı.

## Tarayıcıda sürüldü

Gerçek panelde uçtan uca: kayıt/giriş → `/mappings` → yalnızca 3 YAPRAK
listelendi (ara "Giyim" **gelmedi**) → "Giyim > Elbise" seçildi → rozet
"Eşleşmedi" → **"Zorunlu öznitelik eksik"**, eksikler **adıyla** yazıldı
("Beden, Renk"), isteğe bağlı "Kumaş" sayılmadı (0/2) → Beden eşlendi (1/2,
"Eksik: Renk") → Renk eşlendi → **"Hazır" (2/2)**. Eşleşmemiş `ayakkabi`
"Eşleşmedi" kaldı. **Konsol hatası yok.** Kalıcılık **ham satırlarla**
doğrulandı (Eloquent kimlik haritasına güvenilmedi). Test verisi silindi.

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 440 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
npm run build                                 # YERELDE (container'da Node yok)
```

Panel: `/` özet · `/products` ürünler · `/products/{id}/channels` kanala
gönderme · `/orders` siparişler · `/inventory` stok · `/channels` kanallar ·
`/mappings` **eşleştirme**

## Sıradaki adım — dokümandaki Faz 2 sırası

1. **Katalog aktarımı, ön koşul kapısı, onay durumu takibi** (24 sa) —
   §14 · `PrerequisiteGate`: eksik eşleştirmede listing `blocked`, **stok
   akışı etkilenmez**. Girdisi hazır: `category_mappings` +
   `attribute_mappings` + `attribute_value_mappings`. Ekran zaten "hazır mı"
   sorusunu cevaplıyor (`ready` alanı); kapı aynı mantığı listing tarafında
   uygulayacak — **mantık ortak bir yere alınmalı, ikinci kez yazılmamalı.**
2. **Stok ve fiyat itme** (16 sa) — çapraz kanal döngüsünün yarısı kapanır.
3. **Sipariş yoklaması** (22 sa) — Faz 2 demosu bunu ister.

Panel tarafında hâlâ açık: **mutabakat panel ekranı** ve **`RequestResync` +
T10** (§18 · P1, faz 1.6'da listeli ama yazılmadı).

**Abonelik/ödeme Faz 4'tür** (hafta 21–25) — şimdi yazılmamalı.

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

## Mutasyonla / gerçek çalıştırmayla bulunan gerçek boşluklar (tarihçe)

Hepsi testler yeşilken bulundu:

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
- **`inventory_movements` kolonu `type`, `movement_type` DEĞİL.**
- **`channel_connections` kolonu `label`, `name` DEĞİL.**
- **`api_calls` zaman kolonu `called_at`** — `created_at` YOK.
- **`ErrorClass` case'i `RATE_LIMITED`**, `RATE_LIMIT` DEĞİL.
- **`TenantContext` metodu `runFor()`**, `run()` DEĞİL.
- **`MissingTenantContextException` `Support\Tenancy\Exceptions\` altında.**
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

## Bilinen açık uçlar

**1 · CI'ın 429 düzeltmesinden sonraki durumu buradan görülemedi.** `gh`
kimlik doğrulamalı değil (`gh auth status` → "not logged into any GitHub
hosts") ve bu turda da doğrulanamadı. `gh auth login` sonrası
`gh run list` ile bakılmalı. Düzeltmenin kendisi (`6e2217e`) yerinde.

**2 · `--order-by=random` düşüşü bu turda da tekrar üretilemedi.** Dört tur
koşuldu, dördü de yeşil (seed'ler: 1787050697 · 1787050762 · 1787050865 ·
1787050952). PHPUnit 11'de `--seed` seçeneği YOK; seed çıktının sonunda
"Random Order Seed" satırında raporlanır. Görülürse o satırdaki seed
kaydedilmeli.
