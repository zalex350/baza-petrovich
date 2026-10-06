{{--
    «В корзину» в списках товаров (каталог, поиск, главная).
    Данные: $product, $compact — узкая колонка таблицы (поиск).

    Форма — обычный POST на /order/add/{id}; data-cart-add, data-cart-qty и
    data-cart-message — точки подключения для отправки через fetch (CartController::add
    отвечает JSON на Accept: application/json), разметку менять не придётся.
--}}
@php
    $compact ??= false;
@endphp

<div class="{{ $compact ? 'mt-2' : 'mt-3' }}" data-cart-buy>

    @guest

        <a
            href="{{ route('login', ['return' => request()->getRequestUri()]) }}"
            class="block w-full rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-50 text-center {{ $compact ? 'px-3 py-2 text-sm' : 'py-3' }} transition"
        >
            Войдите, чтобы заказать
        </a>

    @else

        {{-- Оформлять заказы могут только клиенты (BR-121): администратор кнопку не видит --}}
        @if(auth()->user()->isClient())

            @if($product->price === null)

                <button
                    type="button"
                    disabled
                    class="w-full rounded-xl bg-slate-200 text-slate-500 cursor-not-allowed {{ $compact ? 'px-3 py-2 text-sm' : 'py-3' }}"
                >
                    Цена по запросу
                </button>

            @elseif($product->stock < 1)

                <button
                    type="button"
                    disabled
                    class="w-full rounded-xl bg-slate-200 text-slate-500 cursor-not-allowed {{ $compact ? 'px-3 py-2 text-sm' : 'py-3' }}"
                >
                    Нет в наличии
                </button>

            @else

                <form
                    method="POST"
                    action="/order/add/{{ $product->id }}"
                    class="flex items-stretch gap-2"
                    data-cart-add
                    data-product-id="{{ $product->id }}"
                >

                    @csrf

                    <label class="sr-only" for="qty-{{ $product->id }}">Количество</label>

                    <input
                        id="qty-{{ $product->id }}"
                        type="number"
                        name="qty"
                        value="1"
                        min="1"
                        max="{{ $product->stock }}"
                        class="w-16 border border-slate-300 rounded-xl px-2 text-center"
                        data-cart-qty
                    >

                    <button
                        type="submit"
                        class="grow inline-flex items-center justify-center gap-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-white {{ $compact ? 'px-3 py-2 text-sm' : 'py-3' }} transition"
                    >
                        @svg('heroicon-o-shopping-cart', 'w-5 h-5 shrink-0')
                        В корзину
                    </button>

                </form>

                <div class="text-sm mt-1 empty:hidden" data-cart-message></div>

            @endif

        @endif

    @endguest

</div>
