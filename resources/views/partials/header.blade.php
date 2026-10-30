{{--
    Шапка витрины (SRS §3.4–3.6, BR-002…BR-010).
    Данные: $cartSummary (HeaderComposer), $catalogMenu (CatalogMenuComposer).
    Под основной строкой — главное меню (SRS §3.5, BR-008): активный пункт подсвечен;
    на телефоне те же пункты — в панели ☰ (partials/header/mobile).
--}}
@php
    $user = auth()->user();
    $iconClass = 'w-6 h-6 shrink-0';

    // Активный раздел (BR-008)
    $isCatalog = request()->is('catalog*') || request()->is('product/*');
    $isCart    = request()->is('order');
    // Разделы кабинета — один список с меню кабинета (App\Support\AccountMenu)
    $accountMenu = $user ? \App\Support\AccountMenu::items($user) : [];
    $isCabinet = $user && \App\Support\AccountMenu::isActive($user);

    // Главное меню: подпись, адрес, когда активен
    $mainMenu = [
        ['label' => 'Главная', 'url' => '/', 'active' => request()->is('/')],
        ['label' => 'Каталог', 'url' => route('catalog.index', [], false), 'active' => $isCatalog],
        ['label' => 'Акции', 'url' => '/promo', 'active' => request()->is('promo')],
        ['label' => 'Новинки', 'url' => '/new', 'active' => request()->is('new')],
        ['label' => 'Доставка и оплата', 'url' => '/delivery', 'active' => request()->is('delivery')],
        ['label' => 'О компании', 'url' => '/about', 'active' => request()->is('about')],
        ['label' => 'Контакты', 'url' => '/contacts', 'active' => request()->is('contacts')],
    ];
@endphp

