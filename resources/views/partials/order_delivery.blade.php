{{-- Получение (снимок из заказа): способ, адрес, контакт; розница — email и оплата; комментарий клиента.
     Данные: $order; $admin — админка: ещё источник визита (UTM, gclid — App\Support\Attribution) --}}
<div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6">

    <h2 class="text-xl font-semibold mb-4">
        Получение
    </h2>

    <dl class="grid grid-cols-1 sm:grid-cols-[220px_1fr] gap-x-6 gap-y-3">

        @if($order->deliveryLabel())
            <dt class="text-slate-500">Способ получения</dt>
            <dd data-delivery-method>{{ $order->deliveryLabel() }}</dd>
        @endif

        <dt class="text-slate-500">{{ str_starts_with((string) $order->delivery_method, 'pickup') ? 'Адрес самовывоза' : 'Адрес доставки' }}</dt>
        <dd>

            @if($order->delivery_address)

                @if($order->delivery_title && ! $order->delivery_method)
                    <span class="font-medium">{{ $order->delivery_title }}</span><br>
                @endif

                {{ $order->delivery_address }}

            @else

                <span class="text-slate-500">Не указан</span>

            @endif

        </dd>

        @if($order->delivery_contact_name || $order->delivery_contact_phone)

            <dt class="text-slate-500">{{ $order->isGuest() ? 'Покупатель' : 'Контактное лицо' }}</dt>
            <dd>{{ collect([$order->delivery_contact_name, $order->delivery_contact_phone])->filter()->implode(', ') }}</dd>

        @endif

        @if($order->customer_email)
            <dt class="text-slate-500">Email</dt>
            <dd>{{ $order->customer_email }}</dd>
        @endif

        @if($order->paymentLabel())
            <dt class="text-slate-500">Оплата</dt>
            <dd data-payment-method>{{ $order->paymentLabel() }}</dd>
        @endif

        @if(($admin ?? false) && ($source = \App\Support\Attribution::label($order->attribution)))
            <dt class="text-slate-500">Источник</dt>
            <dd data-order-source>
                {{ $source }}
                @if(! empty($order->attribution['landing']))
                    <span class="block text-sm text-slate-500">Вход: {{ $order->attribution['landing'] }}@if(! empty($order->attribution['referrer'])) · с {{ parse_url($order->attribution['referrer'], PHP_URL_HOST) }}@endif</span>
                @endif
            </dd>
        @endif

        <dt class="text-slate-500">Комментарий к заказу</dt>
        <dd class="whitespace-pre-line">{{ $order->comment ?? '—' }}</dd>

    </dl>

</div>
