@extends('layouts.app')

@section('title', 'Мой заказ')

@section('content')

<h1 class="text-3xl font-bold mb-6">
    Мой заказ
</h1>

@if(!$order || !$order->items->count())

    <div class="bg-white border rounded p-6">

        <p>
            Заказ пуст
        </p>

    </div>

@else

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

                @foreach($order->items as $item)

                    <tr>

                        <td class="px-4 py-3">

                            @if($item->product->image)

                                <img
                                    src="{{ asset('storage/'.$item->product->image) }}"
                                    alt="{{ $item->product->display_name ?: $item->product->name }}"
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
                                {{ $item->product->display_name ?: $item->product->name }}
                            </a>

                        </td>

                        <td class="px-4 py-3 text-sm text-slate-400">
                            {{ $item->product->sku }}
                        </td>

                        <td class="px-4 py-3 text-right text-slate-700">
                            {{ $item->price }} BYN
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
                            {{ $item->price * $item->quantity }} BYN
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
            Итого: {{ $order->items->sum(fn($item) => $item->price * $item->quantity) }} BYN
        </h2>

        <form
            method="POST"
            action="/order/checkout"
        >

            @csrf

            <button
                class="bg-green-600 text-white px-6 py-3 rounded"
            >
                Оформить заказ
            </button>

        </form>

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