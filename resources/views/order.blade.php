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

    <div class="space-y-4">

        @foreach($order->items as $item)

            <div class="bg-white border rounded p-5">

                <h3 class="text-xl font-semibold mb-3">
                    {{ $item->product->name }}
                </h3>

                <div class="space-y-3">

                    <p>
                        Цена:
                        {{ $item->price }} BYN
                    </p>

                    <form
                        method="POST"
                        action="/order/update/{{ $item->id }}"
                        class="flex items-center gap-3"
                    >

                        @csrf

                        <input
                            type="number"
                            name="quantity"
                            value="{{ $item->quantity }}"
                            min="1"
                            class="border rounded px-2 py-1 w-24"
                        >

                        <button
                            type="submit"
                            class="bg-black text-white px-4 py-2 rounded"
                        >
                            Обновить
                        </button>

                    </form>

                    <p>
                        Сумма:
                        <strong>
                            {{ $item->price * $item->quantity }} BYN
                        </strong>
                    </p>

                    <form
                        method="POST"
                        action="/order/delete/{{ $item->id }}"
                    >

                        @csrf

                        <button
                            type="submit"
                            class="text-red-600 underline"
                        >
                            Удалить
                        </button>

                    </form>

                </div>

            </div>

        @endforeach

    </div>

    <div class="mt-8 bg-white border rounded p-5">

        <h2 class="text-2xl font-bold mb-4">
            Итого: {{ $order->total }} BYN
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