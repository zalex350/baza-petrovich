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
        @include('partials.cancel_request_badge', ['order' => $order])
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
        {{ date_ru($order->placed_at, true) }}
    </p>

</div>

@include('partials.order_delivery', ['order' => $order])

@include('partials.order_items', ['order' => $order, 'admin' => false])

{{-- Отмена — только запросом; решает менеджер --}}
@if($order->hasOpenCancelRequest())

    <div class="mt-6 bg-orange-50 rounded-2xl border border-orange-200 p-6" data-cancel-request>

        <h2 class="text-lg font-semibold text-orange-800 mb-1">
            Запрошена отмена
        </h2>

        <p class="text-slate-700">
            {{ date_ru($order->cancel_requested_at, true) }}
            @if($order->cancelRequester)
                — {{ $order->cancelRequester->name }}
            @endif
        </p>

        <p class="text-slate-700 whitespace-pre-line mt-1">Причина: {{ $order->cancel_reason }}</p>

        <p class="text-sm text-slate-500 mt-2">
            Менеджер рассмотрит запрос; решение появится в истории заказа.
        </p>

    </div>

@elseif($order->canRequestCancel(auth()->user()))

    <details class="mt-6 bg-white rounded-2xl border border-slate-200 p-6" @if($errors->has('cancel_reason')) open @endif>

        <summary class="cursor-pointer font-semibold text-red-700">
            Запросить отмену
        </summary>

        <form
            method="POST"
            action="/order/{{ $order->id }}/cancel-request"
            class="mt-4 space-y-3 max-w-xl"
        >

            @csrf

            <label for="cancel_reason" class="block text-sm font-medium text-slate-600">
                Причина отмены <span class="text-red-600">*</span>
            </label>

            <textarea
                id="cancel_reason"
                name="cancel_reason"
                rows="3"
                maxlength="1000"
                required
                class="border border-slate-300 rounded px-3 py-2 w-full"
            >{{ old('cancel_reason') }}</textarea>

            @error('cancel_reason')
                <p class="text-red-600 text-sm">{{ $message }}</p>
            @enderror

            <button
                type="submit"
                class="rounded-xl border border-red-300 text-red-700 hover:bg-red-50 px-5 py-2.5 transition"
            >
                Запросить отмену
            </button>

        </form>

    </details>

@endif

<div class="mt-8 flex items-center justify-end">

    <div class="flex items-center gap-3">

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