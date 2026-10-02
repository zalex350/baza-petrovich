{{-- Адрес доставки (снимок из заказа) и комментарий клиента. Данные: $order --}}
<div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6">

    <h2 class="text-xl font-semibold mb-4">
        Доставка
    </h2>

    <dl class="grid grid-cols-1 sm:grid-cols-[220px_1fr] gap-x-6 gap-y-3">

        <dt class="text-slate-500">Адрес доставки</dt>
        <dd>

            @if($order->delivery_address)

                @if($order->delivery_title)
                    <span class="font-medium">{{ $order->delivery_title }}</span><br>
                @endif

                {{ $order->delivery_address }}

            @else

                <span class="text-slate-500">Не указан</span>

            @endif

        </dd>

        @if($order->delivery_contact_name || $order->delivery_contact_phone)

            <dt class="text-slate-500">Контактное лицо</dt>
            <dd>{{ collect([$order->delivery_contact_name, $order->delivery_contact_phone])->filter()->implode(', ') }}</dd>

        @endif

        <dt class="text-slate-500">Комментарий к заказу</dt>
        <dd class="whitespace-pre-line">{{ $order->comment ?? '—' }}</dd>

    </dl>

</div>
