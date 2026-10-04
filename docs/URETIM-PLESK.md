# 34Pazar — Plesk sunucusunda üretim kurulumu

IONOS VPS (Ubuntu 24.04 + Plesk Obsidian). Aynı makinede başka müşteri
siteleri de çalışır; bu yüzden **80/443 Plesk'te kalır**, uygulama Docker'da
yalnız `127.0.0.1:8090`'dan düz HTTP dinler, Plesk alan adını ve SSL'i yönetir.

```
ziyaretçi ──https──▶ Plesk Apache (34pazar.com, Let's Encrypt)
                       └─▶ 127.0.0.1:8090 Caddy ─▶ php-fpm (app)
                                                horizon · scheduler · relay
                                                postgres · redis (dışarı kapalı)
```

Genel üretim notları (süreçler, yedek, güncelleme sırası) için
`docs/URETIM-KURULUM.md`. Burada yalnız Plesk'e özgü farklar var.

## 1. Docker (bir kez)

Plesk'in kendi paketlerine dokunmaz; Docker'ın resmi deposundan kurulur.

```bash
apt-get update && apt-get install -y ca-certificates curl
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable" > /etc/apt/sources.list.d/docker.list
apt-get update && apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
docker compose version
```

⚠️ Sunucuda Plesk'in kendi Postgres'i `127.0.0.1:5432`'de çalışıyor. Bizim
Postgres'imiz port açmaz (yalnız Docker ağında), çakışma yoktur.

## 2. Kod ve ortam

```bash
git clone git@github.com:ahmethamdi/entegrasyon.git /opt/34pazar && cd /opt/34pazar
cp .env.production.example .env.production
```

`.env.production` içinde en az:

```
APP_NAME=34Pazar
APP_URL=https://34pazar.com
APP_DOMAIN=34pazar.com
DEPLOY_TARGET=plesk
DB_PASSWORD=<uzun rastgele>
REDIS_PASSWORD=<uzun rastgele>
APP_KEY=            # ilk kurulumda: docker compose ... run --rm app php artisan key:generate --show
```

`ACME_EMAIL` Plesk kurulumunda kullanılmaz (TLS Plesk'te).

## 3. Plesk'te alan adı

⚠️ Bu sunucuda Plesk'in nginx bileşeni KURULU DEĞİL — 80/443'ü doğrudan
Apache tutar. "Additional nginx directives" burada hiçbir şey yapmaz;
yönlendirme Apache'de kurulur (5 Eki 2026'da böyle kuruldu).

```bash
plesk bin subscription --create 34pazar.com -owner admin -service-plan "Default Domain" \
  -ip 212.227.142.108 -login pazar34_web -passwd "$(openssl rand -base64 24)" -hosting true -notify false
# Sertifika, yönlendirme YOKKEN alınır (ACME dosyasını Plesk kendisi sunar)
plesk bin extension --exec letsencrypt cli.php -d 34pazar.com -d www.34pazar.com -m info@34devs.com
```

`/var/www/vhosts/system/34pazar.com/conf/vhost_ssl.conf` (= panelde
**Apache & nginx Settings → Additional directives for HTTPS**):

```apache
RewriteEngine On
RewriteCond %{HTTP_HOST} ^www\.34pazar\.com$ [NC]
RewriteRule ^(.*)$ https://34pazar.com$1 [R=301,L]

ProxyPreserveHost On
ProxyTimeout 120
ProxyPass /.well-known/acme-challenge !
ProxyPass / http://127.0.0.1:8090/
ProxyPassReverse / http://127.0.0.1:8090/
RequestHeader set X-Forwarded-Proto "https"
RequestHeader set X-Forwarded-Port "443"
LimitRequestBody 22020096
```

Sonra `plesk sbin httpdmng --reconfigure-domain 34pazar.com`. HTTP→HTTPS
301'i Plesk'in varsayılanı (yeni abonelikte açık geldi).

`ProxyPass /.well-known/acme-challenge !` OLMAZSA Let's Encrypt
yenilemesi uygulamaya gider ve 404 alır — sertifika 90 günde düşer.
`X-Forwarded-Proto` OLMAZSA uygulama isteği `http` sanar ve e-posta
doğrulama bağlantıları "geçersiz imza" verir (Laravel tarafında
`trustProxies` açık; Caddy tarafında `trusted_proxies` — ikisi de hazır).

Sunucuda nginx olsaydı aynı iş `location ~ ^/ { proxy_pass http://127.0.0.1:8090; … }`
ile "Additional nginx directives"ten yapılırdı.

## 4. İlk dağıtım

```bash
cd /opt/34pazar
./deploy.sh                      # DEPLOY_TARGET=plesk .env'den okunur
docker compose -f docker-compose.prod.yml -f docker-compose.plesk.yml --env-file .env.production \
  run --rm app php artisan db:seed --class=PlanSeeder --force
docker compose -f docker-compose.prod.yml -f docker-compose.plesk.yml --env-file .env.production \
  run --rm app php artisan db:seed --class=ChannelTypeSeeder --force
curl -fsS https://34pazar.com/up
```

`DemoStoreSeeder` üretimde ÇALIŞMAZ (kendini reddeder).

## 5. Yedek

Günlük veritabanı dökümü, 14 gün saklanır (Plesk yedeği Docker
volume'lerini KAPSAMAZ — bu yüzden ayrı):

```bash
cat > /etc/cron.d/34pazar-yedek <<'CRON'
30 3 * * * root umask 077 && cd /opt/34pazar && docker compose -f docker-compose.prod.yml -f docker-compose.plesk.yml --env-file .env.production exec -T postgres pg_dump -U entegrasyon -Fc entegrasyon > /var/backups/34pazar-$(date +\%F).dump && find /var/backups -name '34pazar-*.dump' -mtime +14 -delete
CRON
```

Sunucu dışına kopya (Storage Box / S3) büyüdükçe eklenir — tek makinede
duran yedek, makine giderse onunla gider.

## Kaynak payı

VPS 4 çekirdek / 8 GB; ölçülen boş bellek ~5 GB. Bu yığın (app + horizon +
scheduler + relay + postgres + redis) başlangıç yükünde tahminen 1–1,5 GB tutar (ölçülmedi; ilk haftada `docker stats` ile bak).
Redis `maxmemory 512mb`. Büyüyünce ayrı sunucuya taşınır — compose dosyaları
sağlayıcıdan bağımsızdır, yalnız `DEPLOY_TARGET` boş bırakılır.
