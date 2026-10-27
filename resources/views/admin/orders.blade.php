@extends('layouts.admin')

@section('title', 'Админка — Заказы')

{{-- Поля формы на x-input/x-select показывают ошибки сами — во flash только сводка --}}
@section('flash-validation', 'summary')

@section('content')

{{-- Пример экрана на UI-ките (resources/views/components): x-input, x-select, x-button, x-table, x-badge, x-price, x-empty-state --}}

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
    class="bg-white rounded-card border border-slate-200 p-4 mb-6 grid gap-4 sm:grid-cols-2 lg:flex lg:flex-wrap lg:items-end"
>

    <x-input
        name="q"
        id="q"
        type="search"
        label="Поиск"
        :value="$filters['q'] ?? ''"
        placeholder="Номер заказа, клиент, УНП, пользователь, email"
        class="sm:col-span-2 lg:grow lg:min-w-64"
    />

    <x-select
        name="status"
        id="status"
        label="Статус"
        placeholder="Все"
        :options="collect($statuses)->mapWithKeys(fn ($status) => [$status->value => $status->label()])"
        :value="$filters['status'] ?? ''"
    />

    <div class="grid grid-cols-2 gap-4 lg:contents">
        <x-input name="date_from" id="date_from" type="date" label="Оформлен с" :value="$filters['date_from'] ?? ''" />
        <x-input name="date_to" id="date_to" type="date" label="по" :value="$filters['date_to'] ?? ''" />
    </div>

    <label class="flex items-center gap-2 lg:py-2">
        <input
            type="checkbox"
            name="cancel_request"
            value="1"
            class="rounded border-slate-300 text-primary focus:ring-primary"
            @checked($filters['cancel_request'] ?? false)
        >
        Запрос на отмену
    </label>

    <div class="flex items-center gap-4">
        <x-button>Найти</x-button>

        @if($filtered)
            <x-button variant="link" href="/admin/orders">Сбросить</x-button>
        @endif
    </div>

</form>

@if(!$orders->count())

    <x-empty-state :title="$filtered ? 'Заказы не найдены' : 'Заказов нет'" :icon="$filtered ? 'magnifying-glass' : 'inbox'">
        @if($filtered)
            Измените условия поиска или сбросьте фильтры.
            <x-slot:action>
                <x-button variant="secondary" href="/admin/orders">Сбросить фильтры</x-button>
            </x-slot:action>
        @else
            Заказы появятся здесь, когда клиенты их оформят.
        @endif
    </x-empty-state>

@else

    {{-- 7 колонок помещаются в контент рядом с сайдбаром от 1280 px; уже — карточки --}}
    <x-table stack="xl" caption="Заказы">

        <x-slot:head>
            <x-table.th>№ заказа</x-table.th>
            <x-table.th>Дата оформления</x-table.th>
            <x-table.th>Клиент</x-table.th>
            <x-table.th>Статус</x-table.th>
            <x-table.th align="right">Без НДС</x-table.th>
            <x-table.th align="right">НДС</x-table.th>
            <x-table.th align="right">С НДС</x-table.th>
        </x-slot:head>

        @foreach($orders as $order)

            <tr class="hover:bg-slate-50">

                <x-table.td label="№ заказа" nowrap>
                    <a href="/admin/order/{{ $order->id }}" class="font-medium text-slate-800 underline underline-offset-2 hover:text-slate-600">
                        {{ $order->number }}
                    </a>
                </x-table.td>

                <x-table.td label="Дата">
                    {{ date_ru($order->placed_at) }}
                </x-table.td>

                <x-table.td label="Клиент">
                    <div>{{ $order->organization->name ?? '—' }}</div>
                    @if($order->user)
                        <div class="text-sm text-slate-500">{{ $order->user->name }}</div>
                    @endif
                </x-table.td>

                <x-table.td label="Статус">
                    <div class="flex flex-wrap items-center justify-end gap-1.5 xl:justify-start">
                        <x-badge :status="$order->status" />

                        @if($order->hasOpenCancelRequest())
                            <x-badge tone="attention" data-cancel-badge>Запрос на отмену</x-badge>
                        @endif

                        @if($order->is_paid)
                            <x-badge tone="success">Оплачен</x-badge>
                        @endif
                    </div>
                </x-table.td>

                <x-table.td label="Без НДС" align="right">
                    <x-price :amount="$order->total_without_vat" />
                </x-table.td>

                <x-table.td label="НДС" align="right">
                    <x-price :amount="$order->total_vat" />
                </x-table.td>

                <x-table.td label="С НДС" align="right" class="font-semibold text-slate-800">
                    <x-price :amount="$order->total_with_vat" />
                </x-table.td>

            </tr>

        @endforeach

    </x-table>

@endif

<div class="mt-8">
    <x-pagination :paginator="$orders" />
</div>

@endsection
