# Devir Notu — 17 Ağustos 2026 (kanal bağlama turu)

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## Tek cümlede durum

Artık **mağaza panelden bağlanabiliyor**: form → kimlik bilgisi kasaya →
sağlık kontrolü → durum yazımı, tarayıcıda uçtan uca doğrulandı. Bir önceki
turda §6 bütünlük taramaları kapanmıştı. **269 test yeşil** (1065 assertion),
Pint temiz, iki random seed'de stabil.

## Bu sohbette ne yapıldı

§13 · faz 1.4 · "WooCommerce bağlantı ekranı ve sağlık kontrolü".

| Ne | Nerede |
|---|---|
| Bağlama action'ı | `app/Domain/Channels/Actions/ConnectChannel.php` |
| Sağlık kontrolü + durum yazımı | `app/Domain/Channels/Actions/CheckChannelHealth.php` |
| Adres normalleştirme | `app/Domain/Channels/Support/StoreUrl.php` |
| Tekillik istisnası | `app/Domain/Channels/Exceptions/AccountAlreadyConnectedException.php` |
| Panel controller | `app/Http/Controllers/ChannelConnectionController.php` |
| Ekranlar | `resources/js/Pages/Channels/{Index,Create}.vue` |
| Rotalar | `routes/web.php` |
| Flash paylaşımı | `app/Http/Middleware/HandleInertiaRequests.php` |
| Action testleri | `tests/Feature/Channels/ConnectChannelTest.php` (9) |
| Ekran testleri | `tests/Feature/Channels/ChannelConnectionScreenTest.php` (12) |

21 yeni test (248 → 269).

## Akışın özü

```
Form (kanal · etiket · adres · consumer key/secret)
  → StoreUrl::parse  → host küçük harf, şema/eğik çizgi atılır, https zorlanır
  → guardAgainstForeignTenant  → mağaza başka kiracıdaysa AccountAlreadyConnected
  → TRANSACTION: firstOrNew(type, account) + CredentialVault::store
  → COMMIT
  → CheckChannelHealth (transaction DIŞINDA — ağ çağrısı)
       sağlıklı  → status = active,  health = healthy,  last_error = null
       sağlıksız → status = pending, health = unhealthy, last_error = mesaj
  → /channels · success veya warning flash
```

**Sağlık kontrolü geçmeden bağlantı aktif olmaz.** Kimlik bilgisi yine
saklanır (çağrıyı yapabilmek için zorunlu ve kullanıcı mağazası geçici kapalı
olabilir) ama `pending` durumda senkron çalışmaz. Aktif ama çalışmayan
bağlantı en pahalı hata biçimidir: kullanıcı ürün göndermeye başlar, hepsi
AUTHENTICATION ile kalıcı hataya düşer ve sebep ancak destek kaydıyla bulunur.

## Tarayıcıda doğrulandı

Kayıt → `/channels` (boş durum) → `/channels/create` → var olmayan mağaza ile
gönderim → `/channels` üzerinde:

- amber uyarı: "kaydedildi ama kanal cevap vermedi — bağlantı beklemede"
- kırmızı `CEVAP VERMİYOR` rozeti, `Durum: pending`
- cURL hata metni kartta görünür (gizlenmiyor)
- yetenekler tip sisteminden: `Ürün · Stok · Fiyat · Sipariş · Kargo`
  (Kategori ve Onay YOK — Woo onları desteklemiyor)
- **sırlar sayfada yok**, konsol hatası yok

## Mutasyonla sınandı — altısı da öldü

| Mutasyon | Öldüren test |
|---|---|
| Sağlıksızken de `status = active` | 4 test |
| Çapraz kiracı koruması `if (false)` | `the_same_store_cannot_be_connected_by_two_tenants` |
| Sırlar `settings` kolonuna da yazılıyor | `secrets_never_land_in_the_settings_column` |
| Inertia'ya `$c->toArray()` gönderiliyor | 2 test (yetenekler + sır sızıntısı) |
| `strtolower($host)` kaldırıldı | `store_url_is_normalised_before_uniqueness_is_checked` |
| Health rotası `runAsSystem()` ile sorguluyor | `health_check_cannot_target_another_tenants_connection` |

