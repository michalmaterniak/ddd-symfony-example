FROM php:8.5-fpm
RUN apt update
RUN apt install -y git sudo unzip libzip-dev
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN install-php-extensions zip xdebug bcmath ds pdo_mysql pdo_pgsql intl amqp
RUN install-php-extensions @composer

RUN curl -1sLf 'https://dl.cloudsmith.io/public/symfony/stable/setup.deb.sh' | sudo -E bash
RUN apt install -y symfony-cli

RUN useradd -m user --uid 1000 -s /usr/bin/bash
RUN echo "user:user" | chpasswd
RUN usermod -aG sudo user

COPY opcache.ini /usr/local/etc/php/conf.d/
COPY custom.ini /usr/local/etc/php/conf.d/

USER user
WORKDIR /app
