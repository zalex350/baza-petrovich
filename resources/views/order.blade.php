@extends('layouts.app')

@section('title', 'Корзина')

{{-- Ошибки оформления — в панели «Ваш заказ», «Добавить по артикулу» — под полями: общий список не нужен --}}
@section('flash-validation', 'none')

{{--
    Корзина и оформление — одна страница (макеты docs/design/Main, CartMobile).
    Слева позиции с галочкой «в заказ» (x-cart-row), справа закреплённая панель «Ваш заказ»
    по отмеченным позициям; на телефоне итог и «Оформить» — в нижней панели.
    Действия без перезагрузки — resources/js/cart-page.js: после каждого обновляет части data-refresh.
    Данные: CartController::index — $items, $validation (все позиции), $order (отмеченные), $shortages,
    $addresses, $checkoutToken, $continueUrl.
--}}

@section('content')

@php
    $client = auth()->user()->isClient();
    $state = $order->state();
    $selectedCount = $items->where('selected', true)->count();
    $totals = $order->totals();

    // «НДС 20%» — если ставка у отмеченных одна, иначе просто «НДС»
    $rates = $items->where('selected', true)->map(fn ($item) => $item->product?->vat_rate_label)->filter()->unique();
    $vatLabel = $rates->count() === 1 ? 'НДС '.$rates->first() : 'НДС';

    // Почему нельзя оформить (подсказка у неактивной кнопки)
    $blocker = match (true) {
        ! $client                          => null,
        $selectedCount === 0               => 'Отметьте позиции, которые войдут в заказ.',
        $state === \App\Enums\CartState::NeedsUpdate     => 'Цены изменились — примите новые цены.',
        $state === \App\Enums\CartState::NeedsCorrection => 'Исправьте отмеченные позиции.',
        $addresses->isEmpty()              => 'Добавьте адрес доставки.',
        default                            => null,
    };

    // Перечень причин отказа в оформлении (CheckoutException, BR-135) — числовые ключи ошибок
    $checkoutProblems = collect($errors->getMessages())->filter(fn ($v, $k) => is_int($k) || ctype_digit((string) $k))->flatten();
@endphp

<div @class(['pb-36 lg:pb-0' => $items->isNotEmpty() && $client]) data-cart-page>

    {{-- Последняя открытая категория или поиск (из сессии), иначе — каталог --}}
    <a href="{{ $continueUrl }}" class="text-sm text-link hover:underline" data-continue-shopping>
        ← Продолжить покупки
    </a>

    <div class="mt-2 mb-5 flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <div>
            <h1 class="text-3xl font-bold tracking-tight">
                Корзина
            </h1>
            @if($items->isNotEmpty())
                <p class="mt-1 text-ink-muted" data-refresh="subtitle">
                    {{ \App\Support\Plural::ru($items->count(), ['позиция', 'позиции', 'позиций']) }} · к заказу отмечено {{ \App\Support\Plural::ru($selectedCount, ['позиция', 'позиции', 'позиций']) }}, {{ $order->quantity() }} шт.
                </p>
            @endif
        </div>
    </div>

@if($items->isEmpty())

    {{-- Пустая корзина: «Добавить по артикулу» — над пустым состоянием --}}
    @if($client)
        <div class="mb-4 hidden overflow-hidden rounded-card border border-line xl:block">
            <x-cart-quick-add />
        </div>
        <x-cart-quick-add layout="card" class="mb-4 xl:hidden" />
    @endif

    <x-empty-state title="Корзина пуста" icon="shopping-cart" data-cart-empty>
        Добавьте товары из каталога или найдите их по артикулу.
        <x-slot:action>
            <x-button :href="route('catalog.index')">Перейти в каталог</x-button>
        </x-slot:action>
    </x-empty-state>

