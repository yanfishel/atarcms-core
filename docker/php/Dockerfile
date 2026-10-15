FROM php:8.4-cli-alpine

RUN apk add --no-cache icu-libs icu-data-full libjpeg-turbo libpng libwebp libavif freetype \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev linux-headers libjpeg-turbo-dev libpng-dev libwebp-dev libavif-dev freetype-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-avif --with-freetype \
    && docker-php-ext-install intl pdo_mysql gd exif \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN printf 'error_reporting=E_ALL\ndisplay_errors=On\nmemory_limit=512M\n' > /usr/local/etc/php/conf.d/zz-dev.ini
