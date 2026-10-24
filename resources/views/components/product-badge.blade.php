{{--
    Бейдж товара на витрине: ХИТ, НОВИНКА.
    <x-product-badge type="hit" />
    <x-product-badge :product="$product" />   — все бейджи товара (is_hit, is_new) в ряд; нет ни одного — ничего
    type: hit | new.
--}}
@props(['type' => null, 'product' => null])

@php
    $types = $product
        ? array_keys(array_filter(['hit' => $product->is_hit, 'new' => $product->is_new]))
        : array_filter([$type]);

    $badges = [
        'hit' => ['ХИТ', 'bg-tone-warning-bg text-tone-warning-text'],
        'new' => ['НОВИНКА', 'bg-tone-success-bg text-tone-success-text'],
    ];
@endphp

@if($product && $types)
    <span {{ $attributes->class('inline-flex flex-wrap gap-1') }}>
        @foreach($types as $t)
            <span class="inline-flex items-center rounded-control px-2 py-0.5 text-xs font-semibold tracking-wide {{ $badges[$t][1] }}" data-product-badge="{{ $t }}">{{ $badges[$t][0] }}</span>
        @endforeach
    </span>
@elseif(! $product && $types)
    <span {{ $attributes->class(['inline-flex items-center rounded-control px-2 py-0.5 text-xs font-semibold tracking-wide', $badges[$type][1]]) }} data-product-badge="{{ $type }}">{{ $badges[$type][0] }}</span>
@endif
