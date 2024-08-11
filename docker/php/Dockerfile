FROM php:8.3.10-fpm-alpine3.20

RUN apk add --no-cache $PHPIZE_DEPS linux-headers libzip-dev \
    && pecl install redis-6.0.2 \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pdo_mysql pcntl opcache zip \
    && apk del $PHPIZE_DEPS linux-headers

COPY --from=composer:2.7.7 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-flash-sale.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-flash-sale.conf

WORKDIR /var/www/html

CMD ["php-fpm"]
