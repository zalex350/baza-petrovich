{{--
    Наличие: 3 полоски + подпись (макеты docs/design: Main, Catalog, Product). Пороги — config/shop.php.
    <x-stock :product="$product" />                 — остаток и «под заказ» из товара
    <x-stock :stock="12" :on-order="false" />        — без модели
    Остаток ≥ stock_many — «Много» (3 зелёные); больше stock_few — «Мало» (2 жёлтые);
    1…stock_few — точное «N шт.» (1 жёлтая); 0 — «Под заказ» (on_order) или «Нет в наличии» (пустые).
    data-stock: many | few | count | on_order | out_of_stock.
--}}
@props(['product' => null, 'stock' => 0, 'onOrder' => false])

@php
    $stock = (int) ($product ? $product->stock : $stock);
    $onOrder = (bool) ($product ? $product->on_order : $onOrder);

    [$level, $bars, $label] = match (true) {
        $stock >= config('shop.stock_many') => ['many', 3, 'Много'],
        $stock > config('shop.stock_few')   => ['few', 2, 'Мало'],
        $stock > 0                          => ['count', 1, $stock.' шт.'],
        $onOrder                            => ['on_order', 0, 'Под заказ'],
        default                             => ['out_of_stock', 0, 'Нет в наличии'],
    };

    [$barColor, $textColor] = match ($level) {
        'many'          => ['bg-success', 'text-tone-success-text'],
        'few', 'count'  => ['bg-warning', 'text-tone-warning-text'],
        'on_order'      => ['bg-line-empty', 'text-ink-muted'],
        default         => ['bg-line-empty', 'text-tone-danger-text'],
    };
@endphp

<span
    {{ $attributes->class(['inline-flex items-center gap-2 whitespace-nowrap tabular-nums', $textColor]) }}
    data-stock="{{ $level }}"
    @if($stock > 0) title="В наличии: {{ $stock }} шт." @endif
>
    <span class="inline-flex items-end gap-0.5" aria-hidden="true">
        @foreach(['h-2', 'h-3', 'h-4'] as $i => $height)
            <span @class(['w-1 rounded-bar', $height, $i < $bars ? $barColor : 'bg-line-empty'])></span>
        @endforeach
    </span>
    <span>@if($stock > 0)<span class="sr-only">В наличии: </span>@endif{{ $label }}</span>
</span>
