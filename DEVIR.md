# Devir Notu — 21 Ağustos 2026 (FAZ 4 SÜRÜYOR — panel cilası başladı)

Kod tarafında yarım iş YOK; çalışma ağacı temiz. Son commit `aba0a29`
(+ bu devir notu commit'i). **FAZ 3 KAPALI** ve **FAZ 4 SÜRÜYOR**.
Bu turda **panel cilası maddesinin ilk turu** yazıldı (`aba0a29`):
mobil düzen ve bekleme durumları. Faz durumu iddiası devir notundan
değil **dokümanın §13 listesinden** doğrulanır.

## ⚠️ ÖNCE BUNU OKU — `.env`'DE CANLI STRIPE ANAHTARLARI VAR

**21 Ağustos'ta `.env`'e `pk_live_` / `sk_live_` yazılmış durumda** —
devir notunun ısrarla uyardığı TEST anahtarları DEĞİL. Kullanıcının
Stripe hesabı CANLI ve gerçek ciro taşıyor.

**SONUÇLARI:**
- `/billing` düğmeleri artık ETKİN (`stripeConfigured()` anahtarı
  görüyor) — yani ekran "ödeme yapılandırılmadı" DEMİYOR.
- Canlı anahtarla checkout açmak GERÇEK kart / GERÇEK para demektir ve
  test kartı `4242 4242 4242 4242` **çalışmaz**.
- `STRIPE_WEBHOOK_SECRET` hâlâ yer tutucu (`whsec_...`), yani webhook
  imzası doğrulanamaz ve abonelik YAZILAMAZ.

**BU TURDA NE OLDU:** panel cilasını doğrularken çift tıklama korumasını
sınamak için `/billing/checkout`'a istek gitti ve **canlı hesapta BİR
checkout oturumu açıldı**. Kart girilmedi, **ücret çekilmedi**;
`subscriptions` tablosu BOŞ (0 satır) ve kullanılmayan oturumların
süresi Stripe tarafından kendiliğinden dolar. Yine de bilinerek
yapılmadı — bu yüzden burada yazılı.

**SIRADAKİ OTURUM İÇİN KURAL: `/billing` üzerinde tarayıcı doğrulaması
YAPMA** (anahtarlar test moduna çevrilene kadar). Test moduna geçiş:
`https://dashboard.stripe.com/test/apikeys` — URL'deki `/test/` parçası
zorunludur.

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## Yerel demo hesabı

`panel@example.com` · parola **`devpassword`** (bu turda tarayıcı
doğrulaması için atandı, kullanıcı onayıyla böyle bırakıldı). Yalnızca
yerel geliştirme veritabanında.

## BURADAN DEVAM ET

```bash
docker compose up -d
docker compose exec app php artisan test      # 829 yeşil olmalı
```

### ⏭ SIRADAKİ İŞ — STRIPE'I GERÇEK ANAHTARLA SÜRMEK (ERTELENDİ)

**DURUM (21 Ağustos 2026): kullanıcı Stripe'a SONRA dönmeye karar
verdi.** Anahtarlar `.env`'e YAZILMADI ve akış sürülmedi. Kod hazır ve
28 testle korunuyor; eksik olan yalnızca anahtarlar. Bu madde bir
BLOKAJ DEĞİLDİR — panel cilası ve diğer Faz 4 maddeleri Stripe
beklemeden yapılabilir.

**Stripe CLI KURULDU** (`brew`, sürüm 1.50.3) ama `stripe login`
YAPILMADI — o adım tarayıcı ister ve kullanıcının yapması gerekir.

**⚠️ EN KRİTİK UYARI — ÖNCE TEST MODUNA GEÇ.** Kullanıcının Stripe
hesabı CANLI ve gerçek ciro taşıyor (21 Ağustos ekran görüntüsünde
€713.71 görüldü). Canlı anahtarla (`pk_live_` / `sk_live_`)
çalışılırsa **gerçek karttan gerçek para çekilir** ve test kartı
`4242...` da çalışmaz. Test moduna geçmenin en garantili yolu adres
çubuğudur:

```
https://dashboard.stripe.com/test/apikeys
```

URL'deki **`/test/`** parçası zorunludur. Sağ üstteki "Test mode"
anahtarı da aynı işi görür ama arayüz sürümüne göre yeri değişiyor.

**Kullanıcının yapacakları (sırayla):**

```bash
stripe login                                              # tarayıcı açar
stripe listen --forward-to localhost:8080/webhooks/stripe # AÇIK KALMALI
```

İkinci komut ekrana `whsec_...` yazar. Sonra `.env`'e üç satır:

```
STRIPE_KEY=pk_test_...          # /test/apikeys → Publishable key
STRIPE_SECRET=sk_test_...       # aynı sayfa → Secret key ("Reveal")
STRIPE_WEBHOOK_SECRET=whsec_... # `stripe listen` çıktısından
```

**`whsec_` API KEYS SAYFASINDA YOKTUR** — kullanıcı 21 Ağustos'ta onu
orada aradı ve bulamadı. O sır ancak bir webhook uç noktası
oluşturulunca doğar: yerelde `stripe listen` komutu verir, sunucuda
Developers → Webhooks → Add endpoint sonrası "Signing secret".

**ANAHTAR SOHBETE YAZILMAZ, KODA GÖMÜLMEZ** — kullanıcı `.env`'e
yazar, kod `config()` ile okur.

**Anahtarlar yazılınca yapılacaklar:**

1. `docker compose exec app php artisan config:clear`
2. Tarayıcıda `/billing` → "Bu plana geç" (düğmeler artık etkin)
3. Stripe ödeme sayfası → test kartı `4242 4242 4242 4242`
   (tarih: gelecekte herhangi biri, CVC: herhangi 3 hane)
4. **Kontrol edilecekler:** webhook geldi mi (`stripe listen`
   terminalinde görünür) · `subscriptions` satırı `active` mi ·
   `tenants.plan_code` güncellendi mi · `/billing` yeni planı
   gösteriyor mu · **kota yükseldi mi** (demo kiracısı 2/1 kanalla
   kırmızıydı; `pro` planda 2/5 olmalı)
5. Plan yükseltmeyi de sına: ikinci kez satın al → **eski abonelik
   `cancelled`, yenisi `active`** olmalı ve `UNIQUE(tenant_id) WHERE
   aktif` kısıtı İHLAL EDİLMEMELİ

**DOĞRULAMA DURUMU (20 Ağustos, anahtarsız):** webhook uç noktası
imzasız istekte 500 + `stripe.webhook_secret_missing` günlüğü veriyor —
bu DOĞRU davranış (sır yokken doğrulama yapılamaz, kabul edilmez) ve
**419 CSRF DEĞİL**, yani rota doğru şekilde muaf. Rotalar kayıtlı,
`APP_URL=http://localhost:8080` doğru.

**GERÇEK ÇALIŞTIRMA KURALI BURADA DA GEÇERLİ** — bu projede her turda
gerçek bir hata buldu ve Stripe'ta da bulması beklenir.

Sonra: **panel cilası** (Faz 4'ün sıradaki maddesi, 20 sa).

### AÇIK UÇ — PROJE İSMİ HENÜZ SEÇİLMEDİ

Kullanıcı isim arıyor ve **bulunca haber verecek**. Sekiz tur öneri
yapıldı, hiçbiri tutmadı; `kanalca.com` boş çıktı ama beğenilmedi,
`voltexio` elde ama elektrik çağrışımı nedeniyle elendi.

**İSİM KODU ETKİLEMİYOR:** namespace `App\...` ve görünen ad
`VITE_APP_NAME`'den okunuyor. İsim yalnızca **8 dosyada** geçiyor
(`config/entegrasyon.php` · `resources/js/app.js` · `PanelLayout.vue` ·
`Login.vue` · `Register.vue` · `app.blade.php` ·
`MetricAlertMail.php` · `mail/metric-alert.blade.php`) ve çoğu yorum
veya görünen metin. **Değiştirmek ~yarım saat.**

**İsim Stripe'ı veya başka bir işi BEKLETMEZ.**

### KULLANICI KARARI — ÖDEME STRIPE İLE (20 Ağustos 2026)

**Abonelik ve ödeme STRIPE üzerinden yazılacak, iyzico ile DEĞİL.**
Doküman §13 · Faz 4 "iyzico" diyor; bu **bilinçli ve kullanıcı onaylı
bir sapmadır** ("kod ile doküman çeliştiğinde doküman esastır"
kuralının istisnası — sapmayı kullanıcının kendisi istedi).

Şema kararı DEĞİŞMEZ: `tenants.plan_code` zaten var, §4 · `plans`
(kiracısız + seed), `subscriptions` `UNIQUE(tenant_id) WHERE
status='active'`, §3 · `Plan` · `Subscription` · `UsageRecord`.
Sağlayıcıya özgü kimlikler (`stripe_customer_id`,
`stripe_subscription_id`) TAHSİLAT katmanında yaşar, çekirdek kota
mantığında değil. Laravel Cashier (`laravel/cashier`) Stripe'ın resmî
paketi — değerlendirilebilir.

Webhook alımında projenin **gelen hat kuralları** aynen geçerli:
HMAC ham gövde üzerinden (`Stripe-Signature`), JSON ayrıştırmadan
ÖNCE; CSRF muaf ve oturumsuz rota; her durumda 2xx; tekilleştirme
olay kimliğiyle.

### Dokümanın gerçek faz tablosu (§13)

| Faz | Saat | Hafta | Durum |
|---|---|---|---|
| Faz 1 — Woo dikey dilimi | 140 | 1–8 | BİTTİ |
| Faz 2 — Trendyol + çift yönlü | 126 | 9–15 | BİTTİ |
| Faz 3 — Güvenilirlik + görünürlük | 84 | 16–20 | **84/84 · BİTTİ** |
| Faz 4 — Ticarileşme | 90 | 21–25 | **86/90** |
| Faz 5 — Tampon | 28 | 26 | başlamadı |

**Toplam 468 saat · tahminen ~416 saat bitti → yaklaşık %89.**

### Faz 3'ün BEŞ maddesi — BEŞİ DE BİTTİ

| # | Madde | Saat | Durum |
|---|---|---|---|
| 1 | Mutabakat motoru (3 katman, 4 aday, onarım) | 30 | BİTTİ |
| 2 | Metrik toplama, panel grafikleri, uyarı e-postaları | 16 | **BİTTİ** — toplama + panel (`8e27913`) + e-posta (`bbe2852`) |
| 3 | Senkron geçmişi ekranı, hata gezgini, yeniden deneme | 14 | **BİTTİ** (`244a397`) |
| 4 | Ölü mektup ekranı, bağlantı sağlığı, fazla satış ekranı | 10 | **BİTTİ** (`244a397`) |
| 5 | Toplu içe aktarma (Excel/CSV) + kanaldan ürün çekme | 14 | **BİTTİ** — CSV (`f234303`) + kanaldan çekme (`99008b8`) |

**Madde 3 ve 4 aynı ekranla kapandı** — `/failures` hem hata gezgini
hem ölü mektup ekranıdır ve tek tıkla yeniden deneme onun butonudur.
Madde 4'ün "bağlantı sağlığı" parçası `/channels` ekranında zaten
vardı; "fazla satış ekranı" `/inventory` ve `/reconciliation`'da.

**Madde 5 de tek ekranla kapandı** — `/products/import` artık İKİ
kaynak taşıyor (CSV dosyası · kanal bağlantısı). Yeni ekran açılmadı.

**Madde 2 bu turda TAMAMEN kapandı** — toplama ve panel zaten vardı,
eksik olan bildirimdi: eşik aşımı yalnızca `/metrics` rozetlerinde
görünüyordu ve **kimse bakmadıkça hiçbir yerde görünmüyordu.**

## SIRADAKİ İŞ — FAZ 4'ÜN KALANI (50 sa)

Faz 3'te kalan madde YOK. Dokümanın §13 · Faz 4 listesi:

| Madde | Saat | Durum |
|---|---|---|
| Onboarding akışı | 20 | **BİTTİ** (`a118b3a`) |
| Abonelik: şema + kota | ~14 | **BİTTİ** (`d02b984`) |
| Abonelik: Stripe tahsilat (checkout + webhook) | ~12 | **BİTTİ** (`6f89fe1`) |
| Panel cilası (boş durumlar, yükleniyor, mobil — **ON ÜÇ** ekran) | 20 | **BİTTİ** (`aba0a29` + `26426ff`) |
| Türkçe yardım dokümantasyonu | 12 | kaldı |
| Güvenlik kontrol listesi + yük testi | 12 | kaldı |
| Onay durumu ekranı | küçük | kaldı |

Fazın 20 saati zaten bitmişti (mutabakat panel ekranı `513480d` ve ona
bağlı işler); onboarding ve abonelik şeması/kotasıyla birlikte
**~55/90 saat bitti → ~35 saat kaldı.**

**ABONELİK/ÖDEME MADDESİ KAPANDI** (26 sa). Şema, kota, checkout ve
webhook yazıldı; panelde `/billing` ekranı var.

**AÇIK UÇ — GERÇEK ANAHTARLA SÜRÜLMEDİ.** `.env`'de `STRIPE_SECRET`
tanımlı değil; ekran bunu açıkça söylüyor ve satın alma düğmeleri
devre dışı. **Anahtarı KULLANICI yazar** (`STRIPE_KEY`,
`STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`) — sohbete yazılmaz, koda
gömülmez. Sonra yapılacaklar:

1. Stripe panelinde webhook uç noktası tanımla:
   `https://<alan-adı>/webhooks/stripe` · olaylar:
   `checkout.session.completed`, `customer.subscription.updated`,
   `customer.subscription.deleted`
2. Yerelde denemek için: `stripe listen --forward-to
   localhost:8080/webhooks/stripe` (CLI kendi `whsec_` sırrını verir)
3. Test kartıyla (`4242 4242 4242 4242`) uçtan uca sür: plan seç →
   ödeme → webhook → abonelik `active` → kota yükseldi mi

**PANEL CİLASI BAŞLADI** (`aba0a29`) — maddenin en ağır iki parçası
bitti. Bu turda YAPILANLAR ve maddeden ÇIKANLAR:

| Parça | Durum |
|---|---|
| Mobil düzen | **BİTTİ** — 12 ekran × 320/390/768/1280px taşmasız |
| Bekleme durumları | **BİTTİ** — açıkta kalan iki düğme kapatıldı |
| Tablo okunabilirliği | **BİTTİ** (`26426ff`) — kırpma giderildi, kaydırma devreye girdi |
| Form ekranları | **BİTTİ** — ölçüldü, düzeltme gerekmedi |
| Tutarlılık turu | **BİTTİ** — 11 ekranda sıkışmış metin taraması temiz |
| Boş durumlar | **ZATEN VARDI** — 13 ekranın hepsinde (denetlendi) |
| Gezinme yüklemesi | **GEREKMİYOR** — Inertia ilerleme çubuğu zaten çalışıyor |

**BOŞ DURUM VE YÜKLEME MADDESİ KAPANDI, YENİDEN AÇMA.** İkisi de
denetlendi: boş durum metni on üç ekranın HEPSİNDE var (`Inventory`
dahil — grep'in kaçırdığı satır 177'de), `Orders/Show` ise ayrıntı
ekranı olduğu için boş duruma İHTİYAÇ DUYMAZ. Gezinme yüklemesi için
`app.js`'te `progress: { color: '#A8532B' }` tanımlı ve çubuk gerçekten
çiziliyor — yavaşlatılmış istekte marka rengiyle doğrulandı. Ekran
başına spinner eklemek GEREKSİZ TEKRAR olur.

**MADDE KAPANDI** (`26426ff` ile). Tablo okunabilirliği, form ekranları
ve tutarlılık turu bitti; ayrıntı aşağıda. **Faz 4'te kalan:** Türkçe
yardım dokümantasyonu (12 sa) · güvenlik kontrol listesi + yük testi
(12 sa) · onay durumu ekranı (küçük).

**YENİ SOHBET BURADAN BAŞLASIN.** Stripe ertelendi (yukarıda) ve
teknik bağımlılık yok; panel cilası Faz 4'ün sıradaki maddesidir.
Alternatifler: Türkçe yardım dokümantasyonu (12 sa) · güvenlik
kontrol listesi + yük testi (12 sa) · onay durumu ekranı (küçük).
**Hangisiyle başlanacağı kullanıcının kararıdır — sor.**

**Panel cilası maddesi onboarding'den SONRA gelmeli** — boş durum
metinleri artık şeridin söylediğiyle çelişmemeli. Ekranların çoğunda
boş durum metni ZATEN var (gerçek çalıştırmada görüldü: "Henüz bağlı
kanal yok.", "Başarısız işlem yok — tüm gönderimler kanala ulaştı.");
madde bunları gözden geçirip yükleniyor ve mobil düzeni eklemek.

**Onay durumu için ayrı ekran** fazın en küçük kalıntısıdır: rozet ve
red sebebi ürün-kanal ekranında ZATEN var; eksik olan yalnızca toplu
görünüm.

**Abonelik/ödeme artık teknik olarak da yazılabilir**: kota neyi
sınırladığını senkron davranışından alır ve o davranış OTURDU.

**EKRAN İŞİ ÇIKARSA TARAYICIDA DOĞRULA** — bu kural dört turdur
uygulanıyor ve **dört turda da gerçek boşluk ya da gerçek kanıt
üretti**. Bu tur ekran işi DEĞİLDİ ama kural gerçek çalıştırma
biçiminde uygulandı ve **yine gerçek bir hata buldu** (aşağıda).

Yeni pazaryerleri (Hepsiburada → Amazon → Etsy → eBay) **Faz 4
bittikten SONRA** — sıra ve gerekçeler aşağıda.

## Tek cümlede durum

**Faz 1, Faz 2 ve FAZ 3 BİTTİ** (§13 listesinden doğrulandı); **Faz
4'te ~86/90 saat bitti** — onboarding, abonelik/ödeme ve **PANEL
CİLASI MADDESİ** kapandı (`aba0a29` + `26426ff`). **831 test yeşil**
(2678 assertion), Pint temiz. Panelde **ON ÜÇ** ekran + her ekranda
onboarding şeridi; **panel artık telefonda kullanılabiliyor**
(önceden yedi ekran ve çıkış düğmesi erişilemezdi, `/failures`'ın hata
sütunu okunamıyordu ve kiracı adı on iki ekranda boştu). Sıradaki iş:
**Türkçe yardım dokümantasyonu** (12 sa) · güvenlik kontrol listesi +
yük testi (12 sa) · onay durumu ekranı (küçük).

## Bu turda ne eklendi

### §13 · Faz 4 · PANEL CİLASI — TABLO OKUNABİLİRLİĞİ + KİRACI ADI (`26426ff`) — MADDEYİ KAPATIR

| Ne | Nerede |
|---|---|
| Tablo asgari genişliği | 7 ekran (`Failures` · `Reconciliation` · `Inventory` · `Products` · `Orders` · `Orders/Show` · `Products/Import` · `Dashboard`) |
| SKU bölünmesi | `Failures` · `Reconciliation` · `Inventory` |
| Paylaşılan kiracı prop'u | `Http/Middleware/HandleInertiaRequests` |
| Testler | `OnboardingProgressTest`'e 2 test (toplam 831) |

**`overflow-x-auto` TEK BAŞINA YETMİYORDU — turun ilk bulgusu.** Yedi
tablonun hepsi zaten o sınıfı taşıyordu ama tablo `w-full` olduğu için
tarayıcı sütunları dar ekrana SIKIŞTIRIYOR ve kaydırma HİÇ devreye
girmiyordu. En kötü hâli `/failures`: kullanıcıya ne yapacağını
söyleyen "Hata" sütunu **~40px'lik bir şeride** düşüyor, kelime
ortasından kırpılıyor ve satır başına tek kelime sarıyordu. Ölçüm
(390px): tablo 658px, görünen 340px, **gizli 318px**.

`min-w-*` verilince sütunlar doğal boyutunu koruyor ve kutu KAYIYOR.
**Sayfa taşması ÜRETİLMEDİ** — 320px'te on iki ekranın hepsi hâlâ
temiz; tablolar KENDİ kutularının içinde kayıyor.

**SKU'LAR ARTIK BÖLÜNMÜYOR** (`whitespace-nowrap`): `TSH−KIRMIZI−M`
üç satıra bölünüyordu ve SKU bir KİMLİKTİR.

**KART DÜZENİNE GEÇİLMEDİ ve bu BİLİNÇLİ.** `/inventory`'de görünen
sütunlar (SKU, elde, satılabilir, fazla satış) zaten ÖNEMLİ olanlar;
sağa kayan "Senkron" rozeti ikincil. Yedi tabloyu kart düzenine
çevirmek maddenin istemediği bir yeniden yazım olurdu.

**KİRACI ADI ON İKİ EKRANDA BOŞTU — turun ASIL bulgusu ve testlerin
göremediği gerçek hata.** Başlıktaki kiracı adı YALNIZCA özet
ekranında görünüyordu.

Sebep `onboarding` prop'unun kapanışla çözdüğü tuzağın BİREBİR
AYNISIDIR ve CLAUDE.md'de ZATEN YAZILIYDI: `share()` `web` grubunda,
`tenant` ara katmanı ROTA seviyesinde çalışır — yani `share()` bağlam
kurulmadan ÖNCE çağrılır ve kapanış DIŞINDA okunan `$tenant` her zaman
null döner. `onboarding` kapanışla yazılmıştı, **`tenant` yazılmamıştı.**

**ÖZET EKRANI HATAYI MASKELİYORDU:** `DashboardController` KENDİ
`tenant` prop'unu gönderiyor ve paylaşılanı EZİYOR. Bu yüzden test
ÖZET DIŞINDA bir ekranda (`/channels`) koşmak ZORUNDA.

**İKİ MUTASYON SÜRÜLDÜ, İKİSİ DE ÖĞRETİCİ:**

1. **Kapanışı hemen çağırmak** (eski davranış) → test KIRMIZI. Koruma
   gerçek.
2. **Aynı bozuk kodla testi `/` üzerine almak** → test YEŞİL kalıyor.
   **"İki savunma mutasyonu gizler"** tuzağının bu projedeki yeni
   tekrarı: testin rota seçimi tesadüf değil, korumanın KENDİSİ.

**FORM EKRANLARI ÖLÇÜLDÜ, DÜZELTME GEREKMEDİ** — `/products/create`,
`/channels/create` ve `/products/import`'ta 44px'den dar veya taşan
kontrol YOK.

**TUTARLILIK TURU TEMİZ** — on bir ekranda "60px'den dar kutuda 25
karakterden uzun metin" taraması hiçbir şey bulmadı (yani `/failures`'ı
okunmaz yapan koşul artık hiçbir ekranda yok).

**831 test yeşil** (2 yeni), Pint temiz.

### §13 · Faz 4 · PANEL CİLASI — MOBİL DÜZEN + BEKLEME DURUMLARI (`aba0a29`)

| Ne | Nerede |
|---|---|
| Mobil menü | `Layouts/PanelLayout.vue` (katlanan menü, `lg` altında) |
| Sağlık kontrolü beklemesi | `Pages/Channels/Index.vue` |
| Checkout beklemesi | `Pages/Billing/Index.vue` |
| Arama kutusu taşması | `Pages/Orders/Index.vue` |

**BAŞLIK TÜM PANELİ YATAY KAYDIRIYORDU — turun asıl bulgusu.** 390px
görünüm alanında belge **1001px** genişliyordu. On öğelik menü tek
satıra sığmadığı için **"Siparişler"den sonraki YEDİ ekran ve ÇIKIŞ
düğmesi ekranın DIŞINDA** kalıyordu ve hamburger menü olmadığı için
**telefondan panelde gezinmek MÜMKÜN DEĞİLDİ.**

**TAŞMANIN TEK KAYNAĞI BAŞLIKTI.** Başlık `display:none` yapılıp
ölçüldüğünde belge tam **390px**'e oturuyordu — yani içerik sütunları
zaten duyarlıydı (tablolar `overflow-x-auto` taşıyor) ve sorun on üç
ekranın HEPSİNDE aynı tek kaynaktan geliyordu.

**KIRILMA NOKTASI `lg` (1024px), `sm` DEĞİL.** On menü öğesi ~900px
istiyor; `sm` seçilseydi **768px'lik tabletler taşımaya devam ederdi**
(ölçüldü). Bugün 320→1280px arasında taşma YOK.

**MENÜ GEZİNMEDE KAPANIR** (`watch(currentPath)`). Kapanmasaydı
seçilen bağlantı yeni ekranı açar ama panel açık kalıp içeriği örterdi
ve kullanıcı her seferinde elle kapatmak zorunda kalırdı.

**MASAÜSTÜ DAVRANIŞI DEĞİŞMEDİ** — on bağlantı satır içi, tek "Çıkış"
düğmesi, "Menü" düğmesi görünmüyor (ölçüldü + ekran görüntüsü).

**ÇİFT TIKLAMA GERÇEK PARA RİSKİYDİ.** `/billing` "Bu plana geç"
düğmesi yalnızca ödeme yapılandırılmamışken devre dışıydı; **istek
uçarken değil.** Ödeme oturumu SUNUCUDA açılıp Stripe'a yönlendirildiği
için arada gecikme vardır ve her ek basış **YENİ bir checkout oturumu**
yaratırdı. Artık üç hızlı tıklama **TEK POST** üretiyor (ölçüldü).
`onFinish` ile SIFIRLANMAZ: yönlendirme başladıktan sonra düğme kilitli
kalmalı, yoksa çift tıklama penceresi yeniden açılır.

**SAĞLIK KONTROLÜ DE AYNI KALIBI ALDI** — ağ çağrısıdır, saniyeler
sürer ve tepkisiz görünen düğme tekrar tekrar basılıp kanalın hız
sınırı kotasını harcardı. Tıklanan düğme "Kontrol ediliyor…" olur,
**diğer bağlantıların düğmeleri de kilitlenir**.

**KALIP UYDURULMADI:** `Failures`, `Products/Channels` ve `Mappings`
ekranları bu `busy` + `disabled` + `…` kalıbını ZATEN kullanıyordu;
açıkta kalan iki düğme aynı kalıba getirildi.

**`/orders` ARAMA KUTUSU 320px'DE TAŞIYORDU** (sabit `w-64` + düğme +
boşluk > 320px). Kutu artık dar ekranda esner (`w-full min-w-0`),
`sm` ve üstünde eski `w-64` genişliğine döner.

**GEZİNME YÜKLEMESİ İÇİN HİÇBİR ŞEY EKLENMEDİ ve bu bilinçli.**
`app.js` zaten `progress: { color: '#A8532B' }` taşıyor ve çubuk
gerçekten çiziliyor: istek yapay olarak yavaşlatıldığında çubuk marka
rengiyle (`rgb(168, 83, 43)`) göründü. Yerelde görünmemesinin sebebi
Inertia'nın 250 ms gecikmesidir — hızlı yanıtta çubuk yanıp sönmesin
diye. Ekran başına spinner eklemek gereksiz tekrar olurdu.

**BU DEĞİŞİKLİKLER PHP TESTİYLE KORUNMUYOR** — projede JS test
koşucusu YOK (`package.json`'da script yok) ve hiçbir PHP testi bu Vue
ayrıntılarına değinmiyor. Vitest eklemek YENİ BİR PARADİGMA olurdu ve
proje kuralları bunu yasaklıyor. Ekran işi bu projede **tarayıcıda**
doğrulanır; öyle yapıldı ve ölçümler yukarıda.

**GERÇEK TARAYICIDA DOĞRULANDI** (Playwright CLI, gerçek oturum):
12 ekran × 4 genişlik (320/390/768/1280) taşma taraması · mobil menü
açılıp **on bağlantı + Çıkış** göründü · bağlantıya tıklandı,
**gezindi ve menü kendiliğinden kapandı** · üç hızlı tıklama tek POST
üretti · masaüstü başlığı değişmedi.

**829 test yeşil, Pint temiz.**

### §13 · Faz 4 · STRIPE TAHSİLAT HATTI (`6f89fe1`) — ABONELİK MADDESİNİ KAPATIR

| Ne | Nerede |
|---|---|
| Webhook | `Http/Controllers/StripeWebhookController` (`/webhooks/stripe`) |
| Uygulama | `Domain/Billing/Actions/SyncSubscriptionFromStripe` |
| Panel | `Http/Controllers/BillingController` + `Pages/Billing/Index.vue` |
| Yapılandırma | `config/entegrasyon.php` → `stripe.*` (`.env`) |
| Testler | `StripeWebhookTest` (15) · `BillingScreenTest` (13) |

**LARAVEL CASHIER KULLANILMADI** (kullanıcı kararı): kendi
`subscriptions` tablosunu dayatıyor ve §4'e göre yazdığımız şemayla
çakışırdı. Ham `stripe/stripe-php` ile yazıldı; şema AYNEN kaldı.

**ABONELİK DURUMUNUN TEK GERÇEK KAYNAĞI STRIPE'TIR.** Panel yalnızca
checkout oturumu açar ve YÖNLENDİRİR; aboneliği WEBHOOK yazar. Panel
yazsaydı ödeme alınmadan kota açılır ve kullanıcı ödeme sayfasında
vazgeçse bile abonelik açık kalırdı.

**İMZA HAM GÖVDE ÜZERİNDEN ve AYRIŞTIRMADAN ÖNCE.** Kanal
webhook'larıyla aynı kural; bedeli daha ağır çünkü doğrulanmamış bir
`checkout.session.completed` ÜCRETSİZ ABONELİK açmak demektir.
Tolerans 300 sn (tekrar saldırısı).

**PLAN YÜKSELTMEDE ESKİ AKTİF ABONELİK AYNI TRANSACTION'DA KAPATILIR.**
Kapatılmasaydı `UNIQUE(tenant_id) WHERE aktif` INSERT'i eler ve **ödeme
alınmışken abonelik AÇILMAZDI** — en kötü hata biçimi.

**BEŞ MUTASYON SÜRÜLDÜ, ÜÇÜ YAKALANDI, İKİSİ GERÇEK BOŞLUK BULDU:**

1. **`canceled` → `active` eşlemesi HAYATTA KALDI.** Silme testi
   `deleted` bayrağını kullandığı için eşleme tablosuna HİÇ
   uğramıyordu; oysa Stripe iptali `subscription.updated` +
   `status: canceled` ile de bildirir ve o yolda iptal edilmiş abonelik
   AKTİF kalıp kota vermeye devam ederdi. Üç test eklendi.
2. **Gizli plan kapısı HAYATTA KALDI** — test Stripe yapılandırılmamışken
   koştuğu için istek SONRAKİ kapıya takılıyor ve aynı alan hatasını
   üretiyordu. **İki savunma mutasyonu gizler** tuzağının bu projedeki
   yeni tekrarı. Testler artık Stripe'ı yapılandırılmış varsayıyor ve
   mesajı BEKLENEN METİNLE sınıyor.

Yakalananlar: geçersiz imzanın kabulü, idempotency çıpasının
kaldırılması, bilinmeyen durumun `active`'e düşmesi.

**BOZUK BİÇİMLİ OLAYDA `data.object` DÜZ DİZİ GELEBİLİYOR** ve
`toArray()` ölümcül hata veriyordu — 500 dönmek Stripe'a uç noktayı
kapattırırdı (yazarken bulundu, testle korunuyor).

**TARAYICIDA DOĞRULANDI:** demo kiracısı `/billing` ekranında
"6/25 ürün" (yeşil) ve "2/1 kanal" (**KIRMIZI** — kota aşılmış)
görüyor; Kurumsal planda "sınırsız" yazıyor (sıfır DEĞİL) ve ödeme
yapılandırılmadığı için ekran bunu AÇIKÇA söylüyor.

### §13 · Faz 4 · ABONELİK ŞEMASI + KOTA (`d02b984`)

| Ne | Nerede |
|---|---|
| Şema | `2026_08_20_000300_create_billing_tables` (`plans` · `subscriptions`) |
| Modeller | `Domain/Billing/Models/Plan` · `Subscription` |
| Kota | `Domain/Billing/Actions/EnforceQuota` |
| Metrik | `Domain/Billing/Enums/QuotaMetric` (SÖZLEŞME) |
| Hata | `Domain/Billing/Exceptions/QuotaExceededException` |
| Seed | `database/seeders/PlanSeeder` (free · starter · pro · business) |
| Bağlandığı yollar | `ProductController::store` · `ChannelConnectionController::store` |
| Testler | `EnforceQuotaTest` (11) · `SubscriptionSchemaTest` (7) · `PlanLimitContractTest` (6) · `QuotaEnforcementPathsTest` (5) |

**KULLANICI KARARI — İKİ KOTA:** ürün sayısı ve kanal bağlantısı
sayısı. İkisi de sektör standardı, müşterinin anlaması kolay ve ANLIK
sayım.

**`usage_records` YAZILMADI ve bu BİLİNÇLİ.** §4 onu DÖNEMSEL ölçüm
için tanımlıyor ("fiyatlandırma verisi geriye dönük üretilemez") ama
iki kota da anlık `COUNT`; şimdi yazmak hiçbir yerden yazılmayan boş
bir tablo bırakırdı. Sipariş/senkron başına ücretlendirmeye geçilirse
eklenir.

**KOTA STOK VE SİPARİŞ AKIŞINA DOKUNMAZ** — §14'ün ön koşul kapısıyla
AYNI tasarım hedefi ve testte ledger snapshot'ıyla korunuyor. Sipariş
ASLA reddedilmez; ödeme sorunu yüzünden stok bozmak veya sipariş
kaybetmek, çözdüğünden büyük zarar verir.

**KOTA YARATMAYI ENGELLER, VAR OLANI SİLMEZ.** Plan düşünce limitin
üstündeki ürünler silinmez ve senkronları durmaz.

**LİMİT YOKSA SINIRSIZDIR, SIFIR DEĞİL** — yeni bir kota türü
eklendiğinde tüm mevcut planların o kotada sıfıra düşüp herkesi
kilitlemesini önler.

**ANAHTAR YENİLEME KOTADAN ETKİLENMEZ.** `ConnectChannel` aynı hesabı
`firstOrNew` ile yeniden kullanır; ayrım yapılmasaydı kotası dolu
satıcı süresi dolmuş anahtarını güncelleyemez ve kanalı KALICI
ölürdü — üstelik tam da ödeme yapmasını istediğimiz anda.

**`external_ref` SAĞLAYICIDAN BAĞIMSIZ ADLANDIRILDI** (§4) ve KISMİ
TEKİL: Stripe olayları EN AZ BİR KEZ gönderilir ve tekrar ikinci bir
abonelik satırı doğururdu. NULL'lar tekilliği ihlal etmez.

**ALTI MUTASYON, ALTISI DA YAKALANDI:** (1) `>=` → `>`, (2) iptal
edilmiş aboneliğin kota vermesi, (3) null limitin sıfıra düşmesi,
(4) kiracı scope'unun kalkması, (5) anahtar yenilemenin de
engellenmesi, (6) **kota çağrısının ürün yolundan tamamen
kaldırılması** — bu sonuncusu `pushPrices` boşluğunun (yazıldı ama
çağıranı yok) aynısıdır ve `QuotaEnforcementPathsTest` tam bunun için
yazıldı.

**SÖZLEŞME TESTİ EKLENDİ.** `plans.limits` anahtarları BEKLENEN
METİNLE sınanıyor: yazan (seed) ve okuyan (`limitFor`) aynı enum'u
çağırdığı için mutasyon ikisini BİRLİKTE kaydırır, davranış testleri
yeşil kalır ama üretimdeki satırlar eski anahtarı taşır ve kota
SESSİZCE kalkardı. `MetricScope` ve `AlertKey` tuzağının ÜÇÜNCÜ
tekrarı — bu kez ÖNCEDEN yazıldı.

**GERÇEK ÇALIŞTIRILDI:** planlar seed edildi (4 plan, doğru JSONB
anahtarlarıyla). Demo kiracısı `free` plana düşüyor ve **2/1 kanalla
kotası DOLU** (planlardan önce yaratıldığı için); tarayıcıda üçüncü
kanalı eklemeyi denedi ve **"Kanal bağlantısı kotası doldu: 2/1. Daha
fazla kanal bağlamak için planını yükselt."** mesajını gördü. **VAR
OLAN iki kanal `active` kaldı ve çalışmaya devam ediyor** — kuralın
gerçek kanıtı budur.

### §13 · Faz 4 · ONBOARDING AKIŞI (`a118b3a`) — FAZ 4'ÜN İLK MADDESİ

| Ne | Nerede |
|---|---|
| Türetme | `Domain/Identity/Support/OnboardingProgress` — TEK KAYNAK |
| Paylaşım | `Http/Middleware/HandleInertiaRequests::share()` |
| Şerit | `resources/js/Layouts/PanelLayout.vue` |
| Testler | `OnboardingProgressTest` (13) |

**Doküman tek satır söylüyor** (§13 · Faz 4): "Onboarding: kayıt →
kanal bağla → ürün aktar → ilk senkron — 20 sa". Başka hiçbir yerde
onboarding tanımı YOK — adımlar dokümanın, tasarım kararları bu turun.

**İLERLEME SAKLANMAZ, TÜRETİLİR — turun ana kararı.** `tenants`'a
kolon veya ayrı tablo EKLENMEDİ. Gerekçe projenin iki yerleşik
kararının aynısı: `is_dirty` generated column'dır (§4) ve
`DriftHistory` sayacı ayrı kolonda TUTMAZ (§10). Burada tuzak daha da
keskin: adım "bitti" damgalanıp veri sonradan giderse kayıtlı ilerleme
**YALAN söyler**. Türetilmiş ilerleme yalan söyleyemez — ve bu
gerçek tarayıcıda KANITLANDI (aşağıda).

**KANAL ADIMI `active` İSTER, VARLIK YETMEZ.** `pending` bağlantı
kanalla HİÇ konuşamamıştır; adım kapatılsaydı kullanıcı ürün
göndermeye başlar ve hepsi `AUTHENTICATION` ile KALICI hataya düşerdi.

**SENKRON ADIMI `completed` İSTER.** `pending` kuyrukta bekliyordur,
`dead` tam olarak BAŞARISIZ olmuştur, **`superseded` ise terminaldir
ama HİÇ GÖNDERİLMEMİŞTİR** (§8).

**KİRACI KONTROLÜ KAPANIŞIN İÇİNDE YAPILIR — gerçek çalıştırmada
bulundu.** `share()` `web` grubunda, `tenant` ise ROTA seviyesinde
çalışır; yani `share()` bağlam kurulmadan ÖNCE çağrılır ve dışarıda
okunan `$tenant` HER ZAMAN null olurdu (prop null döndü, 13 test
kırmızı kaldı). Kapanış yanıt üretilirken çalıştığı için bağlamı
kurulmuş görür.

**ŞERİT LAYOUT'TA YAŞAR** ve kapatma butonu YOKTUR: saklanan tercih
ilerlemenin İKİNCİ gerçek kaynağı olurdu. Dört adım bitince kaybolur.

**BEŞ MUTASYON, BEŞİ DE YAKALANDI:** (1) `active` filtresini
kaldırmak, (2) `completed` filtresini kaldırmak, (3) şeridi her zaman
göstermek, (4) kiracı scope'unu kaldırmak (çapraz kiracı sızıntısı),
(5) adım sırasını ters çevirmek.

**GERÇEK TARAYICIDA SÜRÜLDÜ (yeni kiracı kaydedilerek):**

1. Kayıt → `/` → şerit **1/4**, adım 2 "sıradaki" işaretli.
2. **`pending` bağlantı eklendi → şerit HÂLÂ 1/4.** Bağlantı "Kanallar"
   listesinde görünüyor ama adım KAPANMADI — saklanan ilerleme burada
   yanlış cevap verirdi.
3. Bağlantı `active` → **2/4**, düğme "Ürün aktar →".
4. Panelden ürün eklendi → **3/4**, düğme "Ürüne git →".
5. `completed` operasyon → **şerit KAYBOLDU**.
6. **Bağlantı sağlıksızlığa düşürüldü → şerit GERİ GELDİ (3/4)** ve
   düğme yeniden "Kanal bağla →" oldu. Türetilmiş durumun asıl kazancı
   budur.

**DEMO KİRACISINDA ŞERİT 3/4 GÖRÜNÜYOR ve bu DOĞRU** — demo verisinde
2 aktif kanal ve 6 ürün var ama **HİÇ senkron operasyonu yok** (0
satır, hiçbir durumda). Şerit tam olarak eksik olanı söylüyor.

**DEV VERİSİ GERİ ALINDI:** tarayıcıda açılan test kiracısı ve
kullanıcısı silindi; demo kiracısı olduğu gibi duruyor.

### Bir önceki tur — §11 · §12 · §13 · Faz 3 · madde 2 · UYARI E-POSTALARI (`bbe2852`) — FAZ 3'Ü KAPATIR

| Ne | Nerede |
|---|---|
| Tarama | `Support/Observability/DispatchAlerts` |
| Komut | `Support/Observability/DispatchAlertsCommand` (`alerts:dispatch`, GÜNLÜK 09:00) |
| Çıpa biçimi | `Support/Observability/AlertKey` — TEK KAYNAK |
| Gönderim | `Support/Observability/AlertMailer` (sağlayıcıdan BAĞIMSIZ) |
| Model | `Support/Observability/AlertDelivery` |
| Mailable | `Mail/MetricAlertMail` + `resources/views/mail/metric-alert.blade.php` |
| Şema | `alert_deliveries` (`2026_08_20_000200`) — `UNIQUE(alert_key, sent_on)` |
| Kapsam ayrımı | `MetricScope::tenantIdOf()` / `connectionIdOf()` (YENİ) |
| Biçimleme | `MetricUnit::format()` (YENİ, sunucu tarafı) |
| Yapılandırma | `config/entegrasyon.php` → `alerts.admin_email` (`ALERT_ADMIN_EMAIL`) |
| Kayıt + zamanlama | `bootstrap/app.php` · `routes/console.php` |
| Testler | `DispatchAlertsTest` (17) + `MetricUnitFormatTest` (5) + `ScheduledScansTest`'e 3 yerde iddia |

**KAPATILAN BOŞLUK:** eşik aşımı ölçülüyordu, saklanıyordu ve panelde
rozetle gösteriliyordu — ama **kimse `/metrics`'e bakmadıkça hiçbir
yerde görünmüyordu.** Uyarı, ölçümü BİLDİRİME çevirir. Faz 3'ün son
maddesi buydu.

**KULLANICI KARARI — SMTP SAĞLAYICISI ŞİMDİLİK SEÇİLMEDİ, `log`
sürücüsünde kalınıyor.** Kod Laravel'in `Mail` cephesiyle
SAĞLAYICIDAN BAĞIMSIZ yazıldı: Mailgun/Postmark/SES'e geçiş TEK bir
`.env` satırıdır (`MAIL_MAILER`) ve **KOD DEĞİŞMEZ.** Sağlayıcı
seçimi bir yapılandırma kararıdır, mimari karar değil.

**KULLANICI KARARI — YÖNETİCİ ADRESİ YAPILANDIRMADAN OKUNUR**
(`ALERT_ADMIN_EMAIL` → `alerts.admin_email`). Koda gömülü adres YOK:
gömülü olsaydı adres her kurulumda aynı olurdu ve değiştirmek deploy
gerektirirdi.

**AYNI UYARI AYNI GÜN İKİ KEZ GİTMEZ — maddenin EN ÖNEMLİ kuralı.**
Eşik aşımı KALICI bir durumdur ve her turda yeniden ölçülür; koruma
olmasaydı aynı uyarı tur tur giderdi, gelen kutusu dolar ve **insanlar
uyarıları OKUMAYI BIRAKIR** — ondan sonra gerçek bir olay da fark
edilmez. Çıpa `alert_deliveries (alert_key, sent_on)` tekilliğidir;
yarış `insertOrIgnore` ile çözülür.

**ÇIPA GÖNDERİMDEN ÖNCE YAZILIR.** Sonra yazılsaydı paralel iki tur
İKİSİ DE gönderir ve ihlali ancak e-posta çıktıktan SONRA fark
ederdik — geri alınamaz. **"Yazdı ama gönderemedi" hâli BİLİNÇLİ
OLARAK KABUL EDİLİYOR**: bir uyarıyı kaçırmak, aynı uyarıyı iki kez
göndermekten iyidir.

**`sent_on` TARİHTİR, ZAMAN DAMGASI DEĞİL.** Tekilliğin cevaplaması
gereken soru "AYNI GÜN mü" — zaman damgasıyla iki gönderim ASLA
çakışmaz ve kısıt hiçbir şey korumazdı.

**TARAMA ÖLÇMEZ, OKUR.** Değerler `metric_snapshots`'tan gelir; on üç
ağır toplama sorgusu YENİDEN KOŞTURULMAZ. Koşturulsaydı iki gerçek
kaynağı doğardı — turlar farklı anlarda çalıştığı için panel bir değeri,
e-posta BAŞKA bir değeri gösterirdi — ve `percentile_cont` maliyeti iki
kez ödenirdi.

**EŞİK `Metric::threshold()` İÇİNDE TEK KAYNAKTIR** ve karşılaştırma
`Metric::breaches()` ile yapılır; `>` / `>=` yeniden YAZILMAZ. **Eşiğe
TAM DAYANAN değer aşım DEĞİLDİR** (§11 "büyüktür" der) — aksi hâlde
panel yeşil gösterirken e-posta giderdi.

**SON ÖLÇÜM `id` İLE SEÇİLİR, `captured_at` İLE DEĞİL.** Damga saniye
hassasiyetlidir ve iki tur aynı damgayı taşıyabilir. `MetricsController`
ile AYNI kural: ayrışsalardı panel bir değere "son", uyarı BAŞKA bir
değere "son" derdi. `DISTINCT ON` kullanılır — **`MAX(id)` İMKÂNSIZ**
(PostgreSQL'de uuid için `max()` toplam fonksiyonu YOK).

**KİRACI uyarısı satıcının SAHİPLERİNE, SİSTEM ve BAĞLANTI uyarısı
YÖNETİCİYE gider.** Bağlantı uyarıları (api gecikmesi, 429) da
yöneticiye gider çünkü onlar satıcının DÜZELTEMEYECEĞİ altyapı
sorunlarıdır. Yönlendirme tam da bu ayrıma dayandığı için
`MetricScope::idOf()` YETMEZ — o metot kapsam TÜRÜNÜ ayırt etmiyor;
bu turda `tenantIdOf()` ve `connectionIdOf()` eklendi.

**YÖNETİCİ ADRESİ TANIMSIZSA GÖNDERİLMEZ ve ÇIPA DA YAZILMAZ.** Çıpa
yazılsaydı o günün uyarısı YANARDI: adres yapılandırıldıktan sonra bile
bir daha hiç gönderilemezdi. Atlanan uyarı günlüğe yazılır
(`alerts.no_recipient`) — sessizce kaybolmaz.

**DAVETİ KABUL ETMEMİŞ ÜYEYE GÖNDERİLMEZ** (`accepted_at` NULL):
adres DOĞRULANMAMIŞTIR ve uyarı bir yabancının gelen kutusuna düşerdi.

**§12'NİN "KİRACI BAŞINA 10'DAN FAZLA ÖLÜ İŞ" MADDESİ AYRI YOL
DEĞİLDİR.** `Metric::DEAD_OPERATIONS` zaten KİRACI başına ölçüyor ve
eşiği 10. Ayrı bir özet yolu açılsaydı eşik İKİ YERDE yaşar ve zamanla
ayrışırdı.

**MAILABLE `ShouldQueue` UYGULAMAZ.** Tarama zaten zamanlanmış bir
komutta koşuyor; kuyruğa alınsaydı gönderim aynı-gün çıpasından
AYRIŞIRDI ve başarısız bir iş, çıpası yazılmış ama e-postası HİÇ
gitmemiş bir satır bırakırdı — **satıra bakan hiç kimse bunu
anlayamaz.**

**ALICILAR AYRI AYRI E-POSTA ALIR**, tek `to()` çağrısına dizi
verilmez: verilseydi sahipler birbirlerinin adreslerini görürdü.

**GÖVDE DEĞERİ VE EŞİĞİ BİRLİKTE GÖSTERİR** ("9 — eşik 5"): tek başına
bir sayı hiçbir şey söylemez. Ayrıca metriğe özgü TAVSİYE taşır ve
doğru ekrana yönlendirir — ölü mektup ekranının "hata sınıfı ve tavsiye
gösterilir" kuralının aynısı.

**SEKİZ MUTASYON, SEKİZİ DE YAKALANDI — ama BİRİ ancak SÖZLEŞME TESTİ
EKLENDİKTEN SONRA.** `AlertKey` önekini değiştirmek HİÇBİR testi
kırmıyordu: yazan da okuyan da aynı yardımcıyı çağırdığı için BİRLİKTE
kayıyorlar. Ama kalıcı veri eski biçimde durur, DÜNKÜ satırlar bir daha
HİÇ BULUNAMAZ ve tekrar koruması SESSİZCE yok olur — satıcı aynı
uyarıyı ikinci kez alır. Test artık BEKLENEN METİNLE (literal)
sınıyor. **Bu, geçmiş bir turda `MetricScope` üzerinde hayatta kalan
mutasyonun BİREBİR AYNISIDIR** — tuzak listesinde kayıtlı ve bu turda
TEKRARLADI.

Diğer yedi: (1) aynı gün tekrar korumasını kaldırmak, (2) `breaches()`
→ `>=`, (3) `accepted_at` filtresini kaldırmak, (4) kiracı filtresini
kaldırmak (çapraz kiracı sızıntısı), (5) `captured_at` ile sıralamak,
(6) alıcı yokken çıpayı yazmak, (7) kiracı uyarısını yöneticiye
yönlendirmek.

**GERÇEK ÇALIŞTIRMADA GERÇEK BİR HATA BULUNDU (testler yeşilken) —
TURUN ASIL BULGUSU.** `MetricUnit::format(0)` **BOŞ DİZE** döndürüyordu:
`decimal()` yardımcısı `rtrim(..., '0')`'ı SAYININ TAMAMINA uyguluyor ve
`"0"` `""`'e çöküyordu. E-posta "uyarı eşiği: " diye bitiyordu, arkası
BOŞ. Sıfır eşikli iki metrik (`outbox_consume_gap`, `sync_delivery_gap`)
tam da **uyarı üreten metriklerdi** — yani hata HER uyarı e-postasında
görünecekti. Aynı kırpma `10`'u da `1` yapardı. Düzeltme: kırpma
yalnızca ONDALIK KISMA uygulanıyor. `MetricUnitFormatTest` artık on üç
metriğin eşiğini de süpürüyor; boş eşikli bir e-posta ÜRETİLEMEZ.

**GERÇEK ÇALIŞTIRILDI (gerçek komut + gerçek veri + gerçek posta
sürücüsü):**

1. `metrics:capture` 11 metrik yazdı; İKİ sistem metriği eşiği aştı.
2. **Yönetici adresi TANIMSIZKEN 2 uyarı ATLANDI** ve
   `alerts.no_recipient` olarak günlüğe yazıldı — çıpa YAZILMADI.
3. Adres tanımlandıktan sonra 2 uyarı GİTTİ.
4. **İkinci ve üçüncü turlar İKİSİNİ DE BASTIRDI** — aynı gün tekrar
   koruması gerçek turda kanıtlandı.
5. Kiracı uyarısı `demo@entegrasyon.local` adresine gitti (**yöneticiye
   DEĞİL**); gövde "9 — eşik 5" ve tavsiye stok ekranını gösteriyordu.

**`config/mail.php` VARDIR** (Laravel varsayılanı, `MAIL_MAILER=log`) —
devir notunun "mail altyapısı HİÇ YOK, `config/mail.php` bile yok"
iddiası YANLIŞTI. Eksik olan `app/Mail` ve `app/Notifications`'tı.

**DEV VERİSİ GERİ ALINDI:** `alert_deliveries` ve `metric_snapshots`
boşaltıldı, günlük dosyası kırpıldı (tablolar kalıcı, migration
yerinde).

### Bir önceki tur — §13 · Faz 3 · madde 5 · KANALDAN ÜRÜN ÇEKME (`99008b8`)

Faz 3 · madde 5'i kapattı. §7'ye **SEKİZİNCİ yetenek arayüzü**
eklendi (`SupportsCatalogImport`, kullanıcı onaylı sapma):
`SupportsCatalog`'un iki okuma metodu da YEREL bir kayıttan başlar,
içe aktarma ise TERS soruyu sorar ("kanalda BENDE OLMAYAN ne var?").
Var olan SKU'da **kanal stoğu YAZILMAZ** (satılmış mallar geri gelir
ve bakiye sessizce bozulurdu); fiyat `regular_price`'tan okunur;
`internal_category_id` asla ezilmez; SKU'suz ürün atlanır ama ADIYLA
raporlanır. Sekiz mutasyonun sekizi de yakalandı. Gerçek HTTP + gerçek
worker + tarayıcıda doğrulandı: `TSH-201`'in `on_hand`'i kanal 500
iddia etmesine RAĞMEN −3'te KALDI.

### Bir önceki tur — §11 · METRİK TOPLAMA + SAĞLIK EKRANI (`8e27913`)

| Ne | Nerede |
|---|---|
| Tablo | `metric_snapshots` (bigserial, `scope` metni, §4 birebir) |
| Toplama | `Support/Observability/CaptureMetrics` (13 metrik) |
| Enum | `Metric` — eşik + birim + kapsam türü + etiket, TEK KAYNAK |
| Kapsam | `MetricScope` · `MetricScopeKind` · `MetricUnit` |
| Komut | `metrics:capture`, SAATLİK (`routes/console.php`) |
| Ekran | `MetricsController` + `Pages/Metrics/Index.vue` (`/metrics`) |
| Testler | `CaptureMetricsTest` (28) + `MetricsScreenTest` (18) |

**KAPATILAN BOŞLUK:** sistem çalışıyordu ama NE KADAR İYİ çalıştığı
hiçbir yerde görünmüyordu. §17 maddeyi P0'a koyuyor: "ölçülmeyen
güvenilirlik iddia edilemez."

**ANLIK GÖRÜNTÜ ALINIR, PANEL CANLI SORGU YAPMAZ.** Asıl sebep sorgu
ağırlığı değil: grafik GEÇMİŞ ister ve canlı sorgu yalnızca ŞU ANI
verir — "artıyor mu" sorusunu asla cevaplayamaz. Tablo bir ZAMAN
SERİSİDİR; her tur EKLER, üzerine yazmaz.

**ÖLÇÜLEMEYEN METRİK SIFIR YAZMAZ.** Hiç tamamlanmış operasyon yoksa
p95 hesaplanamaz ve satır HİÇ yazılmaz; sıfır yazılsaydı grafik "her şey
mükemmel" derdi. İSTİSNA `outbox_consume_gap` ve `sync_delivery_gap`:
orada sıfır GERÇEK bir ölçümdür ve eşik zaten sıfırdır.

**`metric_snapshots` KİRACIYA AİT DEĞİLDİR** (§4: `tenant_id` yok) —
metriklerin çoğu sistem genelidir. Bedeli: global scope BU TABLODA
ÇALIŞMAZ ve panel filtresi `scope` üzerinden ELLE yazılır.

**EŞİKLER KAPSAMLIDIR** (§11): fazla satış ve ölü iş KİRACI başına, api
gecikmesi ve 429 KANAL başına. Sistem geneli toplansaydı yüz kiracılı
kurulumda tek kiracının sorunu gürültüde kaybolurdu.

**OTUZ BEŞ MUTASYON, OTUZ BEŞİ DE YAKALANDI** — biri ancak test
eklendikten sonra: **`MetricScope::tenant()` hem YAZAN hem OKUYAN
tarafta kullanıldığı için önek değişse ikisi BİRLİKTE kayıyor** ve
davranış testleri yeşil kalıyordu. Ama tablo bir zaman serisidir: eski
satırlar eski önekle yazılmıştır ve yeni okuyucu onları HİÇ BULAMAZ —
grafik sessizce sıfırlanır. Biçim artık beklenen METİNLE sınanıyor
(sözleşme testi). Kuyruk adlarının Horizon yapılandırmasıyla
karşılaştırılmasıyla aynı gerekçe.

**GERÇEK ÇALIŞTIRILDI (gerçek komut + gerçek veri + tarayıcı):**
`metrics:capture` dev veritabanında 11 satır yazdı — kiracı kapsamlı
olanlar ayrı ayrı, ölçülemeyenler (p95, hata oranı — son bir saatte veri
yok) HİÇ yazılmadı. Beş tur koşturulup grafik doğrulandı.

**GERÇEK ÇALIŞTIRMADA İKİ GÖRSEL BOŞLUK BULUNDU (testler yeşilken):**

1. **SABİT SERİDE SPARKLINE KUTUNUN DİBİNE ÇİZİLİYORDU.** `span || 1`
   kısayolu (yaygın kalıp) tüm noktaları `y=32`'ye koyuyor ve satıcı
   "değer dibe vurdu" sanıyordu — oysa değer HİÇ DEĞİŞMEMİŞTİ. Beş
   turun beşi de aynı değeri ölçünce beş kart birden yanıltıcı göründü.
   Sabit seri artık ORTADAN çiziliyor.
2. **EŞİĞE TAM DAYANAN DEĞER HİÇBİR ŞEY SÖYLEMİYORDU.** "Fazla satış
   5 / eşik 5" aşım DEĞİLDİR (§11 "büyüktür" der) ve kırmızı
   gösterilemez, ama sessizce sıradan göstermek satıcıyı bir adım
   ötede olduğundan habersiz bırakıyordu. `nearThreshold` eklendi
   (eşiğin %80'i); SIFIR EŞİKLİ metriklerde uyarı YOKTUR — her sağlıklı
   ölçümü sarıya boyardı.

**DEV VERİSİ GERİ ALINDI:** turun yazdığı 55 anlık görüntü silindi
(tablo kalıcı, migration yerinde).

### Bir önceki tur — §12 · ÖLÜ MEKTUP EKRANI + TEK TIKLA YENİDEN DENEME (`244a397`)

| Ne | Nerede |
|---|---|
| Controller | `Http/Controllers/SyncFailureController` |
| Ters çevirim | `SyncDomain::fromOperationType()` (YENİ) |
| Ekran | `Pages/Failures/Index.vue` |
| Rota | GET `/failures` · POST `/failures/retry` |
| Navigasyon | `PanelLayout` — Mutabakat ile Kanallar arasında |
| Testler | `DeadLetterScreenTest` (29) |

**KAPATILAN BOŞLUK:** §12'nin beş adımının İLK ÜÇÜ zaten çalışıyordu
(operasyon `dead`, sync state `error_*`, `failed_jobs`); eksik olan
panel ve butondu. Onlar olmadan ölü satır SONSUZA KADAR ölü kalır:
`error_permanent` mutabakatta ASLA aday değildir (§10) ve o satıra
başka hiçbir mekanizma dokunmaz.

**DURUM YAZMAK YETMEZ** (§9 · Karar 18). Buton `sync_operations.status
= 'pending'` YAZMAZ — kanonik veri değişmediği için kimse o operasyonu
yeniden dispatch etmez. `RequestResync` çağrılır ve aynı transaction'da
`ListingResyncRequested` yazar; **asıl iş odur.**

**ESKİ ÖLÜ OPERASYON `dead` KALIR.** Yeniden deneme YENİ operasyon açar
(`intent=REPAIR`, anahtar `resync:` ön ekli — mutabakatın `repair:`
ön ekinden ayrı). Eskisini `pending`'e çevirmek "beş kez denendi ve
öldü" denetim izini silerdi.

**DOMAIN OPERASYON TÜRÜNDEN OKUNUR.** `sync_operations`'ta `domain`
kolonu YOK; alan `operation_type` içinde yaşıyor. `SyncDomain::
fromOperationType()` bu turda yazıldı (tanınmayan tür NULL döner,
istisna fırlatmaz). Sabit `INVENTORY` yazılsaydı ölü bir `PRICE_PUSH`
için stok senkronu açılır ve fiyat HİÇ gitmezdi.

**HATA SINIFI VE TAVSİYE GÖSTERİLİR, ÖZET KALICI/GEÇİCİ AYIRIR.**
`AUTHENTICATION` (anahtarı yenile) ile `VALIDATION` (veriyi düzelt)
kullanıcıya FARKLI iş yaptırır. Gizlenseydi "yeniden dene" tek çare
gibi görünür ve kullanıcı aynı hatayı sonsuza kadar üretirdi.

**YİRMİ MUTASYON, YİRMİSİ DE YAKALANDI** — ama ÜÇÜ ancak test
eklendikten sonra:

1. **İKİ SAVUNMADAN BİRİ MUTASYONU GİZLİYORDU.** Toplu denemenin kiracı
   kapsaması hem operasyon sorgusunda hem de `listing` ilişkisinde
   vardı; ilişki de kapsanmış olduğu için yabancı satır NULL dönüp
   atlanıyordu ve **operasyon sorgusundan kapsama TAMAMEN kaldırılsa
   bile test yeşil kalıyordu**. Kurgu ikinci savunmayı DEVRE DIŞI
   bıraktı (yabancı operasyon davranan kiracının listing'ini işaret
   ediyor) ve tek koruma yalnızlaştırıldı. **YENİ KURAL: iki savunma
   varsa test onları AYRI AYRI sınamalı; yoksa biri sessizce
   düştüğünde hiçbir şey kırmaz.**
2. **`sync_attempts` ham sorgusunun kiracı filtresi test edilmemişti** —
   `DB::table()` global scope'a tabi değil (bu projede BEŞİNCİ tur).
3. **Özetin kalıcı/geçici ayrımı hiç okunmuyordu.**

**GERÇEK ÇALIŞTIRILDI (gerçek TLS stub + gerçek worker + tarayıcı):**
Yerel stub (`host.docker.internal:9912`) 400 döndürdü → adapter
`VALIDATION` sınıflandırdı → operasyon **`dead`**, sync state
**`error_permanent`** (§12 · adım 1-2). 404 ile iki geçici ölüm daha
üretildi. Panelde üç satır, özet 3/1, uyarı kutusu ve tavsiye metni
doğrulandı. **"Hepsini yeniden dene" üç `ListingResyncRequested`
yazdı, tüketici üç `intent=REPAIR` operasyonu açtı ve eski üçü `dead`
kaldı** — §12 · adım 5'in tam kanıtı.

**GERÇEK ÇALIŞTIRMADA İKİ BOŞLUK BULUNDU (testler yeşilken):**

1. **FLASH ANAHTARI UYDURULMUŞTU.** `status` yazılmıştı;
   `HandleInertiaRequests::share()` yalnızca `success`/`warning`
   paylaşıyor. İstek başarılı oluyor, olay yazılıyor ama kullanıcı
   **HİÇBİR geri bildirim görmüyordu** — butonun çalışıp çalışmadığını
   bilemez ve tekrar tekrar basardı. Hiçbir test flash mesajını
   okumuyordu. **Kuyruk adı uydurma tuzağının panel karşılığı budur.**
2. **HATA MESAJI HAM İSTİSNA METNİYDİ** ve satıcı
   `ürün` okuyordu — ekranın TÜM AMACINI boşa çıkarıyordu.
   Guzzle gövdeyi **120 karakterde kesip** `(truncated...)` eklediği
   için `json_decode` **TEK BAŞINA YETMEZ**: gerçek kanal mesajları
   neredeyse HER ZAMAN daha uzundur ve yalnızca tam gövdeyi çözen bir
   ayrıştırıcı **pratikte HİÇ çalışmazdı**. Kırpık gövdeden `message`
   alanı çekiliyor, yarım kaçış dizisi ve `(truncated...)` işareti
   atılıyor, metnin yarım olduğu `…` ile belli ediliyor.

**DEV VERİSİ GERİ ALINDI:** stub durduruldu, sertifika güven
deposundan silindi, bağlantı gerçek adresine döndü, demo kimlik bilgisi
ve bu turun operasyon/olay/state satırları silindi, üç stok düzeltmesi
ledger'dan geri alındı ve **ledger = projeksiyon eşitliği üç varyantta
da doğrulandı** (`on_hand_after` zinciri de tutarlı: düzeltmeler hep en
son hareketti).

### Bir önceki tur

### §13 · Faz 3 · madde 5 · TOPLU İÇE AKTARMA — CSV (`f234303`)

| Ne | Nerede |
|---|---|
| Ayrıştırıcı | `Catalog/Support/CsvProductParser` (+ `CsvParseResult`) |
| Action | `Catalog/Actions/ImportProducts` (+ `ImportResult`) |
| İş | `Catalog/Jobs/ImportProductsJob` (kuyruk **`listing:bulk`**) |
| Durum | `product_imports` tablosu + `ProductImport` modeli |
| Ekran | `Products/Import.vue` · GET+POST `/products/import` |
| Testler | `CsvProductImportTest` (16) + `ProductImportScreenTest` (12) |

**KAPATILAN BOŞLUK:** satıcı 500 ürününü panelden tek tek giremiyordu.
§17 bu maddeyi "TEMEL" önceliğe koyuyor: ödeme mekanizması olsa bile
ürünlerini sisteme sokamayan satıcı sistemi kullanamaz.

**AYRIŞTIRMA İLE YAZMA AYRI.** `CsvProductParser` saf ve yan etkisizdir;
birleştirilselerdi ondalık ayırıcı / BOM / kolon eşleme kuralları ancak
veritabanı kurup ürün yaratarak test edilebilirdi.

**TÜRKÇE EXCEL BİÇİMİ BİRİNCİ SINIF VATANDAŞ** — gerçek dosya BOM +
noktalı virgül + virgüllü ondalık taşır:
- BOM atılmazsa ilk kolonun adı `"\u{FEFF}sku"` olur ve dosya "sku
  kolonu yok" diye reddedilir — kullanıcı gözüyle kolon ORADA.
- `(float) "1.299,90"` PHP'de **1.0** eder. Kuruşlar değil LİRALAR
  düşer. Virgül varsa nokta BİNLİK ayırıcıdır ve atılır.
- Virgül ondalık olduğunda Excel alan ayırıcısını noktalı virgüle
  çevirir; yalnızca virgül desteklenseydi Türkçe kaydedilmiş her dosya
  tek kolon olarak okunurdu.

**KOLONLAR ADIYLA EŞLENİR, KONUMLA DEĞİL** — konumla eşlenseydi fiyat
kolonu stok sanılır ve 500 ürün yanlış fiyatla kanala giderdi.

**AÇILIŞ STOĞU LEDGER ÜZERİNDEN GİRER** — `CreateProduct` çağrılır,
`inventory_levels` satırına DOKUNULMAZ. Doğrudan yazmak 500 satırlık
dosyada 500 bozuk bakiye ve 500 sahte sürüklenme demekti.

**VAR OLAN SKU GÜNCELLENİR (kullanıcı kararı) ama STOK SATIRDAN
YAZILMAZ.** Satıcının en sık işi toplu fiyat güncellemesidir. Stok
yalnızca ledger yollarından değişir; var olan üründe uygulansaydı
SATILMIŞ mallar bir dosya yüklemesiyle geri gelir ve bakiye kalıcı
bozulurdu — maddenin en tehlikeli hatası: sessiz, geri alınamaz, fazla
satışa yol açar. `applyUpdate()` stok parametresi ALMAZ.

**TEK BOZUK SATIR DOSYAYI DÜŞÜRMEZ** ve tur **TEK TRANSACTION'A
SARILMAZ**: 437. satırdaki hata önceki 436 ürünü geri alsaydı kullanıcı
her denemede baştan başlardı.

**KUYRUK `listing:bulk`** (§15) ve `reconciliation` ile havuz PAYLAŞMAZ
— §15'in açık kuralı. **Yeniden deneme YOK** (`$tries = 1`): içe aktarma
idempotent DEĞİLDİR ve yarıda kalan turda hangi satırın işlendiği
bilinmiyor.

**ON BEŞ MUTASYON, ON BEŞİ DE YAKALANDI** — biri ancak test eklendikten
sonra: `catch (Throwable)` daraltıldığında hiçbir test kırılmıyordu,
çünkü mevcut "bozuk satır" testlerinin hepsi AYRIŞTIRMADA eleniyor ve
yazma yoluna hiç ulaşmıyordu. Yani maddenin en kritik kuralı yazma
tarafında HİÇ SINANMAMIŞTI. Ayrıştırmayı GEÇİP yazarken patlayan satır
testi eklendi (300 karakterlik başlık; `products.title` 255 sınırlı).

**GERÇEK ÇALIŞTIRILDI (gerçek worker + gerçek Türkçe Excel dosyası):**
BOM + noktalı virgül + `"1.299,90"` içeren 5 satırlık dosya
`listing:bulk` kuyruğuna atıldı, `queue:work --queue=listing:bulk` işi
ALDI ve tamamladı. 3 ürün yazıldı (1299.90 / 449.50 / 59.90 doğru
okundu), 2 bozuk satır satır numarasıyla raporlandı. Ledger doğrulandı:
`type=IMPORT`, `delta=12`, `source=product_creation` ve ledger toplamı =
projeksiyon. Ekran Playwright ile sürüldü. Dev verisi geri alındı.

### §13 · Faz 4 · MUTABAKAT PANEL EKRANI (`513480d`)

| Ne | Nerede |
|---|---|
| Controller | `Http/Controllers/ReconciliationController` |
| Ekran | `Pages/Reconciliation/Index.vue` |
| Rota | `GET /reconciliation` (auth + tenant), SALT OKUNUR |
| Navigasyon | `PanelLayout` — Stok ile Kanallar arasında |
| Testler | `ReconciliationScreenTest` (13) |

**KAPATILAN BOŞLUK:** üç mutabakat katmanı da `reconciliation_items`
yazıyordu ve HİÇBİRİ gösterilmiyordu. §17 sürüklenme tespitini "ürünün
temel iddiası", panel görünürlüğünü "destek yükünü belirleyen tek ekran"
diye listeliyor; ikisi birleşince ekranın yokluğu şu demekti: satıcı
kanalda yanlış stok olduğunu ancak müşteri şikâyet edince öğrenir.

**`MANUAL_REVIEW` EN ÜSTTE VE AYRI SAYILIR.** O satırlarda otomatik
onarım DURMUŞTUR; `DRIFT_DETECTED` ile aynı kefeye konsaydı satıcı
"sistem hallediyor" sanır ve tam olarak müdahale bekleyen satırı hiç
görmezdi. Uyarı kutusu ne yapılacağını SOMUT söylüyor.

**ÜÇ SAYI, ÜÇ FARKLI EYLEM.** `REMOTE_UNREACHABLE` sürüklenme SAYILMAZ
(fark kanıtlanmamıştır) ama AYRI gösterilir — sessizce yutulsaydı satıcı
kanalının okunamadığını hiç bilmezdi.

**FAZLA SATIŞTA İKİ DEĞER AYRIŞIR VE İKİSİ DE GÖSTERİLİR** (§17 · P0):
"Bizde 0" (kırpılmış giden değer) + altında "bakiye −2" (ham kanonik).

**LISTING BAŞINA SON KALEM** — her tur yeni kalem yazar; hepsi
listelenseydi üç turdur sürüklenen tek listing ekranı üç satırla
doldururdu. `MAX(id)` KULLANILAMAZ (PostgreSQL'de uuid için `max()` yok,
sorgu patlar) → `DISTINCT ON`.

**ON DÖRT MUTASYON, ON DÖRDÜ DE YAKALANDI** — biri ancak test ölçeği
düzeltildikten sonra: sıralamanın TAMAMEN kaldırılması hiçbir testi
kırmıyordu, çünkü `MANUAL_REVIEW` satırı testte zaten önce yaratılmıştı
ve UUIDv7 zaman sıralı olduğu için sıralamasız da başta geliyordu. Kurgu
ters çevrildi: takılı satır SONRA yaratılıyor, başa gelmesi ancak
sıralamayla mümkün.

**TARAYICIDA DOĞRULANDI** — ekran işi kuralı bu turda İLK KEZ uygulandı.
Demo kiracıda gerçek mutabakat yolundan üç senaryo üretildi (sıradan
sürüklenme, üç tur üst üste `MANUAL_REVIEW`, fazla satış) ve ekran
Playwright ile sürüldü: navigasyon sekmesi, dört özet kartı, uyarı
kutusu, rozetler, "Bizde 0 / bakiye −2", "Son tur: Sıcak (5 dk)" ve
geçmiş filtresi doğrulandı. Dev verisi geri alındı.

### §10 · ONARIM DÖNGÜ EMNİYETİ — 3 TUR KURALI (`355c7a4`)

| Ne | Nerede |
|---|---|
| Sayaç | `Reconciliation/Support/DriftHistory` |
| Durum | `ItemStatus::MANUAL_REVIEW` (yeni) |
| Kapı | `ReconcileConnection::classify()` — geçmişe duyarlı |
| Testler | `RepairLoopSafetyTest` (11) |

**KAPATILAN BOŞLUK:** §10'un VERIFY adımı ve §1 · Karar 13 ("üç tur üst
üste sürüklenmede otomatik onarım duruyor") YAZILMAMIŞTI. Doküman bunu
P0 değer listesinde sayıyor. Onarım sürüm kapısını ATLAR ve
`desired_version`'ı ARTIRMAZ; bedeli, kanal 200 dönüp değişikliği
UYGULAMIYORSA aynı farkın her turda yeniden onarılmasıdır — sıcak
katmanda beş dakikada bir, SONSUZA KADAR.

**SAYAÇ GEÇMİŞTEN TÜRETİLİR, AYRI KOLON YOK** (kullanıcı kararı).
`reconciliation_items` zaten gerçeği taşıyor; ayrı sayaç kolonu, kalem
yazan HER yolun onu da güncellemesini zorunlu kılardı ve biri unutulunca
iki gerçek kaynağı sessizce ayrışırdı. **Sayılan şey ARDIŞIKLIKTIR**,
toplam değil: araya giren eşleşme zinciri KIRAR. Emniyet kalıcı ceza
değildir — kanal düzelip bir tur eşleşince kendiliğinden kalkar.

**`REPAIRED` DURUMU DA YAZILIYOR** (§10). Enum'da vardı ama HİÇ
yazılmıyordu: onarımın tuttuğu hiçbir yerde kayıtlı değildi.

**ON DÖRT MUTASYON: ON ÜÇÜ YAKALANDI, BİRİ HAYATTA KALDI VE KALMALI.**
İkisi ancak müdahaleden sonra:
- **`REMOTE_UNREACHABLE`'ın zinciri KIRMASI** hiçbir testi bozmuyordu. O
  hâlde emniyet pratikte hiç devreye giremezdi: gerçek kanallarda geçici
  hata KURALDIR ve araya giren tek bir ağ hatası sayacı sıfırlayıp sonsuz
  döngüyü baştan başlatırdı. Doğru davranış ÜÇÜNCÜ seçenektir — o tur ne
  SAYILIR ne zinciri KIRAR; YOK SAYILIR.
- **`MANUAL_REVIEW`'ın zinciri uzatmaması** altı turluk testte
  yakalanmıyordu: sayaç yalnızca son 10 kalemi okuyor ve ilk iki
  `REPAIR_QUEUED` hâlâ penceredeydi. Tur sayısı pencereden BÜYÜĞE
  çıkarıldı (14). **YENİ KURAL: bir pencere/limit varsa testin ölçeği o
  pencereyi AŞMALI.**

**HAYATTA KALAN (dürüst sınır):** `DriftHistory`'deki kiracı filtresi.
Sorgu zaten `listing_id` ile daraltılıyor, FK `listings`'e bağlı ve bir
listing TEK kiracıya ait. Gerekçe koda yazıldı, sahte test YAZILMADI.

**GERÇEK ÇALIŞTIRILDI (İNATÇI KANAL: 200 döner, yazmayı UYGULAMAZ):**
tur 1-2 `REPAIR_QUEUED` + iki onarım · tur 3-5 `MANUAL_REVIEW`, onarım
AÇILMADI · kanal düzelince tur 6 `MATCHED` ve emniyet KENDİLİĞİNDEN
KALKTI. Dev verisi geri alındı.

### §10 · ILIK VE SOĞUK MUTABAKAT KATMANLARI (`5df1983`)

| Ne | Nerede |
|---|---|
| Katman enum'ı | `Reconciliation/Enums/ReconciliationScope` |
| Örneklem | `Reconciliation/Support/SampledCandidates` |
| Aday seçimi | `CandidateSelector::for()` artık scope alır |
| Yönlendirme | `ReconcileConnection::selectCandidates()` |
| Komutlar | `reconcile:warm` (saatlik) · `reconcile:cold` (günlük 05:00) |
| Kayıt | `bootstrap/app.php` · Zamanlama `routes/console.php` |
| Testler | `ReconciliationLayersTest` (17) + `ScheduledScansTest`'e 9 iddia |

Dokümanın bütçe tablosu (§10):

| Katman | Sıklık | Kapsam | Bütçe |
|---|---|---|---|
| Sıcak | 5 dakika | 30 dk satış · geçici hata · 1 sa bekleyen | ≤ 50 |
| Ilık | Saatlik | 24 sa satış · 24 sa bekleyen | ≤ 300 |
| Soğuk | Günlük | Rastgele örneklem — uzun kuyruk | %2, üst sınır 500 |

**BEŞ ADIMLI AKIŞ YENİDEN KULLANILIR.** DETECT/RECORD/CLASSIFY/REPAIR/
VERIFY üç katmanda da AYNIDIR; değişen yalnızca aday seçimi ve bütçedir.
Akış katman başına kopyalansaydı üç kopya zamanla ayrışır ve
`max(available, 0)` gibi bir kural birinde düzeltilip ötekilerde eski
hâliyle kalırdı.

**PENCERELER KATMANDAN GELİR** (`ReconciliationScope`), sorguya gömülü
değil. Gömülü olsaydı ılık katman `CandidateSelector`'ın bir KOPYASI
olarak yazılırdı. Ilık katman sıcakla AYNI eşikleri kullansaydı 300'lük
bütçesini sıcak turun her beş dakikada bir zaten baktığı satırlarla
doldurur ve HİÇBİR ŞEY EKLEMEZDİ.

**SOĞUK KATMAN DÖRT SEBEP SORGUSUNU ÇALIŞTIRMAZ — maddenin tüm
gerekçesi budur.** Uzun kuyruk tam olarak o dört sebebin hiçbirine
takılmayan satırdır: satmıyor, hata almamış, bekleyen işi yok,
sürüklenme geçmişi yok. Satıcı kanal panelinden stoğu elle değiştirdiyse
o sürüklenme sıcak ve ılık katmanlarda SONSUZA KADAR görünmez. Dört
sorgu soğukta da koşsaydı soğuk katman ılığın günlük bir kopyası olur ve
500'lük bütçenin çoğunu ılık turun bir saat önce zaten baktığı satırlar
yerdi.

**SIRALAMA `last_observed_at NULLS FIRST` — "rastgele" DEĞİL, EN ESKİ.**
Doküman kapsamı "rastgele örneklem" diye adlandırıyor ama §4 bu iş için
AÇIKÇA `sync_states_observed_idx (domain, last_observed_at NULLS FIRST)`
tanımlıyor ve o indeksin başka hiçbir kullanıcısı yok. `ORDER BY
random()` hem indeksi kullanamaz (her turda tam tarama) hem de %2
bütçeyle bir satırın AYLARCA seçilmemesi demektir. `NULLS FIRST` kritik:
hiç gözlenmemiş satır sürüklenmeye en açık olandır ve `NULLS LAST`
olsaydı dar bütçede ASLA seçilmezdi.

**SOĞUK BÜTÇE ORANSALDIR, 500 yalnızca ÜST SINIR.** Sabit 500 kullanmak
50 listing'i olan bağlantıda TAM KATALOG TARAMASI demektir ve o hiçbir
katmanda yoktur. Alt sınır 1: küçük katalogda %2 sıfıra yuvarlanır ve
soğuk katman o satıcılar için HİÇ çalışmazdı.

## Mutasyonla sınandı — ON YEDİ mutasyon, ON YEDİSİ DE YAKALANDI

Ama ÜÇÜ ancak test veya düzeltme eklendikten sonra:

**1 · `lifecycle_status = 'live'` yükleminin kaldırılması hiçbir testi
kırmıyordu.** Sebep incedir: yalnızca draft satır içeren bir bağlantıda
`activeListingCount()` sıfır döner, bütçe sıfır olur ve `for()` SQL'e HİÇ
GELMEDEN çıkar — yani yüklem o senaryoda çalışmıyordu bile. Karışık
katalog testi eklendi (canlı + taslak) ve **taslak satır ÖNCE yaratıldı**:
her iki satır da hiç gözlenmemiş olduğu için sıralama `l.id ASC`
tie-breaker'ına düşer ve listing kimlikleri UUIDv7 — ZAMAN SIRALI —
olduğundan önce yaratılan başa gelir. Canlı satır önce yaratılsaydı bir
kişilik bütçe onu seçer, taslağa hiç sıra gelmez ve test YİNE SAHTE
YEŞİL kalırdı.

**2 · `reconcile:cold` komutunun scope'u `WARM`'a çevrildiğinde hiçbir
test kırılmıyordu.** Komut kayıtlıydı, zamanlanmıştı, sıfırla çıkıyordu ve
sweeper'ı gerçekten çağırıyordu — yalnızca YANLIŞ KATMANI sürüyordu.
Sonuç: uzun kuyruk hiç taranmaz ve `schedule:list` kusursuz görünür.
Kayıt testi, frekans testi ve "başarıyla çalışır" testinin ÜÇÜ DE bunu
göremez: hepsi komutun VAR OLDUĞUNU sınar, NE YAPTIĞINI değil. Komutu
gerçekten çalıştırıp yazılan turun `scope` alanını okuyan test eklendi ve
üç komut bağlaması da ayrı ayrı doğrulandı.

**3 · GERÇEK ÇALIŞTIRMADA BULUNDU — bütçe tabanı ile örneklem havuzu
AYRIŞIYORDU.** Dev veritabanında sayım 3 dedi, örneklem 2 satır döndü:
`activeListingCount()` `error_permanent` satırlarını sayıyor, örneklem
onları hariç tutuyordu. Kalıcı hataya düşmüş satırı çok olan bir
bağlantıda bütçe gerçekte taranabilecek satır sayısının ÜSTÜNE çıkar ve
"aktif listing'lerin %2'si" kuralı sessizce daha büyük bir orana dönerdi
— sapma tam da oranın en çok korumak istediği yerde (büyük katalog, çok
hatalı satır) en büyük olur. Sayım havuzla aynı yüklemleri taşıyacak
şekilde düzeltildi ve testi yazıldı. Testler bunu göremezdi çünkü küçük
kataloglarda alt sınır 1 her iki hesabı da aynı sayıya indiriyor.

## RASTGELE SIRADA DÜŞÜŞ — YAKALANDI VE DÜZELTİLDİ

Yeni `each_command_drives_its_own_layer` testi `latest('started_at')`
kullanıyordu ve **altı turda bir düşüyordu**. Sebep:
`reconciliation_runs.started_at` SANİYE hassasiyetlidir ve üç komut aynı
saniye içinde koştuğunda ikisi AYNI damgayı taşır; hangisinin "son"
olduğu belirsiz kalır ve sorgu bazen ılık turu döndürür. Sıralama `id`'ye
(UUIDv7 — zaman sıralı ve saniye içinde de ayırt edici) alındı.
Düzeltmeden sonra **sekiz ardışık rastgele tur temiz** ve mutasyon
koruması korundu (mutasyon altında hâlâ kırmızı).

Bu, projenin zaman damgası hassasiyeti tuzağının bir kez daha tekrarı —
bu kez `outbox_events` değil `reconciliation_runs` üzerinde ve sorguda
değil TESTTE.

## GERÇEK ÇALIŞTIRILDI — gerçek HTTP + gerçek worker

Yerel TLS stub'ı (`host.docker.internal:9911`) kanal olarak kullanıldı,
sertifika container'ın güven deposuna eklendi ve tur bitince kaldırıldı.

1. **Soğuk tur örneklemle aday seçti** (`reason=sampled` — dört sebebin
   hiçbiri değil), Woo adapter'ını sürdü ve gerçek istek attı:
   `GET /products?include=4242&per_page=100`.
2. **Sürüklenme bulundu:** kanonik `available=17`,
   `expected_remote=17`, kanal `99` → `DRIFT_DETECTED`, `magnitude=82`.
3. **Onarım açıldı:** `intent=REPAIR`, anahtar
   `inv:{listing}:4:repair:{reconciliation_item_id}` — kalem kimliğini
   çıpa olarak taşıyor. **`desired`/`synced` 4'te KALDI** (§10: onarım
   sürüm kapısını atlar ve sürümü ARTIRMAZ).
4. **`queue:work --queue=inventory:high` `PushInventory`'yi çalıştırdı**
   ve kanala kanonik değer gitti:
   `POST /products/batch {"id":4242,"stock_quantity":17}`.
5. **Doğrulama AYRI turda:** kanal artık 17 döndürünce SICAK tur kalemi
   `drift_detected` sebebiyle yeniden aday etti ve **MATCHED** yazdı —
   sürüklenme kapandı ve bir sonraki tur onu artık aday etmeyecek.

Ayrıca doğrulandı: `error_permanent` satırı için SIFIR kalem yazıldı
(üç katmanda da), draft satır örneklenmedi, `schedule:list` üç katmanı da
doğru cron ifadesiyle gösteriyor (`*/5 * * * *` · `0 * * * *` ·
`0 5 * * *`). **Dev verisi geri alındı** (bu oturumun 50 turu, 14 kalemi
ve 1 onarım operasyonu silindi; 17 Ağustos'tan kalan 6 tur korundu).

### Bir önceki tur — §13 · Faz 3 · fiyat senkron yolu (`d17aa8a`)

Tetikleyici `UpdateProduct` → `VariantPriceChanged` → fan-out tüketicisi
→ `PRICE_PUSH` → `PushPrices` (kuyruk **`price:high`**). Gruplama
`PriceBatchBuilder`'da, bağlantı başına. `pushPrices` gövdeleri (Woo VE
Trendyol) ilk günden beri hazırdı ama **çekirdekte çağıranı yoktu**.
Kuyruk adı `price:default` yazılmıştı; §15 ve `config/horizon.php`
`price:high` diyor — düzeltildi ve adı Horizon yapılandırmasıyla
karşılaştıran test eklendi.

### Bir önceki tur — §13 · Faz 3 · RequestResync + T10 (`9ec5ac0`)

`error_permanent → pending` geçişi AYNI transaction içinde
`ListingResyncRequested` yazar; durum değişikliği tek başına hiçbir iş
üretmez. Niyet REPAIR ve ayırt edici çıpa OLAY KİMLİĞİDİR
(`resync:` ön eki, mutabakatın `repair:` ön ekinden ayrı).

### Bir önceki turlar

**`PruneApiCalls` (`a452a27`):** ölçüt `expires_at`, durum kodu değil;
silme partilenir; tur başına üst sınır var; transaction YOK.

**Sipariş güncelleme + kargo (`ab4bffe`):** ikisi de stok hareketi
ÜRETMEZ; NULL "değişmedi" demektir; paket başına tek satır.

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 829 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
npm run build                                 # YERELDE (container'da Node yok)
```

Panel: `/` özet · `/products` ürünler · `/products/import` toplu içe
aktarma (**CSV + kanaldan çekme**) · `/products/{id}/channels` kanala
gönderme · `/orders`
siparişler · `/inventory` stok · `/reconciliation` mutabakat ·
`/failures` başarısız işlemler · **`/metrics` sistem sağlığı** ·
`/channels` kanallar · `/mappings` eşleştirme · **`/billing` abonelik**

Panel gerçek çalıştırması: `http://localhost:8080` ·
`demo@entegrasyon.local` / `demo12345`

## YOL HARİTASI — NE BİTTİ, NE KALDI (20 Ağustos 2026)

### Bitti

**Çekirdek:** stok ledger'ı (`ApplyMovement`, `LockInventoryRows`), outbox
relay + fan-out, adapter mimarisi (**8 yetenek arayüzü** — sekizincisi
geçmiş turda eklendi: `SupportsCatalogImport`), sipariş alımı,
gelen hat (webhook → inbox → router), giden hat (`InventoryBatchBuilder`,
`PushInventory`, `SyncResultRecorder`), koruma katmanı (`ChannelRateLimiter`,
`CircuitBreaker`), ürün aktarımı (`PushListing`, `PublishListing`),
§6 bütünlük taramaları (iki seviye), **§10 mutabakatın ÜÇ KATMANI DA**
+ **onarım döngü emniyeti (3 tur kuralı)**,
ön koşul kapısı + onay takibi, sipariş yoklaması, sipariş güncelleme +
kargo, `PruneApiCalls`, resync yolu, fiyat senkron yolu, kanaldan ürün
çekme, **metrik toplama + uyarı e-postaları (`alerts:dispatch`)**.

**Kanallar (2):** WooCommerce (tam — **içe aktarma dahil**) · Trendyol
(taksonomi, katalog, onay, stok/fiyat itme, sipariş yoklaması).

**Panel (12 ekran):** özet · ürünler · ürün oluştur/düzenle · toplu
içe aktarma (**iki kaynak: CSV + kanal**) · ürün-kanal · siparişler ·
sipariş ayrıntısı · stok · mutabakat · başarısız işlemler · **sistem
sağlığı** · kanallar · eşleştirme.

**Testler:** 829 yeşil (2673 assertion).
**P0/P1'in TAMAMI yeşil** — T1…T12. Yazılmamış P0/P1 testi KALMADI.

**Faz 4:** onboarding akışı (`a118b3a`) — dört adım, VERİDEN türetilen
ilerleme, her panel ekranında şerit.

### Kaldı — FAZ 4 (panel + abonelik)

**Faz 3'ten kalan madde YOK.** Sıra kullanıcının kararına bağlı;
teknik bağımlılık yok.

- ~~**Onarım döngü emniyeti**~~ — **BİTTİ** (`355c7a4`). Çekirdek ön
  koşuldu: ekran onu göstermek zorundaydı.
- ~~**Mutabakat panel ekranı**~~ — **BİTTİ** (`513480d`), tarayıcıda
  doğrulandı.
- ~~**Toplu içe aktarma (CSV)**~~ — **BİTTİ** (`f234303`), gerçek
  worker'da ve tarayıcıda doğrulandı.
- ~~**Kanaldan ürün çekme**~~ — **BİTTİ** (`99008b8`), gerçek HTTP +
  gerçek worker + tarayıcıda doğrulandı. Faz 3 · madde 5'i kapatır;
  §7'ye SEKİZİNCİ yetenek arayüzü eklendi (kullanıcı onaylı sapma).
- ~~**Uyarı e-postaları**~~ — **BİTTİ** (`bbe2852`), gerçek komut +
  gerçek posta sürücüsüyle doğrulandı. **FAZ 3'Ü KAPATIR.** SMTP
  sağlayıcısı şimdilik `log`; geçiş TEK bir `.env` satırıdır ve KOD
  DEĞİŞMEZ.
- ~~**Onboarding akışı**~~ — **BİTTİ** (`a118b3a`), gerçek tarayıcıda
  uçtan uca sürüldü. İlerleme VERİDEN türetilir; `tenants`'a kolon
  eklenmedi.
- **Panel cilası** (§13 · Faz 4, 20 sa): boş durumlar, yükleniyor, mobil.
  Artık ON İKİ ekrana + onboarding şeridine dokunur. **Onboarding'den
  SONRA gelmeli**: boş durum metinleri şeridin söylediğiyle
  çelişmemeli.
- ~~**Abonelik: şema + kota**~~ — **BİTTİ** (`d02b984`). `plans` ·
  `subscriptions` · `EnforceQuota`; ürün ve kanal yollarına bağlı ve
  tarayıcıda doğrulandı. İki kota: ürün sayısı · kanal sayısı.
- ~~**Abonelik: Stripe tahsilat hattı**~~ — **BİTTİ** (`6f89fe1`).
  Checkout + webhook + `/billing` ekranı. **AÇIK UÇ: gerçek anahtarla
  sürülmedi** — `.env`'e KULLANICI yazar (`STRIPE_KEY`,
  `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`) ve sonra test kartıyla
  uçtan uca doğrulanmalı.
- **Türkçe yardım dokümantasyonu** (12 sa).
- **Güvenlik kontrol listesi + yük testi** (12 sa).
- **Onay durumu için ayrı ekran** (rozet + red sebebi ürün-kanal
  ekranında var). En küçük madde.

**Trendyol'da kapsam dışı bırakılanlar** (eksik DEĞİL): `delist`,
`fetchListing`, `acknowledgeOrder` — kargo §14 gereği kapsam dışı.

### KULLANICI KARARI — YENİ PAZARYERLERİ (19 Ağustos 2026)

**Bu maddeler FAZ 4 BİTTİKTEN SONRA ele alınır.** Sıra (kullanıcı aksini
söylemedikçe):

1. **Hepsiburada** — TR pazarı, Trendyol'a en yakın model (taksonomi +
   zorunlu öznitelik + onay süreci). `ListingMapper` · `TaxonomyClient` ·
   `TrackApprovalStatus` kalıbı doğrudan örnek olur; en düşük riskli.
