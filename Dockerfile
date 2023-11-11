FROM php:8.2.5-fpm

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
WORKDIR /app