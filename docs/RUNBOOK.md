# 34Pazar — Olay Runbook'u ve Yedek Kurulumu (S13)

Bu belge, bir şey bozulduğunda **sırayla ne yapılacağını** söyler. Panik anında
okunmak için yazıldı: önce kısa karar, sonra adımlar. Komutlar sunucuda
(`ssh -i ~/.ssh/ionos_vps root@212.227.142.108`, depo `/opt/34pazar`) koşar.

Kısaltma: `C="docker compose -f docker-compose.prod.yml -f docker-compose.plesk.yml --env-file .env.production"`

---

## 1. Hedefler: RPO / RTO

| | Hedef | Dayanak |
|---|---|---|
| **RPO** (en çok ne kadar veri kaybı) | **24 saat** | Gece 02:00 UTC off-site yedek (`backup:offsite`) + 03:30 yerel döküm (cron). Arada gelen sipariş/stok hareketi kaybolabilir; kanallardaki siparişler yoklamayla (`orders:poll`) yeniden çekilir, stok mutabakatı kanalları düzeltir. |
| **RTO — veritabanı bozuldu, sunucu sağlam** | **30 dakika** | Yerel döküm + `pg_restore`. Geri yükleme adımı ölçüldü: 323 KB / 84 tablo **0,5 sn** (yerel sandbox, 24 Eki); canlı döküm ~1,4 MB. Süreyi bakım modu, doğrulama ve kontrol belirler, yükleme değil. |
| **RTO — sunucu tümden kayboldu** | **4 saat** | Yeni VPS + Docker + depo + `.env.production` + off-site yedekten geri yükleme. **Ölçülmedi** (hedef); ilk tatbikatta ölçülüp buraya yazılmalı. |

RPO'yu 1 saatin altına indirmek WAL arşivi (sürekli yedek) ister — ayrı karar.

Haftalık prova (`backup:verify`, Pazar 02:40 UTC) en yeni off-site yedeği
gerçekten açar ve satırları sayar; sonuç (başarı da) ntfy'ye gelir. **Pazar
günü "Yedek geri yükleme provası ✓" bildirimi gelmediyse yedek zinciri bozuktur.**

---

## 2. Off-site yedek kurulumu (bir kez — senin yapacağın)

### 2.1 IONOS S3 bucket + erişim anahtarı

IONOS Object Storage, IONOS **Cloud** (Data Center Designer, DCD) ürünüdür;
VPS'in panelinden ayrıdır. Ücretlidir (depolanan GB başına).

1. https://dcd.ionos.com → giriş → **Storage → IONOS Object Storage**.
2. **Bucket oluştur:**
   - Bucket türü: **Contract-owned**
   - Bölge: **eu-central-3 (Berlin)** — sunucudan farklı bir veri merkezi (coğrafi ayrım).
   - Ad: `34pazar-yedek-<rastgele>` (ör. `34pazar-yedek-7f3k`; bucket adları geneldir, tahmin edilmesin).
   - **Object Lock**: varsa aç (Governance, 30 gün) — ele geçirilmiş bir anahtar yedekleri silemesin. Açılamazsa atla.
   - Versioning: kapalı kalabilir (saklama politikasını uygulama yapıyor).
   - Public access: **kapalı**.
3. **Erişim anahtarı:** Object Storage → **Access keys → Generate key**. Erişim anahtarı (92 karakter) ve gizli anahtar BİR KEZ gösterilir; parola yöneticine kaydet.
   - Mümkünse yalnız bu bucket'a yetkili bir anahtar/kullanıcı kullan (Bucket policy: `s3:PutObject`, `s3:GetObject`, `s3:ListBucket`, `s3:DeleteObject`).
4. Sunucuda `/opt/34pazar/.env.production`'a ekle:

