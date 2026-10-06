{{--
    Бейдж открытого запроса на отмену.
    Данные: $order, $admin — в админке «Запрос на отмену», у клиента «Запрошена отмена».
--}}
@if($order->hasOpenCancelRequest())
    <span class="inline-flex rounded-full bg-orange-100 text-orange-700 px-2 py-0.5 text-xs" data-cancel-badge>
        {{ ($admin ?? false) ? 'Запрос на отмену' : 'Запрошена отмена' }}
    </span>
@endif
