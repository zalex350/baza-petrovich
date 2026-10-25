{{--
    Покупка товара: в строке списка (x-product-row) и в карточке товара (layout="card").
    Его же отдаёт CartController::add в JSON (поле html) — cart-actions.js заменяет им блок
    после «В корзину» без перезагрузки; вид блока — из поля layout формы.
    <x-product-row.buy :product="$product" :in-cart="$item" />
    <x-product-row.buy :product="$product" :in-cart="$item" layout="card" />   — крупно, на всю ширину (48px)
    Гость — «Войдите, чтобы заказать» (возврат на эту страницу); администратор — «Редактировать»
    (товар в админке; заказывать он не может, BR-121); иной не клиент — ничего;
    нет цены / нет остатка — неактивная кнопка; товар в корзине — x-qty (POST /order/update/{item}) и
    «В корзине · перейти»; иначе — x-qty с кратностью товара и «В корзину» (POST /order/add/{id}).
    Без JS — обычные формы. Сообщение об ошибке — в [data-cart-message].
--}}
@props(['product', 'inCart' => null, 'layout' => 'row'])

@php
    $user = auth()->user();
    $card = $layout === 'card';

    // Количество в корзине, не попадающее в кратность (положили до её настройки), x-qty не «исправляет»
    $min = (int) $product->min_qty;
    $step = (int) $product->qty_step;
    $aligned = $inCart && $inCart->quantity >= $min && ($inCart->quantity - $min) % max(1, $step) === 0;

    $size = $card ? 'lg' : 'md';
    $wide = $card ? 'w-full' : 'w-full md:w-auto';
    $addButton = $card ? 'grow basis-48' : 'grow md:grow-0';
@endphp

<div {{ $attributes->class(['flex flex-col', $card ? 'gap-2' : 'gap-1 md:items-end']) }} data-cart-buy>
    @guest
        <x-button variant="secondary" :size="$size" :href="route('login', ['return' => request()->getRequestUri()])" :class="$wide">
            Войдите, чтобы заказать
        </x-button>
    @elseif($user->isAdmin())
        <x-button variant="secondary" :size="$size" :href="'/admin/product/'.$product->id" :class="$wide" data-admin-edit>
            Редактировать
        </x-button>
    @elseif($user->isClient())
        @if($product->price === null)
            <x-button variant="secondary" :size="$size" type="button" disabled :class="$wide" data-buy-disabled>Цена уточняется</x-button>
        @elseif($product->stock < 1)
            <x-button variant="secondary" :size="$size" type="button" disabled :class="$wide" data-buy-disabled>Нет в наличии</x-button>
        @elseif($inCart)
            <form method="POST" action="/order/update/{{ $inCart->id }}" @class(['flex', $card ? 'flex-wrap items-center gap-x-4 gap-y-2' : 'md:justify-end']) data-cart-update>
                @csrf
                <x-qty
                    name="quantity"
                    :id="'cart-qty-'.$product->id"
                    :value="$inCart->quantity"
                    :min="$aligned ? $min : 1"
                    :step="$aligned ? $step : 1"
                    :max="$product->stock"
                    :size="$card ? 'lg' : 'sm'"
                    label="Количество в корзине"
                />
                {{-- Без JS количество отправляется этой кнопкой --}}
                <button type="submit" class="sr-only">Обновить количество</button>

                @if($card)
                    <a href="/order" class="text-link hover:underline" data-in-cart>В корзине · перейти</a>
                @endif
            </form>

            @unless($card)
                <a href="/order" class="text-meta text-link hover:underline" data-in-cart>В корзине · перейти</a>
            @endunless
        @else
            <form method="POST" action="/order/add/{{ $product->id }}" @class(['flex gap-2', 'flex-wrap gap-2.5' => $card]) data-cart-add data-product-id="{{ $product->id }}">
                @csrf
                @if($card)
                    <input type="hidden" name="layout" value="card">
                @endif
                <x-qty name="qty" :id="'qty-'.$product->id" :product="$product" :max="$product->stock" :size="$card ? 'lg' : 'sm'" />
                <x-button :size="$size" :class="$addButton">В корзину</x-button>
            </form>
        @endif

        <div @class(['text-meta text-tone-danger-text empty:hidden', 'md:text-right' => ! $card]) role="status" data-cart-message></div>
    @endguest
</div>
