# Devir Notu — 18 Ağustos 2026 (Faz 2 · taksonomi)

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## Tek cümlede durum

**Faz 2'nin ilk iki maddesi kapandı**: Trendyol bağlanabiliyor ve kategori
ağacı çekilip sürümlenerek önbelleğe yazılıyor. **414 test yeşil**
(1635 assertion), Pint temiz.

## Bu turda ne eklendi

### §13 · Faz 2 · taksonomi (commit `b62e32b`)

"Taksonomi çekme, önbellekleme, sürümleme — 20 sa". Kategori/öznitelik
eşleştirme arayüzünün ve dolayısıyla katalog aktarımının ön koşulu.

| Ne | Nerede |
|---|---|
| Şema | `database/migrations/..._create_taxonomy_tables.php` |
| Modeller | `Channels/Models/ChannelCategory.php`, `ChannelCategoryAttribute.php` |
| Çekme + düzleştirme | `Adapters/Trendyol/Taxonomy/TaxonomyClient.php` |
| Önbellek | `Channels/Actions/SyncTaxonomy.php` |
| Süpürme + komut | `Support/SyncTaxonomyForChannels.php`, `Console/SyncTaxonomyCommand.php` |
| Testler | `TaxonomySyncTest` (17) |

`taxonomy:sync` `bootstrap/app.php` içinde kayıtlı ve `routes/console.php`
içinde **günlük 03:00**'e zamanlı; test ikisini **ayrı** doğruluyor.

**TAKSONOMİ KİRACIYA AİT DEĞİLDİR.** Tablolar `tenant_id` kolonu TAŞIMAZ:
Trendyol'un kategori ağacı tüm satıcılar için aynıdır. Kiracı başına
kopyalansaydı aynı 30 bin satır her kiracı için yeniden saklanır ve her
kiracı ayrı ayrı çekmek zorunda kalırdı. Kiracıya ait olan
**eşleştirmedir** — ağaç kanalın GERÇEĞİ, eşleştirme satıcının KARARI.

**Yeni sürüm eskiyi SİLMEZ.** Eşleştirmeler eski sürüme bağlıdır; sürüm bir
**ayıraçtır**, imha emri değil.

**Sürüm içerikten türer ve SIRALANIR.** Kanal sürüm numarası vermiyor.

**Öznitelik yalnızca YAPRAK için çekilir** — ara kategoriye ürün açılamaz.

## GERÇEK ÇALIŞTIRMADA İKİ HATA BULUNDU

İkisi de **testler yeşilken** duruyordu.

### 1 · Tek bozuk bağlantı tüm kanalı durduruyordu

Tur kanal türü başına bir bağlantı seçiyor ve bozuksa pes ediyordu. İlk
gerçek `taxonomy:sync` çalıştırmasında **"0 kanal türü"** çıktı: ayarı
eksik eski bir test bağlantısı seçilmişti. Üretimde bu, o kanaldaki TÜM
satıcıların taksonomisiz kalması demekti — üstelik sorun kendi
bağlantılarında olmadığı için hiçbiri düzeltemezdi.

→ Bağlantılar artık **sırayla** deneniyor, ilk başarılı olan turu tamamlıyor.

### 2 · Başarısız yanıt sessizce boş ağaca dönüşüyordu

`json()` bir 500 gövdesinde de dizi döndürüyor ve `categories` anahtarı
bulunmadığı için ağaç **boş** çıkıyordu. O boş ağaç geçerli bir sürümle
yazılır, panel "bu kanalda hiç kategori yok" der ve ürün aktarımı ön koşul
kapısında sonsuza kadar takılırdı — hata hiçbir yere düşmeden.

→ `throw()` ile yükseltiliyor (hem ağaç hem öznitelik yolunda).

## Mutasyonla sınandı — yedi mutasyon

**Yakalananlar:** eski sürümü silme · yetenek kapısı · yaprak filtresi ·
sürüme zaman karıştırma · yaprak türetme · sıralama.

**Sıralama mutasyonu ilk turda HAYATTA KALDI:** testlerin hiçbiri aynı ağacı
farklı sırada göndermiyordu. Gerçek test eklendi — kanal sıra değiştirirse
sürüm DEĞİŞMEMELİ, yoksa satıcı hiçbir şey yapmamışken tüm eşleştirmeler
"yeniden doğrula" damgası yer.

**DÜRÜST SINIR (sahte test yazılmadı):** `SyncTaxonomy`'deki `runAsSystem`
sarmalayıcısı bugün davranışı değiştirmiyor — `ChannelCategory`
`BelongsToTenant` KULLANMADIĞI için global scope hiç uygulanmıyor.
Sarmalayıcı niyeti belgeliyor ve birisi ileride o trait'i eklerse yazımın
sessizce kiracıya kapanmasını önlüyor. Kod içinde de böyle yazıldı.

**BİR UYARI — bozuk bağlantı testinin ilk hâli sahte yeşildi.** `base_url`'ü
boşaltmak bozukluk ÜRETMİYOR: `Http::fake()` her adrese cevap veriyor ve
istek sessizce "başarılı" oluyor. Mutasyon uygulandığında test yine geçti.
Gerçek bozukluk için HTTP 500 kullanıldı; ancak o zaman mutasyonu yakaladı.
**Mutasyonun testi gerçekten kırdığını doğrula.**

## Yerel HTTPS stub'ıyla uçtan uca doğrulandı

Kural gevşetilmedi, stub'a TLS eklendi; sertifika ve stub sonradan silindi
(trust store temizliği doğrulandı).

