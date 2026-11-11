@extends('layouts.admin')

@section('title', 'Заказ ' . $order->number)

@section('content')

<h1 class="text-3xl font-bold mb-6">
    Заказ {{ $order->number }}
</h1>

<div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6">

    <div class="space-y-2">

        <p>
            Клиент:
            <strong>
                {{ $order->organization->name ?? '—' }}
            </strong>
        </p>

        <p>
            Пользователь:
            <strong>
                {{ $order->user->name ?? '—' }}
            </strong>
        </p>

        <p>
            Email:
            <strong>
                {{ $order->user->email ?? '—' }}
            </strong>
        </p>

        <p>
            Дата оформления:
            {{ date_ru($order->placed_at, true) }}
        </p>

        <p data-exchange-state>
            Обмен с 1С:
            <x-badge :tone="match ($order->exchange_state) { \App\Enums\OrderExchangeState::Sent => 'success', \App\Enums\OrderExchangeState::Rejected => 'danger', default => 'neutral' }">
                {{ $order->exchange_state->label() }}
            </x-badge>
            @if($order->number_1c)
                — № {{ $order->number_1c }}@if($order->registered_at) от {{ date_ru($order->registered_at, true) }}@endif
            @endif
        </p>

    </div>

</div>

@include('partials.order_delivery', ['order' => $order])

{{-- 1С отклонила заказ: решает менеджер — отменить (резерв снимается) или отправить повторно --}}
@if($order->exchange_state === \App\Enums\OrderExchangeState::Rejected && $order->status !== \App\Enums\OrderStatus::Cancelled)

    <x-alert type="error" title="1С отклонила заказ" class="mb-6" data-exchange-rejected>

        <p class="whitespace-pre-line">Причина: {{ $order->reject_reason ?: '—' }}</p>

        <p class="mt-1">Остаток по заказу зарезервирован, пока заказ не отменён или не отправлен повторно.</p>

        <div class="mt-4 flex flex-wrap gap-3">

            <x-confirm
                action="/admin/order/{{ $order->id }}/exchange/resend"
                label="Отправить в 1С повторно"
                title="Отправить заказ в 1С повторно?"
                confirm-label="Отправить"
                variant="primary"
            >
                Заказ {{ $order->number }} снова попадёт в выгрузку для 1С. Убедитесь, что причина отказа устранена.
            </x-confirm>

            <x-confirm
                action="/admin/order/{{ $order->id }}/exchange/cancel"
                label="Отменить заказ"
                title="Отменить заказ?"
                confirm-label="Отменить заказ"
                :open="$errors->has('comment')"
            >
                Заказ {{ $order->number }} будет отменён, резерв остатка снимется.

                <x-slot:fields>
                    <x-textarea
                        name="comment"
                        id="exchange_cancel_comment"
                        label="Комментарий (необязательно)"
                        rows="2"
                        maxlength="1000"
                    />
                </x-slot:fields>
            </x-confirm>

        </div>

    </x-alert>

@endif

{{-- Запрос клиента на отмену: подтвердить (отмена + возврат остатка) или отклонить с комментарием --}}
@if($order->hasOpenCancelRequest())

    <div class="bg-orange-50 rounded-2xl border border-orange-200 p-6 mb-6" data-cancel-request>

        <h2 class="text-xl font-semibold text-orange-800 mb-2">
            Запрос на отмену
        </h2>

        <p class="text-slate-700">
            {{ date_ru($order->cancel_requested_at, true) }}
            — {{ $order->cancelRequester->name ?? '—' }}
        </p>

        <p class="text-slate-700 whitespace-pre-line mt-1 mb-4">Причина: {{ $order->cancel_reason }}</p>

        <div class="grid gap-6 md:grid-cols-2">

            @if($order->status->canTransitionTo(\App\Enums\OrderStatus::Cancelled))

                <form
                    method="POST"
                    action="/admin/order/{{ $order->id }}/cancel-request/approve"
                    class="space-y-3"
                >

                    @csrf

                    <label for="approve_comment" class="block text-sm font-medium text-slate-600">
                        Комментарий (необязательно)
                    </label>

                    <textarea
                        id="approve_comment"
                        name="comment"
                        rows="2"
                        maxlength="1000"
                        class="border border-slate-300 rounded px-3 py-2 w-full bg-white"
                    ></textarea>

                    <button
                        type="submit"
                        class="bg-red-600 hover:bg-red-700 text-white px-5 py-2 rounded-xl transition"
                        onclick="return confirm('Отменить заказ {{ $order->number }}? Остаток вернётся на склад.')"
                    >
                        Подтвердить отмену
                    </button>

                </form>

            @else

                <p class="text-slate-600">
                    Заказ в статусе «{{ $order->status->label() }}» — отменить его уже нельзя, запрос можно только отклонить.
                </p>

            @endif

            <div class="space-y-3">

                <p class="text-slate-600">
                    Заказ останется в работе, клиент увидит причину отклонения.
                </p>

                {{-- После ошибки (пустая причина) окно открывается снова, с ошибкой у поля --}}
                <x-confirm
                    action="/admin/order/{{ $order->id }}/cancel-request/reject"
                    label="Отклонить запрос"
                    title="Отклонить запрос на отмену?"
                    confirm-label="Отклонить"
                    variant="primary"
                    :open="$errors->has('reject_comment')"
                >
                    Заказ {{ $order->number }} не будет отменён.

                    <x-slot:fields>
                        <x-textarea
                            name="reject_comment"
                            id="reject_comment"
                            label="Причина отклонения"
                            rows="3"
                            maxlength="1000"
                            required
                        />
                    </x-slot:fields>
                </x-confirm>

            </div>

        </div>

    </div>

