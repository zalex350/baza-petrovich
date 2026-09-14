@extends('layouts.app')

@section('title', 'Мои заказы')

@section('content')

<h1 class="text-3xl font-bold mb-6">
    Мои заказы
</h1>

@if(!$orders->count())

    <div class="bg-white border rounded p-6">

        <p>
            Заказов пока нет
        </p>

    </div>

@else

    <div class="space-y-4">

        @foreach($orders as $order)

            <div class="bg-white border rounded p-5">

                <h3 class="text-xl font-semibold mb-3">
                    Заказ #{{ $order->id }}
                </h3>

                <div class="space-y-2">

                    <p>
                        Статус:
                        <strong>{{ $order->status }}</strong>
                    </p>

                    <p>
                        Сумма:
                        <strong>{{ $order->total }} BYN</strong>
                    </p>

                    <p>
                        Дата:
                        {{ $order->created_at }}
                    </p>

                </div>

                <div class="mt-4 flex flex-wrap gap-4">

                    <a
                        href="/order/{{ $order->id }}"
                        class="underline"
                    >
                        Открыть заказ
                    </a>

                    <form
                        method="POST"
                        action="/order/{{ $order->id }}/reorder"
                    >

                        @csrf

                        <button
                            type="submit"
                            class="text-blue-600 underline"
                        >
                            Повторить заказ
                        </button>

                    </form>

                    <form
                        method="POST"
                        action="/order/{{ $order->id }}/delete"
                    >

                        @csrf

                        <button
                            type="submit"
                            class="text-red-600 underline"
                            onclick="return confirm('Удалить заказ?')"
                        >
                            Удалить заказ
                        </button>

                    </form>

                </div>

            </div>

        @endforeach

    </div>

@endif

@endsection