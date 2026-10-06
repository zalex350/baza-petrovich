@extends('layouts.app')

@section('title', 'Заказ ' . $order->number)

@section('content')

<h1 class="text-3xl font-bold mb-6">
    Заказ {{ $order->number }}
</h1>

<div class="bg-white border rounded p-5 mb-6">

    <p class="mb-2">
        Статус:
        @include('partials.order_status', ['status' => $order->status])
    </p>

    <p class="mb-2">
        Оформил:
        {{ $order->user->name ?? '—' }}
    </p>

    <p class="mb-2">
        Оплата:
        @if($order->is_paid)
            <span class="text-green-600 font-medium">Оплачен</span>
        @else
            <span class="text-slate-500">Не оплачен</span>
        @endif
    </p>

    <p>
        Дата оформления:
        {{ $order->placed_at?->format('d.m.Y H:i') }}
    </p>

</div>

@include('partials.order_delivery', ['order' => $order])

@include('partials.order_items', ['order' => $order, 'admin' => false])

<div class="mt-8 flex items-center justify-end">

    <div class="flex items-center gap-3">

    {{-- Отменить можно только свой заказ и только пока он «Новый» --}}
    @if($order->status === \App\Enums\OrderStatus::New && $order->user_id === auth()->id())

        <form
            method="POST"
            action="/order/{{ $order->id }}/cancel"
        >

            @csrf

            <button
                type="submit"
                class="rounded-xl border border-red-300 text-red-700 hover:bg-red-50 px-5 py-2.5 transition"
                onclick="return confirm('Отменить заказ {{ $order->number }}?')"
            >
                Отменить заказ
            </button>

        </form>

    @endif

    <form
        method="POST"
        action="/order/{{ $order->id }}/reorder"
    >

        @csrf

        <button
            type="submit"
            class="rounded-xl bg-slate-800 hover:bg-slate-700 text-white px-5 py-2.5 transition"
        >
            Повторить заказ
        </button>

    </form>

    </div>

</div>

<div class="mt-8">

    @include('partials.order_history', ['order' => $order, 'showAuthor' => false])

</div>

@endsection