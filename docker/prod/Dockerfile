# Üretim imajı — kod imajın İÇİNDE (yerel compose'taki gibi bağlanmaz).
#
# Aşama 1: ön yüz derlenir (node yalnız burada; üretim imajına girmez).
FROM node:22-alpine AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
COPY public ./public
RUN npm run build

# Aşama 2: PHP bağımlılıkları (dev paketleri YOK).
FROM composer:2 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
# Platform kontrolü BURADA atlanır: composer imajında pcntl/intl yoktur ama
# çalışma imajında vardır; orada `composer check-platform-reqs` doğrular.
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --ignore-platform-reqs

# Aşama 3: çalışma imajı (app, horizon, scheduler, relay aynı imajı kullanır).
FROM php:8.3-fpm-alpine AS app

RUN apk add --no-cache icu-libs libzip libpq \
    && apk add --no-cache --virtual .build-deps icu-dev libzip-dev postgresql-dev linux-headers $PHPIZE_DEPS \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip bcmath opcache pcntl posix \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY docker/prod/php.ini /usr/local/etc/php/conf.d/99-entegrasyon.ini

WORKDIR /var/www/html

COPY --chown=www-data:www-data --from=vendor /build /var/www/html
COPY --chown=www-data:www-data --from=assets /build/public/build /var/www/html/public/build
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Eklentiler GERÇEKTEN var mı — eksikse derleme burada düşer, üretimde değil.
RUN composer check-platform-reqs --no-dev --no-interaction && rm /usr/local/bin/composer

# .env imaja GİRMEZ — compose `env_file` ile verir (.dockerignore).
RUN rm -f .env && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY --chmod=755 docker/prod/entrypoint.sh /usr/local/bin/entrypoint.sh

USER www-data

EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

# Aşama 4: web (Caddy) — yalnız public/ (derlenmiş ön yüz dahil). Sürüm
# eşleşmesi garanti: aynı derlemeden kopyalanır.
FROM caddy:2-alpine AS web
COPY docker/prod/Caddyfile /etc/caddy/Caddyfile
COPY --from=app /var/www/html/public /var/www/html/public
