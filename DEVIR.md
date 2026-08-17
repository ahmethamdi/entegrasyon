# Devir Notu — 17 Ağustos 2026 (Faz 2 başladı · Trendyol istemcisi)

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## Tek cümlede durum

**Faz 1 kapandı, Faz 2 başladı.** Trendyol artık bağlanabiliyor: istemci,
kimlik doğrulama ve dinamik hız sınırı çalışıyor. **397 test yeşil**
(1592 assertion), Pint temiz.

## Bu turda ne eklendi

### 1 · ÜRETİMİ ETKİLEYEN HATA — kimlik bilgisi gönderilmiyordu (`97a7eb7`)

**Bu hata WooCommerce'i de vuruyordu ve testler yeşilken duruyordu.**

`channel_credentials` kiracıya göre kapsanır ve `ChannelHttpClient` bağlam
OLMADAN çağrılabilir: kiracı bağlamını kurmayan bir kuyruk işi, `runAsSystem`
ile koşan bir tarama (seviye 2 kurtarma, mutabakat) veya panelden tetiklenen
sağlık kontrolü. Kapsanmış sorgu o durumda istisna fırlatıyordu, `secrets()`
onu yutuyordu ve istek **sessizce kimliksiz** gidiyordu.

Bedeli en pahalı hata biçimi: kanal 401 döner, adapter bunu `AUTHENTICATION`
diye sınıflandırır, `RetryPolicy` **kalıcı** hata sayar ve listing "anahtarın
yanlış" diyerek ölür — oysa anahtar doğrudur ve yalnızca hiç
gönderilmemiştir. Kullanıcı anahtarı defalarca yeniden girer, hiçbiri işe
yaramaz.

