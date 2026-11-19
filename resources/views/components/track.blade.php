{{--
    Событие аналитики при открытии страницы (resources/js/analytics.js → window.track).
    <x-track event="view_item" :params="['currency' => 'BYN', 'value' => 10, 'items' => [...]]" />
    once — ключ, по которому событие отправляется один раз в браузере (покупка: номер заказа),
    чтобы обновление страницы заказа не дублировало конверсию.
    Счётчики не подключены (App\Support\Analytics::config() = null) — ничего не выводится.
--}}
@props(['event', 'params' => [], 'once' => null])

@if(\App\Support\Analytics::config())
    <script type="application/json" data-track-event="{{ $event }}" @if($once) data-track-once="{{ $once }}" @endif>{!! json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endif
