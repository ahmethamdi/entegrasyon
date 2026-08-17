# Devir Notu — 17 Ağustos 2026 (sipariş listesi ekranı + CI 429)

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## Tek cümlede durum

**§13 · faz 1.6 kapandı**: alınan sipariş artık panelde görünüyor, fazla
satış ve eşleşmemiş SKU ayrı ayrı uyarılıyor. **374 test yeşil**
(1545 assertion), Pint temiz.

## Bu turda ne eklendi

### 1 · Sipariş listesi ekranı (commit `98a39b6`)

§13 · faz 1.6'nın son açık maddesi: "panelde sipariş listesi ve fazla
satış uyarısı". Sipariş alımı çalışıyordu ama kullanıcının siparişi
göreceği hiçbir yer yoktu.

| Ne | Nerede |
|---|---|
| Kontrolcü | `app/Http/Controllers/OrderController.php` |
| Ekranlar | `resources/js/Pages/Orders/{Index,Show}.vue` |
| Rotalar | `routes/web.php` → `/orders`, `/orders/{id}` |
| Navigasyon | `Layouts/PanelLayout.vue` → "Siparişler" |
| Testler | `OrderScreenTest` (19) |

**Fazla satış gizlenmez** (§17 · P0): OVERSOLD satırlar uyarıyla
listeleniyor, üst özet kaç siparişin fazla satış içerdiğini söylüyor,
ayrı filtre var.

**Eşleşmemiş SKU AYRI uyarıdır.** `variant_id` NULL olan satırın stoğu
HİÇ düşülmez ve satır PENDING kalır. Bu fazla satıştan farklı bir
sorundur: orada stok düşmüş ve eksik görünür, burada stoğa hiç
dokunulmamıştır ve tablo "her şey yolunda" der — satıcı eşleştirmeyi
yapana kadar bakiye olduğundan fazla görünür. Kendi rozeti, kendi
sayacı, kendi filtresi var.

**Rozet sırası: fazla satış > eşleşmemiş > stok düşüldü.** İkisi aynı
siparişte olabilir; fazla satış satılmış ve stoğu eksiye düşmüş bir
kalemdir ve kargo çıkışı gerçekten tehlikededir.

### 2 · CI 429 — asıl sebep bulundu (commit `6e2217e`)

Önceki tur (`02c0e89`) `COMPOSER_AUTH` ekledi ama CI yine kırmızıydı.

**`COMPOSER_AUTH` bu indirmelere hiç değmiyordu.** `composer.lock`
paketleri `api.github.com/.../zipball` olarak işaretler ve indirme
`codeload.github.com`'a gider; `github-oauth` token'ı Composer'ın
metadata çağrılarını kimliklendirir ama **zip indirmesine değmez**. Yani
token konulduğu hâlde indirmeler anonim gidiyor ve paylaşımlı runner
IP'sinin düşük limitine takılıyordu.

Composer'ın dist URL'lerini başka bir aynaya yönlendiren desteklenen bir
ayarı **yok** — denendi ve konteynerde doğrulandı: `preferred-install`
json biçimini kabul etmiyor, `repos.packagist` yalnızca `repositories`
listesine ekliyor ve codeload'u devre dışı bırakmıyor.

Bu yüzden çözüm kaynak yoluna düşmek:

1. **Yeniden denemede `--prefer-source`** — git clone zip uç noktasına
   hiç dokunmaz ve 429 tam oradadır. Konteynerde doğrulandı: 115 paketin
   tamamı kaynak yoldan kuruldu, exit 0.
2. **Bekleme 60/120 sn'ye çıkarıldı.** 429 saniyelerle değil dakikalarla
   açılır; önceki tur 15/30/45 sn bekledi ve üç deneme de limit
   kapalıyken yapıldığı için üçü de düştü.

Ayrıca **`setup-php` action'ının KENDİSİ** de 429 yiyip işi düşürüyordu
ve bu, kendi adımlarımızdaki retry'ın **ulaşamadığı** yerdi: iş ilk
komutumuz çalışmadan ölüyordu. Adım artık `continue-on-error` ile
işaretli ve `outcome == 'failure'` olduğunda ikinci kez deneniyor.

## Mutasyonla sınandı — sekiz mutasyon, sekizi de yakalandı

Kiracı filtresi (satır sayımı) · kiracı filtresi (özet) · rozet sırası ·
fazla satış bayrağı · eşleşmemiş sayımı · fazla satış filtresi ·
sıralama · ayrıntıda kiracı scope'u.

**İKİSİ İLK TURDA HAYATTA KALDI.** `DB::table()` üzerindeki iki kiracı
filtresi de testsizdi — satır sayımı ve özet AYRI sorgulardır ve
dolayısıyla ayrı boşluklardı. **Bu boşluk artık ÜÇ ayrı turda çıktı.**
Sahte test yazılmadı; gerçek testler eklendi (B kiracısının satırı A'nın
sipariş kimliğine bağlanır — çapraz kiracı sızıntısının gerçek biçimi).

## Testler iki gerçek hata buldu

- **Kanal ilişkisi eager-load edilmiyordu** — lazy loading kapalı,
  istisna fırlatıyordu (50 satırlık listede 50 ek sorgu olurdu).
