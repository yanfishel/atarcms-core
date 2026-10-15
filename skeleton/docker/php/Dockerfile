FROM php:8.4-fpm-alpine

RUN apk add --no-cache icu-libs icu-data-full libjpeg-turbo libpng libwebp libavif freetype git unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev linux-headers libjpeg-turbo-dev libpng-dev libwebp-dev libavif-dev freetype-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-avif --with-freetype \
    && docker-php-ext-install intl pdo_mysql gd exif \
    && (php -m | grep -qi 'zend opcache' || docker-php-ext-install opcache) \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/zz-atar.ini

# One user runs the site's PHP: PHP-FPM's workers, composer and bin/atar all run as www-data, moved to the uid and gid
# that own the site's files on the host (HOST_UID/HOST_GID in .env; on Linux `id -u` and `id -g`). The default 82 is
# Alpine's own www-data, right for Docker Desktop (Windows, macOS), where the owner of mounted files does not matter.
ARG HOST_UID=82
ARG HOST_GID=82
# An empty value (HOST_UID= in .env) means the default.
RUN uid="${HOST_UID:-82}"; gid="${HOST_GID:-82}"; \
    if [ "$uid" != "0" ] && [ "$gid" != "0" ] && { [ "$uid" != "82" ] || [ "$gid" != "82" ]; }; then \
        apk add --no-cache --virtual .user-deps shadow \
        && groupmod -o -g "$gid" www-data \
        && usermod -o -u "$uid" -g "$gid" www-data \
        && apk del .user-deps; \
    fi
# Composer's cache and config for www-data (whose home directory is not writable).
ENV COMPOSER_HOME=/tmp/composer

# Production profile (OPcache sizing): docker build --build-arg ATAR_PRODUCTION=1 …
ARG ATAR_PRODUCTION=0
COPY production.ini /usr/local/etc/php/atar-production.ini
RUN if [ "$ATAR_PRODUCTION" = "1" ]; then cp /usr/local/etc/php/atar-production.ini /usr/local/etc/php/conf.d/zzz-atar-production.ini; fi