@else

    {{-- Позиции, снятые с заказа из-за нехватки остатка --}}
    <div data-refresh="banner">
        @if($shortages->isNotEmpty())
            <x-alert type="warning" class="mb-4" data-shortage-banner>
                @foreach($shortages as $item)
                    <p>
                        У позиции «{{ $item->title() }}» на складе {{ $validation->shortage($item)->available }} шт. из {{ $item->quantity }}.
                        Позиция снята с заказа — уменьшите количество или оставьте её в корзине на потом.
                    </p>
                @endforeach
            </x-alert>
        @endif
    </div>

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">

        {{-- Позиции --}}
        <section aria-label="Позиции корзины" class="min-w-0">

            {{-- Таблица (xl и шире) --}}
            <div class="hidden overflow-hidden rounded-card border border-line bg-surface xl:block">

                {{-- Добавить по артикулу — полосой над строкой заголовков --}}
                @if($client)
                    <x-cart-quick-add />
                @endif

                <div class="grid grid-cols-[1.5rem_minmax(0,1fr)_6rem_8rem_6rem_7rem_2.5rem] items-center gap-x-3 bg-field px-4 py-3 text-meta font-medium text-ink-muted">
                    <span data-refresh="select-all">
                        <input
                            type="checkbox"
                            class="h-5 w-5 rounded border-line-strong text-primary focus:ring-primary"
                            aria-label="Выбрать все"
                            data-cart-select-all
                            @checked($selectedCount > 0 && $selectedCount === $items->reject(fn ($i) => $validation->isUnavailable($i))->count())
                        >
                    </span>
                    <span>Товар</span>
                    <span class="text-right">Цена без НДС</span>
                    <span class="text-center">Количество</span>
                    <span>Наличие</span>
                    <span class="text-right">Сумма без НДС</span>
                    <span class="sr-only">Удалить</span>
                </div>

                {{-- data-refresh="rows": после «Добавить по артикулу» появляется новая строка --}}
                <div class="divide-y divide-line-soft border-t border-line-soft" data-refresh="rows">
                    @foreach($items as $item)
                        <x-cart-row :item="$item" :validation="$validation" />
                    @endforeach
                </div>

                <div class="flex flex-wrap justify-between gap-x-4 gap-y-2 border-t border-line-soft px-4 py-3.5 text-sm text-ink-muted">
                    <span>В заказ попадут только отмеченные позиции. Остальные останутся в корзине.</span>
                    <x-confirm action="/order/clear" label="Очистить корзину" title="Очистить корзину?" confirm-label="Очистить" trigger-variant="link" trigger-size="sm" class="text-danger" data-cart-clear>
                        Из корзины будут удалены все позиции.
                    </x-confirm>
                </div>
            </div>

            {{-- Карточки (уже xl, макет CartMobile) --}}
            <div class="flex flex-col gap-3 xl:hidden">
                @if($client)
                    <x-cart-quick-add layout="card" />
                @endif

                <label class="flex items-center gap-3 px-1 text-sm text-ink-muted">
                    <span data-refresh="select-all-m">
                        <input
                            type="checkbox"
                            class="h-5 w-5 rounded border-line-strong text-primary focus:ring-primary"
                            data-cart-select-all
                            @checked($selectedCount > 0 && $selectedCount === $items->reject(fn ($i) => $validation->isUnavailable($i))->count())
                        >
                    </span>
                    Выбрать все
                </label>

                <div class="flex flex-col gap-3" data-refresh="cards">
                    @foreach($items as $item)
                        <x-cart-row :item="$item" :validation="$validation" layout="card" />
                    @endforeach
                </div>

                <div class="flex flex-wrap justify-between gap-2 px-1 text-sm text-ink-muted">
                    <span>В заказ попадут только отмеченные позиции.</span>
                    <x-confirm action="/order/clear" label="Очистить корзину" title="Очистить корзину?" confirm-label="Очистить" trigger-variant="link" trigger-size="sm">
                        Из корзины будут удалены все позиции.
                    </x-confirm>
                </div>
            </div>

        </section>

        {{-- Ваш заказ --}}
        <aside aria-label="Итог заказа" class="flex flex-col gap-4 rounded-card border border-line bg-surface p-5 lg:sticky lg:top-28" data-cart-panel>

            <h2 class="text-lg font-bold">Ваш заказ</h2>

            <div data-refresh="summary">
                <dl class="grid grid-cols-[1fr_auto] gap-x-3 gap-y-2 tabular-nums">
                    <dt class="text-ink-muted">Позиций / штук</dt>
                    <dd class="text-right">{{ $order->count() }} / {{ $order->quantity() }}</dd>
                    <dt class="text-ink-muted">Сумма без НДС</dt>
                    <dd class="text-right"><x-price :amount="$totals->net" /></dd>
                    <dt class="text-ink-muted">{{ $vatLabel }}</dt>
                    <dd class="text-right"><x-price :amount="$totals->vat" /></dd>
                </dl>
                <div class="mt-3.5 flex items-baseline justify-between gap-3 border-t border-line-soft pt-3.5">
                    <span class="font-semibold">Итого с НДС</span>
                    <x-price :amount="$totals->gross" class="text-2xl font-bold tracking-tight" data-cart-grand-total />
                </div>
            </div>

            {{-- Состояние отмеченных позиций (SRS §7.17) --}}
            <div data-refresh="state">
                <div class="flex flex-col gap-2 text-sm" data-cart-state="{{ $selectedCount ? $state->value : 'nothing_selected' }}">
                    @if($selectedCount)
                        <span @class([
                            'font-medium',
                            'text-tone-success-text' => $state === \App\Enums\CartState::Ready,
                            'text-tone-warning-text' => $state === \App\Enums\CartState::NeedsUpdate,
                            'text-tone-danger-text' => $state === \App\Enums\CartState::NeedsCorrection,
                        ])>{{ $state->label() }}</span>

                        @if($state !== \App\Enums\CartState::Ready)
                            <span class="text-ink-muted">{{ $state->hint() }}</span>
                        @endif

                        @if($order->hasPriceChanges())
                            <form method="POST" action="/order/accept-prices">
                                @csrf
                                <x-button variant="secondary" size="sm">Принять новые цены</x-button>
                            </form>
                        @endif
                    @endif
                </div>
            </div>

            @if($checkoutProblems->isNotEmpty())
                <x-alert type="error" data-checkout-problems>
                    <ul class="list-disc pl-5">
                        @foreach($checkoutProblems as $problem)
                            <li>{{ $problem }}</li>
                        @endforeach
                    </ul>
                </x-alert>
            @endif

            @if(! $client)

                {{-- BR-121 --}}
                <p class="text-ink-muted">Оформлять заказы могут только клиенты.</p>

            @else

                <form method="POST" action="/order/checkout" id="checkout-form" class="flex flex-col gap-4" x-data x-on:submit="$el.querySelectorAll('[data-checkout-submit]').forEach(b => b.disabled = true); document.querySelectorAll('[form=checkout-form][data-checkout-submit]').forEach(b => b.disabled = true)">
                    @csrf
                    <input type="hidden" name="checkout_token" value="{{ $checkoutToken }}">

                    {{-- Адрес доставки: выбор обязателен (BR-128), только адреса своей организации (BR-129) --}}
                    <fieldset class="flex flex-col gap-2">
                        <legend class="mb-2 font-semibold">Адрес доставки <span class="text-danger">*</span></legend>

                        @if($addresses->isEmpty())
                            <p class="text-sm text-ink-muted">
                                У организации нет адресов доставки.
                                <a href="/addresses/create" class="text-link underline">Добавьте адрес</a>,
                                чтобы оформить заказ.
                            </p>
                        @else
                            @php($selectedAddress = old('delivery_address_id', $addresses->firstWhere('is_default', true)?->id ?? $addresses->first()->id))

                            @foreach($addresses as $address)
                                <label class="flex cursor-pointer items-start gap-2.5 rounded-panel border border-line-strong px-3 py-2.5 has-[:checked]:border-primary has-[:checked]:ring-1 has-[:checked]:ring-primary" data-address>
                                    <input
                                        type="radio"
                                        name="delivery_address_id"
                                        value="{{ $address->id }}"
                                        class="mt-1 border-line-strong text-primary focus:ring-primary"
                                        @checked((string) $selectedAddress === (string) $address->id)
                                        required
                                    >
                                    <span class="min-w-0">
                                        <span class="font-semibold">{{ $address->title }}</span>
                                        @if($address->is_default)
                                            <x-badge tone="success">основной</x-badge>
                                        @endif
                                        <span class="block text-sm text-ink-muted">{{ $address->address }}</span>
                                        @if($address->contact_name || $address->contact_phone)
                                            <span class="block text-meta text-ink-muted">{{ collect([$address->contact_name, $address->contact_phone])->filter()->implode(', ') }}</span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach

                            <a href="/addresses" class="text-sm text-link hover:underline">Управление адресами</a>
                        @endif

                        @error('delivery_address_id')
                            <p class="text-sm text-tone-danger-text" data-field-error>{{ $message }}</p>
                        @enderror
                    </fieldset>

                    {{-- Комментарий к заказу — необязательный (BR-130) --}}
                    <x-textarea name="comment" label="Комментарий к заказу" rows="3" maxlength="1000" placeholder="Например: позвонить за час до доставки" />

                    <div class="flex flex-col gap-2" data-refresh="submit">
                        <x-button size="lg" class="w-full" :disabled="$blocker !== null" data-checkout-submit>Оформить заказ</x-button>
                        @if($blocker)
                            <p class="text-sm text-tone-warning-text" data-checkout-hint>{{ $blocker }}</p>
                        @endif
                    </div>

                    <p class="text-meta text-ink-muted">Менеджер проверит заказ и передаст его в 1С. Статус будет виден в разделе «Мои заказы».</p>
                </form>

            @endif

        </aside>

    </div>

    {{-- Телефон: итог и «Оформить» внизу экрана (макет CartMobile) --}}
    @if($client)
        <div class="fixed inset-x-0 bottom-0 z-40 flex items-center gap-3 border-t border-line bg-surface px-4 pb-5 pt-3 shadow-[0_-4px_16px_rgba(17,24,39,0.06)] lg:hidden" data-refresh="mobilebar">
            <div class="min-w-0 flex-1 tabular-nums">
                <div class="text-meta text-ink-muted">Итого с НДС · {{ $order->quantity() }} шт.</div>
                <x-price :amount="$totals->gross" class="text-xl font-bold" />
            </div>
            <x-button size="lg" form="checkout-form" :disabled="$blocker !== null" data-checkout-submit>Оформить</x-button>
        </div>
    @endif

@endif

</div>

@endsection
