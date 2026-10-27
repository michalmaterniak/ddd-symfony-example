# Production image for Kubernetes: unlike .docker/php85 (dev, code mounted from the host),
# the code and vendor/ are baked into the image. Build from the repository root:
#   docker build -t symfony8-app:dev .
FROM php:8.5-fpm

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
# pcntl lets messenger:consume stop gracefully on SIGTERM (sent by Kubernetes when it stops a pod)
RUN install-php-extensions amqp bcmath ds intl pcntl pdo_pgsql zip @composer

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'date.timezone = "Europe/Warsaw"\nopcache.validate_timestamps = 0\n' > "$PHP_INI_DIR/conf.d/zz-app.ini"

ENV APP_ENV=prod \
    APP_DEBUG=0

WORKDIR /app

# dependencies first: this layer is rebuilt only when composer files change
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && composer run-script post-install-cmd \
    && chown -R www-data:www-data var

USER www-data
