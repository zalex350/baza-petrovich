{{--
    Заказ гостя розницы по ссылке /o/{token} (OrderController::guestShow): номер, статус, получение,
    оплата, позиции, сумма. Ссылку покупатель получает после оформления (и в письме, если указал email).
--}}
@extends('layouts.app')

@section('title', 'Заказ ' . $order->number)

@section('content')

<div class="max-w-4xl" data-guest-order>

    <h1 class="text-3xl font-bold mb-2">
        Заказ {{ $order->number }}
    </h1>

    <p class="text-ink-muted mb-6">
        Оформлен {{ date_ru($order->placed_at, true) }}.
        Статус:
        @include('partials.order_status', ['status' => $order->status])
    </p>

    @if(session('success'))
        <x-alert type="info" class="mb-6" data-guest-order-next>
            Менеджер свяжется с вами по телефону {{ $order->delivery_contact_phone }}, чтобы подтвердить заказ.
            Сохраните ссылку на эту страницу — по ней можно проверить статус заказа.
        </x-alert>
    @endif

    @include('partials.order_delivery', ['order' => $order])

    @include('partials.order_items', ['order' => $order, 'admin' => false])

    <div class="mt-6 flex flex-wrap items-center gap-3">
        <x-button :href="route('catalog.index')">Продолжить покупки</x-button>
        <x-copy :text="url('/o/'.$order->public_token)" label="Скопировать ссылку на заказ" />
    </div>

</div>

@endsection