2. **Amazon (SP-API)** — en büyük iş değeri, en yüksek karmaşıklık: LWA
   OAuth + farklı rate limit modeli, feed tabanlı asenkron aktarım
   (`submitFeed` → `getFeedResult` yoklaması), FBA/FBM ayrımı. Muhtemelen
   §7'ye yeni bir yetenek arayüzü gerekir — bu MİMARİ bir karardır ve
   dokümana bakılmadan yapılmaz.
3. **Etsy** — OAuth 2.0 + PKCE, "taxonomy_id" + shop section modeli;
   envanter uç noktası varyant bazlı ve Woo'dan farklı.
4. **eBay** — Inventory API (offer/inventory item ayrımı) + politika
   nesneleri (payment/return/fulfillment policy) bağlantı kurulumunda
   zorunlu. `channel_connections.settings` bunu taşıyabilir ama bağlama
   akışı ekstra adım ister.

**Shopify BU LİSTEDE DEĞİL** — kullanıcı açıkça istemedi. Memory'deki
"Teknoloji Kararları" notu "Laravel + Node Shopify app" diyor; o karar
artık geçerli değil.

**MİMARİ SÖZ:** yeni kanal eklemek çekirdeği DEĞİŞTİRMEMELİ. Kanal başına
bir adapter + (varsa) mapper/normalizer; stok matematiği, outbox,
fan-out, kilit ve mutabakat aynı kalır. `if ($channel === '...')`
YAZILMAZ — yetenek `instanceof` ile okunur. Kanal başına kabaca
**40–60 saat**, Amazon'da daha fazla.

