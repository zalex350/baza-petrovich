# syntax=docker/dockerfile:1
#
# Образы тестового стенда (docker-compose.staging.yml). Код и зависимости — внутри образа,
# без bind-mount: composer install --no-dev и npm ci && npm run build выполняются здесь,
# при сборке (deploy.sh → docker compose build). Node в работающем стенде не нужен.
#
#   app — php-fpm с кодом, vendor и собранным фронтом
#   web — nginx со статикой public/ из того же кода

# --- Зависимости PHP (без dev) ---
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# --- Фронт (Vite + Tailwind): только на этапе сборки ---
FROM node:22 AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build

# --- php-fpm ---
FROM php:8.2-fpm AS app

# GD с JPEG и WebP — обработка фото товаров; zip (libzip) — .xlsx через openspout/openspout;
# opcache — production-режим PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libonig-dev libxml2-dev libzip-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip opcache \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/staging/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www

COPY --from=vendor --chown=www-data:www-data /app /var/www
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/public/build

# storage — том (загрузки, логи, сессии); в образе — пустая структура, ею заполняется
# новый том. public/storage → storage/app/public (то же, что php artisan storage:link)
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && ln -sfn /var/www/storage/app/public public/storage \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/staging/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# --- nginx: статика из того же кода ---
FROM nginx:1.27 AS web
COPY docker/staging/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/public /var/www/public
