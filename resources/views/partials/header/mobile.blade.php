{{--
    Мобильная шапка (<1024px), макет docs/design/CartMobile: ☰ слева (панель тоже выезжает слева),
    логотип по центру, корзина справа; поиск — отдельной строкой ниже. Кабинет, вход и контакты — в панели.
    Off-canvas панель с drill-down каталогом. Поведение — resources/js/mobile-menu.js.
--}}
<div
    class="lg:hidden"
    x-data="mobileMenu({ menuUrl: @js(route('catalog.menu', [], false)) })"
    @keydown.escape.window="closePanel()"
>

    <div class="px-4 py-2.5 flex flex-col gap-2.5">

        {{-- ☰ — логотип — корзина --}}
        <div class="flex items-center justify-between">

            <button
                type="button"
                x-ref="burger"
                @click="openPanel()"
                :aria-expanded="panel.toString()"
                aria-controls="mobile-panel"
                aria-label="Меню"
                class="w-touch h-touch -ml-2.5 flex items-center justify-center text-primary"
            >
                @svg('heroicon-o-bars-3', 'w-6 h-6')
            </button>

            <x-logo size="sm" class="min-w-0 truncate" />

            <a
                href="/order"
                aria-label="Корзина: {{ $cartSummary['count'] }} поз."
                @if($isCart) aria-current="page" @endif
                data-cart-link
                @class(['relative w-touch h-touch -mr-2.5 flex items-center justify-center', 'text-link' => $isCart, 'text-primary' => ! $isCart])
            >
                @svg('heroicon-o-shopping-cart', 'w-6 h-6')
                <span class="absolute top-1 right-0.5 min-w-4 px-1.5 rounded-full bg-accent text-accent-fg text-counter font-bold text-center tabular-nums" data-cart-count-mobile @if($cartSummary['count'] < 1) hidden @endif>{{ $cartSummary['count'] }}</span>
            </a>

        </div>

        {{-- Поиск — всегда виден --}}
        <div id="mobile-search">
            @include('partials.header.search', ['id' => 'mobile'])
        </div>

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
        class="fixed inset-y-0 left-0 z-[70] w-[min(22rem,88vw)] bg-surface shadow-xl flex flex-col"
        role="dialog"
        aria-modal="true"
        aria-label="Меню"
    >

        <div class="h-14 px-4 flex items-center justify-between border-b border-line shrink-0">
            <x-logo size="sm" :href="null" />
            <button type="button" x-ref="close" @click="closePanel()" aria-label="Закрыть меню" class="w-10 h-10 flex items-center justify-center text-ink-muted">
                @svg('heroicon-o-x-mark', $iconClass)
            </button>
        </div>

        {{-- Главный экран панели --}}
        <div x-show="view === 'main'" class="flex-1 overflow-y-auto">

            <nav class="py-2 text-base" aria-label="Мобильное меню">

                <button type="button" @click="openCatalog()" class="w-full flex items-center gap-3 px-4 py-3 text-left font-medium hover:bg-canvas">
                    @svg('heroicon-o-squares-2x2', 'w-5 h-5 text-ink-muted')
                    <span class="flex-1">Каталог</span>
                    @svg('heroicon-o-chevron-right', 'w-4 h-4 text-ink-muted')
                </button>

                {{-- Главное меню, кроме «Каталог» (он — кнопкой выше) --}}
                @foreach($mainMenu as $item)
                    @continue($item['label'] === 'Каталог')
                    <a
                        href="{{ $item['url'] }}"
                        @class(['flex items-center gap-3 px-4 py-3 pl-12', 'font-semibold text-link' => $item['active'], 'hover:bg-canvas' => ! $item['active']])
                        @if($item['active']) aria-current="page" @endif
                        data-mobile-menu-item
                    >{{ $item['label'] }}</a>
                @endforeach

                <a href="/uslugi" class="flex items-center gap-3 px-4 py-3 hover:bg-canvas" data-services-link>
                    @svg('heroicon-o-wrench-screwdriver', 'w-5 h-5 text-ink-muted')
                    Услуги
                </a>

                <a href="{{ route('lead.create', ['type' => 'callback']) }}" class="flex items-center gap-3 px-4 py-3 hover:bg-canvas" data-callback-link>
                    @svg('heroicon-o-phone-arrow-down-left', 'w-5 h-5 text-ink-muted')
                    Обратный звонок
                </a>

                <div class="my-2 border-t border-line"></div>

                @guest

                    <a href="{{ route('login') }}" class="flex items-center gap-3 px-4 py-3 hover:bg-canvas">
                        @svg('heroicon-o-user', 'w-5 h-5 text-ink-muted')
                        Войти
                    </a>

                    <a href="/partner" class="flex items-center gap-3 px-4 py-3 hover:bg-canvas" data-partner-link>
                        @svg('heroicon-o-briefcase', 'w-5 h-5 text-ink-muted')
                        Оптовым клиентам
                    </a>

                @else

                    <div class="px-4 py-2">
                        <div class="text-xs text-ink-muted">Личный кабинет</div>
                        <div class="font-medium truncate">{{ $user->name }}</div>
                        @if($user->organization)
                            <div class="text-sm text-ink-muted truncate">{{ $user->organization->name }}</div>
                        @endif
                    </div>

                    {{-- Разделы кабинета — тот же список, что в меню кабинета и в шапке --}}
                    @foreach($accountMenu as $item)
                        <a
                            href="{{ $item['url'] }}"
                            @class(['flex items-center gap-3 px-4 py-3', 'font-semibold text-link' => $item['active'], 'hover:bg-canvas' => ! $item['active']])
                            @if($item['active']) aria-current="page" @endif
                            data-mobile-account-item
                        >
                            @svg($item['icon'], 'w-5 h-5 text-ink-muted')
                            {{ $item['label'] }}
                        </a>
                    @endforeach

                    @if($user->isAdmin())
                        <a href="/admin/products" class="flex items-center gap-3 px-4 py-3 hover:bg-canvas" data-admin-link>
                            @svg('heroicon-o-cog-6-tooth', 'w-5 h-5 text-ink-muted')
                            Админ-панель
                        </a>
                    @endif

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full text-left px-4 py-3 pl-12 text-danger hover:bg-canvas">Выход</button>
                    </form>

                @endguest

            </nav>

        </div>

        {{-- Каталог: drill-down --}}
        <div x-show="view === 'catalog'" x-cloak class="flex-1 min-h-0 flex flex-col">

            <div class="px-2 py-2 border-b border-line shrink-0">

                <button type="button" @click="back()" class="flex items-center gap-2 px-2 py-2 text-sm text-ink-muted">
                    @svg('heroicon-o-chevron-left', 'w-4 h-4')
                    Назад
                </button>

                <template x-if="current">
                    <a :href="current.url" class="block px-2 pb-1 font-semibold hover:text-link" x-text="current.name"></a>
                </template>
                <template x-if="! current">
                    <div class="px-2 pb-1 font-semibold">Каталог</div>
                </template>

                <template x-if="current">
                    <a :href="current.url" class="block px-2 pb-1 text-sm text-link">
                        Все товары раздела (<span x-text="current.count"></span>)
                    </a>
                </template>

            </div>

            <div class="flex-1 overflow-y-auto" x-ref="catalogList">

                <p x-show="loading" class="px-4 py-3 text-sm text-ink-muted">Загрузка…</p>

                <p x-show="failed" x-cloak class="px-4 py-3 text-sm text-danger">
                    Не удалось загрузить каталог.
                    <a href="{{ route('catalog.index') }}" class="underline">Открыть страницу каталога</a>
                </p>

                <ul>
                    <template x-for="node in items" :key="node.id">
                        <li class="border-b border-line">

                            {{-- Раздел с подразделами: название — ссылка на весь раздел, стрелка — вглубь --}}
                            <template x-if="node.children.length">
                                <div class="flex items-stretch">
                                    <a :href="node.url" class="flex-1 min-w-0 flex items-center gap-3 px-4 py-3 hover:bg-canvas" data-mobile-parent-link>
                                        <span class="flex-1 min-w-0" x-text="node.name"></span>
                                        <span class="text-xs text-ink-muted" x-text="node.count"></span>
                                    </a>
                                    <button type="button" @click="enter(node)" class="w-touch shrink-0 flex items-center justify-center border-l border-line text-ink-muted hover:bg-canvas" :aria-label="'Подразделы: ' + node.name">
                                        @svg('heroicon-o-chevron-right', 'w-4 h-4')
                                    </button>
                                </div>
                            </template>

                            <template x-if="! node.children.length">
                                <a :href="node.url" class="flex items-center gap-3 px-4 py-3 hover:bg-canvas">
                                    <span class="flex-1 min-w-0" x-text="node.name"></span>
                                    <span class="text-xs text-ink-muted" x-text="node.count"></span>
                                </a>
                            </template>

                        </li>
                    </template>
                </ul>

            </div>

        </div>

        {{-- Контакты внизу --}}
        <div class="border-t border-line px-4 py-4 text-sm text-ink-muted space-y-2 shrink-0">
            {{-- Контакты — «Настройки» в админке; незаполненное не выводится --}}
            @foreach(\App\Models\Setting::phones() as $phone)
                <a href="tel:{{ $phone['tel'] }}" class="flex items-center gap-2">
                    @svg('heroicon-o-phone', 'w-4 h-4')
                    {{ $phone['number'] }}
                    @if($phone['label'])
                        <span class="text-meta">· {{ $phone['label'] }}</span>
                    @endif
                </a>
            @endforeach
            @if($email = \App\Models\Setting::value('contact_email'))
                <a href="mailto:{{ $email }}" class="flex items-center gap-2">
                    @svg('heroicon-o-envelope', 'w-4 h-4')
                    {{ $email }}
                </a>
            @endif
            @if($hours = \App\Models\Setting::value('contact_hours'))
                <div class="flex items-center gap-2">
                    @svg('heroicon-o-clock', 'w-4 h-4')
                    {{ $hours }}
                </div>
            @endif
        </div>

    </aside>

</div>
