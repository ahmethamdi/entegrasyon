#!/usr/bin/env bash
# Üretim dağıtımı — sunucuda, depo kökünde çalıştırılır.
#
#   ./deploy.sh
#
# Sıra önemlidir: migration YENİ kodla ama süreçler değişmeden ÖNCE
# koşar; şema geriye uyumlu yazıldığı sürece eski süreçler birkaç saniye
# daha çalışabilir. Ayrıntı: docs/URETIM-KURULUM.md
#
# ⚠️ MIGRATION KURALI (kesintisiz dağıtım, 24 Ekim 2026): migration koştuğu
# anda ESKİ kod hâlâ istek karşılar ve işçiler eski koddadır. Bu yüzden bir
# dağıtımda yalnız GERİYE UYUMLU değişiklik yapılır: kolon/tablo EKLE,
# nullable ya da varsayılanlı yap. Eski kodun okuduğu kolonu silmek ya da
# adını değiştirmek İKİ dağıtımdır: önce kod o kolonu bırakır, sonraki
# dağıtımda migration onu kaldırır.
set -euo pipefail

cd "$(dirname "$0")"

if [[ ! -f .env.production ]]; then
    echo "HATA: .env.production yok (bkz. .env.production.example)" >&2
    exit 1
fi

# Plesk sunucusunda (80/443 Plesk'te) üst katman eklenir:
#   DEPLOY_TARGET=plesk ./deploy.sh   (ya da .env.production'da DEPLOY_TARGET=plesk)
DEPLOY_TARGET="${DEPLOY_TARGET:-$(grep -E '^DEPLOY_TARGET=' .env.production | cut -d= -f2 || true)}"

COMPOSE=(docker compose -f docker-compose.prod.yml --env-file .env.production)

if [[ "${DEPLOY_TARGET}" == "plesk" ]]; then
    COMPOSE=(docker compose -f docker-compose.prod.yml -f docker-compose.plesk.yml --env-file .env.production)
fi

echo "→ Kod güncelleniyor"
git pull --ff-only

echo "→ İmajlar derleniyor"
"${COMPOSE[@]}" build

echo "→ Veritabanı ve Redis"
"${COMPOSE[@]}" up -d postgres redis

echo "→ Migration"
"${COMPOSE[@]}" run --rm app php artisan migrate --force

# SÜREÇLER SIRAYLA (24 Ekim 2026). Eski `up -d` hepsini birlikte yeniden
# yaratıyordu: web, Horizon'un 90 sn'lik zarif duruşunu beklerken kapalı
# kalıyor, site ~90 sn 503 veriyordu (yerelde ve canlıda ölçüldü).
#   1. app   — php-fpm SIGQUIT ile elindeki istekleri bitirir; yeni süreç
#              ayağa kalkana kadar Caddy isteği bekletip yeniden dener
#              (Caddyfile · lb_try_duration).
#   2. web   — yalnız imajı değiştiyse yenilenir; ~1 sn'lik boşluk kalır.
#   3. işçiler — en son; uzun zarif duruşları artık siteyi tutmaz.
echo "→ Süreçler yenileniyor: app"
"${COMPOSE[@]}" up -d --no-deps app

echo "→ Süreçler yenileniyor: web"
"${COMPOSE[@]}" up -d --no-deps web

echo "→ Süreçler yenileniyor: işçiler"
"${COMPOSE[@]}" up -d --no-deps --remove-orphans horizon scheduler relay

# Horizon işçileri ellerindeki işi bitirip yeni kodla döner.
"${COMPOSE[@]}" exec -T horizon php artisan horizon:terminate || true

echo "→ Sağlık kontrolü"
DOMAIN="$(grep -E '^APP_DOMAIN=' .env.production | cut -d= -f2)"
for i in $(seq 1 30); do
    if curl -fsS "https://${DOMAIN}/up" >/dev/null 2>&1; then
        echo "✓ https://${DOMAIN} ayakta"
        exit 0
    fi
    sleep 2
done

echo "HATA: https://${DOMAIN}/up 60 sn içinde yanıt vermedi — '${COMPOSE[*]} logs --tail=100' ile bak" >&2
exit 1