## Bu turda öğrenilen iki şey

**1. Sessiz `catch` beni kendi hatamdan habersiz bıraktı.**
`capabilitiesOrEmpty()` içindeki `catch (Throwable) { return []; }`,
`with('channelType:code,name')` yazdığım için `adapter_class`'ın yüklenmediğini
ve `AdapterRegistry`'nin patladığını gizledi. Test "Undefined array key" ile
düştü ama sebebi görünmüyordu. Catch artık `Log::warning` yazıyor — koruma
olarak kalıyor, hata gizleyici olarak değil.

**Eager-load kuralı**: yetenek okunacaksa `adapter_class` DA seçilmeli.

**2. Dev veritabanı seed'i bayattı.** Tarayıcı turunda açılır listede Trendyol
göründü; oysa seeder onu `is_active = false` ve yazılmamış
`Trendyol\TrendyolAdapter` ile tanımlıyor. Satır eski bir seeder sürümünden
kalmıştı (`created_at == updated_at`, yani sonradan değiştirilmemiş).
`php artisan db:seed --class=ChannelTypeSeeder` düzeltti. Kod doğruydu.

**Ders**: tarayıcıda gördüğün tuhaflığın kaynağı kod olmayabilir; dev verisinin
tazeliğini de doğrula.

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 269 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
npm run build                                 # YERELDE (container'da Node yok)
docker compose exec app php artisan db:seed --class=ChannelTypeSeeder
```

Panel: `http://localhost:8080/` · Kanallar: `http://localhost:8080/channels`

Tarayıcı doğrulaması için Playwright scratchpad'e kuruldu (`npm i playwright`);
projeye bağımlılık EKLENMEDİ.

## Zamanlanmış işler — §15 tablosu

| Komut | Frekans | Ne kurtarır |
|---|---|---|
| `inbox:recover` | 1 dakika | Kuyruğa hiç girmemiş webhook — **kaybedilen şey SİPARİŞ** |
| `sync:detect-stuck` | 5 dakika | Seviye 2: Redis `PushInventory`'yi düşürdü |
| `outbox:detect-unconsumed` | 10 dakika | Seviye 1: tüketici hiç çalışmadı |

`outbox:relay` **bu listede yok ve olmamalı** — supervisor altında sürekli
çalışan bir süreç.

## Sıradaki adım — SEÇİM SENİN

1. **Ürün/stok listesi ekranı** — satıcının her gün bakacağı ekran; fazla
   satış uyarısı ve senkron rozeti (§13 · faz 1.2 ve 1.5 panel maddeleri).
   Artık gerçek mağaza bağlanabildiği için gerçek veriyle beslenebilir.
2. **`PushListing` işi + panelden gönderme akışı** (§13 · faz 1.5) — faz
   1.4'ün açık kalan tek ucu. Bağlama ve sağlık kontrolü tamam; ürünü kanala
   gönderen iş henüz yok.
3. **§10 mutabakat** — sürüklenme tespiti; `clock_timestamp()` kuralına tabi
   ve karşılaştırma `max(available, 0)` ile yapılır.

## Bu projede işe yarayan çalışma biçimi

1. **Testi önce yaz, kırmızı olduğunu gör**, sonra implementasyonu yaz.
2. **Mutasyonla sına**: kodu kasten boz, testin kırmızıya döndüğünü doğrula.
3. Stok yazan her testin sonunda `assertLedgerMatchesProjection()` çağır.
4. **Entegrasyonu ayrıca sına** — sınıfın var olması onu kimsenin çağırdığı
   anlamına gelmez (`ScheduledScansTest` geçen turda tam bunu buldu).
5. **Uçtan uca test yaz** — parçalar tek tek doğruyken aralarındaki sözleşme
   yanlış olabilir (`LostWorkRecoveryTest`).
