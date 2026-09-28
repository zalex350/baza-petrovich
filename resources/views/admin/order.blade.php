@extends('layouts.admin')

@section('title', 'Заказ #' . $order->id)

@section('content')

<h1 class="text-3xl font-bold mb-6">
    Заказ #{{ $order->id }}
</h1>

<div class="bg-white border rounded p-6 mb-6">

    <div class="space-y-2">

        <p>
            Пользователь:
            <strong>
                {{ $order->user->name ?? 'Unknown' }}
            </strong>
        </p>

        <p>
            Email:
            <strong>
                {{ $order->user->email ?? '-' }}
            </strong>
        </p>

        <p>
            Дата:
            {{ $order->created_at }}
        </p>

    </div>

</div>

<div class="bg-white border rounded p-6 mb-6">

    <h2 class="text-2xl font-semibold mb-4">
        Статус заказа
    </h2>

    <form
        method="POST"
        action="/admin/order/{{ $order->id }}"
        class="flex items-center gap-4"
    >

        @csrf

        <select
            name="status"
            class="border rounded px-3 py-2"
        >

            <option
                value="new"
                @selected($order->status === 'new')
            >
                New
            </option>

            <option
                value="processing"
                @selected($order->status === 'processing')
            >
                Processing
            </option>

            <option
                value="shipped"
                @selected($order->status === 'shipped')
            >
                Shipped
            </option>

        </select>

        <label class="flex items-center gap-2">
            <input
                type="checkbox"
                name="is_paid"
                value="1"
                @checked($order->is_paid)
            >
            Оплачен
        </label>

        <button
            type="submit"
            class="bg-black text-white px-5 py-2 rounded"
        >
            Обновить
        </button>

    </form>

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