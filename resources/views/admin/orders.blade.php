@extends('layouts.admin')

@section('title', 'Админка — Заказы')

@section('content')

@php
    $filtered = collect($filters)->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty();
@endphp

<h1 class="text-3xl font-bold mb-6">
    Админка — Заказы
</h1>

{{-- Поиск и фильтры (SRS §10.7.4) --}}
<form
    method="GET"
    action="/admin/orders"
    class="bg-white rounded-2xl border border-slate-200 p-4 mb-6 flex flex-wrap items-end gap-4"
>

    <div class="grow min-w-64">

        <label for="q" class="block text-sm font-medium text-slate-600 mb-1">
            Поиск
        </label>

        <input
            id="q"
            type="search"
            name="q"
            value="{{ $filters['q'] ?? '' }}"
            placeholder="Номер заказа, клиент, УНП, пользователь, email"
            class="border border-slate-300 rounded px-3 py-2 w-full"
        >

    </div>

    <div>

        <label for="status" class="block text-sm font-medium text-slate-600 mb-1">
            Статус
        </label>

        <select
            id="status"
            name="status"
            class="border border-slate-300 rounded px-3 py-2"
        >

            <option value="">Все</option>

            @foreach($statuses as $status)

                <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>
                    {{ $status->label() }}
                </option>

            @endforeach

        </select>

    </div>

    <div>

        <label for="date_from" class="block text-sm font-medium text-slate-600 mb-1">
            Оформлен с
        </label>

        <input
            id="date_from"
            type="date"
            name="date_from"
            value="{{ $filters['date_from'] ?? '' }}"
            class="border border-slate-300 rounded px-3 py-2"
        >

    </div>

    <div>

        <label for="date_to" class="block text-sm font-medium text-slate-600 mb-1">
            по
        </label>

        <input
            id="date_to"
            type="date"
            name="date_to"
            value="{{ $filters['date_to'] ?? '' }}"
            class="border border-slate-300 rounded px-3 py-2"
        >

    </div>

    <button
        type="submit"
        class="bg-slate-800 hover:bg-slate-700 text-white px-5 py-2 rounded-xl transition"
    >
        Найти
    </button>

    @if($filtered)

        <a
            href="/admin/orders"
            class="text-slate-600 hover:text-slate-800 underline py-2"
        >
            Сбросить
        </a>

    @endif

</form>

@if(!$orders->count())

    <div class="bg-white rounded-2xl border border-slate-200 p-6">

        <p>
            {{ $filtered ? 'Заказы не найдены' : 'Заказов нет' }}
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
                        Дата оформления
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                        Клиент
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                        Пользователь
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                        Статус
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600 text-right">
                        Без НДС
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600 text-right">
                        НДС
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600 text-right">
                        С НДС
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                    </th>

                </tr>

            </thead>

            <tbody class="divide-y divide-slate-200">

                @foreach($orders as $order)

                    <tr class="hover:bg-slate-50">

                        <td class="px-4 py-3 font-medium text-slate-800">
                            {{ $order->number }}
                        </td>

                        <td class="px-4 py-3 text-slate-700">
                            {{ $order->placed_at?->format('d.m.Y H:i') }}
                        </td>

                        <td class="px-4 py-3 text-slate-700">
                            {{ $order->organization->name ?? '—' }}
                        </td>

                        <td class="px-4 py-3 text-slate-700">
                            {{ $order->user->name ?? '—' }}
                        </td>

                        <td class="px-4 py-3">

                            <div class="flex items-center gap-2">

                                @include('partials.order_status', ['status' => $order->status])

                                @if($order->is_paid)

                                    <span class="inline-flex rounded-full bg-green-100 text-green-700 px-2 py-0.5 text-xs">
                                        Оплачен
                                    </span>

                                @endif

                            </div>

                        </td>

                        <td class="px-4 py-3 text-right text-slate-700 whitespace-nowrap">
                            {{ number_format($order->total_without_vat, 2, '.', '') }} BYN
                        </td>

                        <td class="px-4 py-3 text-right text-slate-700 whitespace-nowrap">
                            {{ number_format($order->total_vat, 2, '.', '') }} BYN
                        </td>

                        <td class="px-4 py-3 text-right font-semibold text-slate-800 whitespace-nowrap">
                            {{ number_format($order->total_with_vat, 2, '.', '') }} BYN
                        </td>

                        <td class="px-4 py-3">

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