## Demo verisi panelde duruyor

`demo@entegrasyon.local` / `demo12345` — gezilebilir bir kiracı.
6 ürün, 2 kanal bağlantısı, `demo-v3` taksonomisi (4 yaprak) ve
**bilinçli olarak KISMİ** eşleştirmeler: `mutfak` eşleşmedi ·
`kadin-elbise` zorunlu öznitelik eksik (Renk) · `tisort` hazır.
`TSH-201` fazla satış taşıyor (bakiye −3).

Bu veri commit'lerde DEĞİL, yalnızca yerel veritabanında.

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
11. **Testte "işi çalıştır" derken reflection'a sapma.**
12. **Adapter gövdesi yazdıysan ÇEKİRDEĞİN ONU SÜRDÜĞÜNÜ de sına.**
13. **Ekran yazdıysan TARAYICIDA sür.** Playwright CLI ile giriş yap,
    snapshot al, ekran görüntüsü al ve GÖZLE bak. Mutabakat ekranı bu
    turda böyle doğrulandı; `npx --package @playwright/cli playwright-cli`
    ile çalışır (wrapper script kurulu değil). `open` YENİ OTURUM açar ve
    çerezi düşürür — giriş sonrası navigasyonu menü linkine tıklayarak yap.
14. **`--order-by=random` ile en az birkaç tur koş.** Geçmiş bir turda
    yeni bir test altı turda bir düşüyordu ve sıralı koşuda ASLA
    görünmezdi.

