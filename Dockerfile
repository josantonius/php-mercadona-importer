FROM php:8-cli-alpine

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
WORKDIR /app