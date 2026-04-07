# ----------------------------------
# STAGE 1: THE BASE
# ----------------------------------
FROM php:8.4-fpm-trixie AS base

RUN apt-get update && apt-get install -y \
    acl \
    file \
    gettext \
    git \
    unzip \
    libicu-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install pdo_mysql pcntl intl \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app


### prod
FROM base AS prod

COPY . /app
ENV APP_ENV=prod

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Build the frontend assets
RUN php bin/console tailwind:build --minify
RUN php bin/console asset-map:compile


### dev
FROM base AS dev

# Install Xdebug
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# Configure Xdebug
RUN echo "xdebug.mode=debug,profile" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.start_with_request=trigger" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_port=9003" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.output_dir=/app/var/profiling" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.profiler_output_name=cachegrind.out.%R" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini