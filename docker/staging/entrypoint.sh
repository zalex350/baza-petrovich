#!/bin/sh
# Запуск php-fpm на стенде: кэши конфигурации, маршрутов и шаблонов собираются
# при старте контейнера (.env подключён снаружи — кэш должен видеть именно его).
# Для разовых команд (docker compose run --rm php php artisan …) кэши не трогаются.
#
# Владелец storage и bootstrap/cache — www-data (от него работают php-fpm, queue, scheduler).
# Контейнер php стартует от root: права чинятся до и после кэшей — файл, созданный от root
# (laravel.log после ручного artisan), иначе не даёт приложению писать лог и ошибки теряются.
# Кэши собираются от www-data.
set -e

fix_permissions() {
    chown -R www-data:www-data storage bootstrap/cache
}

if [ "$1" = "php-fpm" ]; then
    if [ "$(id -u)" = 0 ]; then
        fix_permissions
        for cmd in config:cache route:cache view:cache; do
            su -s /bin/sh www-data -c "php artisan $cmd"
        done
        fix_permissions
    else
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache
    fi
fi

exec "$@"
