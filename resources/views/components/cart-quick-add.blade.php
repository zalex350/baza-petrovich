{{--
    «Добавить по артикулу» на странице корзины: артикул с подсказками (/search/suggest —
    артикул и название; выбор подставляет артикул и количество товара по умолчанию),
    количество (пусто — default_qty товара), «Добавить». Enter — добавить.
    <x-cart-quick-add />                      — полоса над строкой заголовков таблицы (xl и шире)
    <x-cart-quick-add layout="card" />        — над карточками (уже xl): поля в две строки
    Без перезагрузки — cart-page.js (форма [data-quick-add]); без JS — обычная форма, ошибки у полей.
--}}
@props(['layout' => 'strip'])

@php
    $card = $layout === 'card';
    $suffix = $card ? '-m' : '';
@endphp

<form
    method="POST"
    action="/order/quick-add"
    x-data="skuSuggest(@js(route('search.suggest', [], false)))"
    {{ $attributes->class([
        'bg-field',
        $card ? 'flex flex-col gap-2 rounded-card border border-line p-3.5' : 'flex items-start gap-3 border-b border-line-soft px-4 py-3',
    ]) }}
    data-quick-add
>
    @csrf

    <span @class(['text-sm font-medium text-ink-muted', 'shrink-0 pt-2.5' => ! $card])>Добавить по артикулу</span>

    <div @class(['relative min-w-0', 'flex-1 max-w-sm' => ! $card])>
        <x-input
            name="sku"
            :id="'quick-sku'.$suffix"
            placeholder="Артикул"
            aria-label="Артикул"
            autocomplete="off"
            role="combobox"
            aria-autocomplete="list"
            :aria-controls="'quick-sku-list'.$suffix"
            x-ref="sku"
            x-on:input="search()"
            x-on:keydown.arrow-down.prevent="move(1)"
            x-on:keydown.arrow-up.prevent="move(-1)"
            x-on:keydown.enter="enter($event)"
            x-on:keydown.escape="close()"
            x-on:blur="setTimeout(() => close(), 150)"
            ::aria-expanded="open.toString()"
        />
        <p class="mt-1 hidden text-sm text-tone-danger-text" data-quick-error="sku"></p>

        {{-- Подсказки: артикул + название --}}
        <ul
            id="quick-sku-list{{ $suffix }}"
            x-show="open"
            x-cloak
            class="absolute inset-x-0 top-11 z-30 max-h-72 overflow-y-auto rounded-panel border border-line bg-surface py-1 shadow-lg"
            role="listbox"
        >
            <template x-for="(item, index) in items" :key="item.sku">
                <li
                    role="option"
                    :aria-selected="(index === active).toString()"
                    x-on:mousedown.prevent="pick(item)"
                    x-on:mouseenter="active = index"
                    :class="index === active ? 'bg-canvas' : ''"
                    class="cursor-pointer px-3 py-2 text-sm"
                >
                    <span class="font-medium text-ink" x-text="item.sku"></span>
                    <span class="block truncate text-meta text-ink-muted" x-text="item.name"></span>
                </li>
            </template>
        </ul>
    </div>

    <div @class(['flex items-start gap-2', 'w-full' => $card])>
        <div @class(['shrink-0', $card ? 'w-28' : 'w-24'])>
            <x-input name="qty" :id="'quick-qty'.$suffix" type="number" min="1" placeholder="Кол-во" aria-label="Количество" x-ref="qty" />
            <p class="mt-1 hidden text-sm text-tone-danger-text" data-quick-error="qty"></p>
        </div>
        <x-button variant="secondary" :class="$card ? 'flex-1' : 'shrink-0'">Добавить</x-button>
    </div>
</form>