- **`order_events.type` ENUM'a cast edilir** ve ekrana nesne gidiyordu.
  `->value` ile değeri gönderiliyor. (DEVIR.md'de `sync_operations.intent`
  için aynı tuzak zaten yazılıydı.)

## Tarayıcıda doğrulandı

Üç sipariş kuruldu: temiz · fazla satış · fazla satış + eşleşmemiş SKU
karışık. Liste, iki filtre, arama ve ayrıntı çalışıyor. Karışık sipariş
her iki uyarıyı da gösteriyor ve rozeti FAZLA SATIŞ. Ayrıntıda geçmiş
"Sipariş alındı" (webhook) ve "Fazla satış tespit edildi" (system)
satırlarını taşıyor. **Konsol hatası yok.** Demo verisi sonradan silindi.

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 374 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
npm run build                                 # YERELDE (container'da Node yok)
```

Panel: `/` özet · `/products` ürünler · `/products/{id}/channels` kanala
gönderme · `/orders` siparişler · `/inventory` stok · `/channels` kanallar

## Sıradaki adım — SEÇİM SENİN

1. **Mutabakat panel ekranı** — `reconciliation_runs` /
   `reconciliation_items` yazılıyor ama hiçbir yerde gösterilmiyor.
   Sürüklenme bulunduğunu kullanıcı göremiyor; `recon_items_drift_idx`
   tam bu sorgu için var. Panelde kalan en büyük boşluk artık bu.
2. **Faz 2 · `TrendyolAdapter`** — ikinci kanal. Adapter mimarisi bunun
   için kuruldu; `SupportsCatalog`/`SupportsInventory` sözleşmeleri hazır.
3. **Ilık/soğuk mutabakat katmanları** (§10) — sıcak katman yazıldı; ılık
   (saatlik, 300 listing) ve soğuk (günlük, %2 örneklem) aynı
   `ReconcileConnection`'ı farklı bütçeyle çağırır.
4. **`RequestResync` + T10** — §18'de P1 ve §13 · faz 1.6'da listeli ama
   yazılmadı: `error_permanent → pending` geçişi `ListingResyncRequested`
   üretmeli. Faz 1.6'nın tek kalan eksiği bu (panel maddesi kapandı).

**Abonelik/ödeme Faz 4'tür** (hafta 21–25) — şimdi yazılmamalı. Gerekçe:
kota neyi sınırladığını senkron davranışından alır.

## Bu projede işe yarayan çalışma biçimi

1. **Testi önce yaz, kırmızı olduğunu gör**, sonra implementasyonu yaz.
2. **Mutasyonla sına** — ve **mutasyonun gerçekten uygulandığını
   doğrula**. Python yamalarında `assert old in s` kullan: eşleşmezse
   patlar ve yanlışlıkla "hayatta kaldı" sanmazsın.
3. **Mutasyon hayatta kalırsa SAHTE TEST YAZMA** — ya gerçek test bul, ya
   yapısal sınırı belgele.
4. **`DB::table()` yazdıysan kiracı filtresinin TESTİNİ de yaz.** Bu tur
   dahil ÜÇ turda aynı boşluk çıktı. Birden çok `DB::table()` sorgusu
   varsa **her biri ayrı testtir**.
5. Stok yazan her testin sonunda `assertLedgerMatchesProjection()` çağır.
6. **Entegrasyonu ayrıca sına** — sınıfın var olması onu kimsenin çağırdığı
   anlamına gelmez.
7. **Ekran işi bittiğinde TARAYICIDA çalıştır.**
8. **Kuyruk işi yazdıysan GERÇEK WORKER'DA çalıştır**
   (`queue:work --stop-when-empty`).

## Mutasyonla / gerçek çalıştırmayla bulunan gerçek boşluklar (tarihçe)

Hepsi testler yeşilken bulundu:

- **`DB::table()` kiracı filtresinin testi yoktu** — ÜÇ ayrı turda.
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

- **`AdjustStock` içindeki `LockInventoryRows` çağrısı** — `ApplyMovement`'ın
  UPDATE'i aynı satır kilidini zaten koyuyor.
- **`published_at IS NOT NULL` yüklemi** — NULL karşılaştırması satırı zaten
  eler.
- **`hash_equals` → `===`** — zamanlama saldırısı işlevsel testte görünmez.
- **Adapter'a `max($q, 0)`** — `InventoryPushItem` negatifi kurucuda reddeder.

## Tekrar tekrar ısıran tuzaklar

- **`DB::table()` global scope'a TABİ DEĞİLDİR** — filtre VE testi yazılır.
- **Rota model bağlaması kiracı bağlamından ÖNCE çalışır** —
  `SubstituteBindings` `web` grubunda, `tenant` rota seviyesinde.
  Kimliği `string` al, kontrolcüde ara.
- **ENUM'a cast edilen alan ekrana `->value` ile gider**
  (`order_events.type`, `sync_operations.intent`).
- **Lazy loading KAPALI** — ilişki kullanacaksan eager-load et.
- **Kuyruk işi bağlamı KENDİ kurar** ve `finally` ile bırakır.
- **Ana sınıfta `readonly` promoted property + `SerializesModels` = ölüm.**
- **`inventory_movements` kolonu `type`, `movement_type` DEĞİL.**
- **`channel_connections` kolonu `label`, `name` DEĞİL.**
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
