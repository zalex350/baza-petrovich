FROM php:8.2-fpm

# libjpeg/libwebp/freetype — GD с поддержкой JPEG и WebP: обработка фото товаров
# (intervention/image, размеры в webp)
RUN apt-get update && apt-get install -y \
    git curl zip unzip \
    libpng-dev libonig-dev libxml2-dev \
    libjpeg62-turbo-dev libwebp-dev libfreetype6-dev

RUN docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Xdebug установлен, но выключен (xdebug.mode=off в php.ini): не тормозит тесты и страницы.
# Включить на один запуск: XDEBUG_MODE=debug (или coverage) перед командой, например
#   docker-compose exec -e XDEBUG_MODE=coverage php php artisan test --coverage
RUN pecl install xdebug && docker-php-ext-enable xdebug

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
