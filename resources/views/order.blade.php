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
                        Цена
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600 text-right">
                        Количество
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600 text-right">
                        Сумма
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
                    @endphp

                    @if($validation->isUnavailable($item))

                        {{-- Товар нельзя заказать (удалён, скрыт, снят с публикации): позицию можно только убрать --}}
                        <tr class="bg-slate-50">

                            <td class="px-4 py-3">

                                <div class="w-12 h-12 bg-slate-100 flex items-center justify-center rounded border border-slate-200 text-2xl opacity-50">
                                    🚰
                                </div>

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

                            <td class="px-4 py-3">

                                <form
                                    method="POST"
                                    action="/order/delete/{{ $item->id }}"
                                >

                                    @csrf

                                    <button
                                        type="submit"
                                        class="text-red-600 hover:text-red-700 underline text-sm"
                                    >
                                        Удалить
                                    </button>

                                </form>

                            </td>

                        </tr>

                        @continue

                    @endif

                    <tr>

                        <td class="px-4 py-3">

                            @if($product->image)

                                <img
                                    src="{{ asset('storage/'.$product->image) }}"
                                    alt="{{ $item->title() }}"
                                    class="w-12 h-12 object-contain rounded border border-slate-200">

                            @else

                                <div class="w-12 h-12 bg-slate-100 flex items-center justify-center rounded border border-slate-200 text-2xl">
                                    🚰
                                </div>

                            @endif

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

                                {{ $item->price_at_add }} BYN

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

                        <td class="px-4 py-3 text-right font-semibold text-slate-800">
                            {{ number_format($item->subtotal, 2, '.', '') }} BYN
                        </td>

                        <td class="px-4 py-3">

                            <form
                                method="POST"
                                action="/order/delete/{{ $item->id }}"
                            >

                                @csrf

                                <button
                                    type="submit"
                                    class="text-red-600 hover:text-red-700 underline text-sm"
                                >
                                    Удалить
                                </button>

                            </form>

                        </td>

                    </tr>

                @endforeach

            </tbody>

        </table>

    </div>

    <div class="mt-8 bg-white border rounded p-5">

        <h2 class="text-2xl font-bold mb-4">
            Итого: {{ number_format($validation->total(), 2, '.', '') }} BYN
        </h2>

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

                <button
                    @disabled(! $validation->isReady())
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
