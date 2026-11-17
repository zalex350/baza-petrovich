{{--
    Цена товара на витрине: строка списка, плитка, карточка.
    <x-product-price :product="$product" />                  — строка списка (layout="row", по правому краю)
    <x-product-price :product="$product" layout="tile" />    — плитка
    <x-product-price :product="$product" layout="card" />    — карточка товара, крупно
    Цена — для текущего покупателя (Product::price: розница со скидкой или тип цены организации).
    Скидка — прежняя цена зачёркнута (Product::old_price). Подпись — «за м.п.» по единице товара;
    розница (shop.prices_include_vat) — «с НДС», B2B — «без НДС» и строка «с НДС …».
    Нет цены — розница «Цена по запросу» (кнопку «Узнать цену» выводит x-product-row.buy), B2B «Цена уточняется».
    Цены скрыты (B2B, гость) — «Цена и наличие — после входа».
--}}
@props(['product', 'layout' => 'row'])

@php
    $card = $layout === 'card';
    $tile = $layout === 'tile';
    $retail = \App\Services\Vat\VatCalculator::pricesIncludeVat();
    $unit = unit_label($product->unit);
    $old = prices_visible() ? $product->old_price : null;
@endphp

<div {{ $attributes->class(['tabular-nums', 'text-right' => ! $card && ! $tile]) }} data-product-price>
    @if(! prices_visible())
        <span class="text-sm text-ink-muted" data-guest-price>Цена и наличие — после входа</span>
    @elseif($product->price === null)
        <span @class(['font-semibold text-ink-muted', 'text-xl' => $card]) data-price-tbd>{{ config('shop.retail') ? 'Цена по запросу' : 'Цена уточняется' }}</span>
    @else
        @if($card)
            <div class="text-meta text-ink-muted">Цена {{ $retail ? 'с НДС' : 'без НДС' }}, за {{ $unit }}</div>
        @endif

        <div @class(['flex flex-wrap items-baseline gap-x-2', 'justify-end' => ! $card && ! $tile])>
            <x-price :amount="$product->price" @class([
                'font-bold',
                'text-price' => $card,
                'text-lg' => ! $card,
                'text-tone-danger-text' => $old,
                'text-ink' => ! $old,
            ]) data-card-price />
            @if($old)
                <s class="text-sm text-ink-muted" data-old-price>{{ money($old) }}</s>
            @endif
        </div>

        @unless($card)
            <div class="text-meta text-ink-muted">за {{ $unit }}@unless($retail) · без НДС@endunless</div>
        @endunless

        @unless($retail)
            <div class="text-meta text-ink-muted" data-card-vat>
                @if($product->vat_rate === null)
                    НДС не облагается
                @else
                    с НДС {{ $card ? $product->vat_rate_label.' — '.money($product->price_with_vat) : money($product->price_with_vat, false) }}
                @endif
            </div>
        @endunless
    @endif
</div>
