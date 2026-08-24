# Güvenlik Kontrol Listesi

**Kaynak:** Mimari Karar Dokümanı v2.2 · §11 · "Minimum production kontrol
listesi". Liste **dokümandan türetilmiştir**, sıfırdan uydurulmamıştır.

Son denetim: **24 Ağustos 2026** · 871 test yeşil.

Bu belge iki şeyi ayırır: **testle korunan** maddeler (kod değişirse
kırmızıya döner) ve **sunucu kurulumunda elle yapılacak** maddeler (kod
onları zorlayamaz). İkincisi, sunucu kurulmadan kapatılamaz ve bunu
belirtmek bir eksiklik itirafı değil, kapsamın dürüst çizilmesidir.

---

## Özet

| # | §11 maddesi | Durum |
|---|---|---|
| 1 | APP_KEY iki ayrı yerde yedeklendi | ⬜ SUNUCU |
| 2 | Tüm kimlik bilgileri şifreli; düz metin taraması temiz | ✅ TESTLİ |
| 3 | Her webhook rotasında HMAC doğrulaması var ve test edilmiş | ✅ TESTLİ |
| 4 | Kiracı izolasyon testi her model için yazıldı | 🟡 KISMİ |
| 5 | Worker bağlam sızıntısı testi yeşil (P0) | ✅ TESTLİ |
| 6 | Adapter paylaşımsızlık testi yeşil (P0) | ✅ TESTLİ |
| 7 | Günlüklerde sır ve kişisel veri taraması temiz (iki katman) | ✅ TESTLİ |
| 8 | HTTPS zorunlu, HSTS açık | 🟡 KOD ✅ / NGINX ⬜ |
| 9 | PostgreSQL dışarıdan erişilemez | ⬜ SUNUCU |
| 10 | Redis parola korumalı, dışarıdan erişilemez | ⬜ SUNUCU |
| 11 | Yedekler alınıyor VE geri yükleme en az bir kez test edildi | ✅ PROVA YAPILDI |
| 12 | Bağımlılık güvenlik taraması CI'da | ✅ CI'DA |
| 13 | Yönetici hesaplarında iki faktörlü doğrulama | ⬜ YAZILMADI |

Ek olarak §11'in webhook güvenliği tablosu ve denetim kaydı maddesi de
bu turda kapatıldı (aşağıda).

---

## ✅ Testle korunan maddeler

Bu maddeler **koda gömülü ve testle korunuyor**. Kontrol listesi onları
yeniden yazmaz, **doğrular**.

### 2 · Kimlik bilgileri şifreli

