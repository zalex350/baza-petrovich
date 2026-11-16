{{--
    Мегаменю каталога (desktop ≥1024px). Данные: $catalogMenu (CatalogMenu::tree()).
    Поведение — resources/js/mega-menu.js: задержка открытия/закрытия, menu-aim,
    Esc / клик вне панели, клавиатура. Иконка раздела (x-category-icon, цвет — currentColor):
    у корневого — иконка, без неё картинка категории, без них — пустое место той же ширины;
    у подраздела — иконка перед названием, если есть.
--}}
@php
    $firstRootId = $catalogMenu[0]['id'] ?? null;
@endphp

<div
    class="shrink-0"
    x-data="megaMenu({ first: {{ $firstRootId ?? 'null' }} })"
    @keydown.escape.window="close(true)"
    @click.window="onWindowClick($event)"
>

    <button
        type="button"
        x-ref="button"
        @mouseenter="buttonEnter()"
        @mouseleave="buttonLeave()"
        @click="toggle()"
        @keydown.arrow-down.prevent="openAndFocus()"
        aria-haspopup="true"
        aria-controls="mega-menu"
        :aria-expanded="open.toString()"
        @class([
            'flex items-center gap-2.5 h-touch px-4 rounded-control font-semibold transition',
            'bg-link text-white' => $isCatalog,
            'bg-primary hover:bg-primary-hover text-white' => ! $isCatalog,
        ])
        :class="open && 'ring-2 ring-link/30'"
        data-mega-button
    >
        <span x-show="! open">@svg('heroicon-o-bars-3', 'w-5 h-5')</span>
        <span x-show="open" x-cloak>@svg('heroicon-o-x-mark', 'w-5 h-5')</span>
        Каталог
    </button>

    <div
        id="mega-menu"
        x-ref="panel"
        x-show="open"
        x-transition.opacity.duration.150ms
        x-cloak
        @mouseenter="panelEnter()"
        @mouseleave="panelLeave()"
        class="absolute left-6 right-6 top-full mt-px bg-surface border border-line rounded-b-card shadow-xl flex max-h-[70vh] overflow-hidden z-50"
        data-mega-panel
    >

        @if(empty($catalogMenu))

            <div class="p-6 text-ink-muted">Каталог пока пуст.</div>

        @else

            {{-- Слева: корневые категории --}}
            <nav
                class="w-80 shrink-0 border-r border-line overflow-y-auto py-2"
                aria-label="Разделы каталога"
                x-ref="rootList"
                @mousemove="trackMouse($event)"
            >
                <ul>
                    @foreach($catalogMenu as $root)
                        <li>
                            <a
                                href="{{ $root['url'] }}"
                                class="flex items-center gap-3 px-4 py-2.5 text-sm outline-none"
                                :class="active === {{ $root['id'] }} ? 'bg-canvas text-link' : 'text-ink hover:bg-canvas'"
                                @mouseenter="itemEnter({{ $root['id'] }})"
                                @focus="activate({{ $root['id'] }})"
                                @keydown.arrow-down.prevent="moveRoot(1)"
                                @keydown.arrow-up.prevent="moveRoot(-1)"
                                @keydown.arrow-right.prevent="focusSubmenu()"
                                aria-controls="mega-sub-{{ $root['id'] }}"
                                :aria-expanded="(active === {{ $root['id'] }}).toString()"
                                data-mega-root="{{ $root['id'] }}"
                            >
                                @if(! empty($root['icon']))
                                    <x-category-icon :icon="$root['icon']" :svg="$root['icon_svg'] ?? null" class="h-6 w-6" />
                                @elseif($root['image'])
                                    <img src="{{ $root['image'] }}" alt="" class="w-6 h-6 object-contain shrink-0">
                                @else
                                    <span class="w-6 h-6 shrink-0" aria-hidden="true"></span>
                                @endif
                                <span class="flex-1 min-w-0 truncate">{{ $root['name'] }}</span>
                                <span class="text-xs text-ink-muted tabular-nums">{{ $root['count'] }}</span>
                                @svg('heroicon-o-chevron-right', 'w-4 h-4 text-slate-300 shrink-0')
                            </a>
                        </li>
                    @endforeach
                </ul>

                <a href="{{ route('catalog.index') }}" class="block px-4 py-2.5 mt-1 border-t border-line text-sm text-link hover:bg-canvas">
                    Все разделы каталога →
                </a>
            </nav>

            {{-- Справа: подкатегории активного раздела --}}
            <div class="flex-1 min-w-0 overflow-y-auto p-6" x-ref="submenu">

                @foreach($catalogMenu as $root)

                    <section
                        id="mega-sub-{{ $root['id'] }}"
                        x-show="active === {{ $root['id'] }}"
                        @keydown.arrow-left.prevent="focusActiveRoot()"
                        @if(! $loop->first) x-cloak @endif
                        data-mega-sub="{{ $root['id'] }}"
                    >

                        <div class="flex items-baseline justify-between gap-4 mb-5">
                            <h3 class="text-lg font-semibold text-ink">
                                {{-- Заголовок группы — ссылка на весь раздел --}}
                                <a href="{{ $root['url'] }}" class="hover:text-link" data-mega-heading>{{ $root['name'] }}</a>
                            </h3>
                            <a href="{{ $root['url'] }}" class="text-sm text-link hover:underline shrink-0">
                                Открыть весь раздел →
                            </a>
                        </div>

                        @if(empty($root['children']))

                            <p class="text-sm text-ink-muted">
                                Товаров в разделе: {{ $root['count'] }}.
                            </p>

                        @else

                            <div class="grid grid-cols-2 xl:grid-cols-3 gap-x-8 gap-y-6">

                                @foreach($root['children'] as $child)

                                    <div class="min-w-0">

                                        <a href="{{ $child['url'] }}" class="group inline-flex items-start gap-2 font-medium text-sm text-ink hover:text-link">
                                            <x-category-icon :icon="$child['icon'] ?? null" :svg="$child['icon_svg'] ?? null" class="h-5 w-5 text-ink-muted group-hover:text-link" />
                                            <span>
                                                {{ $child['name'] }}
                                                <span class="text-xs font-normal text-ink-muted">{{ $child['count'] }}</span>
                                            </span>
                                        </a>

                                        @if(! empty($child['children']))

                                            <ul class="mt-2 space-y-1">

                                                @foreach(array_slice($child['children'], 0, 5) as $grandchild)
                                                    <li>
                                                        <a href="{{ $grandchild['url'] }}" class="text-sm text-ink-muted hover:text-link">
                                                            {{ $grandchild['name'] }}
                                                        </a>
                                                    </li>
                                                @endforeach

                                                @if(count($child['children']) > 5)
                                                    <li>
                                                        <a href="{{ $child['url'] }}" class="text-sm text-link hover:underline">
                                                            ещё {{ count($child['children']) - 5 }}
                                                        </a>
                                                    </li>
                                                @endif

                                            </ul>

                                        @endif

                                    </div>

                                @endforeach

                            </div>

                        @endif

                    </section>

                @endforeach

            </div>

        @endif

    </div>

</div>
