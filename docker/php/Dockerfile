FROM php:8.4-fpm-alpine

ARG HOST_UID=1000
ARG HOST_GID=1000

RUN set -eux; \
    apk add --no-cache icu-libs libxml2 libzip oniguruma sqlite-libs; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libxml2-dev libzip-dev linux-headers oniguruma-dev sqlite-dev; \
    docker-php-ext-install -j"$(nproc)" bcmath dom intl mbstring pcntl pdo_mysql pdo_sqlite xml xmlwriter zip; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apk del .build-deps; \
    addgroup -g "${HOST_GID}" app; \
    adduser -D -u "${HOST_UID}" -G app app; \
    mkdir -p /var/www/backend; \
    chown -R app:app /var/www/backend

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend

COPY --chown=app:app backend/composer.json backend/composer.lock ./

USER app

RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts

USER root

COPY --chown=app:app backend/ ./

RUN mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R app:app storage bootstrap/cache

USER app

RUN composer install --no-interaction --no-progress --prefer-dist

CMD ["php-fpm"]