@endif

<div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6">

    <h2 class="text-xl font-semibold mb-4">
        Статус заказа
    </h2>

    <p class="mb-4">
        @include('partials.order_status', ['status' => $order->status])
    </p>

    @if(! $manualStatus)

        <p class="text-slate-500">
            Ручная смена статусов отключена.
        </p>

    @elseif($order->status->isFinal())

        <p class="text-slate-500">
            Заказ завершён — статус больше не меняется.
        </p>

    @else

        {{-- Только допустимые переходы из текущего статуса --}}
        <form
            method="POST"
            action="/admin/order/{{ $order->id }}/status"
            class="space-y-4 max-w-xl"
        >

            @csrf

            <div>

                <label for="status" class="block text-sm font-medium text-slate-600 mb-1">
                    Новый статус
                </label>

                <select
                    id="status"
                    name="status"
                    class="border border-slate-300 rounded px-3 py-2 w-full"
                >

                    @foreach($order->status->allowedTransitions() as $next)

                        <option value="{{ $next->value }}" @selected(old('status') === $next->value)>
                            {{ $next->label() }}
                        </option>

                    @endforeach

                </select>

            </div>

            <div>

                <label for="comment" class="block text-sm font-medium text-slate-600 mb-1">
                    Комментарий (необязательно)
                </label>

                <textarea
                    id="comment"
                    name="comment"
                    rows="2"
                    maxlength="1000"
                    class="border border-slate-300 rounded px-3 py-2 w-full"
                >{{ old('comment') }}</textarea>

            </div>

            <button
                type="submit"
                class="bg-slate-800 hover:bg-slate-700 text-white px-5 py-2 rounded-xl transition"
            >
                Сменить статус
            </button>

        </form>

    @endif

</div>

<div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6">

    <h2 class="text-xl font-semibold mb-4">
        Оплата
    </h2>

    <form
        method="POST"
        action="/admin/order/{{ $order->id }}"
        class="flex items-center gap-4"
    >

        @csrf

        <label class="flex items-center gap-2">
            <input
                type="checkbox"
                name="is_paid"
                value="1"
                @checked($order->is_paid)
            >
            Оплачен
        </label>

        <button
            type="submit"
            class="bg-slate-800 hover:bg-slate-700 text-white px-5 py-2 rounded-xl transition"
        >
            Сохранить
        </button>

    </form>

</div>


{{-- Счёт на оплату (SRS §8.10, UC-035): PDF; позже — из 1С (OrderInvoice::source) --}}
<div class="bg-white rounded-card border border-slate-200 p-card mb-6 space-y-4" data-admin-invoice>

    <h2 class="text-xl font-semibold">Счёт на оплату</h2>

    @if($order->invoice)
        <div class="flex flex-wrap items-center gap-3 text-sm">
            @svg('heroicon-o-document-text', 'h-6 w-6 text-ink-muted')
            <a href="/admin/order/{{ $order->id }}/invoice" class="font-medium text-link hover:underline">{{ $order->invoice->original_name }}</a>
            <span class="text-ink-muted">
                {{ number_format($order->invoice->size / 1024, 0, ',', ' ') }} КБ ·
                {{ $order->invoice->source->label() }}@if($order->invoice->uploader), {{ $order->invoice->uploader->name }}@endif ·
                {{ date_ru($order->invoice->updated_at, time: true) }}
            </span>
            <x-confirm
                :action="'/admin/order/'.$order->id.'/invoice/delete'"
                label="Удалить"
                title="Удалить счёт?"
                confirm-label="Удалить"
                variant="danger"
                trigger-variant="link"
                trigger-size="sm"
            >
                Клиент больше не сможет скачать счёт по заказу {{ $order->number }}.
            </x-confirm>
        </div>
    @else
        <p class="text-sm text-ink-muted" data-invoice-missing>Счёт не загружен — клиент видит «Счёт ещё не сформирован».</p>
    @endif

    <form method="POST" action="/admin/order/{{ $order->id }}/invoice" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
        @csrf
        <x-file
            name="invoice"
            :label="$order->invoice ? 'Заменить счёт' : 'Загрузить счёт'"
            accept="application/pdf"
            hint="PDF до {{ intdiv(\App\Models\OrderInvoice::MAX_KB, 1024) }} МБ."
            class="max-w-md"
        />
        <x-button>{{ $order->invoice ? 'Заменить' : 'Загрузить' }}</x-button>
    </form>

</div>

@include('partials.order_history', ['order' => $order, 'showAuthor' => true])

@include('partials.order_items', ['order' => $order, 'admin' => true])

@endsection