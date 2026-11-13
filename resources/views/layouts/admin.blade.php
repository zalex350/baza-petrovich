<!DOCTYPE html>
<html lang="ru">
<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        @yield('title', 'Админ-панель') — {{ config('shop.name') }}
    </title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite([
        'resources/css/app.css',
        'resources/js/app.js'
    ])

    {{-- Скрипты отдельных экранов (редактор страниц) --}}
    @stack('head')

</head>

<body class="bg-canvas text-ink" data-layout="admin">

@php
    // Пункты бокового меню админки; активный раздел выделяется (BR-008)
    $adminMenu = [
        // 'active' — точные шаблоны: 'admin/order*' совпал бы и с admin/organizations
        ['url' => '/admin/products',      'label' => 'Товары',       'icon' => 'heroicon-o-cube',                    'active' => ['admin/products', 'admin/products/*', 'admin/product/*']],
        ['url' => '/admin/catalog-filters', 'label' => 'Фильтры каталога', 'icon' => 'heroicon-o-funnel', 'active' => ['admin/catalog-filters', 'admin/catalog-filters/*']],
        ['url' => '/admin/attributes',    'label' => 'Характеристики', 'icon' => 'heroicon-o-adjustments-horizontal',  'active' => ['admin/attributes', 'admin/attribute/*']],
        ['url' => '/admin/search-synonyms', 'label' => 'Синонимы поиска', 'icon' => 'heroicon-o-language', 'active' => ['admin/search-synonyms', 'admin/search-synonym/*']],
        ['url' => '/admin/orders',        'label' => 'Заказы',       'icon' => 'heroicon-o-clipboard-document-list', 'active' => ['admin/orders', 'admin/order/*']],
        ['url' => '/admin/organizations', 'label' => 'Клиенты',      'icon' => 'heroicon-o-building-office-2',       'active' => ['admin/organizations', 'admin/organization/*']],
        ['url' => '/admin/users',         'label' => 'Пользователи', 'icon' => 'heroicon-o-users',                   'active' => ['admin/users', 'admin/user/*']],
        ['url' => '/admin/partners',      'label' => 'Заявки',       'icon' => 'heroicon-o-briefcase',               'active' => ['admin/partners', 'admin/partner/*']],
        ['url' => '/admin/categories',    'label' => 'Категории',    'icon' => 'heroicon-o-folder',                  'active' => ['admin/categories', 'admin/category/*']],
        ['url' => '/admin/banners',       'label' => 'Баннеры',      'icon' => 'heroicon-o-photo',                   'active' => ['admin/banners', 'admin/banner/*']],
        ['url' => '/admin/pages',         'label' => 'Страницы',     'icon' => 'heroicon-o-document-text',           'active' => ['admin/pages', 'admin/page/*']],
        ['url' => '/admin/exchange',      'label' => 'Обмен с 1С',   'icon' => 'heroicon-o-arrows-right-left',       'active' => ['admin/exchange', 'admin/exchange/*']],
        ['url' => '/admin/settings',      'label' => 'Настройки',    'icon' => 'heroicon-o-cog-6-tooth',             'active' => ['admin/settings']],
    ];
@endphp

<div class="min-h-screen lg:flex" x-data="{ sidebar: false }">

    {{-- Боковое меню: на десктопе прилипает, высотой в экран, со своей прокруткой --}}
    <aside
        class="bg-slate-900 text-slate-200 w-64 shrink-0 fixed inset-y-0 left-0 z-40 overflow-y-auto overscroll-contain transform transition-transform lg:sticky lg:top-0 lg:h-screen lg:self-start lg:translate-x-0"
        data-admin-sidebar
        :class="sidebar ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
    >

        <div class="h-16 flex items-center justify-between px-5 border-b border-slate-800">

            <a href="/admin/products" class="font-extrabold tracking-tight text-white">
                {{ config('shop.name') }}
                <span class="block text-xs font-normal text-slate-400">Админ-панель</span>
            </a>

            <button type="button" class="lg:hidden text-slate-400 hover:text-white" @click="sidebar = false" aria-label="Закрыть меню">
                @svg('heroicon-o-x-mark', 'w-6 h-6')
            </button>

        </div>

        <nav class="p-3 space-y-1" aria-label="Меню админ-панели">

            @foreach($adminMenu as $item)

                @php($isActive = request()->is(...$item['active']))

                <a
                    href="{{ $item['url'] }}"
                    @class([
                        'flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition',
                        'bg-slate-700 text-white font-medium' => $isActive,
                        'text-slate-300 hover:bg-slate-800 hover:text-white' => ! $isActive,
                    ])
                    @if($isActive) aria-current="page" @endif
                >
                    @svg($item['icon'], 'w-5 h-5 shrink-0')
                    {{ $item['label'] }}
                </a>

            @endforeach

        </nav>

    </aside>

    <div
        class="fixed inset-0 bg-black/40 z-30 lg:hidden"
        x-show="sidebar"
        x-transition.opacity
        @click="sidebar = false"
        x-cloak
    ></div>

    {{-- Контент --}}
    <div class="flex-1 min-w-0">

        <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 lg:px-8">

            <button type="button" class="lg:hidden text-slate-600" @click="sidebar = true" aria-label="Открыть меню">
                @svg('heroicon-o-bars-3', 'w-6 h-6')
            </button>

            <div class="ml-auto flex items-center gap-4 text-sm">

                <a href="/" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-link hover:underline" data-open-site>
                    Открыть сайт
                    <span aria-hidden="true">↗</span>
                    <span class="sr-only">(в новой вкладке)</span>
                </a>

                <span class="text-slate-600">
                    {{ auth()->user()->name }}
                </span>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-red-600 hover:text-red-700">
                        Выйти
                    </button>
                </form>

            </div>

        </header>

        <main class="max-w-7xl mx-auto py-6 px-4 lg:px-8">

            <x-alert.flash :validation="trim($__env->yieldContent('flash-validation', 'list'))" />

            @yield('content')

        </main>

    </div>

</div>

</body>
</html>
