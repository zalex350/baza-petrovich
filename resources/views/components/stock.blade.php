{{--
    Наличие: 3 полоски + подпись. Пороги — config/shop.php (stock_many, stock_few).
    <x-stock :product="$product" />                 — остаток и «под заказ» из товара
    <x-stock :stock="12" :on-order="false" />        — без модели
    Остаток ≥ stock_many — «Много» (3 полоски); ≤ stock_few — «Мало» (1); между — «N шт.» (2);
    0 — «Под заказ» (on_order) или «Нет в наличии» (0 полосок). data-stock: many | some | few | on_order | out_of_stock.
--}}
@props(['product' => null, 'stock' => 0, 'onOrder' => false])

@php
    $stock = (int) ($product ? $product->stock : $stock);
    $onOrder = (bool) ($product ? $product->on_order : $onOrder);

    [$level, $bars, $label] = match (true) {
        $stock >= config('shop.stock_many') => ['many', 3, 'Много'],
        $stock > config('shop.stock_few')   => ['some', 2, $stock.' шт.'],
        $stock > 0                          => ['few', 1, 'Мало'],
        $onOrder                            => ['on_order', 0, 'Под заказ'],
        default                             => ['out_of_stock', 0, 'Нет в наличии'],
    };

    [$barColor, $textColor] = match ($level) {
        'many', 'some' => ['bg-success', 'text-tone-success-text'],
        'few'          => ['bg-warning', 'text-tone-warning-text'],
        'on_order'     => ['bg-line', 'text-tone-warning-text'],
        default        => ['bg-line', 'text-tone-danger-text'],
    };
@endphp

<span
    {{ $attributes->class(['inline-flex items-center gap-2 text-sm font-medium whitespace-nowrap tabular-nums', $textColor]) }}
    data-stock="{{ $level }}"
    @if($stock > 0) title="В наличии: {{ $stock }} шт." @endif
>
    <span class="inline-flex items-end gap-0.5" aria-hidden="true">
        @foreach([1, 2, 3] as $bar)
            <span @class(['w-1 rounded-sm', ['h-1.5', 'h-2.5', 'h-3.5'][$bar - 1], $bar <= $bars ? $barColor : 'bg-line'])></span>
        @endforeach
    </span>
    <span>@if($stock > 0)<span class="sr-only">В наличии: </span>@endif{{ $label }}</span>
</span>
