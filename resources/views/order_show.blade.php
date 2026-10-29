@extends('layouts.account')

@section('title', 'Заказ ' . $order->number)

@section('account')

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

    {{-- Счёт на оплату (SRS §8.10, UC-035); нет — ERR-024 --}}
    <div class="mt-4 flex flex-wrap items-center gap-3" data-invoice>
        @if($order->invoice)
            <x-button variant="secondary" :href="'/order/'.$order->id.'/invoice'" data-invoice-download>
                @svg('heroicon-o-arrow-down-tray', 'h-5 w-5')
                Скачать счёт
            </x-button>
        @else
            <span class="inline-flex items-center gap-2 text-ink-muted" data-invoice-missing>
                @svg('heroicon-o-document-text', 'h-5 w-5')
                {{ \App\Models\OrderInvoice::NOT_READY }}
            </span>
        @endif
    </div>

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

    @if(auth()->user()->isClient())
        {{-- «Повторить» (SRS §8.11): позиции — в текущую корзину, итог — на странице корзины --}}
        <form method="POST" action="/order/{{ $order->id }}/reorder" data-reorder>
            @csrf
            <x-button>
                @svg('heroicon-o-arrow-path', 'h-5 w-5')
                Повторить заказ
            </x-button>
        </form>
    @endif

    </div>

</div>

<div class="mt-8">

    @include('partials.order_history', ['order' => $order, 'showAuthor' => false])

</div>

@endsection