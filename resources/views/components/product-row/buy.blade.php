{{--
    Покупка товара: в строке списка (x-product-row) и в карточке товара (layout="card").
    Его же отдаёт CartController::add в JSON (поле html) — cart-actions.js заменяет им блок
    после «В корзину» без перезагрузки; вид блока — из поля layout формы.
    <x-product-row.buy :product="$product" :in-cart="$item" />
    <x-product-row.buy :product="$product" :in-cart="$item" layout="card" />   — крупно, на всю ширину (48px)
    <x-product-row.buy :product="$product" :in-cart="$item" layout="tile" />   — плитка (x-product-card): количество, под ним кнопка
    Гость — «Войдите, чтобы заказать» (возврат на эту страницу); администратор — «Редактировать»
    (товар в админке; заказывать он не может, BR-121); иной не клиент — ничего;
    нет цены / нет остатка — неактивная кнопка; иначе — x-qty с кратностью товара и «В корзину»
    (POST /order/add/{id}): кнопка видна всегда, товар уже в корзине — количество добавляется
    к имеющемуся. Под количеством — «В корзине: N шт. · перейти»; место под неё оставлено
    во всех строках, чтобы строки списка не отличались по высоте.
    В строке колонки фиксированной ширины (grid-cols-product-buy): количество | кнопка —
    одна под другой во всех строках; кнопка без количества — во второй колонке.
    Без JS — обычные формы. Сообщение об ошибке — в [data-cart-message].
--}}
@props(['product', 'inCart' => null, 'layout' => 'row'])

@php
    $user = auth()->user();
    $card = $layout === 'card';
    $tile = $layout === 'tile';

    $size = $card ? 'lg' : 'md';
    // Строка: на телефоне количество | кнопка на всю оставшуюся ширину, с md — фиксированные колонки
    $grid = 'grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-2 gap-y-1 md:grid-cols-product-buy';
    // Кнопка без количества: в строке — во второй колонке, в карточке и плитке — на всю ширину
    $single = $card || $tile ? 'w-full' : 'col-span-2 w-full md:col-span-1 md:col-start-2';
    $stacked = $card || $tile;
    // Один товар может стоять на странице несколько раз (карусели главной) — id поля уникален
    $qtyId = 'qty-'.$product->id.($tile ? '-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)) : '');
@endphp

<div {{ $attributes->class(['flex flex-col', $card ? 'gap-2' : 'gap-1']) }} data-cart-buy>
    @guest
        <x-button variant="secondary" :size="$size" :href="route('login', ['return' => request()->getRequestUri()])" class="w-full">
            Войдите, чтобы заказать
        </x-button>
    @elseif($user->isAdmin())
        <div @class([$grid => ! $stacked])>
            <x-button variant="secondary" :size="$size" :href="'/admin/product/'.$product->id" :class="$single" data-admin-edit>
                Редактировать
            </x-button>
        </div>
    @elseif($user->isClient())
        @if($product->price === null || $product->stock < 1)
            <div @class([$grid => ! $stacked])>
                <x-button variant="secondary" :size="$size" type="button" disabled :class="$single" data-buy-disabled>
                    {{ $product->price === null ? 'Цена уточняется' : 'Нет в наличии' }}
                </x-button>
            </div>
        @else
            <form
                method="POST"
                action="/order/add/{{ $product->id }}"
                @class([$card ? 'flex flex-wrap gap-2.5' : ($tile ? 'flex flex-col gap-2' : $grid)])
                data-cart-add
                data-product-id="{{ $product->id }}"
            >
                @csrf
                @if($card || $tile)
                    <input type="hidden" name="layout" value="{{ $layout }}">
                @endif
                <x-qty name="qty" :id="$qtyId" :product="$product" :max="$product->stock" :size="$card ? 'lg' : 'sm'" />
                <x-button :size="$size" :class="$card ? 'grow basis-48' : 'w-full'">В корзину</x-button>
            </form>
        @endif

        {{-- «В корзине: N шт.» — место оставлено и без товара в корзине --}}
        <div class="min-h-5 text-meta text-ink-muted" data-in-cart-slot>
            @if($inCart)
                <span data-in-cart>В корзине: {{ $inCart->quantity }} шт. · <a href="/order" class="text-link hover:underline">перейти</a></span>
            @endif
        </div>

        <div class="text-meta text-tone-danger-text empty:hidden" role="status" data-cart-message></div>
    @endguest
</div>
