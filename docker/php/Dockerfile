FROM php:8.4-cli-alpine

RUN apk add --no-cache icu-libs icu-data-full libjpeg-turbo libpng libwebp libavif freetype \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev linux-headers libjpeg-turbo-dev libpng-dev libwebp-dev libavif-dev freetype-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-avif --with-freetype \
    && docker-php-ext-install intl pdo_mysql gd exif \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# OPcache on the command line too: a test process boots the application again and again, including the same config
# files and templates each time.
RUN docker-php-ext-enable opcache \
    && printf 'error_reporting=E_ALL\ndisplay_errors=On\nmemory_limit=512M\nopcache.enable_cli=1\n' > /usr/local/etc/php/conf.d/zz-dev.ini