<header class="bg-surface border-b border-line sticky top-0 z-50" data-header>

    {{-- Верхняя строка: контакты --}}
    <div class="hidden lg:block bg-primary text-meta text-primary-fg" data-header-topbar>

        <div class="max-w-page mx-auto px-6 py-1.5 flex items-center justify-between">

            <div class="flex items-center gap-6">

                {{-- Контакты — «Настройки» в админке; в полосе — первый телефон, незаполненное не выводится --}}
                @php
                    $topPhone = \App\Models\Setting::phones()[0] ?? null;
                    $topEmail = \App\Models\Setting::value('contact_email');
                    $topHours = \App\Models\Setting::value('contact_hours');
                @endphp

                @if($topPhone)
                    <a href="tel:{{ $topPhone['tel'] }}" class="hover:text-white" @if($topPhone['label']) title="{{ $topPhone['label'] }}" @endif>
                        {{ $topPhone['number'] }}
                    </a>
                @endif

                @if($topEmail)
                    <a href="mailto:{{ $topEmail }}" class="hover:text-white">
                        {{ $topEmail }}
                    </a>
                @endif

                @if($topHours)
                    <span>
                        {{ $topHours }}
                    </span>
                @endif

            </div>

            <nav class="flex items-center gap-5" aria-label="Информация">
                <a href="/delivery" class="hover:text-white">Доставка</a>
                <a href="/contacts" class="hover:text-white">Контакты</a>
            </nav>

        </div>

    </div>

    {{-- Основная строка (desktop) --}}
    <div class="hidden lg:block">

        <div class="relative max-w-page mx-auto px-6 py-3.5 flex items-center gap-4 xl:gap-5">

            <x-logo class="shrink-0" />

            @include('partials.header.mega-menu')

            @include('partials.header.search', ['id' => 'desktop'])

            <a
                href="#brands"
                class="font-medium text-ink hover:text-link shrink-0"
            >
                Производители
            </a>

            {{-- Корзина --}}
            <a
                href="/order"
                @class([
                    'flex items-center gap-3 shrink-0',
                    'text-link' => $isCart,
                    'text-ink hover:text-link' => ! $isCart,
                ])
                @if($isCart) aria-current="page" @endif
                aria-label="Корзина: {{ $cartSummary['count'] }} поз."
                data-cart
                data-cart-link
            >
                <span class="relative">
                    @svg('heroicon-o-shopping-cart', $iconClass)
                    {{-- Пустая корзина — счётчик скрыт; cart-actions.js показывает его после «В корзину» --}}
                    <span class="absolute -top-2 -right-2.5 min-w-4 px-1.5 rounded-full bg-link text-white text-counter font-bold text-center tabular-nums" data-cart-count @if($cartSummary['count'] < 1) hidden @endif>{{ $cartSummary['count'] }}</span>
                </span>
                {{-- На 1024px подписи скрыты, чтобы поиску хватило ширины --}}
                <span class="leading-tight hidden xl:block">
                    <span class="block text-meta text-ink-muted">Корзина, с НДС</span>
                    {{-- Сумма с НДС всех позиций корзины (как и счётчик); НДС в ней — в title --}}
                    <x-price
                        :amount="$cartSummary['total']"
                        class="block font-semibold text-ink"
                        data-cart-total
                        title="с НДС, в т.ч. НДС {{ money($cartSummary['vat']) }}"
                    />
                </span>
            </a>

            {{-- Пользователь --}}
            @guest

                <x-button variant="secondary" size="sm" href="/partner" class="shrink-0" data-partner-link>Стать партнёром</x-button>

                <a
                    href="{{ route('login') }}"
                    class="flex items-center gap-2 pl-4 border-l border-line font-medium text-ink hover:text-link shrink-0"
                >
                    @svg('heroicon-o-user', $iconClass)
                    Войти
                </a>

            @else

                <div class="relative shrink-0 pl-4 border-l border-line" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">

                    <button
                        type="button"
                        @click="open = ! open"
                        :aria-expanded="open.toString()"
                        aria-haspopup="true"
                        aria-controls="user-menu"
                        aria-label="Личный кабинет"
                        title="Личный кабинет"
                        @class([
                            'flex items-center gap-2 text-left',
                            'text-link' => $isCabinet,
                            'text-ink hover:text-link' => ! $isCabinet,
                        ])
                    >
                        <span class="leading-tight max-w-28 xl:max-w-48">
                            <span class="block truncate font-semibold">{{ $user->name }}</span>
                            @if($user->organization)
                                <span class="block truncate text-meta text-ink-muted">{{ $user->organization->name }}</span>
                            @endif
                        </span>
                        @svg('heroicon-o-chevron-down', 'w-4 h-4')
                    </button>

                    <div
                        id="user-menu"
                        x-show="open"
                        x-transition.origin.top.right
                        x-cloak
                        class="absolute right-0 mt-2 w-64 bg-surface border border-line rounded-panel shadow-lg py-2 text-sm z-50"
                        role="menu"
                    >
                        <div class="px-4 pb-2 mb-1 border-b border-line text-xs text-ink-muted">Личный кабинет</div>
                        @foreach($accountMenu as $item)
                            <a
                                href="{{ $item['url'] }}"
                                @class(['flex items-center gap-2.5 px-4 py-2', 'bg-chip text-link font-medium' => $item['active'], 'hover:bg-canvas' => ! $item['active']])
                                role="menuitem"
                                @if($item['active']) aria-current="page" @endif
                                data-user-menu-item
                            >
                                @svg($item['icon'], 'h-4 w-4 shrink-0 text-ink-muted')
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                        <form method="POST" action="{{ route('logout') }}" class="border-t border-line mt-1 pt-1">
                            @csrf
                            <button type="submit" class="w-full text-left px-4 py-2 text-danger hover:bg-canvas" role="menuitem">Выход</button>
                        </form>
                    </div>

                </div>

                @if($user->isAdmin())

                    <a
                        href="/admin/products"
                        class="text-ink-muted hover:text-ink shrink-0"
                        title="Админ-панель"
                        aria-label="Админ-панель"
                        data-admin-link
                    >
                        @svg('heroicon-o-cog-6-tooth', $iconClass)
                    </a>

                @endif

            @endguest

        </div>

    </div>

    {{-- Главное меню (desktop) --}}
    <nav class="hidden lg:block border-t border-line-soft" aria-label="Главное меню" data-main-menu>
        <ul class="max-w-page mx-auto px-6 flex items-center gap-1 text-sm">
            @foreach($mainMenu as $item)
                <li>
                    <a
                        href="{{ $item['url'] }}"
                        @class([
                            'relative inline-flex items-center px-3 py-2.5 font-medium transition',
                            'text-link after:absolute after:inset-x-3 after:bottom-0 after:h-0.5 after:rounded-bar after:bg-link' => $item['active'],
                            'text-ink hover:text-link' => ! $item['active'],
                        ])
                        @if($item['active']) aria-current="page" @endif
                    >{{ $item['label'] }}</a>
                </li>
            @endforeach
        </ul>
    </nav>

    {{-- Мобильная версия (<1024px) --}}
    @include('partials.header.mobile')

</header>
