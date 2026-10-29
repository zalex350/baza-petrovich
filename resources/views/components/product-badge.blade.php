{{--
    Бейдж товара на витрине: «%» (акция), ХИТ, НОВИНКА.
    <x-product-badge type="hit" />
    <x-product-badge :product="$product" />   — все бейджи товара в ряд: %, НОВИНКА, ХИТ; нет ни одного — ничего
    type: promo | hit | new.
--}}
@props(['type' => null, 'product' => null])

@php
    $types = $product
        ? array_keys(array_filter(['promo' => $product->is_promo, 'new' => $product->is_new, 'hit' => $product->is_hit]))
        : array_filter([$type]);

    $badges = [
        'promo' => ['%', 'bg-badge-promo-bg text-badge-promo-text'],
        'hit' => ['ХИТ', 'bg-badge-hit-bg text-badge-hit-text'],
        'new' => ['НОВИНКА', 'bg-badge-new-bg text-badge-new-text'],
    ];
@endphp

@if($product && $types)
    <span {{ $attributes->class('inline-flex flex-wrap gap-1.5') }}>
        @foreach($types as $t)
            <span class="inline-flex items-center rounded-tag px-1.5 py-0.5 text-counter font-bold tracking-wider {{ $badges[$t][1] }}" data-product-badge="{{ $t }}" @if($t === 'promo') title="Акция" @endif>{{ $badges[$t][0] }}@if($t === 'promo')<span class="sr-only"> Акция</span>@endif</span>
        @endforeach
    </span>
@elseif(! $product && $types)
    <span {{ $attributes->class(['inline-flex items-center rounded-tag px-1.5 py-0.5 text-counter font-bold tracking-wider', $badges[$type][1]]) }} data-product-badge="{{ $type }}">{{ $badges[$type][0] }}</span>
@endif
