<?php

/*
|--------------------------------------------------------------------------
| Витрина: контакты и реквизиты для шапки/подвала (SRS §3.4)
|--------------------------------------------------------------------------
*/

return [

    'name' => env('SHOP_NAME', 'SMESITEL.BY'),

    // Контакты на сайте — «Настройки» в админке (App\Models\Setting). Эти значения — только
    // начальные для переноса миграцией 2026_10_30_100000_move_contacts_to_settings
    'phone' => env('SHOP_PHONE', '+375 (29) 000-00-00'),

    'email' => env('SHOP_EMAIL', 'info@smesitel.by'),

    'working_hours' => env('SHOP_WORKING_HOURS', 'Пн–Пт 9:00–18:00'),

    // Время жизни кэша дерева категорий для меню, секунд
    'catalog_menu_ttl' => (int) env('SHOP_CATALOG_MENU_TTL', 3600),

    /*
    | Фото товаров (SRS §4.7, §10.4.8). Диск — любой из config/filesystems.php:
    | public — storage/app/public, нужен symlink public/storage (php artisan storage:link);
    | public_media — public/media, без symlink (хостинг, где symlink недоступен).
    | При смене диска файлы переносятся вместе с папкой products/.
    */
    'images' => [
        'disk' => env('SHOP_IMAGES_DISK', 'public'),

        // products/{product_id}/{случайное имя}-{размер}.webp
        'directory' => 'products',

        // Размеры: длинная сторона не больше, пропорции сохраняются, маленькие не растягиваются
        'sizes' => [
            'thumb' => 300,   // каталог, поиск, корзина, миниатюры
            'medium' => 800,   // карточка товара
            'large' => 1600,  // увеличенный просмотр
        ],

        'quality' => (int) env('SHOP_IMAGES_QUALITY', 82),

        // Максимум на файл, КБ (10 МБ)
        'max_kb' => 10240,

        // Проверяется MIME по содержимому файла, а не расширение
        'mimetypes' => ['image/jpeg', 'image/png', 'image/webp'],

        // Заглушка: нет фото или файл не загрузился
        'placeholder' => '/images/no-photo.svg',
    ],

    /*
    | Индикатор наличия x-stock (макеты docs/design): остаток ≥ stock_many — «Много»
    | (3 полоски); больше stock_few — «Мало» (2); 1…stock_few — точное «N шт.» (1).
    | Нет остатка — «Под заказ» (on_order) или «Нет в наличии».
    */
    'stock_many' => (int) env('SHOP_STOCK_MANY', 10),

    'stock_few' => (int) env('SHOP_STOCK_FEW', 3),

    // Выгрузка ассортимента (кабинет, App\Services\AssortmentExport): остаток — точным
    // числом до этого порога, больше — «более N»
    'export_stock_cap' => (int) env('SHOP_EXPORT_STOCK_CAP', 20),

    'catalog' => [
        // Товары заводятся вручную в админке. false — товары приходят из 1С: создание
        // в админке запрещено (403), поля учётной системы (название, артикул, бренд,
        // цена, НДС, остаток, «под заказ») только для просмотра, сервер их не принимает.
        // Данные сайта (категория, тексты, адрес страницы, характеристики, фото,
        // видимость) редактируются всегда (SRS §11.3: категория принадлежит сайту).
        'manual_products' => (bool) env('SHOP_CATALOG_MANUAL_PRODUCTS', true),
    ],

    'orders' => [
        // Статусы заказов меняет менеджер вручную в админке. false — ручная смена
        // отключена (статусы приходят из внешней системы, например 1С).
        'manual_status' => (bool) env('SHOP_ORDERS_MANUAL_STATUS', true),
    ],

];