6. **Ekran işi bittiğinde TARAYICIDA çalıştır.** Bu turda iki bulgu yalnızca
   orada göründü; testler ikisini de görmüyordu.

## Mutasyonla bulunan gerçek boşluklar (tarihçe)

Hepsi testler yeşilken bulundu:

- **Eager-load'da `adapter_class` seçilmiyordu** (bu tur) — panel yetenekleri
  sessizce boş kalıyordu ve `catch` sebebi gizliyordu.
- **Kurtarma taramaları zamanlayıcıya bağlanmamıştı** — `inbox:recover` bir
  tur boyunca hiç çalışmadı.
- **`ApplyMovement` outbox yüküne `origin_connection_id` yazmıyordu** —
  fan-out'un yankı bastırması üretimde hiç çalışmıyordu.
- **`verifyWebhookSignature` kiracı bağlamı bekliyordu** — meşru her webhook
  sessizce reddedilirdi.
- **Başarıda sürüm kapısı yoktu** — bayat başarı `synced_version`'ı geri sarıyordu.
- **Bağlantı filtresi testi aslında tenant scope'u sınıyordu.**

## Davranışla sınanamayan kurallar (dürüst sınır)

Mutasyon hayatta kalır ve kalmalı; sahte test YAZILMADI:

- **`published_at IS NOT NULL` yüklemi**: NULL karşılaştırması satırı zaten
  eler. Cümleyi doküman ve `outbox_unconsumed_idx` eşleşmesi için tutuyoruz.
- **`hash_equals` → `===`**: zamanlama saldırısı işlevsel testte görünmez.
- **Adapter'a `max($q, 0)`**: `InventoryPushItem` negatifi kurucuda reddettiği
  için ikinci kırpma her zaman işlemsizdir.
- **`regenerate()` çağrısı**: `SessionGuard::login()` zaten çağırıyor; ikinci
  çağrı **kaldırıldı**.

## Tekrar tekrar ısıran tuzaklar

- **`clock_timestamp()`** — zaman damgaları saniye hassasiyetli, `now()`
  transaction başında donar. Pencere testi: `pg_sleep(1.1)`, sonra damgayı
  `date_trunc('second', now())` ile donmuş `now()`'a **eşit** yaz, eşiği bir
  saniye ver.
- **`Command::run()` REZERVE İMZADIR.** Tarama/iş mantığı `Support/` altında
  sade sınıfta, komut ince kabuk (`OutboxRelay` / `OutboxRelayCommand`).
- **Domain komutları otomatik keşfedilmez** — `bootstrap/app.php` →
  `withCommands()` içinde açık kayıt gerekir.
- **`QUEUE_CONNECTION=sync` gerçek worker'ı taklit etmez.** Planlamayı sınayan
  testler `Queue::fake()` çağırır.
- **Seviye 2 taraması damgalamadığı için TÜKENMEZ**: ardışık iki tur aynı
  satırları döner. Bilinçli — damgalamak `attempt_count = 0` imzasını yok eder.
- **Tarayıcı testinde `networkidle` yetmeyebilir.** Var olmayan alan adının DNS
  denemesi uzun sürer; yönlendirme `waitForURL(..., { timeout: 90000 })` ile
  beklenmeli. İlk turda "gönderim başarısız" sandım, oysa yalnızca yavaştı.
- **Açılış stoğu ledger üzerinden girer** (IMPORT) ve o hareket de outbox olayı
  yazar. Olayı `movement_type` ile hedefle.
- **Eşzamanlılık testi `RefreshDatabase` ile yazılamaz** → `DatabaseTruncation`
  + ayrı PDO; `tearDown`'da `truncateDatabaseTables()`.
- **CI'da `public/build` yoktur** — `Tests` job'ı `npm run build` çalıştırır.

## Bilinen açık uç

Eski turlarda bir `--order-by=random` turunda tek test düşmüştü; son iki turda
üç seed daha denendi ve tekrar üretilemedi. Görülürse seed ile kaydedilmeli.
