@extends('layouts.account')

@section('title', 'Мои заказы')

{{--
    Заказы организации (BR-113, BR-114): видны заказы всех пользователей организации,
    «Оформил» — кто именно. Активные сверху, завершённые и отменённые — в архиве.
    Данные: OrderController::index — $activeOrders, $archivedOrders.
--}}

@section('account')

<h1 class="text-3xl font-bold tracking-tight mb-1">Мои заказы</h1>

@if(auth()->user()->organization)
    <p class="mb-5 text-ink-muted">Заказы всех сотрудников {{ auth()->user()->organization->name }}.</p>
@endif

@if(! $activeOrders->count() && ! $archivedOrders->count())

    <x-empty-state title="Заказов пока нет" icon="clipboard-document-list" data-orders-empty>
        Оформленные заказы организации появятся здесь.
        <x-slot:action>
            <x-button :href="route('catalog.index')">Перейти в каталог</x-button>
        </x-slot:action>
    </x-empty-state>

@else

    <section class="mb-8" data-orders-active>
        <h2 class="mb-3 text-xl font-bold">Активные</h2>

        @if(! $activeOrders->count())
            <x-empty-state title="Нет активных заказов" icon="inbox" />
        @else
            @include('partials.orders_table', ['orders' => $activeOrders])
        @endif
    </section>

    @if($archivedOrders->count())
        <section data-orders-archive>
            <h2 class="mb-3 text-xl font-bold">Архив</h2>
            @include('partials.orders_table', ['orders' => $archivedOrders])
        </section>
    @endif

@endif

@endsection