## Mutasyonla / gerçek çalıştırmayla bulunan gerçek boşluklar (tarihçe)

Hepsi testler yeşilken bulundu:

- **`MetricUnit::format(0)` BOŞ DİZE döndürüyordu** (bu tur) — `rtrim`
  sayının TAMAMINA uygulanıyordu. Uyarı e-postası "uyarı eşiği: " diye
  bitiyordu ve sıfır eşikli iki metrik tam da uyarı ÜRETENLERDİ: hata
  HER e-postada görünecekti. Aynı kırpma `10`'u `1` yapardı.
- **Kanal stoğunun var olan SKU'ya uygulanması** (mutasyon, geçmiş tur) —
  satılmış mallar bir içe aktarma turuyla geri gelir, bakiye sessizce
  bozulur ve fazla satışa yol açardı.
- **Sabit seride sparkline kutunun dibine çiziliyordu** — "değer dibe
  vurdu" izlenimi, oysa değer hiç değişmemişti.
- **Eşiğe tam dayanan metrik hiçbir şey söylemiyordu.**
- **Yeniden deneme butonunun geri bildirimi hiç görünmüyordu** —
  uydurma flash anahtarı.
- **Hata ekranı ham istisna metni gösteriyordu** — satıcı kaçış dizisi
  okuyordu ve ekranın amacı boşa çıkıyordu.
