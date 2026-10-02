@extends('layouts.app')

@section('title', 'Мои заказы')

@section('content')

<h1 class="text-3xl font-bold mb-6">
    Мои заказы
</h1>

@if(!$activeOrders->count() && !$archivedOrders->count())

    <div class="bg-white border rounded p-6">

        <p>
            Заказов пока нет
        </p>

    </div>

@else

    {{-- Активные заказы --}}

    <div class="mb-10">

        <h2 class="text-2xl font-bold mb-4">
            Активные заказы
        </h2>

        @if(!$activeOrders->count())

            <div class="bg-white border rounded p-6">

                <p>
                    Нет активных заказов
                </p>

            </div>

        @else

            @include('partials.orders_table', ['orders' => $activeOrders])

        @endif

    </div>

    {{-- Архив --}}

    @if($archivedOrders->count())

        <div>

            <h2 class="text-2xl font-bold mb-4">
                Архив
            </h2>

            @include('partials.orders_table', ['orders' => $archivedOrders])

        </div>

    @endif

@endif

@endsection