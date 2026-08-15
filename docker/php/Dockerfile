FROM php:8.3-fpm-alpine

# Mimari Karar Dokümanı v2.2 · §15 · PHP 8.3 (yerel PHP sürümünden bağımsız)

RUN apk add --no-cache \
        git \
        curl \
        unzip \
        icu-dev \
        libzip-dev \
        postgresql-dev \
        oniguruma-dev \
        linux-headers \
        $PHPIZE_DEPS \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        intl \
        zip \
        bcmath \
        opcache \
        pcntl \
        posix \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS \
    && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-entegrasyon.ini

WORKDIR /var/www/html

# php-fpm varsayılan olarak 9000 portunda dinler; nginx buraya bağlanır.
EXPOSE 9000

CMD ["php-fpm"]
