{{--
    Сумма в BYN в едином формате (App\Support\Money): 1 234.50 BYN.
    <x-price :amount="$order->total_with_vat" />                  — просто сумма
    <x-price :amount="$line->net" vat="net" />                    — с пометкой «без НДС» / vat="gross" — «с НДС»
    <x-price :product="$product" />                               — цена товара: без НДС + строка «с НДС: …»
                                                                     или «НДС не облагается»
    Пустая сумма (null) — «Цена уточняется» (placeholder).
--}}
@props(['amount' => null, 'product' => null, 'vat' => null, 'placeholder' => 'Цена уточняется'])

@php
    $value = $product ? $product->price : $amount;
    $formatted = \App\Support\Money::format($value);
    $label = $product ? 'без НДС' : match ($vat) {
        'net'   => 'без НДС',
        'gross' => 'с НДС',
        default => null,
    };
@endphp

@if($formatted === null)
    <span {{ $attributes->class('text-ink-muted') }} data-price-tbd>{{ $placeholder }}</span>
@else
    <span {{ $attributes->class('whitespace-nowrap tabular-nums') }} data-price>
        {{ $formatted }}@if($label) <span class="text-[0.75em] font-normal text-ink-muted">{{ $label }}</span>@endif
        @if($product)
            <span class="block text-[0.75em] font-normal text-ink-muted" data-price-vat>
                {{ $product->vat_rate === null ? 'НДС не облагается' : 'с НДС: '.\App\Support\Money::format($product->price_with_vat) }}
            </span>
        @endif
    </span>
@endif
