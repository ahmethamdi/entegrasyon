# Üretim Kurulumu

Tek sunucu, Docker Compose. **Sağlayıcıdan bağımsız:** Hetzner, DigitalOcean
veya herhangi bir Linux VPS'te aynı adımlar. Sağlayıcı seçimi yalnız sunucuyu
açarken önemlidir; posta sağlayıcısı seçimi yalnız `.env.production`'daki
`MAIL_*` bloğunu değiştirir.

> **4 Ekim 2026 — yerelde baştan sona doğrulandı:** imaj derlendi
> (`check-platform-reqs` eklentileri doğruladı), temiz veritabanına tüm
> migration'lar koştu, altı süreç ayağa kalktı, TLS + HSTS + güvenlik
> başlıkları geldi, `.env`/`.git` 404, webhook 2 MB → 413 / panel 5 MB geçti,
> Redis parolasız erişimi reddetti, stok değişikliği outbox → relay →
> Horizon (`outbox:consume`) yolundan 2 sn'de işlendi. **Gerçek sunucuda ve
> gerçek alan adıyla henüz kurulmadı.**

## Süreçler

| Servis | Ne yapar |
|---|---|
| `web` | Caddy — TLS sertifikasını kendisi alır/yeniler, statik dosyalar, PHP'ye aktarma |
| `app` | php-fpm — panel ve webhook'lar |
| `horizon` | kuyruk işçileri (`config/horizon.php` havuzları, üretimde 10–13 süreç) |
| `scheduler` | zamanlanmış taramalar (`routes/console.php`) |
| `relay` | `outbox:relay` — sürekli süreç, zamanlanmaz |
| `postgres`, `redis` | **dışarıya port açılmaz**; Redis parolalı |

## Sunucu

- En az **4 GB RAM, 2 vCPU** (Horizon üretimde 10+ işçi açar), Ubuntu 24.04 LTS
- Docker Engine + Compose eklentisi: `curl -fsSL https://get.docker.com | sh`
- Güvenlik duvarı — yalnız SSH ve web:
  ```bash
  ufw allow 22/tcp && ufw allow 80/tcp && ufw allow 443/tcp && ufw enable
  ```
  ⚠️ Docker yayınlanan portlarda ufw'yi atlar; bu yüzden compose dosyasında
  postgres/redis için **hiç** port yayınlanmaz (yalnız `web` 80/443).

## İlk kurulum

1. **DNS:** alan adının A kaydı sunucu IP'sine (örn. `panel.ornek.com`).
2. **Depo:** sunucuya klonla (deploy anahtarıyla, salt okunur).
3. **Ortam dosyası:**
   ```bash
   cp .env.production.example .env.production
   chmod 600 .env.production
   ```
   Doldur: `APP_DOMAIN`, `APP_URL`, `ACME_EMAIL`, `DB_PASSWORD`,
   `REDIS_PASSWORD` (ikisi de `openssl rand` ile), `MAIL_*`, `STRIPE_*`,
   `ALERT_ADMIN_EMAIL`, `HORIZON_ADMIN_EMAILS`.
4. **APP_KEY** üret ve **iki ayrı yerde yedekle** (kaybolursa kasadaki tüm
   kanal anahtarları çözülemez):
   ```bash
   docker compose -f docker-compose.prod.yml --env-file .env.production \
     run --rm --no-deps app php artisan key:generate --show
   ```
   Çıktıyı `APP_KEY=`'e yaz.
5. **Dağıt:** `./deploy.sh` — derler, migration koşar, süreçleri başlatır,
   `https://APP_DOMAIN/up` ile sağlık kontrolü yapar.
6. **Plan tohumu** (yalnız ilk kurulumda):
   ```bash
   docker compose -f docker-compose.prod.yml --env-file .env.production \
     exec app php artisan db:seed --class=PlanSeeder --force
   ```

## Dış servisler

- **Posta:** Postmark / Amazon SES / Mailgun — üçü de SMTP verir. Gönderen
  alan adında sağlayıcının verdiği **SPF + DKIM** kayıtları kurulmalı; yoksa
  parola sıfırlama ve e-posta doğrulama postaları gereksize düşer ve yeni
  satıcı panele **giremez** (doğrulama zorunlu).
- **Stripe:** panelde webhook uç noktası `https://APP_DOMAIN/webhooks/stripe`;
  olaylar: `checkout.session.completed`, `customer.subscription.created`,
  `customer.subscription.updated`, `customer.subscription.deleted`. İmza
  sırrı `STRIPE_WEBHOOK_SECRET`. **Pilot önce `sk_test_` anahtarlarıyla.**

## Güncelleme

```bash
./deploy.sh
```

Sıra: `git pull` → derleme → migration → süreçlerin yeniden başlaması →
`horizon:terminate` (işçiler elindeki işi bitirip yeni kodla döner) →
sağlık kontrolü.

## İzleme

- Kuyruk paneli: `https://APP_DOMAIN/horizon` — yalnız `HORIZON_ADMIN_EMAILS`
  listesindeki doğrulanmış hesaplar görür.
- Uygulama günlüğü: `docker compose ... exec app tail -f storage/logs/laravel-*.log`
- Süreç durumu: `docker compose -f docker-compose.prod.yml ps`

## Yedek

`docs/YEDEK-GERI-YUKLEME.md` — prosedür ve prova. Üretimde `pg_dump`
günlük çalışmalı ve yedek **sunucu dışına** kopyalanmalı; `APP_KEY` yedeği
veritabanı yedeğinden AYRI tutulur.

## Henüz yapılmadı

- Gerçek sunucuda kurulum (sunucu seçimi bekleniyor)
- Posta sağlayıcısı seçimi
- Otomatik `pg_dump` zamanlaması ve sunucu dışı yedek hedefi
