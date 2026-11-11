{{--
    «Добавить по артикулу» на странице корзины: артикул с подсказками (/search/suggest —
    артикул и название; выбор подставляет артикул и количество товара по умолчанию),
    количество (пусто — default_qty товара), «Добавить». Enter — добавить.
    <x-cart-quick-add />                      — полоса над строкой заголовков таблицы (xl и шире)
    <x-cart-quick-add layout="card" />        — над карточками (уже xl): поля в две строки
    by-code — у организации клиента есть коды товаров (ClientCodes::has): «Добавить по вашему коду»,
    подсказки — ваш код · артикул · название, выбор подставляет ваш код; наш артикул тоже принимается
    (CartController::quickAdd ищет сначала код организации, затем артикул).
    Без перезагрузки — cart-page.js (форма [data-quick-add]); без JS — обычная форма, ошибки у полей.
--}}
@props(['layout' => 'strip', 'byCode' => false])

@php
    $card = $layout === 'card';
    $suffix = $card ? '-m' : '';
    $label = $byCode ? 'Добавить по вашему коду' : 'Добавить по артикулу';
    $placeholder = $byCode ? 'Ваш код или артикул' : 'Артикул';
    // Выбор показывается в той форме, которую отправили (strip / card — по old('layout'))
    $choices = old('layout', 'strip') === $layout ? (array) session(\App\Http\Controllers\CartController::QUICK_CHOICES, []) : [];
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
    @if($byCode) data-by-code @endif
>
    @csrf
    {{-- Выбранный товар: из подсказки (sku-suggest.js) или кнопкой выбора; ручной ввод его сбрасывает --}}
    <input type="hidden" name="product_id" value="" x-ref="productId" data-quick-product>
    <input type="hidden" name="layout" value="{{ $layout }}">

    <span @class(['text-sm font-medium text-ink-muted', 'shrink-0 pt-2.5' => ! $card])>{{ $label }}</span>

    <div @class(['relative min-w-0', 'flex-1 max-w-sm' => ! $card])>
        <x-input
            name="sku"
            :id="'quick-sku'.$suffix"
            :placeholder="$placeholder"
            :aria-label="$placeholder"
            autocomplete="off"
            role="combobox"
            aria-autocomplete="list"
            :aria-controls="'quick-sku-list'.$suffix"
            x-ref="sku"
            x-on:input="$refs.productId.value = ''; search()"
            x-on:keydown.arrow-down.prevent="move(1)"
            x-on:keydown.arrow-up.prevent="move(-1)"
            x-on:keydown.enter="enter($event)"
            x-on:keydown.escape="close()"
            x-on:blur="setTimeout(() => close(), 150)"
            ::aria-expanded="open.toString()"
        />
        <p class="mt-1 hidden text-sm text-tone-danger-text" data-quick-error="sku"></p>
        {{-- Артикул у нескольких товаров: выбор с брендом (без JS — из сессии, с JS — choices_html) --}}
        <div data-quick-choices>
            <x-cart-quick-choices :choices="$choices" />
        </div>

        {{-- Подсказки: артикул · бренд + название; с кодом организации — «ваш код · артикул», ниже название --}}
        <ul
            id="quick-sku-list{{ $suffix }}"
            x-show="open"
            x-cloak
            class="absolute inset-x-0 top-11 z-30 max-h-72 overflow-y-auto rounded-panel border border-line bg-surface py-1 shadow-lg"
            role="listbox"
        >
            <template x-for="(item, index) in items" :key="item.id">
                <li
                    role="option"
                    :aria-selected="(index === active).toString()"
                    x-on:mousedown.prevent="pick(item)"
                    x-on:mouseenter="active = index"
                    :class="index === active ? 'bg-canvas' : ''"
                    class="cursor-pointer px-3 py-2 text-sm"
                >
                    <template x-if="item.code">
                        <span><span class="font-medium text-ink" x-text="item.code"></span> <span class="text-meta text-ink-muted" x-text="'· Арт. ' + item.article"></span></span>
                    </template>
                    <template x-if="! item.code">
                        <span><span class="font-medium text-ink" x-text="'Арт. ' + item.article"></span> <span class="text-meta text-ink-muted" x-show="item.brand" x-text="'· ' + item.brand"></span></span>
                    </template>
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
