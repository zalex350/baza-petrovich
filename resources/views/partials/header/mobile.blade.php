{{--
    Мобильная шапка (<1024px): ☰ слева (панель тоже выезжает слева), логотип, поиск, корзина, пользователь;
    off-canvas панель с drill-down каталогом. Поведение — resources/js/mobile-menu.js.
--}}
<div
    class="lg:hidden"
    x-data="mobileMenu({ menuUrl: @js(route('catalog.menu', [], false)) })"
    @keydown.escape.window="closePanel(); search = false"
>

    {{-- Строка шапки --}}
    <div class="h-14 px-4 flex items-center gap-1">

        <button
            type="button"
            x-ref="burger"
            @click="openPanel()"
            :aria-expanded="panel.toString()"
            aria-controls="mobile-panel"
            aria-label="Меню"
            class="w-10 h-10 -ml-2 flex items-center justify-center text-slate-700"
        >
            @svg('heroicon-o-bars-3', $iconClass)
        </button>

        <a href="/" class="text-lg font-extrabold tracking-tight text-slate-900 mr-auto truncate" aria-label="{{ config('shop.name') }} — на главную">
            {{ config('shop.name') }}
        </a>

        <button
            type="button"
            @click="toggleSearch()"
            :aria-expanded="search.toString()"
            aria-controls="mobile-search"
            aria-label="Поиск"
            class="w-10 h-10 flex items-center justify-center text-slate-700"
        >
            @svg('heroicon-o-magnifying-glass', $iconClass)
        </button>

        <a
            href="/order"
            aria-label="Корзина: {{ $cartSummary['count'] }} поз."
            @class(['relative w-10 h-10 flex items-center justify-center', 'text-blue-600' => $isCart, 'text-slate-700' => ! $isCart])
        >
            @svg('heroicon-o-shopping-cart', $iconClass)
            @if($cartSummary['count'] > 0)
                <span class="absolute top-0.5 right-0 min-w-5 h-5 px-1 rounded-full bg-blue-600 text-white text-xs leading-5 text-center" data-cart-count-mobile>
                    {{ $cartSummary['count'] }}
                </span>
            @endif
        </a>

        <a
            href="{{ $user ? '/orders' : route('login') }}"
            aria-label="{{ $user ? 'Личный кабинет' : 'Войти' }}"
            @class(['w-10 h-10 flex items-center justify-center', 'text-blue-600' => $isCabinet, 'text-slate-700' => ! $isCabinet])
        >
            @svg($user ? 'heroicon-o-user-circle' : 'heroicon-o-user', $iconClass)
        </a>
    </div>

    {{-- Строка поиска --}}
    <div id="mobile-search" x-ref="searchRow" x-show="search" x-cloak x-transition class="px-4 pb-3">
        @include('partials.header.search', ['id' => 'mobile'])
    </div>

    {{-- Off-canvas --}}
    <div
        class="fixed inset-0 bg-black/40 z-[60]"
        x-show="panel"
        x-transition.opacity
        @click="closePanel()"
        x-cloak
        aria-hidden="true"
    ></div>

    <aside
        id="mobile-panel"
        x-show="panel"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="-translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="-translate-x-full"
        class="fixed inset-y-0 left-0 z-[70] w-[min(22rem,88vw)] bg-white shadow-xl flex flex-col"
        role="dialog"
        aria-modal="true"
        aria-label="Меню"
    >

        <div class="h-14 px-4 flex items-center justify-between border-b border-slate-200 shrink-0">
            <span class="font-extrabold tracking-tight">{{ config('shop.name') }}</span>
            <button type="button" x-ref="close" @click="closePanel()" aria-label="Закрыть меню" class="w-10 h-10 flex items-center justify-center text-slate-600">
                @svg('heroicon-o-x-mark', $iconClass)
            </button>
        </div>

        {{-- Главный экран панели --}}
        <div x-show="view === 'main'" class="flex-1 overflow-y-auto">

            <nav class="py-2 text-base" aria-label="Мобильное меню">

                <button type="button" @click="openCatalog()" class="w-full flex items-center gap-3 px-4 py-3 text-left font-medium hover:bg-slate-50">
                    @svg('heroicon-o-squares-2x2', 'w-5 h-5 text-slate-500')
                    <span class="flex-1">Каталог</span>
                    @svg('heroicon-o-chevron-right', 'w-4 h-4 text-slate-400')
                </button>

                <a href="#brands" @click="closePanel()" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50">
                    @svg('heroicon-o-tag', 'w-5 h-5 text-slate-500')
                    Производители
                </a>

                <a href="#delivery" @click="closePanel()" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50">
                    @svg('heroicon-o-shopping-bag', 'w-5 h-5 text-slate-500')
                    Доставка
                </a>

                <a href="#contacts" @click="closePanel()" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50">
                    @svg('heroicon-o-phone', 'w-5 h-5 text-slate-500')
                    Контакты
                </a>

                <div class="my-2 border-t border-slate-100"></div>

                @guest

                    <a href="{{ route('login') }}" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50">
                        @svg('heroicon-o-user', 'w-5 h-5 text-slate-500')
                        Войти
                    </a>

                @else

                    <div class="px-4 py-2">
                        <div class="text-xs text-slate-400">Личный кабинет</div>
                        <div class="font-medium truncate">{{ $user->name }}</div>
                        @if($user->organization)
                            <div class="text-sm text-slate-500 truncate">{{ $user->organization->name }}</div>
                        @endif
                    </div>

                    <a href="/orders" class="block px-4 py-3 pl-12 hover:bg-slate-50">Кабинет</a>
                    <a href="/orders" class="block px-4 py-3 pl-12 hover:bg-slate-50">Мои заказы</a>
                    @if($user->isClient())
                        <a href="/addresses" class="block px-4 py-3 pl-12 hover:bg-slate-50">Адреса доставки</a>
                    @endif
                    <a href="{{ route('profile.edit') }}" class="block px-4 py-3 pl-12 hover:bg-slate-50">Профиль</a>

                    @if($user->isAdmin())
                        <a href="/admin/products" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50" data-admin-link>
                            @svg('heroicon-o-cog-6-tooth', 'w-5 h-5 text-slate-500')
                            Админ-панель
                        </a>
                    @endif

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full text-left px-4 py-3 pl-12 text-red-600 hover:bg-slate-50">Выход</button>
                    </form>

                @endguest

            </nav>

        </div>

        {{-- Каталог: drill-down --}}
        <div x-show="view === 'catalog'" x-cloak class="flex-1 min-h-0 flex flex-col">

            <div class="px-2 py-2 border-b border-slate-100 shrink-0">

                <button type="button" @click="back()" class="flex items-center gap-2 px-2 py-2 text-sm text-slate-600">
                    @svg('heroicon-o-chevron-left', 'w-4 h-4')
                    Назад
                </button>

                <div class="px-2 pb-1 font-semibold" x-text="current ? current.name : 'Каталог'"></div>

                <template x-if="current">
                    <a :href="current.url" class="block px-2 pb-1 text-sm text-blue-600">
                        Все товары раздела (<span x-text="current.count"></span>)
                    </a>
                </template>

            </div>

            <div class="flex-1 overflow-y-auto" x-ref="catalogList">

                <p x-show="loading" class="px-4 py-3 text-sm text-slate-500">Загрузка…</p>

                <p x-show="failed" x-cloak class="px-4 py-3 text-sm text-red-600">
                    Не удалось загрузить каталог.
                    <a href="{{ route('catalog.index') }}" class="underline">Открыть страницу каталога</a>
                </p>

                <ul>
                    <template x-for="node in items" :key="node.id">
                        <li class="border-b border-slate-50">

                            <template x-if="node.children.length">
                                <button type="button" @click="enter(node)" class="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-slate-50">
                                    <span class="flex-1 min-w-0" x-text="node.name"></span>
                                    <span class="text-xs text-slate-400" x-text="node.count"></span>
                                    @svg('heroicon-o-chevron-right', 'w-4 h-4 text-slate-400 shrink-0')
                                </button>
                            </template>

                            <template x-if="! node.children.length">
                                <a :href="node.url" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50">
                                    <span class="flex-1 min-w-0" x-text="node.name"></span>
                                    <span class="text-xs text-slate-400" x-text="node.count"></span>
                                </a>
                            </template>

                        </li>
                    </template>
                </ul>

            </div>

        </div>

        {{-- Контакты внизу --}}
        <div class="border-t border-slate-200 px-4 py-4 text-sm text-slate-600 space-y-2 shrink-0">
            <a href="tel:{{ preg_replace('/[^+\d]/', '', config('shop.phone')) }}" class="flex items-center gap-2">
                @svg('heroicon-o-phone', 'w-4 h-4')
                {{ config('shop.phone') }}
            </a>
            <a href="mailto:{{ config('shop.email') }}" class="flex items-center gap-2">
                @svg('heroicon-o-envelope', 'w-4 h-4')
                {{ config('shop.email') }}
            </a>
            <div class="flex items-center gap-2">
                @svg('heroicon-o-clock', 'w-4 h-4')
                {{ config('shop.working_hours') }}
            </div>
        </div>

    </aside>

</div>