- **İki savunmadan biri kiracı sızıntısı mutasyonunu gizliyordu.**
- **Bütçe tabanı örneklem havuzuyla ayrışıyordu** (§10 soğuk katman).
- **Komut kayıtlı ve zamanlı olup YANLIŞ KATMANI sürebiliyordu.**
- **`supports_webhooks` eager-load'da seçilmiyordu** — webhook kapısı ölüydü.
- **`pushPrices`'ın çekirdekte çağıranı yok** — Woo dahil.
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

- **Router'ın `FULFILLED` dalı ve kargo olay çıpası** — hiçbir normalizer
  `fulfilled` tipi ÜRETMİYOR (Woo ayrı webhook göndermiyor, Trendyol'da
  kargo §14 gereği kapsam dışı). O olayı üreten kaynak olmadan davranış
  testi yazılamaz.
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
- **`PruneApiCalls`'ta `expires_at < ` → `<=`** — kolon saniye
  hassasiyetli, `clock_timestamp()` mikrosaniye taşır; eşitlik ulaşılamaz.
- **`PruneApiCalls`'ta `while ($deleted < $maxRows)` → `while (true)`** —
  `min()` clamp'i sınırı zaten uyguluyor.
- **`PruneApiCalls`'ta `clock_timestamp()` → `now()`** — tur transaction
  DIŞINDA çalışıyor. Kural "TRANSACTION YOK" kararı geri alınırsa diye.
