FROM php:8.3-fpm-alpine

RUN apk add --no-cache libzip oniguruma \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libzip-dev oniguruma-dev \
    && docker-php-ext-install -j$(nproc) mbstring opcache pdo_mysql zip \
    && apk del .build-deps

COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini

WORKDIR /var/www/html

EXPOSE 9000

CMD ["php-fpm"]
