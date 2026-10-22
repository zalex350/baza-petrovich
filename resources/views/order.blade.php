@extends('layouts.app')

@section('title', 'Мой заказ')

@section('content')

@php
    $state = $validation->state();

    $stateClasses = match ($state) {
        \App\Enums\CartState::Ready           => 'bg-green-100 text-green-700',
        \App\Enums\CartState::NeedsUpdate     => 'bg-yellow-100 text-yellow-700',
        \App\Enums\CartState::NeedsCorrection => 'bg-red-100 text-red-700',
        default                               => 'bg-slate-100 text-slate-700',
    };
@endphp

<h1 class="text-3xl font-bold mb-6">
    Мой заказ
</h1>

@if($state === \App\Enums\CartState::Empty)

    <div class="bg-white border rounded p-6">

        <p>
            Заказ пуст
        </p>

    </div>

@else

    {{-- Состояние корзины (SRS §7.17) --}}
    <div class="bg-white rounded-2xl border border-slate-200 p-5 mb-6" data-cart-state="{{ $state->value }}">

        <div class="flex flex-wrap items-center gap-3">

            <span class="inline-flex rounded-full px-3 py-1 text-sm font-medium {{ $stateClasses }}">
                {{ $state->label() }}
            </span>

            <span class="text-slate-600">
                {{ $state->hint() }}
            </span>

        </div>

        @if($validation->hasPriceChanges())

            <form
                method="POST"
                action="/order/accept-prices"
                class="mt-4"
            >

                @csrf

                <button
                    type="submit"
                    class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded-xl text-sm transition"
                >
                    Принять новые цены
                </button>

            </form>

        @endif

    </div>

    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">

        <table class="w-full text-left">

            <thead class="bg-slate-50 border-b border-slate-200">

                <tr>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                        Фото
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                        Товар
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                        Артикул
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600 text-right">
                        Цена без НДС
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600 text-right">
                        Количество
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600 text-right">
                        Сумма без НДС
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600 text-right">
                        НДС
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600 text-right">
                        Сумма с НДС
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                    </th>

                </tr>

            </thead>

            <tbody class="divide-y divide-slate-200">

                @foreach($cart->items as $item)

                    @php
                        $product = $item->product;
                        $problems = $validation->for($item);
                        $priceChange = $validation->priceChange($item);
                        $line = $item->vatLine();
                    @endphp

                    @if($validation->isUnavailable($item))

                        {{-- Товар нельзя заказать (удалён, скрыт, снят с публикации): позицию можно только убрать --}}
                        <tr class="bg-slate-50">

                            <td class="px-4 py-3">

                                @include('partials.product_image', [
                                    'image' => $product?->mainImage,
                                    'alt'   => $item->title(),
                                    'class' => 'w-12 h-12 object-contain rounded border border-slate-200 bg-white opacity-50',
                                ])

                            </td>

                            <td class="px-4 py-3">

                                <span class="font-medium text-slate-400">
                                    {{ $item->title() }}
                                </span>

                                <div class="mt-1">
                                    <span class="inline-flex rounded-full bg-red-100 text-red-700 px-3 py-1 text-sm">
                                        {{ $problems[0]->title() }}
                                    </span>
                                </div>

                            </td>

                            <td class="px-4 py-3 text-sm text-slate-400">
                                {{ $product?->sku }}
                            </td>

                            <td class="px-4 py-3 text-right text-slate-400">
                                —
                            </td>

                            <td class="px-4 py-3 text-right text-slate-400">
                                {{ $item->quantity }}
                            </td>

                            <td class="px-4 py-3 text-right text-slate-400">
                                —
                            </td>

                            <td class="px-4 py-3 text-right text-slate-400">
                                —
                            </td>

                            <td class="px-4 py-3 text-right text-slate-400">
                                —
                            </td>

                            <td class="px-4 py-3">

                                <x-confirm
                                    action="/order/delete/{{ $item->id }}"
                                    label="Удалить"
                                    title="Удалить товар из корзины?"
                                    confirm-label="Удалить"
                                    trigger-variant="link"
                                    trigger-size="sm"
                                >
                                    «{{ $item->title() }}» будет удалён из корзины.
                                </x-confirm>

                            </td>

                        </tr>

                        @continue

                    @endif

                    <tr>

                        <td class="px-4 py-3">

                            @include('partials.product_image', [
                                'image' => $product->mainImage,
                                'alt'   => $item->title(),
                                'class' => 'w-12 h-12 object-contain rounded border border-slate-200 bg-white',
                            ])

                        </td>

                        <td class="px-4 py-3">

                            <a
                                href="{{ route('product.show', $product->id) }}"
                                class="font-medium text-slate-800 hover:text-blue-600 transition"
                            >
                                {{ $item->title() }}
                            </a>

                            {{-- Причины, по которым позицию нельзя оформить как есть --}}
                            @foreach($problems as $problem)

                                <div class="mt-1">

                                    <span class="inline-flex rounded-full px-3 py-1 text-sm {{ $problem->type->needsCorrection() ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700' }}">
                                        {{ $problem->title() }}
                                    </span>

                                    @if($problem->detail)

                                        <div class="mt-1 text-sm text-slate-500">
                                            {{ \Illuminate\Support\Str::ucfirst($problem->detail) }}
                                        </div>

                                    @endif

                                </div>

                            @endforeach

                        </td>

                        <td class="px-4 py-3 text-sm text-slate-400">
                            {{ $product->sku }}
                        </td>

                        <td class="px-4 py-3 text-right text-slate-700">

                            @if($priceChange)

                                <div class="text-slate-400 line-through">
                                    {{ number_format($priceChange->oldPrice, 2, '.', '') }} BYN
                                </div>

                                <div class="font-semibold text-yellow-700">
                                    {{ number_format($priceChange->newPrice, 2, '.', '') }} BYN
                                </div>

                            @else

                                {{ number_format($item->price_at_add, 2, '.', '') }} BYN

                            @endif

                        </td>

                        <td class="px-4 py-3 text-right">

                            <form
                                method="POST"
                                action="/order/update/{{ $item->id }}"
                                class="flex items-center gap-2 justify-end"
                            >

                                @csrf

                                <input
                                    type="number"
                                    name="quantity"
                                    value="{{ $item->quantity }}"
                                    min="1"
                                    class="border border-slate-300 rounded px-2 py-1 w-16 text-right"
                                >

                                <button
                                    type="submit"
                                    class="bg-slate-800 hover:bg-slate-700 text-white px-3 py-1 rounded text-sm transition"
                                >
                                    Обновить
                                </button>

                            </form>

                        </td>

                        <td class="px-4 py-3 text-right text-slate-700 whitespace-nowrap">
                            {{ number_format($line->net, 2, '.', '') }} BYN
                        </td>

                        <td class="px-4 py-3 text-right text-slate-700 whitespace-nowrap">
                            <span class="block text-xs text-slate-500">{{ $line->rateLabel() }}</span>
                            {{ number_format($line->vat, 2, '.', '') }} BYN
                        </td>

                        <td class="px-4 py-3 text-right font-semibold text-slate-800 whitespace-nowrap">
                            {{ number_format($line->gross, 2, '.', '') }} BYN
                        </td>

                        <td class="px-4 py-3">

                            <x-confirm
                                action="/order/delete/{{ $item->id }}"
                                label="Удалить"
                                title="Удалить товар из корзины?"
                                confirm-label="Удалить"
                                trigger-variant="link"
                                trigger-size="sm"
                            >
                                «{{ $item->title() }}» будет удалён из корзины.
                            </x-confirm>

                        </td>

                    </tr>

                @endforeach

            </tbody>

        </table>

    </div>

    <div class="mt-8 bg-white border rounded p-5">

        @include('partials.vat_totals', ['totals' => $validation->totals()])

        @if(! auth()->user()->isClient())

            {{-- BR-121 --}}
            <p class="text-slate-600">
                Оформлять заказы могут только клиенты.
            </p>

        @else

            <form
                method="POST"
                action="/order/checkout"
                onsubmit="this.querySelector('button').disabled = true"
            >

                @csrf

                <input type="hidden" name="checkout_token" value="{{ $checkoutToken }}">

                {{-- Адрес доставки: выбор обязателен (BR-128), только адреса своей организации (BR-129) --}}
                <fieldset class="mb-6">

                    <legend class="font-semibold mb-2">
                        Адрес доставки <span class="text-red-600">*</span>
                    </legend>

                    @if($addresses->isEmpty())

                        <p class="text-slate-600">
                            У организации нет адресов доставки.
                            <a href="/addresses/create" class="text-blue-600 hover:text-blue-700 underline">Добавьте адрес</a>,
                            чтобы оформить заказ.
                        </p>

                    @else

                        @php
                            $selectedAddress = old('delivery_address_id', $addresses->firstWhere('is_default', true)?->id);
                        @endphp

                        <div class="space-y-2">

                            @foreach($addresses as $address)

                                <label class="flex items-start gap-3 border border-slate-200 rounded-xl p-3 cursor-pointer hover:bg-slate-50">

                                    <input
                                        type="radio"
                                        name="delivery_address_id"
                                        value="{{ $address->id }}"
                                        class="mt-1"
                                        @checked((string) $selectedAddress === (string) $address->id)
                                        required
                                    >

                                    <span>

                                        <span class="font-medium text-slate-800">
                                            {{ $address->title }}
                                        </span>

                                        @if($address->is_default)

                                            <span class="inline-flex rounded-full bg-green-100 text-green-700 px-2 py-0.5 text-xs ml-1">
                                                По умолчанию
                                            </span>

                                        @endif

                                        <span class="block text-slate-600">
                                            {{ $address->address }}
                                        </span>

                                        @if($address->contact_name || $address->contact_phone)

                                            <span class="block text-sm text-slate-500">
                                                {{ collect([$address->contact_name, $address->contact_phone])->filter()->implode(', ') }}
                                            </span>

                                        @endif

                                    </span>

                                </label>

                            @endforeach

                        </div>

                        <a href="/addresses" class="inline-block mt-2 text-sm text-blue-600 hover:text-blue-700 underline">
                            Управление адресами
                        </a>

                    @endif

                </fieldset>

                {{-- Комментарий к заказу — необязательный (BR-130) --}}
                <div class="mb-6">

                    <label for="comment" class="block font-semibold mb-2">
                        Комментарий к заказу
                    </label>

                    <textarea
                        id="comment"
                        name="comment"
                        rows="3"
                        maxlength="1000"
                        placeholder="Необязательно"
                        class="w-full max-w-2xl border border-slate-300 rounded px-3 py-2"
                    >{{ old('comment') }}</textarea>

                </div>

                <button
                    @disabled(! $validation->isReady() || $addresses->isEmpty())
                    class="bg-green-600 text-white px-6 py-3 rounded disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    Оформить заказ
                </button>

            </form>

        @endif

    </div>

    <div class="mt-6">

        <a
            href="/catalog/smesiteli"
            class="underline"
        >
            ← Продолжить покупки
        </a>

    </div>

@endif

@endsection
