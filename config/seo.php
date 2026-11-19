<?php

/*
|--------------------------------------------------------------------------
| SEO витрины (App\Support\Seo, App\Support\SeoTemplates, /sitemap.xml, /robots.txt)
|--------------------------------------------------------------------------
| Тексты и шаблоны меняются в админке «Настройки» → SEO; здесь — значения по умолчанию.
*/

return [

    // Индексация: false — noindex на всех страницах и «Disallow: /» в robots.txt (стенд, локально)
    'index' => (bool) env('SEO_INDEX', env('APP_ENV') === 'production'),

    // Основной домен для canonical, sitemap и Open Graph (без / в конце); пусто — APP_URL
    'url' => env('SEO_URL'),

    'site_name' => env('SEO_SITE_NAME', 'Петрович'),

    // Подпись к названию в заголовке главной
    'tagline' => 'металлобаза в Минске и Бресте: профнастил, металлочерепица, сетка, заборы',

    'description' => 'Металлобаза «Петрович»: профнастил, металлочерепица, сетка, заборы и ворота, металлопрокат в Минске и Бресте. Цены на сайте, доставка по Беларуси в день заказа, монтаж.',

    // Город в шаблонах ({city}) — в предложном падеже: «купить в Минске»
    'city' => env('SEO_CITY', 'Минске'),

    // Картинка Open Graph по умолчанию (1200×630)
    'og_image' => '/images/og-default.jpg',

    // Организация для schema.org (LocalBusiness): филиалы — адреса самовывоза
    'organization' => [
        'type' => 'HardwareStore',
        'legal_name' => 'ООО «Петрович СТД»',
        'price_range' => 'BYN',
        'branches' => [
            [
                'name' => 'Петрович — Минск',
                'street' => 'ул. Молодежная, 1',
                'locality' => 'д. Большое Стиклево',
                'region' => 'Минская обл.',
                'postal' => '220047',
                'geo' => null, // [широта, долгота] — для карт; заполнить по Яндекс.Картам
            ],
            [
                'name' => 'Петрович — Брест',
                'street' => 'ул. Коммерческая, 7',
                'locality' => 'Брест',
                'region' => 'Брестская обл.',
                'postal' => '224000',
                'geo' => null,
            ],
        ],
        'hours' => [
            ['days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'opens' => '09:00', 'closes' => '17:30'],
        ],
    ],

    // Счётчики аналитики (ID — админка «Настройки»); false — не подключать нигде (локально, стенд)
    'analytics' => (bool) env('ANALYTICS_ENABLED', true),

    // Кэш sitemap.xml, секунд
    'sitemap_ttl' => (int) env('SEO_SITEMAP_TTL', 3600),
];
