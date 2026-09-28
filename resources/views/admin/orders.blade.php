@extends('layouts.admin')

@section('title', 'Админка — Заказы')

@section('content')

<h1 class="text-3xl font-bold mb-6">
    Админка — Заказы
</h1>

@if(!$orders->count())

    <div class="bg-white border rounded p-6">

        <p>
            Заказов нет
        </p>

    </div>

@else

    <div class="bg-white border rounded overflow-hidden">

        <table class="w-full border-collapse">

            <thead class="bg-gray-100">

                <tr>

                    <th class="border p-3 text-left">
                        ID
                    </th>

                    <th class="border p-3 text-left">
                        Пользователь
                    </th>

                    <th class="border p-3 text-left">
                        Статус
                    </th>

                    <th class="border p-3 text-left">
                        Сумма
                    </th>

                    <th class="border p-3 text-left">
                        Дата
                    </th>

                    <th class="border p-3 text-left">
                        Действия
                    </th>

                </tr>

            </thead>

            <tbody>

                @foreach($orders as $order)

                    <tr class="hover:bg-gray-50">

                        <td class="border p-3">
                            #{{ $order->id }}
                        </td>

                        <td class="border p-3">
                            {{ $order->user->name ?? 'Unknown' }}
                        </td>

                        <td class="border p-3">
                            {{ $order->status }}
                            @if($order->is_paid)
                                <span class="inline-flex rounded-full bg-green-100 text-green-700 px-2 py-0.5 text-xs ml-2">
                                    Оплачен
                                </span>
                            @endif
                        </td>

                        <td class="border p-3">
                            {{ $order->total }} BYN
                        </td>

                        <td class="border p-3">
                            {{ $order->created_at }}
                        </td>

                        <td class="border p-3">

                            <a
                                href="/admin/order/{{ $order->id }}"
                                class="underline"
                            >
                                Открыть
                            </a>

                        </td>

                    </tr>

                @endforeach

            </tbody>

        </table>

    </div>

@endif

<div class="mt-8">
    {{ $orders->links() }}
</div>

@endsection