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
            {{ $order->placed_at?->format('d.m.Y H:i') }}
        </p>

    </div>

</div>

@include('partials.order_delivery', ['order' => $order])

{{-- Запрос клиента на отмену: подтвердить (отмена + возврат остатка) или отклонить с комментарием --}}
@if($order->hasOpenCancelRequest())

    <div class="bg-orange-50 rounded-2xl border border-orange-200 p-6 mb-6" data-cancel-request>

        <h2 class="text-xl font-semibold text-orange-800 mb-2">
            Запрос на отмену
        </h2>

        <p class="text-slate-700">
            {{ $order->cancel_requested_at?->format('d.m.Y H:i') }}
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

            <form
                method="POST"
                action="/admin/order/{{ $order->id }}/cancel-request/reject"
                class="space-y-3"
            >

                @csrf

                <label for="reject_comment" class="block text-sm font-medium text-slate-600">
                    Причина отклонения <span class="text-red-600">*</span>
                </label>

                <textarea
                    id="reject_comment"
                    name="reject_comment"
                    rows="2"
                    maxlength="1000"
                    required
                    class="border border-slate-300 rounded px-3 py-2 w-full bg-white"
                >{{ old('reject_comment') }}</textarea>

                @error('reject_comment')
                    <p class="text-red-600 text-sm">{{ $message }}</p>
                @enderror

                <button
                    type="submit"
                    class="bg-slate-800 hover:bg-slate-700 text-white px-5 py-2 rounded-xl transition"
                >
                    Отклонить
                </button>

            </form>

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

@include('partials.order_history', ['order' => $order, 'showAuthor' => true])

@include('partials.order_items', ['order' => $order, 'admin' => true])

@endsection