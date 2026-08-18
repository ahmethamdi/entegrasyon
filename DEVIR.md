# Devir Notu — 18 Ağustos 2026 (Faz 2 · ön koşul kapısı ve onay)

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## Tek cümlede durum

**Faz 2'nin ilk DÖRT maddesi kapandı**: Trendyol bağlanıyor, taksonomi
çekiliyor, eşleştirme panelden yapılıyor ve **ürün ön koşul kapısından
geçerek Trendyol'a aktarılıyor, onay durumu takip ediliyor**.
**474 test yeşil** (1786 assertion), Pint temiz, üç random seed'de stabil.

## Bu turda ne eklendi

### §13 · Faz 2 · katalog aktarımı + ön koşul kapısı + onay (`9ebb00d`)

"Katalog aktarımı, ön koşul kapısı, onay durumu takibi — 24 sa".

| Ne | Nerede |
|---|---|
| Kapı | `Sync/Support/PrerequisiteGate.php` + `PrerequisiteResult.php` |
| Katalog çevirisi | `Adapters/Trendyol/Catalog/ListingMapper.php` |
| Aktarım | `TrendyolAdapter::{createListing,updateListing,findExistingListing}` |
| Onay | `Sync/Actions/TrackApprovalStatus.php` + `TrendyolAdapter::fetchApprovalStatus` |
| Süpürme + komut | `Sync/Support/TrackApprovalForConnections.php`, `Console/TrackApprovalStatusCommand.php` |
| Şema | `..._add_approval_columns_to_listings.php` |
| Testler | `PrerequisiteGateTest` (12) · `ApprovalStatusTest` (16) · `TrendyolCatalogTest` (6) |

`approval:track` `bootstrap/app.php` içinde kayıtlı ve `routes/console.php`
içinde **saatlik** zamanlı; test ikisini **ayrı** doğruluyor.

**KAPI STOK AKIŞINA DOKUNMAZ** — §14'ün ana tasarım hedefi ve bu maddenin
varlık sebebi. Eksik eşleştirmede listing `blocked` olur, içerik
gönderilmez, ama hareket/bakiye/outbox HİÇ etkilenmez. Ayrı bir test bunu
snapshot karşılaştırmasıyla koruyor; kırılırsa pazaryeri karmaşıklığı
çekirdeğe sızmış demektir.

**KAPI ÇEKİRDEKTE, `Adapters/Trendyol/Catalog/` ALTINDA DEĞİL.** §19'un
dizin ağacından **bilinçli sapma**, gerekçesi kodda yazılı: kapı kiracının
eşleştirme tablolarını okur ve çekirdeğin listing durumunu belirler;
Trendyol'a özgü hiçbir alan veya uç nokta bilmez ve `SupportsTaxonomy`
uygulayan HER kanalda çalışır. §14'ün kendi kod örneği de
`instanceof SupportsTaxonomy` ile yazılmış. `ListingMapper` ise gerçekten
kanala özgüdür ve dokümanın gösterdiği yerde durur.

**"HAZIR MI" MANTIĞI TEK KAYNAK.** Eşleştirme ekranı artık kapının
`missingRequiredAttributes()` metodunu çağırıyor (bir önceki turun devir
notunda kendime bıraktığım uyarı buydu). İki yerde hesaplansaydı biri
değiştiğinde panel "hazır" derken kapı "eksik" der ve satıcı neyi
düzelteceğini bilemezdi.

**ONAY SÜRECİ OLAN KANALDA GÖNDERİM `live` DEĞİL `pending_approval` YAZAR.**
Doğrudan canlı işaretlenseydi henüz yayında olmayan satır fan-out hedefi
olur ve her stok turunda hata alırdı. Canlı işaretini `TrackApprovalStatus`
kanaldan öğrenerek yazıyor.

**Onay durumunun üç ince kuralı:** (a) yanıtta olmayan satıra DOKUNULMAZ —
yokluk red değildir, kanal yeni ürünü listeye hemen koymaz; (b)
`approved: true` + `onSale: false` **"inactive"**dir, onaylanmış değil —
o satır kanalda görünmez; (c) red sebebi ADIYLA saklanır ve panelde AYRI
kutuda gösterilir — senkron hatası "gönderemedik", red "gönderdik ama
beğenilmedi" demektir.

## TARAYICIDA BİR GERÇEK HATA BULUNDU — testler yeşilken

**Engellenen gönderim panelde "GATE-1 bu kanalda zaten güncel." diyordu.**

`PublishListing`'in boş dizi döndürmesi İKİ ayrı anlama geliyor: sürüm
kapısı eledi (zaten gönderilmiş) veya ön koşul engelledi (hiç
gönderilmedi). Controller ikisini ayırt etmiyordu ve satıcı eksik
eşleştirmeyi "her şey yolunda" sanardı — ürününün neden kanalda
görünmediğini asla anlayamazdı.

→ Controller artık kapıyı ayrıca soruyor ve uyarı kutusunda sebebiyle
gösteriyor. Testi de yazıldı.

## Mutasyonla sınandı — sekiz mutasyon, BİRİ HAYATTA KALDI

**Yakalananlar:** kapının engellemesi · yetenek kapısı · `pending_approval`
dalı · `onSale` kontrolü · kategori çevirisi · öznitelik çevirisi ·
paneldeki paylaşılan eksik hesabı.

**HAYATTA KALAN:** onay yanıtında olmayan satırı "reddedildi" saymak
hiçbir testi kırmıyordu. Sebep: adapter testi yalnızca **batch'i**
sınıyordu, action'ın `null` durumu NASIL ele aldığını değil. Gerçek test
eklendi (`a_listing_missing_from_the_response_keeps_its_status`) ve
mutasyonu **gerçekten kırdığı doğrulandı**.

