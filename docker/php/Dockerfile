FROM php:8.4-fpm-alpine

ARG HOST_UID=1000
ARG HOST_GID=1000

RUN set -eux; \
    apk add --no-cache icu-libs libzip sqlite-libs; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev linux-headers sqlite-dev; \
    docker-php-ext-install bcmath intl pcntl pdo_mysql pdo_sqlite zip; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apk del .build-deps; \
    addgroup -g "${HOST_GID}" app; \
    adduser -D -u "${HOST_UID}" -G app app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend

USER app

CMD ["php-fpm"]
