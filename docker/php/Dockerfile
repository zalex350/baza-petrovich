FROM php:8.2-fpm

# libjpeg/libwebp/freetype — GD с поддержкой JPEG и WebP: обработка фото товаров
# (intervention/image, размеры в webp)
RUN apt-get update && apt-get install -y \
    git curl zip unzip \
    libpng-dev libonig-dev libxml2-dev \
    libjpeg62-turbo-dev libwebp-dev libfreetype6-dev

RUN docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
