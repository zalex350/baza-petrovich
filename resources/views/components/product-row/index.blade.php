{{--
    Строка товара в списках каталога и поиска (макет docs/design/Catalog).
    <x-product-row :product="$product" :in-cart="$cartItems[$product->id] ?? null" />
    product — с mainImage; ключевые характеристики — связь keyAttributes (App\Services\Catalog\KeyAttributes),
    без неё строка характеристик не выводится. in-cart — CartItem этого товара в корзине клиента или null.
    Ниже md — карточка: фото и название, под ними наличие и цена, внизу покупка; с md — одна строка.
    Покупка — x-product-row.buy (его же отдаёт CartController::add после «В корзину» без перезагрузки).
--}}
@props(['product', 'inCart' => null])

@php
    $name = $product->title();
    $url = route('product.show', $product->id);
    $keyAttributes = $product->relationLoaded('keyAttributes')
        ? $product->getRelation('keyAttributes')->map->displayValue()->join(' · ')
        : '';
@endphp

<article
    {{ $attributes->class('grid grid-cols-[5rem_minmax(0,1fr)] gap-x-4 gap-y-3 p-4 md:flex md:flex-wrap md:items-center md:gap-x-4') }}
    data-product-row
    data-product-id="{{ $product->id }}"
>
    <a href="{{ $url }}" class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-control border border-line bg-media md:shrink-0" tabindex="-1" data-product-link>
        @include('partials.product_image', [
            'image' => $product->mainImage,
            'alt'   => $name,
            'class' => 'h-full w-full object-contain p-1.5',
        ])
    </a>

    <div class="min-w-0 md:basis-56 md:grow">
        <x-product-badge :product="$product" class="mb-1" />

        <a href="{{ $url }}" class="font-semibold text-ink hover:text-link md:text-base" data-product-link>{{ $name }}</a>

        <div class="mt-0.5 text-meta text-ink-muted">
            Арт. {{ $product->sku }}@if($product->brand) · {{ $product->brand }}@endif
        </div>

        @if($keyAttributes !== '')
            <div class="mt-0.5 text-meta text-ink-muted" data-key-attributes>{{ $keyAttributes }}</div>
        @endif
    </div>

    {{-- На телефоне наличие и цена — одной строкой; с md — отдельные колонки строки --}}
    <div class="col-span-2 flex items-center justify-between gap-4 md:contents">
        <x-stock :product="$product" class="text-sm md:w-28 md:shrink-0" />

        <div class="whitespace-nowrap text-right md:min-w-36 md:shrink-0" data-row-price>
            @auth
                @if($product->price === null)
                    <span class="text-ink-muted" data-price-tbd>Цена уточняется</span>
                @else
                    <x-price :amount="$product->price" class="text-lg font-bold text-ink" />
                    <div class="text-meta text-ink-muted tabular-nums">
                        без НДС · {{ $product->vat_rate === null ? 'НДС не облагается' : 'с НДС '.money($product->price_with_vat, false) }}
                    </div>
                @endif
            @else
                <span class="text-sm text-ink-muted">Цена доступна после входа</span>
            @endauth
        </div>
    </div>

    <x-product-row.buy :product="$product" :in-cart="$inCart" class="col-span-2 md:ml-auto md:shrink-0" />
</article>
