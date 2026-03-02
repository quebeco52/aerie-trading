FROM php:8.4-fpm-trixie

RUN apt-get update && apt-get install -y \
    acl \
    file \
    gettext \
    git \
    unzip \
    libicu-dev

RUN docker-php-ext-configure intl \
    && docker-php-ext-install pdo_mysql pcntl intl

RUN pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app