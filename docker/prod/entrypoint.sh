#!/bin/sh
# Her süreç (app, horizon, scheduler, relay) başlarken yapılandırmayı
# önbelleğe alır. İmaj derlenirken yapılamaz: ortam değişkenleri ancak
# çalışırken gelir. bootstrap/cache süreçler arasında paylaşılmaz, bu
# yüzden her konteyner kendi önbelleğini kurar.
set -e

php artisan optimize --no-interaction >/dev/null

exec "$@"