## Uçtan uca tarayıcıda sürüldü

Trendyol bağlantısı + kategori ağacı + zorunlu öznitelik seed'lendi, sonra:

1. Gönder → **engellendi**, sebep: "kadin-elbise iç kategorisi bu kanalda
   eşleştirilmemiş", `lifecycle = blocked`, rozet "Ön koşul eksik".
2. Kategori eşleştirildi → yeniden gönder → **yine engellendi**, bu kez
   sebep "Eksik zorunlu öznitelik: Beden" (ikinci kapı koşulu çalışıyor).
3. Öznitelik eşleştirildi → ekran "Hazır (1/1)" → gönder → **kabul edildi**
   ("kanalına gönderiliyor"), `blocked` kalktı, `lifecycle = draft`, sync
   state `pending` ve **eski hata metni temizlendi** (ham satırla
   doğrulandı).

Konsol hatası yok. Test verisi silindi.

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 474 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
npm run build                                 # YERELDE (container'da Node yok)
```

Panel: `/` özet · `/products` ürünler · `/products/{id}/channels` kanala
gönderme · `/orders` siparişler · `/inventory` stok · `/channels` kanallar ·
`/mappings` eşleştirme

## Sıradaki adım — dokümandaki Faz 2 sırası

1. **Stok ve fiyat itme** (16 sa) — çapraz kanal döngüsünün yarısı kapanır:
   Woo satışı Trendyol stoğunu günceller. `TrendyolAdapter::pushInventory`
   ve `pushPrices` hâlâ açıkça istisna fırlatıyor. **Çekirdek tarafı hazır**
   (`InventoryBatchBuilder`, `PushInventory`, `SyncResultRecorder` Woo ile
   çalışıyor); yalnızca adapter gövdeleri ve Trendyol'un
   `price-and-inventory` uç noktası yazılacak.
2. **Sipariş yoklaması** (22 sa) — webhook yok, polling aynı inbox'a yazar.
   **Faz 2 demosu bunu ister**: "Trendyol siparişi Woo stoğunu düşürüyor".

Panel tarafında hâlâ açık: **mutabakat panel ekranı** ve **`RequestResync` +
T10** (§18 · P1, faz 1.6'da listeli ama yazılmadı).

**Abonelik/ödeme Faz 4'tür** (hafta 21–25) — şimdi yazılmamalı.

## Bu projede işe yarayan çalışma biçimi

1. **Testi önce yaz, kırmızı olduğunu gör**, sonra implementasyonu yaz.
2. **Mutasyonla sına** — ve **mutasyonun testi gerçekten KIRDIĞINI doğrula**.
   Python yamalarında `assert old in s` kullan.
3. **Mutasyon hayatta kalırsa SAHTE TEST YAZMA** — ya gerçek test bul, ya
   yapısal sınırı belgele. (Bu turda biri hayatta kaldı ve gerçek testle
   kapatıldı.)
4. **`Http::fake()` her adrese cevap verir** — "bozuk yapılandırma"
   senaryosu fake altında bozuk DEĞİLDİR. Gerçek hata kodu kullan.
5. **Kalıcılık sınarken Eloquent'e güvenme** — kimlik haritası aynı bellek
   nesnesini geri verir. **Ham satırı oku.**
6. **`DB::table()` yazdıysan kiracı filtresinin TESTİNİ de yaz.**
7. Stok yazan her testin sonunda `assertLedgerMatchesProjection()` çağır.
8. **Ekran işi bittiğinde TARAYICIDA çalıştır.** Bu turda panel metni
   hatası ancak orada göründü.
9. **Kuyruk işi / komut yazdıysan GERÇEK ÇALIŞTIR.**
10. **Adapter yazdıysan BAĞLAM DIŞINDA çağırmayı da sına.**
11. **Testte "işi çalıştır" derken reflection'a sapma** — özel metoda
    girmek davranışı değil implementasyonu sınar. Gerçek işi kur ve
    `handle()` çağır; başarısızsa `sync_attempts.error_message`'ı oku.

## Mutasyonla / gerçek çalıştırmayla bulunan gerçek boşluklar (tarihçe)

Hepsi testler yeşilken bulundu:

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
- **Statik fabrika ile örnek metodu AYNI ADI paylaşamaz** (PHP) —
  `PrerequisiteResult::ok()` bu yüzden `satisfied()` değil.
- **`inventory_movements` kolonu `type`, `movement_type` DEĞİL.**
- **`channel_connections` kolonu `label`, `name` DEĞİL.**
- **`api_calls` zaman kolonu `called_at`** — `created_at` YOK.
- **`RemoteListing` parametresi `url`**, `externalUrl` DEĞİL.
- **`ErrorClass` case'i `RATE_LIMITED`**, `RATE_LIMIT` DEĞİL.
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
  KÜÇÜLTÜLMELİ** — yazılan bir gövde listede kalırsa test yanlış sebeple
  kırmızıya döner.

## Bilinen açık uçlar

**1 · CI'ın 429 düzeltmesinden sonraki durumu buradan görülemiyor.** `gh`
kimlik doğrulamalı değil (`gh auth status` → "not logged into any GitHub
hosts") ve bu turda da doğrulanamadı. `gh auth login` sonrası
`gh run list` ile bakılmalı. Düzeltmenin kendisi (`6e2217e`) yerinde.

**2 · `--order-by=random` düşüşü bu turda da tekrar üretilemedi.** Üç tur
koşuldu, üçü de yeşil (seed'ler: 1787059158 · 1787059190 · 1787059224).
PHPUnit 11'de `--seed` seçeneği YOK; seed çıktının sonunda "Random Order
Seed" satırında raporlanır. Görülürse o satırdaki seed kaydedilmeli.
