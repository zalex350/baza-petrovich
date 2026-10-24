{{--
    Покупка в строке списка (x-product-row). Его же отдаёт CartController::add в JSON (поле html) —
    cart-actions.js заменяет им блок после «В корзину» без перезагрузки.
    <x-product-row.buy :product="$product" :in-cart="$item" />
    Гость — «Войдите, чтобы заказать» (возврат на эту страницу); не клиент (BR-121) — ничего;
    нет цены / нет остатка — неактивная кнопка; товар в корзине — x-qty (POST /order/update/{item}) и
    «В корзине · перейти»; иначе — x-qty с кратностью товара и «В корзину» (POST /order/add/{id}).
    Без JS — обычные формы. Сообщение об ошибке — в [data-cart-message].
--}}
@props(['product', 'inCart' => null])

@php
    $user = auth()->user();

    // Количество в корзине, не попадающее в кратность (положили до её настройки), x-qty не «исправляет»
    $min = (int) $product->min_qty;
    $step = (int) $product->qty_step;
    $aligned = $inCart && $inCart->quantity >= $min && ($inCart->quantity - $min) % max(1, $step) === 0;
@endphp

<div {{ $attributes->class('flex flex-col gap-1 md:items-end') }} data-cart-buy>
    @guest
        <x-button variant="secondary" :href="route('login', ['return' => request()->getRequestUri()])" class="w-full md:w-auto">
            Войдите, чтобы заказать
        </x-button>
    @elseif($user->isClient())
        @if($product->price === null)
            <x-button variant="secondary" type="button" disabled class="w-full md:w-auto" data-buy-disabled>Цена уточняется</x-button>
        @elseif($product->stock < 1)
            <x-button variant="secondary" type="button" disabled class="w-full md:w-auto" data-buy-disabled>Нет в наличии</x-button>
        @elseif($inCart)
            <form method="POST" action="/order/update/{{ $inCart->id }}" class="flex md:justify-end" data-cart-update>
                @csrf
                <x-qty
                    name="quantity"
                    :id="'cart-qty-'.$product->id"
                    :value="$inCart->quantity"
                    :min="$aligned ? $min : 1"
                    :step="$aligned ? $step : 1"
                    :max="$product->stock"
                    size="sm"
                    label="Количество в корзине"
                />
                {{-- Без JS количество отправляется этой кнопкой --}}
                <button type="submit" class="sr-only">Обновить количество</button>
            </form>
            <a href="/order" class="text-meta text-link hover:underline" data-in-cart>В корзине · перейти</a>
        @else
            <form method="POST" action="/order/add/{{ $product->id }}" class="flex gap-2" data-cart-add data-product-id="{{ $product->id }}">
                @csrf
                <x-qty name="qty" :id="'qty-'.$product->id" :product="$product" :max="$product->stock" size="sm" />
                <x-button class="grow md:grow-0">В корзину</x-button>
            </form>
        @endif

        <div class="text-meta text-tone-danger-text empty:hidden md:text-right" role="status" data-cart-message></div>
    @endguest
</div>