- `CredentialVault` — Laravel `Crypt` üzerine ince sarmalayıcı, **özel
  kripto YOK** (§11'in referans uygulamasıyla birebir). Anahtar rotasyonu
  `APP_PREVIOUS_KEYS` ile çerçeveye bırakılır; `key_version` yalnızca
  "hangi kayıt henüz yenilenmedi" sorusu için tutulur, çözme
  yönlendirmesinde **kullanılmaz**.
- Sırlar `channel_connections.settings` jsonb'sine **yazılmaz** — orası
  şifrelenmemiştir ve panele olduğu gibi gider.
  → `ConnectChannelTest::secrets_never_land_in_the_settings_column`
- Doğrulama hatasında sır **oturuma flash edilmez**
  (`bootstrap/app.php` → `dontFlash`).
  → `TransportSecurityTest::validation_errors_never_flash_channel_secrets`

### 3 · Webhook HMAC

- İmza **ham gövde üzerinden**, JSON ayrıştırmadan **önce**; sabit zamanlı
  karşılaştırma (`hash_equals`).
- Webhook rotaları `web` grubunda **değil** (CSRF muaf, oturumsuz);
  muafiyetin bedeli imza doğrulamasıyla ödenir ve o **zorunludur**.
- Kanal webhook'u: `InboundPipelineTest` · Stripe: `StripeWebhookTest`.

**§11'in webhook güvenliği tablosu — satır satır:**

| Kontrol | Uygulama | Durum |
|---|---|---|
| Gövde boyutu | nginx `client_max_body_size 1m` | ✅ |
| İçerik tipi | Beklenmeyen tip → **415**, ayrıştırma yok | ✅ |
| HMAC doğrulama | Ham gövde, `hash_equals` | ✅ |
| Tekrar (kimlikli) | `external_event_id` tekillik kısıtı | ✅ |
| Tekrar (kimliksiz) | `payload_hash` + saatlik pencere | ✅ |
| Bilinmeyen bağlantı | **404**, varlık bilgisi sızmaz | ✅ |
| CSRF muafiyeti | Rota `api` grubunda, imza zorunlu | ✅ |
| Rate limit | **Bağlantı başına dakikada 600** → 429 | ✅ |

> **415 ve 429 neden "her durumda 202" kuralını ihlal etmez:** o kural
> TANIDIĞIMIZ bir mesajın işlenmesiyle ilgilidir ve kanalın gereksiz
> yeniden göndermesini önler. Yanlış içerik tipi kanalın YAPILANDIRMA
> hatasıdır; sınır aşımı ise "yavaşla ve TEKRAR GÖNDER" demektir. İkisinde
> de 2xx dönmek mesajı **sessizce kaybettirirdi**.

### 5 · Worker bağlam sızıntısı (P0)

`Queue::looping` + `JobProcessing/Processed/Failed` kancaları her iş
sınırında bağlamı temizler; bağlam yokken tenant-scoped sorgu **istisna
fırlatır**, sessizce veri döndürmez.
→ `QueueTenantContextLeakTest` (5 test)

### 6 · Adapter paylaşımsızlığı (P0)

`AdapterRegistry::for()` her çağrıda **yeni örnek** üretir; container'da
`bind`, **asla `singleton`**. Gerekçe güvenlik: adapter bağlantı taşır ve
paylaşılan örnek kiracı A'nın kimlik bilgisini kiracı B'nin işinde
kullanırdı.
→ `AdapterIsolationTest`

### 7 · İki katmanlı maskeleme

- **Katman 1** anahtar adına göre (yapı korunur), **katman 2** bilinen sır
  değerlerini gövdenin herhangi bir yerinde arar. §11'in gerekçesi:
  kimlik bilgisi bir hata mesajının **içinde** düz metin geçebilir
  (`"Invalid API key: abc123..."`) ve katman 1 bunu yakalayamaz.
- Uygulandığı yerler: `api_calls` (istek/yanıt/URL),
  `channel_connections.last_error`, `sync_attempts.error_message`,
  `listing_sync_states.last_error`, `audit_logs.changes`.
- → `PayloadRedactorTest` (12 test, §11'in beş vakası)
  · `ErrorMessageRedactionTest` (3 test)

> **Bu turda kapatılan gerçek sızıntı:** iki katman YALNIZCA `api_calls`
> yolunda uygulanmıştı. Kalıcı hata kolonları ham `$e->getMessage()`
> yazıyordu ve Laravel'in `RequestException` mesajı **yanıt gövdesinin ilk
> 120 karakterini** gömer. Kanal 401 gövdesinde anahtarı yansıtırsa sır
> şu zinciri izliyordu: kanal gövdesi → exception → `last_error` kolonu →
> Inertia prop → **tarayıcı**. Çözüm `ChannelErrorText` (tek kaynak).

### Denetim kaydı (§11)

`audit_logs` tablosu §4 şemasıyla yazıldı. §11 kapsamı **dar** tutar:
"Her satır değişikliğini kaydetmek gereksiz; bu altı olay anlaşmazlık
çıktığında sorulan sorular."

| §11 olayı | Durum |
|---|---|
| Kanal bağlantısı ekleme | ✅ `channel.connected` |
| Kimlik bilgisi güncelleme | ✅ `channel.credential_updated` (AYRI olay) |
| Elle stok düzeltme | ✅ `stock.adjusted` |
| Kullanıcı davet ve rol değişimi | ⬜ o akış **yazılmadı** |
| Fiyat çakışması kararı | ⬜ o akış **yazılmadı** |
| Kanal bağlantısı silme | ⬜ silme yolu **yok** (bağlantı silinmez, işaretlenir) |

Yazılmayan üç olay için **uydurma enum değeri eklenmedi**: hiçbir yerden
yazılmayan bir değer, denetim ekranında var olmayan bir olayı varmış gibi
gösterirdi.
→ `AuditLogTest` (7 test)

---

## 🟡 Kısmi maddeler

### 4 · Kiracı izolasyon testi "her model için"

**Durum: 30 kiracıya ait modelin 18'inde doğrudan izolasyon testi var,
5'inde dolaylı, 6'sında yok.** §11'in "her model" ifadesi bugün tam
karşılanmıyor ve bu **bilinçli olarak açık bırakılıyor** — testi olmayan
altı model (`ProductImage`, `VariantOption`, `OrderEvent`, `Fulfillment`,
`InboxMessage`, `ReconciliationRun`) hiçbiri panelde doğrudan sorgulanan
bir yüzey değil.

**Bu turda kapatılan GERÇEK açık:** `ProcessInboxMessage`'ın koşullu
geçişinde kiracı filtresi yoktu. `DB::table()` Eloquent global scope'una
**tabi değildir**; yanlış eşleşmiş bir çift başka kiracının inbox satırını
`processing` yapıyor, ardından gelen kapsamlı `find()` satırı bulamıyor ve
iş sessizce çıkıyordu. Satır artık `pending` olmadığı için `inbox:recover`
de toplamıyordu — **o sipariş hiç işlenmiyordu.**
→ `InboundPipelineTest::conditional_transition_never_claims_another_tenants_message`

> **Kural (beşinci kez doğrulandı):** `DB::table()` her kullanıldığında
> **filtre DE testi DE** yazılır. Filtreyi yazmak yetmez; mutasyonla
> silindiğinde test kırılmıyorsa koruma yok demektir.

### 8 · HTTPS zorunlu, HSTS açık

- **Kod tarafı ✅** — `AppServiceProvider` üretimde `URL::forceScheme('https')`
  uygular. Yerelde uygulanmaz (koşulsuz olsaydı yerel panel kırılırdı) ve
  test **iki yönü de** sınar.
  → `TransportSecurityTest`
- **HSTS ⬜ SUNUCU** — bilinçli olarak nginx'e bırakıldı, uygulamaya
  konmadı. Gerekçe: başlığı uygulama katmanından göndermek, uygulamanın
  **hiç cevap veremediği** durumlarda (500, bakım modu, PHP-FPM ölü)
  başlığın da gitmemesi demektir. HSTS'in tüm değeri
  **kesintisizliğindedir**.

**Üretim vhost'una eklenecek satır:**

```nginx
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```

Yerel vhost'a **konmadı** ve konmamalı: `localhost`'a HSTS göndermek
tarayıcıya "bu adrese bir daha asla HTTP ile bağlanma" dedirtir, kayıt
kalıcıdır ve geliştiricinin **diğer localhost projelerini de kırar**
(geri alması: `chrome://net-internals/#hsts`).

Yerel vhost'ta zaten aktif olan, şemadan bağımsız başlıklar:
`X-Content-Type-Options: nosniff` · `X-Frame-Options: SAMEORIGIN` ·
`Referrer-Policy: strict-origin-when-cross-origin`.

**Oturum çerezi:** `.env.example` üç ayarı belgeler. Üretimde
`SESSION_SECURE_COOKIE=true` **zorunludur**; yerelde `false` (aksi halde
`http://localhost:8080` üzerinde oturum hiç kurulamaz ve giriş sonsuz
döngüye girer).

---

## ⬜ Sunucu kurulumunda yapılacaklar

Bunlar **kod tarafından zorlanamaz** ve sunucu kurulmadan kapatılamaz.

### 1 · APP_KEY iki ayrı yerde yedeklendi

> §11: *"APP_KEY kaybedilirse tüm kanal bağlantıları geri döndürülemez
> şekilde ölür ve her müşteri yeniden yetkilendirme yapmak zorunda kalır.
> İlk gün yapılacak beş dakikalık iş."*

- [ ] Parola yöneticisine kaydedildi
- [ ] Çevrimdışı kopya alındı (sunucu yedeğinden **bağımsız**)

Sunucu yedeğine güvenmek **yetmez**: yedek ve anahtar aynı yerde
duruyorsa ikisini birden kaybetmek tek bir olaydır.

### 9 · PostgreSQL dışarıdan erişilemez

- [ ] `listen_addresses = 'localhost'` (§15: yerel soket, aynı makine)
- [ ] Güvenlik duvarında 5432 kapalı
- [ ] `pg_hba.conf` yalnızca yerel bağlantılara izin veriyor

**Yerelde durum:** port `5433` host'a açık — geliştirme için gerekli,
üretim yapılandırmasıyla **karıştırılmamalı**.

### 10 · Redis parola korumalı, dışarıdan erişilemez

- [ ] `requirepass` tanımlı, `.env` → `REDIS_PASSWORD` yazılı
- [ ] `bind 127.0.0.1`, güvenlik duvarında 6379 kapalı

**Yerelde durum:** parola yok, port `6380` host'a açık.

**Zaten doğru olan (§15 · atlanırsa iş kaybı):**
`appendonly yes` ✅ · `maxmemory-policy noeviction` ✅ —
`docker-compose.yml` içinde tanımlı. Varsayılan `allkeys-lru` politikası
bellek baskısı altında **kuyruk işlerini sessizce siler**.

### 13 · Yönetici hesaplarında iki faktörlü doğrulama

**Durum: YAZILMADI.** `users.two_factor_secret` kolonu Laravel
iskeletinden geliyor ama 2FA akışı yok. Bu **ayrı bir özelliktir** ve bu
maddenin (12 sa) kapsamına sığmaz; dokümanın §13 listesinde de kendi
satırı yoktur.

---

## ✅ 11 · Yedek ve geri yükleme provası

**Prova YAPILDI (24 Ağustos 2026).** Ayrıntı ve prosedür:
[`YEDEK-GERI-YUKLEME.md`](YEDEK-GERI-YUKLEME.md).

§11 "en az bir kez test edildi" diyor — yazılı prosedür bunu
**karşılamaz**, gerçek bir geri yükleme gerekir ve yapıldı.

---

## ✅ 12 · Bağımlılık güvenlik taraması CI'da

`.github/workflows/ci.yml` → `security` job'ı:

- `composer audit --locked --no-scripts`
- `npm audit --omit=dev --audit-level=high`

**Ayrı job olmasının sebebi:** tarama, testlerin geçip geçmemesinden
**bağımsız** bir sinyaldir. `tests` içine gömülseydi kırmızı bir test
taramayı hiç çalıştırmazdı — oysa güvenlik açığı tam da acele düzeltme
yapılan günlerde önemlidir.

**429 sorunu yok:** `composer audit` yalnızca `composer.lock`'ı okur ve
advisory API'sine tek istek atar; paket **indirmez**. Diğer job'ları
düşüren `codeload` yolu buraya hiç uğramaz.

**Son durum (24 Ağustos 2026):** ikisi de temiz — 0 açık.

`--audit-level=high` bilinçlidir: `low`/`moderate` geçişli uyarılar npm
ekosisteminde sürekli akar ve her biri CI'ı kırsaydı ekip taramayı
**kapatırdı**.

---

## Yük testi

§11'in "yük testi" maddesi `loadtest:sync` komutuyla karşılanıyor.
Ölçülen şey HTTP değil **senkron hattıdır** — gerekçe ve son ölçüm için
`app/Support/LoadTest/SyncPipelineLoadTest.php` sınıf başlığına bakın.

```bash
docker compose exec app php artisan loadtest:sync \
    --tenants=5 --variants=40 --movements=1000
```

**Son ölçüm (24 Ağustos 2026 · yerel Docker · MacBook):**

| Aşama | Ölçüm |
|---|---|
| Ledger | 523.8 hareket/sn · p50 1.65 ms · p95 2.84 ms · p99 4.67 ms |
| Relay | 339.2 olay/sn · kuyruk tepe 1000 · yayın gecikmesi p95 4 sn |
| Fan-out | 1000 olay → 1000 operasyon · oran 1.0 |
| **Bütünlük** | **`on_hand = Σ on_hand_delta` KORUNDU** |

Komut bütünlük bozuksa **`FAILURE` döner**: hız bir günlük satırıdır,
bütünlük ürünün temel iddiasıdır.

> **Ölçüm alırken:** konteynerde artık `outbox:relay` süreci olmadığından
> emin olun (`ps aux | grep outbox`). Arka planda çalışan bir relay
> kuyruğu sürekli eritir ve yayın ölçümleri anlamsız çıkar — bu turda
> gerçekten oldu.

---

## Bu turda bulunan üç gerçek açık

Hepsi **871 test yeşilken** duruyordu.

1. **Çapraz kiracı UPDATE** (`ProcessInboxMessage`) — başka kiracının
   siparişini kalıcı olarak işlenemez hale getiriyordu.
2. **Hata metni sır sızdırıyordu** — kanal anahtarı veritabanına ve
   panele düz metin yazılabiliyordu.
3. **Doğrulama hatası sırrı oturuma flash ediyordu** — anahtar,
   şifresiz bir oturum tablosuna düşüyordu.

Üçü de artık testle korunuyor ve üçü de mutasyonla doğrulandı.