- **`PruneApiCalls`'ta `runAsSystem` kaldırma** — `api_calls`'un modeli
  YOK, global scope hiç uygulanmıyor.

## Tekrar tekrar ısıran tuzaklar

- **`HandleInertiaRequests::share()` KİRACI BAĞLAMI KURULMADAN ÖNCE
  ÇALIŞIR.** `share()` `web` grubundadır, `EstablishTenantContext` ise
  ROTA seviyesinde bir alias (`tenant`) — yani `web` önce koşar ve
  `$request->attributes->get('tenant')` metot gövdesinde HER ZAMAN
  null'dur. Var olan `tenant` prop'unun çalışıyor olması yanıltır:
  Inertia prop'ları yanıt üretilirken ÇÖZER, ama senin metot gövdende
  yazdığın `if` ZATEN çalışmıştır. **Kiracıya bağlı her yeni paylaşılan
  prop'ta kontrol KAPANIŞIN İÇİNDE yapılmalı** (`fn () => ... `).
  Onboarding turunda bulundu: 13 test prop null döndüğü için kırmızı
  kaldı.
- **ÖLDÜRÜLEN TEST TURU BAYAT BİR POSTGRESQL BACKEND'İ BIRAKIR VE
  SONRAKİ TÜM TURLARI ASAR.** Bu turda sonsuz döngü üreten bir mutasyon
  (sayfa üst sınırının kaldırılması) turu öldürmeyi zorunlu kıldı;
  geride kalan backend `entegrasyon_test` üzerinde kilit tutuyor ve
  `RefreshDatabase`'in `DROP TABLE`'ını BLOKLUYOR. Belirti YANILTICI:
  **sonraki mutasyonlar "takılıyor" sanılır**, oysa kod sağlamdır ve
  sorun tamamen ortamdadır. Çözüm:
  ```sql
  SELECT pg_terminate_backend(pid) FROM pg_stat_activity
   WHERE datname = 'entegrasyon_test' AND pid <> pg_backend_pid();
  ```
  Ayrıca: **macOS'ta `timeout` komutu YOKTUR** — sonsuz döngü üretme
  ihtimali olan bir mutasyonu koşarken süreyi başka bir yolla sınırla
  (arka planda çalıştırıp kendin öldür), yoksa tuzak her seferinde
  yeniden kurulur.
