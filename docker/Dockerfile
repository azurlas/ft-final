FROM composer:2.8.12 AS composer
FROM spiralscout/roadrunner:2025.1.15@sha256:18378c1313a71ce1c3cfc493c99e75c189ef5fb2eaf85290b8a99080fcb0f64f AS roadrunner
FROM php:8.3-cli-bookworm@sha256:4687aec76c4a895b68b91bcd5e48f1ba7a3dea120f6de50bba7e1a93af5372dd
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libzip-dev libonig-dev libxml2-dev supervisor default-mysql-client ca-certificates unzip git \
    && docker-php-ext-install pdo_mysql pcntl intl mbstring dom xml zip opcache \
    && pecl install redis-6.3.0 && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install sockets
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-dev --prefer-dist --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative && mkdir -p var config/jwt && php bin/console assets:install public && chmod +x bin/console docker/entrypoint.sh
COPY docker/php.ini /usr/local/etc/php/conf.d/university.ini
ENV APP_ENV=prod APP_DEBUG=0 RR_HTTP_PORT=8080 RR_RPC_PORT=6001
EXPOSE 8080 2112 9100
ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["api"]