```
BACKUP_S3_ENDPOINT=https://s3.eu-central-3.ionoscloud.com
BACKUP_S3_REGION=eu-central-3
BACKUP_S3_BUCKET=34pazar-yedek-7f3k
BACKUP_S3_KEY=<92 karakterlik erişim anahtarı>
BACKUP_S3_SECRET=<gizli anahtar>
BACKUP_S3_PREFIX=34pazar
```

### 2.2 Şifreleme anahtarı (age)

Yedek, S3'e gitmeden önce **age** ile şifrelenir. Anahtar `.env`'de DEĞİL,
ayrı dosyadadır ve **kopyası sende olmalıdır**: sunucu kaybolursa yedeği
yalnız o kopya açar. Kopya yoksa off-site yedek işe yaramaz.

Sunucuda (deploy'dan SONRA, imajda `age-keygen` var):

```bash
cd /opt/34pazar
mkdir -p secrets
$C run --rm -T --no-deps --entrypoint age-keygen scheduler > secrets/backup-age.key
chown root:82 secrets secrets/backup-age.key   # 82 = konteynerdeki www-data
chmod 750 secrets && chmod 640 secrets/backup-age.key
head -2 secrets/backup-age.key                  # "# public key: age1…" satırı görünmeli
$C up -d --no-deps scheduler                    # dosyayı görsün
```

**Kopyayı sakla** (birini seç, ikisi daha iyi):
- Parola yöneticisi (1Password/Bitwarden): `secrets/backup-age.key` içeriğini güvenli not olarak kaydet (`AGE-SECRET-KEY-1…` satırı dahil, tamamı).
- Çevrimdışı: USB belleğe ya da kâğıda yazdır, evde/kasada sakla.
- **Asla**: e-posta, WhatsApp, aynı S3 bucket'ı, depo.

Anahtarı değiştirirsen eski yedekler eski anahtarla açılır — eskisini 6 ay sakla.

### 2.3 healthchecks.io (dead-man switch)

Zamanlayıcı ya da sunucu ölürse bizim alarmlarımız da ölür. healthchecks.io
"ping gelmedi" diye **dışarıdan** haber verir.

1. https://healthchecks.io → ücretsiz hesap (info@34devs.com).
2. **Integrations**: e-posta (varsayılan) + istersen ntfy/Telegram.
3. İki kontrol aç:

| Kontrol adı | Period | Grace | Ne zaman öter |
|---|---|---|---|
| `34pazar-scheduler` | **5 dakika** | **15 dakika** | Zamanlayıcı 20 dk'dır nabız atmadı (konteyner/sunucu ölü, ağ kopuk) |
| `34pazar-offsite-yedek` | **1 gün** (ya da Cron `0 2 * * *`, UTC) | **2 saat** | Gece yedeği başlamadı/bitmedi ya da `/fail` gönderdi |

4. Her kontrolün **Ping URL**'sini `.env.production`'a yaz:

```
HEALTHCHECKS_HEARTBEAT_URL=https://hc-ping.com/<uuid-1>
HEALTHCHECKS_BACKUP_URL=https://hc-ping.com/<uuid-2>
```

5. `$C up -d --no-deps scheduler` (env'i okusun).

### 2.4 İlk doğrulama (kurulumdan hemen sonra)

```bash
$C exec -T scheduler php artisan backup:offsite   # "yüklendi: 34pazar/34pazar-…dump.age" görmelisin
$C exec -T scheduler php artisan backup:verify    # "açıldı: … satır … sn" görmelisin
$C exec -T scheduler php artisan ops:heartbeat    # "ping: ok"
```

healthchecks.io'da iki kontrol de yeşile dönmeli; ntfy'ye "Yedek geri yükleme provası ✓" gelmeli.

---

## 3. Olaylar

Her olayda önce: **ne zamandan beri?** (ntfy/posta, healthchecks.io, `$C logs --since 1h <servis>`).

### 3.1 Sunucu çöktü / erişilemiyor

Belirti: site açılmıyor, healthchecks `34pazar-scheduler` kırmızı, SSH yok.

1. IONOS VPS panelinden sunucu durumu; yeniden başlat. Çoğu durumda bu yeter:
   konteynerler `restart: unless-stopped` ile kendiliğinden kalkar → `curl -fsS https://34pazar.com/up`.
2. Disk dolu mu: `df -h /` (≥ %95 ise `docker system prune -f`, `/var/backups` eski `oncesi-*.dump`; `./docker/prod/prune-deploy-backups.sh`).
3. Sunucu **geri gelmiyorsa** (donanım/hesap kaybı) → yeni sunucu:
   1. Yeni VPS (Ubuntu), Docker + compose eklentisi kur, Plesk kullanılmayacaksa `docker-compose.prod.yml` tek başına (Caddy TLS'i kendisi alır).
   2. `git clone` (deponun erişimi sende) → `/opt/34pazar`.
   3. `.env.production`'u parola yöneticisindeki kopyadan geri yaz (yoksa `.env.production.example`'dan, tüm anahtarlar yeniden).
   4. `secrets/backup-age.key`'i sendeki kopyadan geri yaz (2.2'deki izinlerle).
   5. `$C up -d postgres redis`
   6. Off-site yedeği indir ve aç (aşağıdaki **"Elle geri yükleme"**).
   7. `./deploy.sh` → `curl -fsS https://<alan>/up`.
   8. DNS: `34pazar.com` A kaydı yeni IP'ye.
   9. Kanal webhook'ları (Shopify/Woo) adres değişmediyse aynen çalışır; değiştiyse `channels:register-webhooks`.
4. Sonra: kayıp penceredeki siparişler kanallardan yoklamayla gelir; `reconcile:cold` stoğu düzeltir. Müşterilere kısa bilgi.

### 3.2 Veritabanı bozuldu / yanlış veri silindi

Belirti: 500 hataları, `SQLSTATE` günlükte, ya da biri yanlışlıkla toplu silme yaptı.

1. **Dur:** yazmayı kes — `$C stop horizon scheduler relay` (kanallara yanlış stok gitmesin), gerekirse `$C exec app php artisan down`.
2. Hangi yedek: olaydan önceki en yeni → yerel `/var/backups/34pazar-YYYY-MM-DD.dump` (gece) ya da `oncesi-*.dump` (deploy öncesi). Yerelde yoksa off-site.
3. Önce mevcut hâlin yedeği (kanıt): `$C exec -T postgres pg_dump -U entegrasyon -Fc entegrasyon > /var/backups/olay-$(date +%F-%H%M).dump`
4. Geri yükle (ayrı DB'ye açıp kontrol et, sonra değiştir):
   ```bash
   $C exec -T postgres createdb -U entegrasyon geri_yukleme
   $C exec -T postgres pg_restore -U entegrasyon --no-owner -d geri_yukleme < /var/backups/<yedek>.dump
   # kontrol: tablo/satır sayıları, son sipariş tarihi
   $C exec -T postgres psql -U entegrasyon -d geri_yukleme -c "select max(created_at) from orders"
   # değiştir (uygulama durmuşken):
   $C exec -T postgres psql -U entegrasyon -d postgres -c "ALTER DATABASE entegrasyon RENAME TO entegrasyon_bozuk"
   $C exec -T postgres psql -U entegrasyon -d postgres -c "ALTER DATABASE geri_yukleme RENAME TO entegrasyon"
   ```
5. `$C up -d horizon scheduler relay`, `php artisan up`, `/up` kontrolü.
6. `entegrasyon_bozuk`'u 7 gün sakla, sonra `DROP DATABASE`.
7. Yedekten sonraki siparişler: `orders:poll` + `reconcile:cold` (kanallar gerçeğin kaynağıdır).

### 3.3 Yanlış deploy (yeni sürüm hata veriyor)

Belirti: deploy sonrası 500, ops:watch alarmı, test akışı bozuk.

1. Kod geri al (migration geriye uyumlu yazıldığı için genelde yeterli — `deploy.sh` başındaki kural).
   **Tercih edilen (kalıcı):** Mac'te `git revert <kötü-commit>` → `git push` → sunucuda `./deploy.sh`.
   **Acil (dakikalar önemliyse, sunucuda):**
   ```bash
   cd /opt/34pazar
   git log --oneline -5                     # iyi olan son commit
   git reset --hard <iyi-commit>
   DEPLOY_SKIP_PULL=1 ./deploy.sh           # pull atlanır; yoksa kötü commit geri gelir
   ```
   Acil yoldan sonra GitHub'da da `git revert` yapılmalı; yapılmazsa bir sonraki normal `./deploy.sh` kötü commit'i yeniden canlıya alır.
2. Migration şemayı bozduysa: deploy öncesi yedek `/var/backups/oncesi-<commit>-…dump` → 3.2.
3. Kesinti ölçüsü: yeni deploy.sh ile ~0–2 sn (24 Eki ölçüldü). Daha uzunsa Caddy/Apache `retry` ayarlarına bak (docs/URETIM-PLESK.md).

### 3.4 Bir kanal kitlendi (istekler başarısız / hız sınırı / devre kesici)

Belirti: ntfy "devre kesici açık", "429 yoğunluğu", "sağlıksız bağlantı 24 sa".

1. Hangi kanal, hangi hata: Horizon (`/horizon`, süper admin) → Failed; `api_calls` son kayıtlar (admin → kiracı → bağlantı).
2. Sınıflandır:
   - **401/403 (kimlik)**: satıcının anahtarı iptal/süresi doldu → satıcıya bildir, panelden yeniden bağlansın. Devre kesici kimlik yenilenince sıfırlanır.
   - **429**: kanalın hız sınırı → bekle; kalıcıysa `RateLimitProfile` değerleri o kanalın belgesiyle karşılaştır.
   - **5xx / zaman aşımı**: kanal tarafında kesinti → kanalın durum sayfası; devre kesici yarı açık denemeyle kendi kapanır.
3. Kanal uzun süre yoksa: o bağlantının yazma izinlerini panelden kapat (stok/fiyat boşa denenmesin); geri gelince aç → `reconcile:cold` farkları onarır.
4. Etsy test bağlantısı (34Devs hesabı) bilinen gürültüdür — `CHANNELS_WITHHELD=etsy`.

### 3.5 Stripe webhook durdu (ödeme alındı, paket açılmadı)

Belirti: müşteri "ödedim, paket açılmadı"; Stripe Dashboard → Developers → Webhooks → `https://34pazar.com/webhooks/stripe` kırmızı.

1. Stripe Dashboard'da son olayların yanıt kodu:
   - **400**: imza tutmuyor → `.env.production`'daki `STRIPE_WEBHOOK_SECRET` Dashboard'daki uç noktanın "Signing secret"ı ile aynı mı? Değiştiyse düzelt, `$C up -d --no-deps app`.
   - **5xx**: uygulama hatası → `$C logs --since 2h app | grep -i stripe`.
   - **Zaman aşımı/bağlantı yok**: 3.1.
2. Olay düzelince Stripe Dashboard → olay → **Resend** (abonelik eşlemesi `external_ref` ile idempotenttir; çift abonelik açmaz).
3. Acil: müşteriyi beklettirme — admin panel → kiracı → **plan ata** (elle), sonra webhook düzelince senkron.
4. Dinlenen olaylar (S14): `checkout.session.completed`, `customer.subscription.created|updated|deleted`, `invoice.payment_failed`, `invoice.paid`, `charge.refunded`. Dashboard'daki uç nokta bu yedi olayı içermeli (`StripeWebhookController::HANDLED_EVENTS`, test kilitli).
5. Olay tekilliği: aynı `evt_…` ikinci kez işlenmez (`billing_webhook_events`); işlenirken düşen olay damgalanmaz, Stripe'ın yeniden denemesiyle işlenir. "Resend" güvenlidir.
6. Çift posta olmasın: Stripe Dashboard → Settings → Billing → Subscriptions and emails → **"Send emails when card payments fail" KAPALI** (Türkçe postayı biz gönderiyoruz; API'den okunamıyor, elle kontrol).

### 3.6 İade (14 gün güvencesi ve diğerleri)

- **14 gün güvencesi:** admin → Müşteriler → kiracı → "14 gün iade güvencesi" → **Son ödemeyi iade et ve ücretsize düşür**. Uygunluk ekranda yazar: ilk ücretli abonelik, ilk ödemeden 14 gün içinde, hesap başına bir kez, Stripe ile ödenmiş. Düğme Stripe'ta iade + abonelik iptali (alacaksız) + hemen ücretsiz plan + kayıt yapar.
- **Stripe Dashboard'dan elle iade:** `charge.refunded` olayı gelir; **tam** iade → abonelik hemen kesilir ve plan ücretsize düşer, **kısmi** iade yalnız kaydedilir. Güvence sayılmaz (güvence yalnız admin düğmesiyle işaretlenir).
- **Shopify ile ödeyen:** iade Shopify Partner panelinden; bizde düğme yok.
- Veri hiçbir iadede silinmez; ücretsiz planın üstündeki kullanımda yalnız yeni ekleme engellenir.

---

## 4. Elle geri yükleme (off-site yedekten, uygulama olmadan)

Sunucu yokken bile çalışır; gereken: bucket erişimi + age anahtarının kopyası.

```bash
# 1) En yeni yedeği indir (aws-cli, rclone ya da DCD arayüzünden indir)
aws --endpoint-url https://s3.eu-central-3.ionoscloud.com s3 ls s3://<bucket>/34pazar/
aws --endpoint-url https://s3.eu-central-3.ionoscloud.com s3 cp s3://<bucket>/34pazar/34pazar-<ZAMAN>.dump.age .
aws --endpoint-url https://s3.eu-central-3.ionoscloud.com s3 cp s3://<bucket>/34pazar/34pazar-<ZAMAN>.manifest.json .

# 2) Bütünlük: manifestteki sha256 ile aynı olmalı
shasum -a 256 34pazar-<ZAMAN>.dump.age; grep sha256 34pazar-<ZAMAN>.manifest.json

# 3) Çöz (anahtar = parola yöneticisindeki kopya)
age --decrypt --identity backup-age.key --output 34pazar.dump 34pazar-<ZAMAN>.dump.age

# 4) Yükle
pg_restore --no-owner --no-privileges -h <host> -U entegrasyon -d entegrasyon 34pazar.dump
```

24 Ekim 2026'da yerel sandbox'ta (S3 uyumlu SeaweedFS, kimlik doğrulamalı)
bu yol uçtan uca denendi: indirilen dosya `age-encryption.org/v1` başlıklı,
düz döküm izi yok; doğru anahtarla 84 tablolu geçerli pg_dump arşivi; yanlış
anahtar reddedildi.

---

## 5. Neyin nerede olduğu

| Ne | Nerede |
|---|---|
| Gece yerel döküm | `/etc/cron.d/34pazar-yedek` → `/var/backups/34pazar-YYYY-MM-DD.dump` (14 gün) |
| Deploy öncesi dökümler | `/var/backups/oncesi-*.dump` — her deploy sonunda temizlenir (en yeni 10 + son 14 gün) |
| Off-site yedek | IONOS S3 `<bucket>/34pazar/` — 7 günlük + 4 haftalık + 6 aylık |
| Şifreleme anahtarı | `/opt/34pazar/secrets/backup-age.key` (+ senin kopyan) |
| Alarmlar | ntfy (`BETA_NTFY_TOPIC`) + info@34devs.com; dış nabız healthchecks.io |
| Kod | `app/Support/Backup/*`, `routes/console.php` (02:00 yedek, Pazar 02:40 prova, 5 dk nabız) |