- **AYNI YARDIMCI HEM YAZAR HEM OKURSA BİÇİM MUTASYONU GİZLENİR — İKİ
  TURDA İKİ KEZ ISIRDI.** Önce `MetricScope::tenant()`, sonra
  `AlertKey`: her ikisinde de öneki değiştirmek hiçbir davranış testini
  kırmadı, çünkü yazan da okuyan da aynı fonksiyonu çağırıyor ve
  BİRLİKTE kayıyorlar. Ama kalıcı veri eski biçimde durur ve yeni
  okuyucu onu HİÇ BULAMAZ. Bedeli her iki yerde de farklı ve her ikisi
  de sessiz: `MetricScope`'ta grafik sıfırlanır, `AlertKey`'de DÜNKÜ
  gönderim satırı bulunamaz ve **aynı gün tekrar koruması yok olur** —
  satıcı aynı uyarıyı ikinci kez alır. **KURAL: kalıcı veriye yazılan
  BİÇİMİ üreten her yardımcı, BEKLENEN LİTERAL METİNLE sınanır
  (sözleşme testi)** — davranış testi bunu ASLA göremez. Kuyruk
  adlarının `config/horizon.php` ile karşılaştırılması aynı gerekçedir.
  Yeni bir "anahtar/kapsam/önek üreten yardımcı" yazdığında sözleşme
  testini AYNI TURDA yaz.