- 3 seviyeli iç içe ağaç düzleştirildi (`Elektronik > Telefon > Akıllı
  Telefon`), 6 kategori yazıldı.
- **Yalnızca 3 yaprak için öznitelik isteği atıldı**; ara kategoriler
  (411, 522, 2011) atlandı — stub günlüğü kanıt.
- 9 öznitelik zorunluluk ve varyant belirleyici bayraklarıyla yazıldı,
  izinli değerler (`["S","M","L"]`) korundu.
- İkinci tur kopya satır açmadı ve yeni sürüm üretmedi.

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 414 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
npm run build                                 # YERELDE (container'da Node yok)
```

Panel: `/` özet · `/products` ürünler · `/products/{id}/channels` kanala
gönderme · `/orders` siparişler · `/inventory` stok · `/channels` kanallar

## Sıradaki adım — dokümandaki Faz 2 sırası

1. **Kategori ve öznitelik eşleştirme arayüzü** (§13 · Faz 2 · 28 sa) —
   `category_mappings`, `attribute_mappings`, `attribute_value_mappings`
   (kiracı BAŞINA, taksonominin aksine) + panel ekranı. Taksonomi önbelleği
   bunun girdisi; katalog aktarımının ön koşulu.
2. **Katalog aktarımı, ön koşul kapısı, onay durumu takibi** (24 sa) —
   §14 · `PrerequisiteGate`: eksik eşleştirmede listing `blocked`, **stok
   akışı etkilenmez**.
3. **Stok ve fiyat itme** (16 sa) — çapraz kanal döngüsünün yarısı kapanır.
4. **Sipariş yoklaması** (22 sa) — Faz 2 demosu bunu ister.

Panel tarafında hâlâ açık: **mutabakat panel ekranı** ve **`RequestResync` +
T10** (§18 · P1, faz 1.6'da listeli ama yazılmadı).

**Abonelik/ödeme Faz 4'tür** (hafta 21–25) — şimdi yazılmamalı.

## Bu projede işe yarayan çalışma biçimi

1. **Testi önce yaz, kırmızı olduğunu gör**, sonra implementasyonu yaz.
2. **Mutasyonla sına** — ve **mutasyonun testi gerçekten KIRDIĞINI doğrula**.
   Python yamalarında `assert old in s` kullan. Bu turda bir test mutasyon
   altında GEÇTİ: kurgusu gerçek bozukluğu üretmiyordu.
3. **Mutasyon hayatta kalırsa SAHTE TEST YAZMA** — ya gerçek test bul, ya
   yapısal sınırı belgele.
4. **`Http::fake()` her adrese cevap verir** — "bozuk yapılandırma"
   senaryosu fake altında bozuk DEĞİLDİR. Gerçek hata kodu kullan.
5. **Kalıcılık sınarken Eloquent'e güvenme** — kimlik haritası aynı bellek
   nesnesini geri verir. **Ham satırı oku.**
6. **`DB::table()` yazdıysan kiracı filtresinin TESTİNİ de yaz.**
7. Stok yazan her testin sonunda `assertLedgerMatchesProjection()` çağır.
8. **Ekran işi bittiğinde TARAYICIDA çalıştır.**
9. **Kuyruk işi / komut yazdıysan GERÇEK ÇALIŞTIR** — bu turda iki hata da
   `php artisan taxonomy:sync` gerçekten koşturulunca çıktı.
10. **Adapter yazdıysan BAĞLAM DIŞINDA çağırmayı da sına.**

## Mutasyonla / gerçek çalıştırmayla bulunan gerçek boşluklar (tarihçe)

Hepsi testler yeşilken bulundu:

- **Tek bozuk bağlantı tüm kanalın taksonomisini durduruyordu.**
- **Başarısız yanıt sessizce boş kategori ağacı yazıyordu.**
- **Kimlik bilgisi bağlam dışında hiç gönderilmiyordu** — Woo dahil.
- **`DB::table()` kiracı filtresinin testi yoktu** — ÜÇ ayrı turda.
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

- **`Http::fake()` her adrese cevap verir** — yapılandırma bozukluğu fake
  altında görünmez.
- **İkinci `Http::fake()` ilkini DEĞİŞTİRMEZ** — iki farklı yanıt için
  `Http::sequence()` kullan.
- **Adapter bağlam DIŞINDA çağrılabilir** — kimlik bilgisi `runAsSystem()`
  ile okunur.
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
- **`(channel_type_code, external_account_id)` GLOBAL tekildir** — testte
  ikinci bağlantı açarken hesap kimliğini değiştir.
- **`clock_timestamp()`** — zaman damgaları saniye hassasiyetli.
- **`Command::run()` REZERVE İMZADIR.** Mantık `Support/` altında.
- **Domain komutları otomatik keşfedilmez** — `bootstrap/app.php`.
- **`QUEUE_CONNECTION=sync` gerçek worker'ı taklit etmez.**
- **Açılış stoğu ledger üzerinden girer** (IMPORT).
- **Eşzamanlılık testi `RefreshDatabase` ile yazılamaz** → `DatabaseTruncation`.
- **`StoreUrl` HTTPS'i zorunlu tutar** — yerel stub'a TLS eklenir.
- **CI'da `public/build` yoktur** — `Tests` job'ı `npm run build` çalıştırır.
- **CI'da `codeload` 429'u** — `--prefer-source` ile kaynak yoldan denenir;
  `COMPOSER_AUTH` zip indirmelerine DEĞMEZ.

## Bilinen açık uç

Eski turlarda bir `--order-by=random` turunda tek test düşmüştü; son
turlarda tekrar üretilemedi. Görülürse seed ile kaydedilmeli.
