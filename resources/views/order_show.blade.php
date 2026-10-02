@extends('layouts.app')

@section('title', 'Заказ ' . $order->number)

@section('content')

<h1 class="text-3xl font-bold mb-6">
    Заказ {{ $order->number }}
</h1>

<div class="bg-white border rounded p-5 mb-6">

    <p class="mb-2">
        Статус:
        @include('partials.order_status', ['status' => $order->status])
    </p>

    <p class="mb-2">
        Оформил:
        {{ $order->user->name ?? '—' }}
    </p>

    <p class="mb-2">
        Оплата:
        @if($order->is_paid)
            <span class="text-green-600 font-medium">Оплачен</span>
        @else
            <span class="text-slate-500">Не оплачен</span>
        @endif
    </p>

    <p>
        Дата оформления:
        {{ $order->placed_at?->format('d.m.Y H:i') }}
    </p>

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

            </tr>

        </thead>

        <tbody class="divide-y divide-slate-200">

            @foreach($order->items as $item)

                <tr>

                    <td class="px-4 py-3">

                        @if($item->product?->image)

                            <img
                                src="{{ asset('storage/'.$item->product->image) }}"
                                alt="{{ $item->product_name }}"
                                class="w-12 h-12 object-contain rounded border border-slate-200">

                        @else

                            <div class="w-12 h-12 bg-slate-100 flex items-center justify-center rounded border border-slate-200 text-2xl">
                                🚰
                            </div>

                        @endif

                    </td>

                    <td class="px-4 py-3">

                        {{-- Название и артикул — из снимка заказа; ссылка только пока товар есть на витрине --}}
                        @if($item->product && $item->product->is_active && $item->product->is_published)

                            <a
                                href="{{ route('product.show', $item->product->id) }}"
                                class="font-medium text-slate-800 hover:text-blue-600 transition"
                            >
                                {{ $item->product_name }}
                            </a>

                        @else

                            <span class="font-medium text-slate-800">
                                {{ $item->product_name }}
                            </span>

                        @endif

                    </td>

                    <td class="px-4 py-3 text-sm text-slate-400">
                        {{ $item->product_sku }}
                    </td>

                    <td class="px-4 py-3 text-right text-slate-700">
                        {{ $item->price }} BYN
                    </td>

                    <td class="px-4 py-3 text-right text-slate-700">
                        {{ $item->quantity }}
                    </td>

                    <td class="px-4 py-3 text-right font-semibold text-slate-800">
                        {{ $item->price * $item->quantity }} BYN
                    </td>

                </tr>

            @endforeach

        </tbody>

    </table>

</div>

<div class="mt-8 flex items-center justify-between">

    <div class="text-2xl font-bold">

        Итого:
        {{ $order->total }} BYN

    </div>

    <div class="flex items-center gap-3">

    {{-- Отменить можно только свой заказ и только пока он «Новый» --}}
    @if($order->status === \App\Enums\OrderStatus::New && $order->user_id === auth()->id())

        <form
            method="POST"
            action="/order/{{ $order->id }}/cancel"
        >

            @csrf

            <button
                type="submit"
                class="rounded-xl border border-red-300 text-red-700 hover:bg-red-50 px-5 py-2.5 transition"
                onclick="return confirm('Отменить заказ {{ $order->number }}?')"
            >
                Отменить заказ
            </button>

        </form>

    @endif

    <form
        method="POST"
        action="/order/{{ $order->id }}/reorder"
    >

        @csrf

        <button
            type="submit"
            class="rounded-xl bg-slate-800 hover:bg-slate-700 text-white px-5 py-2.5 transition"
        >
            Повторить заказ
        </button>

    </form>

    </div>

</div>

<div class="mt-8">

    @include('partials.order_history', ['order' => $order, 'showAuthor' => false])

</div>

@endsection