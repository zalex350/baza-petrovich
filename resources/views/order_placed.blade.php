@extends('layouts.app')

@section('title', 'Заказ ' . $order->number . ' оформлен')

@section('content')

<div class="max-w-3xl">

@if(\App\Support\Analytics::config())
    <x-track event="purchase" :params="\App\Support\Analytics::purchase($order)" :once="$order->number" />
@endif

    <h1 class="text-3xl font-bold mb-2">
        Заказ {{ $order->number }} оформлен
    </h1>

    <p class="text-slate-600 mb-6">
        Дата оформления: {{ date_ru($order->placed_at, true) }}.
        Статус:
        @include('partials.order_status', ['status' => $order->status])
    </p>

    @include('partials.order_delivery', ['order' => $order])

    <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6">

        <h2 class="text-xl font-semibold mb-4">
            Сумма заказа
        </h2>

        @include('partials.vat_totals', ['totals' => $order->vatTotals()])

    </div>

    <div class="flex flex-wrap items-center gap-3">

        <a
            href="/order/{{ $order->id }}"
            class="rounded-xl bg-slate-800 hover:bg-slate-700 text-white px-5 py-2.5 transition"
        >
            Открыть заказ
        </a>

        <a
            href="/orders"
            class="rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-50 px-5 py-2.5 transition"
        >
            Мои заказы
        </a>

        <a
            href="{{ route('catalog.index') }}"
            class="text-slate-600 hover:text-slate-800 underline"
        >
            Продолжить покупки
        </a>

    </div>

</div>

@endsection
