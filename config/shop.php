<?php

/*
|--------------------------------------------------------------------------
| Витрина: контакты и реквизиты для шапки/подвала (SRS §3.4)
|--------------------------------------------------------------------------
*/

return [

    'name' => env('SHOP_NAME', 'Петрович'),

    // Префикс номера заказа: BP-2026-000001
    'order_prefix' => env('SHOP_ORDER_PREFIX', 'BP'),

    // Контакты на сайте — «Настройки» в админке (App\Models\Setting). Эти значения — только
    // начальные для переноса миграцией 2026_10_30_100000_move_contacts_to_settings
    'phone' => env('SHOP_PHONE', '+375 (29) 000-00-00'),

    'email' => env('SHOP_EMAIL', 'info@smesitel.by'),

    'working_hours' => env('SHOP_WORKING_HOURS', 'Пн–Пт 9:00–18:00'),

    /*
    | Розница (baza-petrovich.by): цены видны всем без входа, гость кладёт товары в корзину
    | и оформляет заказ. Цены товаров — розничные, с НДС (VatCalculator выделяет НДС из суммы).
    | false — прежний B2B-режим: цены после входа, цена хранится без НДС.
    */
    'retail' => (bool) env('SHOP_RETAIL', true),

    'prices_include_vat' => (bool) env('SHOP_PRICES_INCLUDE_VAT', true),

    // Учёт остатков: true — остаток из 1С/админки ограничивает заказ, витрина показывает «Много / Мало / N шт.»;
    // false — пока остатков нет (baza-petrovich.by до обмена с 1С): товар «В наличии» (stock > 0) или «Нет в наличии»
    'track_stock' => (bool) env('SHOP_TRACK_STOCK', true),

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

    'exchange_1c' => [
        // Поля товара, которыми владеет 1С (docs/1C_EXCHANGE_SPEC.md, раздел 4, правило 5):
        // у товара с GUID в админке только для чтения с подписью «из 1С», сервер их не принимает.
        // Через запятую; допустимы article, name, sku, brand, unit, vat_rate, price, stock,
        // on_order, min_qty, qty_step, default_qty. min_qty / qty_step / on_order — добавить,
        // когда 1С подтвердит, что присылает их.
        'managed_fields' => array_values(array_filter(array_map('trim', explode(',',
            (string) env('SHOP_1C_MANAGED_FIELDS', 'article,name,brand,unit,vat_rate,price,stock')
        )))),

        // API /api/1c/v1 (docs/1C_EXCHANGE_SPEC.md, раздел 2): предел тела запроса (и сжатого,
        // и распакованного) — сверх 413; запросов в минуту на токен — сверх 429 с Retry-After;
        // срок хранения принятых файлов (storage/app/exchange, очистка exchange:prune)
        'max_body_mb' => (int) env('SHOP_1C_MAX_BODY_MB', 50),
        'rate_limit_per_minute' => (int) env('SHOP_1C_RATE_LIMIT', 60),
        'archive_days' => (int) env('SHOP_1C_ARCHIVE_DAYS', 90),
        // Пояс времени в ответах API (ISO 8601 со смещением); на входе — любое смещение (App\Support\ExchangeTime)
        'timezone' => env('SHOP_1C_TIMEZONE', 'Europe/Minsk'),
        // Цены и остатки: товар — по guid, затем точно по SKU сайта. true — ещё и по артикулу
        // (products.article), только среди товаров без GUID; неоднозначность — ошибка строки
        'match_by_article' => (bool) env('SHOP_1C_MATCH_BY_ARTICLE', false),
    ],

    /*
    | Розничное оформление (гость): способы получения и оплаты. Ключ — код в orders.delivery_method /
    | payment_method (уходит в 1С, Битрикс24, Telegram), label — подпись, address — адрес самовывоза,
    | needs_address — нужен адрес доставки покупателя. enabled = false — способ скрыт (ЕРИП — до подключения).
    */
    'checkout' => [
        'delivery' => [
            'pickup_minsk' => [
                'label' => 'Самовывоз — Минск',
                'address' => 'МКАД (внешняя сторона), д. Большое Стиклево, ул. Молодежная, 1',
                'enabled' => true,
            ],
            'pickup_brest' => [
                'label' => 'Самовывоз — Брест',
                'address' => 'г. Брест, ул. Коммерческая, 7 (кольцо Задворцы)',
                'enabled' => true,
            ],
            'delivery' => [
                'label' => 'Доставка',
                'hint' => 'По звонку, оплата водителю. Заказы до 12:00 — в тот же день. Стоимость — по согласованию с менеджером.',
                'needs_address' => true,
                'enabled' => true,
            ],
        ],
        'payment' => [
            'on_receipt' => ['label' => 'Наличными или картой при получении', 'enabled' => true],
            'invoice' => ['label' => 'Безналичный расчёт по счёту', 'enabled' => true],
            'erip' => ['label' => 'ЕРИП', 'enabled' => (bool) env('SHOP_PAYMENT_ERIP', false)],
        ],
    ],

    'orders' => [
        // Статусы заказов меняет менеджер вручную в админке. false — ручная смена
        // отключена (статусы приходят из внешней системы, например 1С).
        'manual_status' => (bool) env('SHOP_ORDERS_MANUAL_STATUS', true),
    ],

];
