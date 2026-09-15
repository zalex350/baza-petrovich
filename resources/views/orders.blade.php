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

    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">

        <table class="w-full text-left">

            <thead class="bg-slate-50 border-b border-slate-200">

                <tr>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                        № заказа
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                        Дата
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                        Статус
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600 text-right">
                        Сумма
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                    </th>

                </tr>

            </thead>

            <tbody class="divide-y divide-slate-200">

                @foreach($orders as $order)

                    <tr>

                        <td class="px-4 py-3 font-medium text-slate-800">
                            #{{ $order->id }}
                        </td>

                        <td class="px-4 py-3 text-slate-700">
                            {{ $order->created_at->format('d.m.Y') }}
                        </td>

                        <td class="px-4 py-3">

                            @if($order->status === 'new')

                                <span class="inline-flex rounded-full bg-blue-100 text-blue-700 px-3 py-1 text-sm">
                                    Новый
                                </span>

                            @elseif($order->status === 'paid')

                                <span class="inline-flex rounded-full bg-green-100 text-green-700 px-3 py-1 text-sm">
                                    Оплачен
                                </span>

                            @elseif($order->status === 'shipped')

                                <span class="inline-flex rounded-full bg-purple-100 text-purple-700 px-3 py-1 text-sm">
                                    Отправлен
                                </span>

                            @else

                                <span class="inline-flex rounded-full bg-slate-100 text-slate-700 px-3 py-1 text-sm">
                                    {{ $order->status }}
                                </span>

                            @endif

                        </td>

                        <td class="px-4 py-3 text-right font-semibold text-slate-800">
                            {{ $order->total }} BYN
                        </td>

                        <td class="px-4 py-3">

                            <div class="flex items-center gap-3">

                                <a
                                    href="/order/{{ $order->id }}"
                                    class="text-slate-700 hover:text-blue-600 text-sm underline"
                                >
                                    Открыть
                                </a>

                                <form
                                    method="POST"
                                    action="/order/{{ $order->id }}/reorder"
                                    class="inline"
                                >

                                    @csrf

                                    <button
                                        type="submit"
                                        class="text-blue-600 hover:text-blue-700 text-sm underline"
                                    >
                                        Повторить
                                    </button>

                                </form>

                                <form
                                    method="POST"
                                    action="/order/{{ $order->id }}/delete"
                                    class="inline"
                                >

                                    @csrf

                                    <button
                                        type="submit"
                                        class="text-red-600 hover:text-red-700 text-sm underline"
                                        onclick="return confirm('Удалить заказ?')"
                                    >
                                        Удалить
                                    </button>

                                </form>

                            </div>

                        </td>

                    </tr>

                @endforeach

            </tbody>

        </table>

    </div>

@endif

@endsection