FROM php:8.5.11-cli

COPY --from=composer/composer:2.9.8-bin /composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pcntl