→ Kimlik bilgisi artık açıkça `runAsSystem()` ile okunuyor. Bağlantı zaten
elimizde ve kiracısını kendisi taşıyor; kapsama burada bir şey KORUMUYOR,
yalnızca okumayı engelliyordu. `verifyWebhookSignature` aynı gerekçeyle aynı
biçimi zaten kullanıyordu (§13 · faz 1.4'te bulunmuştu) — bu, **aynı boşluğun
istek yolundaki hâli**.

→ Her iki adapter'a da bağlamsız çağrı testi eklendi.

**Nasıl bulundu:** Trendyol testini yazarken adapter'ı bağlam İÇİNDE kurup
`healthCheck()`'i DIŞINDA çağırdım. Test kırmızı oldu ve sebebi kazınca
Woo'da da aynı davranış çıktı.

### 2 · §13 · Faz 2 · ilk madde — Trendyol istemcisi (`168f8c4`)

"Trendyol istemcisi, kimlik doğrulama, dinamik rate limit profili — 16 sa".

| Ne | Nerede |
|---|---|
| Adapter | `app/Domain/Channels/Adapters/Trendyol/TrendyolAdapter.php` |
| Auth çift adları | `ChannelHttpClient::BASIC_AUTH_KEY_PAIRS` |
| Testler | `TrendyolAdapterTest` (21) |

**Satıcı kimliği hesabın kimliğidir**, mağaza adresi değil. Woo'da hesap
kimliği alan adıdır; Trendyol'da tek API adresi var ve tüm satıcılar onu
paylaşıyor. Alan adı kimlik sayılsaydı her satıcı aynı `external_account_id`
ile çakışır ve `(tenant, type, account)` tekilliği ikinciyi reddederdi.
Kimlik ayrıca **yol üzerinde** taşınıyor (`/suppliers/{id}/...`).

**Hız sınırı yanıt başlığından öğreniliyor ve bağlantıya YAZILIYOR.** Sınır
satıcı seviyesine göre değişiyor; süreçle ölseydi her worker'ın ilk istekleri
daima varsayılanla giderdi. Profili adapter bildiriyor, uygulamayı çekirdek
yapıyor — `ChannelRateLimiter` değişmedi.

**Webhook yok**: `verifyWebhookSignature` her zaman `false`. `true` dönmek
Trendyol adına imzasız sipariş enjekte etmenin kapısını açardı.

**Kapsam dışı gövdeler sessizce başarılı DÖNMÜYOR.** Yetenek arayüzleri
§14'teki sözleşmenin tamamını ilan ediyor ama bu madde yalnızca istemci,
kimlik ve hız sınırını kapsıyor; kalan gövdeler açıkça istisna fırlatıyor.
`AdapterResult::success()` dönselerdi operasyon tamamlandı sanılır,
`synced_version` ilerler ve kanalda hiçbir şey değişmemişken satır "senkron"
görünürdü.

## Mutasyonla sınandı — sekiz mutasyon, ÜÇÜ HAYATTA KALDI

Yakalananlar: `runAsSystem` kaldırma · Trendyol anahtar çiftini listeden
düşürme · satıcı kimliğini yoldan düşürme · sınırı hiç öğrenmeme · webhook
imzasını kabul etme.

**Hayatta kalan üçü için sahte test YAZILMADI, gerçek testler eklendi:**

1. **Öğrenilen sınırın KALICILIĞI sınanmıyordu.** Test Eloquent ile yeniden
   okuyordu ve **kimlik haritası aynı bellek nesnesini geri veriyordu**;
   `save()` silinse bile test yeşil kalıyordu. Artık ham satır
   (`DB::table(...)->value('settings')`) okunuyor.
2. **"Bozuk başlık" testi yalnızca `> 0` doğruluyordu** ve varsayılan da
   bunu sağladığı için mutasyon fark edilmiyordu. `(int) "sınırsız"` zaten
   `0` verdiği için o girdi **yapısal olarak** ayırt edici değildi. Gerçek
   ayrımı yapan girdi bulundu: **`"600, 300"`** — araya giren vekil sunucu
   aynı başlığı iki kez görürse birleştirir, `(int)` sessizce ilk sayıya
   iner ve **düşük** sınır yok sayılırdı. Filtre bu yüzden `ctype_digit`.
3. **Yazılmamış yeteneğin sessizce başarılı dönmesi sınanmıyordu.**

## Yerel HTTPS stub'ıyla uçtan uca doğrulandı

Sahte bir Trendyol `sapigw` sunucusu TLS ile ayağa kaldırıldı ve sertifika
konteynerin CA deposuna kondu — **kural gevşetilmedi, stub'a TLS eklendi.**
Sertifika ve stub sonradan silindi (`ca-certificates.crt` temizliği
doğrulandı).

- **Sağlık kontrolü KİRACI BAĞLAMI OLMADAN geçti** (43 ms) — düzeltilen hata
  gerçek TLS sunucusunda doğrulandı.
- **Stub 1200/dk bildirdi → 20/sn öğrenildi** ve satıra yazıldı
  (`burst_capacity: 40`).
- `api_calls` yazıldı (200, `rate_limit_remaining: 1199`), **sır sızmadı**.
- **Yanlış satıcı kimliği → 403**, **iptal edilmiş kimlik bilgisi → 401**;
  ikisi de sağlıksız, yani bozuk bağlantı `active` olmuyor.
- Stub günlüğü kanıt: `GET /suppliers/998877/addresses AUTH=Basic
  R0VSQ0VLX0FOQUhUQVI6R0VSQ0VLX1NJRlJF`.

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 397 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
npm run build                                 # YERELDE (container'da Node yok)
```

Panel: `/` özet · `/products` ürünler · `/products/{id}/channels` kanala
gönderme · `/orders` siparişler · `/inventory` stok · `/channels` kanallar

## Sıradaki adım — dokümandaki Faz 2 sırası

1. **Taksonomi çekme, önbellekleme, sürümleme** (§13 · Faz 2 · 20 sa) —
   `SupportsTaxonomy` gövdeleri. Kategori/öznitelik eşleştirme arayüzü
   (28 sa) bunun üzerine kurulur ve **katalog aktarımının ön koşuludur**
   (§14 · `PrerequisiteGate`: eksikse listing `blocked`, stok akışı
   etkilenmez).
2. **Stok ve fiyat itme** (16 sa) — çapraz kanal döngüsünün yarısı kapanır.
3. **Sipariş yoklaması** (22 sa) — webhook yok, polling aynı inbox'a yazar.
   Faz 2 demosu bunu ister: "Trendyol siparişi Woo stoğunu düşürüyor ve
   tersi".

Panel tarafında hâlâ açık: **mutabakat panel ekranı** ve **`RequestResync` +
T10** (§18 · P1, faz 1.6'da listeli ama yazılmadı).

**Abonelik/ödeme Faz 4'tür** (hafta 21–25) — şimdi yazılmamalı.

## Bu projede işe yarayan çalışma biçimi

1. **Testi önce yaz, kırmızı olduğunu gör**, sonra implementasyonu yaz.
2. **Mutasyonla sına** — ve **mutasyonun gerçekten uygulandığını doğrula**.
   Python yamalarında `assert old in s` kullan: eşleşmezse patlar ve
   yanlışlıkla "hayatta kaldı" sanmazsın.
3. **Mutasyon hayatta kalırsa SAHTE TEST YAZMA** — ya gerçek test bul, ya
   yapısal sınırı belgele. Bu turda üç mutasyon hayatta kaldı ve üçü de
   gerçek testle kapatıldı.
4. **Kalıcılık sınarken Eloquent'e güvenme** — kimlik haritası aynı bellek
   nesnesini geri verir ve `save()` silinse bile test yeşil kalır. **Ham
   satırı oku.**
5. **`DB::table()` yazdıysan kiracı filtresinin TESTİNİ de yaz.** Birden
   çok sorgu varsa **her biri ayrı testtir**.
6. Stok yazan her testin sonunda `assertLedgerMatchesProjection()` çağır.
7. **Ekran işi bittiğinde TARAYICIDA çalıştır.**
8. **Kuyruk işi yazdıysan GERÇEK WORKER'DA çalıştır.**
9. **Adapter yazdıysan BAĞLAM DIŞINDA çağırmayı da sına** — gerçek kuyruk
   işinin hâli bu ve bu turda üretim hatası tam oradan çıktı.

## Mutasyonla / gerçek çalıştırmayla bulunan gerçek boşluklar (tarihçe)

Hepsi testler yeşilken bulundu:

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

- **`AdjustStock` içindeki `LockInventoryRows` çağrısı** — `ApplyMovement`'ın
  UPDATE'i aynı satır kilidini zaten koyuyor.
- **`published_at IS NOT NULL` yüklemi** — NULL karşılaştırması satırı zaten
  eler.
- **`hash_equals` → `===`** — zamanlama saldırısı işlevsel testte görünmez.
- **Adapter'a `max($q, 0)`** — `InventoryPushItem` negatifi kurucuda reddeder.
- **`ctype_digit` yerine yalnızca `(int)` kontrolü, `"sınırsız"` girdisiyle**
  — `(int) "sınırsız"` zaten `0` ve alttaki `<= 0` kapısı onu eler. Kural
  `"600, 300"` girdisiyle sınanır; o girdi gerçekten ayırt edicidir.

## Tekrar tekrar ısıran tuzaklar

- **Adapter bağlam DIŞINDA çağrılabilir** — kimlik bilgisi `runAsSystem()`
  ile okunur.
- **`DB::table()` global scope'a TABİ DEĞİLDİR** — filtre VE testi yazılır.
- **Kalıcılık testinde Eloquent kimlik haritası yanıltır** — ham satır oku.
- **Rota model bağlaması kiracı bağlamından ÖNCE çalışır** —
  `SubstituteBindings` `web` grubunda, `tenant` rota seviyesinde.
- **ENUM'a cast edilen alan ekrana `->value` ile gider**
  (`order_events.type`, `sync_operations.intent`).
- **Lazy loading KAPALI** — ilişki kullanacaksan eager-load et.
- **Kuyruk işi bağlamı KENDİ kurar** ve `finally` ile bırakır.
- **Ana sınıfta `readonly` promoted property + `SerializesModels` = ölüm.**
- **`inventory_movements` kolonu `type`, `movement_type` DEĞİL.**
- **`channel_connections` kolonu `label`, `name` DEĞİL.**
- **`api_calls` zaman kolonu `called_at`** — `created_at` YOK.
- **`ErrorClass` case'i `RATE_LIMITED`**, `RATE_LIMIT` DEĞİL.
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
