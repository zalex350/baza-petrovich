@extends('layouts.app')

@section('title', 'Заказ #' . $order->id)

@section('content')

<h1 class="text-3xl font-bold mb-6">
    Заказ #{{ $order->id }}
</h1>

<div class="bg-white border rounded p-5 mb-6">

    <p class="mb-2">
        Статус:
        <strong>{{ $order->status }}</strong>
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
        Дата:
        {{ $order->created_at }}
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

                        @if($item->product->image)

                            <img
                                src="{{ asset('storage/'.$item->product->image) }}"
                                alt="{{ $item->product->name }}"
                                class="w-12 h-12 object-contain rounded border border-slate-200">

                        @else

                            <div class="w-12 h-12 bg-slate-100 flex items-center justify-center rounded border border-slate-200 text-2xl">
                                🚰
                            </div>

                        @endif

                    </td>

                    <td class="px-4 py-3">

                        <a
                            href="{{ route('product.show', $item->product->id) }}"
                            class="font-medium text-slate-800 hover:text-blue-600 transition"
                        >
                            {{ $item->product->name }}
                        </a>

                    </td>

                    <td class="px-4 py-3 text-sm text-slate-400">
                        {{ $item->product->sku }}
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

@endsection