- **`rtrim($s, '0')` SAYININ TAMAMINA UYGULANIRSA `"0"` → `""` VE
  `"10"` → `"1"`.** Gereksiz sıfırları atmanın yaygın kısayolu, sıfır
  değerini TAMAMEN SİLER: e-posta "uyarı eşiği: " diye biter ve satıcı
  eşiği HİÇ göremez. Kırpma yalnızca ONDALIK KISMA uygulanır.
  Biçimleme yardımcısı yazdıysan **sıfırı ve ondalıksız tam sayıyı
  AYRI AYRI sına** — sıfır eşikli metrikler tam da uyarı üretenlerdi.
- **`span || 1` SPARKLINE'I DİBE ÇİZER.** Sabit seride `max - min = 0`
  olur; sıfırı `1`e sabitlemek (yaygın kısayol) tüm noktaları kutunun EN
  ALTINA koyar ve "değer dibe vurdu" izlenimi verir — oysa değer HİÇ
  DEĞİŞMEDİ. Sabit seri ORTADAN çizilmeli.
- **EŞİĞE TAM DAYANAN DEĞER SESSİZ KALIR.** `>` kuralı doğrudur (5 > 5
  yanlıştır) ama satıcı bir adım ötede olduğunu göremez. Aşım ile
  "yakın" AYRI işaretlenmeli; sıfır eşikli metrikte "yakın" YOKTUR —
  her sağlıklı ölçümü sarıya boyardı.
- **İKİ SAVUNMA VARSA BİRİ MUTASYONU GİZLER.** Ölü mektup ekranında
  toplu denemenin kiracı kapsaması hem operasyon sorgusunda hem
  `listing` ilişkisindeydi; ilişkininki yabancı satırı NULL'a düşürüp
  atlıyordu ve **operasyon sorgusundan kapsama tamamen kaldırılsa bile
  test yeşildi**. Tek satırlık bir eager-load değişikliği o savunmayı
  sessizce düşürür. Testte ikinci savunmayı **DEVRE DIŞI BIRAK** ve
  korumayı yalnızlaştır.
- **FLASH ANAHTARI UYDURULAMAZ — PANELİN PAYLAŞTIĞI AD OLMALI.**
  `HandleInertiaRequests::share()` yalnızca `success` ve `warning`
  paylaşıyor. `status` gibi bir ad Inertia'ya HİÇ ULAŞMAZ: istek
  başarılı olur, iş yapılır, kullanıcı hiçbir geri bildirim görmez.
  Kuyruk adı uydurma tuzağının panel karşılığı. **Testler görmez** —
  hiçbiri flash okumuyorsa.
- **GUZZLE HATA GÖVDESİNİ 120 KARAKTERDE KESER** ve `(truncated...)`
  ekler; JSON kapanmaz ve `json_decode` düşer. Gerçek kanal mesajları
  neredeyse her zaman daha uzun olduğu için **yalnızca tam gövdeyi
  çözen bir ayrıştırıcı pratikte HİÇ çalışmaz** — kırpık gövdeden
  `message` alanı regex ile çekilmeli, yarım kaçış dizisi (`\u00`)
  atılmalı. Ham metin ekrana basılırsa satıcı `ürün` okur.
- **`latest('<timestamp>')` KULLANMA — kodda da testte de.** Geçmiş bir
  turda İKİ mutabakat testinde ve BİR controller'da bulundu; beş turda bir rastgele
  düşüş üretiyordu. Zaman damgaları SANİYE hassasiyetli ve aynı saniyede
  yazılan satırlarda sıra belirsiz. UUIDv7 birincil anahtar zaman
  sıralıdır: **`orderByDesc('id')` kullan.** `DashboardController` örneği
  daha sinsiydi — fan-out tek olaydan onlarca operasyonu aynı saniyede
  açıyor ve "son 15" her yenilemede farklı bir alt küme gösterebiliyordu
  (testi yoktu, o yüzden düşüş de görünmüyordu).
- **PENCERE/LİMİT VARSA TESTİN ÖLÇEĞİ O PENCEREYİ AŞMALI.** `DriftHistory`
  yalnızca son 10 kalemi okuyor; altı turluk test mutasyonu yakalayamadı
  çünkü eski kalemler hâlâ penceredeydi. Ölçek 14'e çıkarılınca yakalandı.
- **UUIDv7 ANAHTAR, SIRALAMASIZ TESTİ SAHTE YEŞİL TUTAR.** Kimlikler zaman
  sıralı olduğundan satırlar YARATILIŞ sırasında gelir; beklediğin satırı
  ÖNCE yaratırsan sıralamanın tamamen kaldırılması testi kırmaz. Elenmesini
  ya da sonda gelmesini beklediğin satırı ÖNCE yarat.
- **`(float) "1.299,90"` = 1.0** — Türkçe Excel biçimi. Kuruşlar değil
  LİRALAR düşer. Virgül varsa nokta BİNLİK ayırıcıdır ve atılır. Türkçe
  Excel ayrıca BOM ekler ve alan ayırıcısını NOKTALI VİRGÜLE çevirir;
  üçü birden ele alınmalı.
- **PostgreSQL'de `max(uuid)` YOKTUR** — `MAX(id)` ile "grup başına son
  satır" sorgusu doğrudan patlar. `DISTINCT ON (col) ... ORDER BY col, id
  DESC` kullanılır.
- **`TenantAwareJob::handle()` FINAL'dir** — alt sınıf `handleForTenant()`
  yazar ve bağımlılığı `app()` ile alır (imza değiştirilemez).
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
- **Statik fabrika ile örnek metodu AYNI ADI paylaşamaz** (PHP).
- **`inventory_movements` kolonu `type`, `movement_type` DEĞİL.**
- **`channel_connections` kolonu `label`, `name` DEĞİL.**
- **`api_calls` zaman kolonu `called_at`** — `created_at` YOK.
- **`sync_operations`'ta `listing_id` YOK** — `entity_type` + `entity_id`
  ve `domain` da YOK (`operation_type` var). Tinker'da ısırdı.
- **`RemoteListing` parametresi `url`**, `externalUrl` DEĞİL.
- **`ErrorClass` case'i `RATE_LIMITED`**, `RATE_LIMIT` DEĞİL.
- **`SyncOperationStatus`'ta `FAILED` YOK** — kalıcı hata `DEAD`.
- **`OpenSyncOperation` `Sync\Actions\` altında** ve parametresi
  `eventVersion`; dönüşü NULLABLE.
- **`TenantContext` metodu `runFor()`**, `run()` DEĞİL.
- **`MissingTenantContextException` `Support\Tenancy\Exceptions\` altında.**
- **`assertLedgerMatchesProjection()` ÜÇ argüman alır.**
- **`(channel_type_code, external_account_id)` GLOBAL tekildir** — aynı
  test içinde iki kez `connection()` çağırmak kısıtı ihlal eder.
- **`clock_timestamp()`** — zaman damgaları saniye hassasiyetli.
- **PostgreSQL'de `interval ?` BAĞLANAMAZ** — `?::interval` cast'i kullan.
  Metni sorguya gömmek katman değerini enjeksiyon yüzeyine taşır.
- **`Command::run()` REZERVE İMZADIR.** Mantık `Support/` altında.
- **Domain komutları otomatik keşfedilmez** — `bootstrap/app.php`.
- **`QUEUE_CONNECTION=sync` gerçek worker'ı taklit etmez.**
- **Açılış stoğu ledger üzerinden girer** (IMPORT).
- **Eşzamanlılık testi `RefreshDatabase` ile yazılamaz** → `DatabaseTruncation`.
- **`StoreUrl` HTTPS'i zorunlu tutar** — yerel stub'a TLS eklenir ve
  sertifika container'ın güven deposuna konur
  (`/usr/local/share/ca-certificates/` + `update-ca-certificates`).
- **CI'da `public/build` yoktur** — `Tests` job'ı `npm run build` çalıştırır.
- **CI'da `codeload` 429'u** — `--prefer-source` ile kaynak yoldan denenir.
- **Eager-load'da OKUNACAK HER ALAN AÇIKÇA SEÇİLMELİ.**
- **KUYRUK ADI UYDURULAMAZ — HORIZON'UN DİNLEDİĞİ AD OLMALI.** Adlar §15
  tablosunda ve `config/horizon.php` içinde: `orders:high` ·
  `inventory:high` · `price:high` · `listing:default` · `inbox:process` ·
  `outbox:consume` · `reconciliation` · `listing:bulk` · `maintenance`.
- **YENİ KUYRUK İŞİ `JobSerializationTest`'E DE EKLENİR.** Testler işi
  doğrudan kurup `handle()` çağırdığı için serileştirme gidiş-dönüşü
  hiç yaşanmaz; o test kuyruğa giren HER işi yazıp geri okur.
  `ImportProductsFromChannelJob` geçmiş turda oraya eklendi. **Bu turun
  `alerts:dispatch` yolu kuyruk işi AÇMAZ** — Mailable bilinçli olarak
  `ShouldQueue` uygulamıyor (gerekçesi yukarıda).
- **YENİ YETENEK ARAYÜZÜ `AdapterRegistry::capabilitiesOf()`'A DA
  EKLENİR.** Arayüz yazılıp adapter uygulasa bile registry anahtarı
  (`catalog_import`) yoksa panel yeteneği HİÇ GÖRMEZ ve kanal seçim
  listesi sessizce boş kalır.
- **YÜK OPERASYON LİSTESİ TAŞIMAZSA `SyncResultRecorder` HİÇBİR ŞEY YAZAMAZ.**
- **MEVCUT BİR TESTİN PREMİSİ BAYATLAYABİLİR.** Bir yol yazdığında onu
  "yok" varsayan testleri ARA.
- **TÜKETİCİYİ DOĞRUDAN ÇAĞIRAN TEST YÖNLENDİRMEYİ SINAMAZ.** Yeni bir
  outbox olay tipi eklediysen `ConsumeOutboxEvent`'in `match` dalını da sına.
- **REPAIR NİYETİ AYIRT EDİCİ ÇIPA İSTER.**
- **SINIR TESTİ YAZDIYSAN İKİ OPERATÖR ALTINDA DA GEÇMEDİĞİNİ DOĞRULA.**
  Farkın göründüğü ÖLÇEĞİ kullan (gün değil saniye) ve mutasyonla doğrula.
- **KOMUT KAYITLI + ZAMANLI + BAŞARILI OLUP YİNE DE YANLIŞ İŞİ YAPABİLİR.**
  Üç test de komutun VAR OLDUĞUNU sınar, NE YAPTIĞINI değil. Komut yeni
  bir parametre/mod alıyorsa **onu gerçekten çalıştırıp yazdığı satırı
  oku** (geçmiş bir turda `reconcile:cold`'un ILIK katmanı sürmesi
  hiçbir testi kırmıyordu).
- **ERKEN ÇIKIŞ, ARKASINDAKİ SQL YÜKLEMİNİ TEST DIŞI BIRAKIR.** `for()`
  bütçe sıfırken SQL'e hiç gelmiyordu; o senaryoyla yazılan test
  yüklemin kaldırılmasını GÖREMEZ. Filtreyi sınayan testi, sorgunun
  GERÇEKTEN KOŞTUĞU bir kurulumda yaz.
- **TESTTE `latest('<timestamp>')` SIRALAMAYI GARANTİ ETMEZ.** Zaman
  damgaları saniye hassasiyetli; aynı saniyede yazılan iki satırda sıra
  belirsizdir ve rastgele sırada aralıklı düşüş üretir. UUIDv7 birincil
  anahtar zaman sıralıdır — **`orderByDesc('id')` kullan.**
- **`api_calls`'un MODELİ YOK** — tablo `DB::table()` ile yazılıp okunuyor.
- **`TrendyolAdapterTest`'teki "yazılmamış yetenek" listesi madde kapandıkça
  KÜÇÜLTÜLMELİ.** Listede kalan: `delist`, `fetchListing` — ikisi de Faz 2
  kapsamı dışı.

## Bilinen açık uçlar

**1 · CI'ın 429 düzeltmesinden sonraki durumu buradan HÂLÂ görülemiyor.**
`gh` kimlik doğrulamalı değil (`gh auth status` → "not logged into any
GitHub hosts") ve bu turda da doğrulanamadı. **`gh auth login` tarayıcı
veya cihaz kodu ister; oturum içinden tamamlanamaz — kullanıcının bir
kez yapması gerekiyor.** Sonrasında `gh run list --limit 5` ile bakılır.
Düzeltmenin kendisi (`6e2217e`) yerinde.

**2 · `--order-by=random` düşüşü DAHA ÖNCEKİ BİR TURDA TEKRAR ÜRETİLDİ
VE KAPATILDI.** Sebep bir testin `latest('started_at')` kullanmasıydı
(yukarıda). Bu, beş oturumdur aranan ESKİ düşüşle aynı şey OLMAYABİLİR —
eski düşüş hiç tekrar üretilemedi ve o turdan beri de görünmedi. Bu
turun İKİ ardışık rastgele turu da temiz: seed'ler **1787225710** ve
**1787225751**. PHPUnit 11'de `--seed` seçeneği YOK; seed çıktının
sonunda raporlanır ve düşüş görülürse KAYDEDİLMELİ.

**3 · `acknowledgeOrder` yazılmadı ve "yazılmamış yetenek" listesinde de
YOK.** Sipariş onaylama Trendyol'da kargo akışının parçasıdır ve §14
kargoyu kapsam dışı bırakır. Bilinçli kapsam sınırı, eksik değil.
