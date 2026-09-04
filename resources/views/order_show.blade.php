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

    <p>
        Дата:
        {{ $order->created_at }}
    </p>

</div>

<div class="space-y-4">

    @foreach($order->items as $item)

        <div class="bg-white border rounded p-5">

            <h3 class="text-xl font-semibold mb-3">
                {{ $item->product->name }}
            </h3>

            <div class="space-y-2">

                <p>
                    Цена:
                    {{ $item->price }} BYN
                </p>

                <p>
                    Количество:
                    {{ $item->quantity }}
                </p>

                <p>
                    Сумма:
                    <strong>
                        {{ $item->price * $item->quantity }} BYN
                    </strong>
                </p>

            </div>

        </div>

    @endforeach

</div>

<div class="mt-8 text-2xl font-bold">

    Итого:
    {{ $order->total }} BYN

</div>

@endsection