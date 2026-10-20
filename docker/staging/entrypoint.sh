#!/bin/sh
# Запуск php-fpm на стенде: кэши конфигурации, маршрутов и шаблонов собираются
# при старте контейнера (.env подключён снаружи — кэш должен видеть именно его).
# Для разовых команд (docker compose run --rm php php artisan …) кэши не трогаются.
set -e

if [ "$1" = "php-fpm" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

exec "$@"
