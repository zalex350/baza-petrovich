{{--
    Строка товара в списках каталога и поиска (макет docs/design/Catalog).
    <x-product-row :product="$product" :in-cart="$cartItems[$product->id] ?? null" />
    product — с mainImage; ключевые характеристики — связь keyAttributes (App\Services\Catalog\KeyAttributes),
    без неё строка характеристик не выводится. «Ваш код: …» рядом с артикулом — client_code()
    (коды страницы загружает контроллер: ClientCodes::load). in-cart — CartItem этого товара в корзине клиента или null.
    Ниже md — карточка: фото и название, под ними наличие и цена, внизу покупка; с md — колонки
    фиксированной ширины под фото и названием, с xl — одна строка.
    Покупка — x-product-row.buy (его же отдаёт CartController::add после «В корзину» без перезагрузки).
--}}
@props(['product', 'inCart' => null])

@php
    $name = $product->title();
    $url = route('product.show', $product->id);
    $clientCode = client_code($product);
    $keyAttributes = $product->relationLoaded('keyAttributes')
        ? $product->getRelation('keyAttributes')->map->displayValue()->join(' · ')
        : '';
@endphp

<article
    {{ $attributes->class('grid grid-cols-[5rem_minmax(0,1fr)] gap-x-4 gap-y-3 p-4 xl:grid-cols-[5rem_minmax(0,1fr)_auto] xl:items-center') }}
    data-product-row
    data-product-id="{{ $product->id }}"
>
    <a href="{{ $url }}" class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-control border border-line bg-media" tabindex="-1" data-product-link>
        @include('partials.product_image', [
            'image' => $product->mainImage,
            'alt'   => $name,
            'class' => 'h-full w-full object-contain p-1.5',
        ])
    </a>

    <div class="min-w-0">
        <x-product-badge :product="$product" class="mb-1" />

        <a href="{{ $url }}" class="font-semibold text-ink hover:text-link md:text-base" data-product-link>{{ $name }}</a>

        <div class="mt-0.5 text-meta text-ink-muted">
            Арт. {{ $product->displayArticle() }}@if($product->brand) · {{ $product->brand }}@endif
        </div>

        @if($clientCode)
            <div class="text-meta text-ink" data-client-code>Ваш код: <span class="font-semibold">{{ $clientCode }}</span></div>
        @endif

        @if($keyAttributes !== '')
            <div class="mt-0.5 text-meta text-ink-muted" data-key-attributes>{{ $keyAttributes }}</div>
        @endif
    </div>

    {{-- Наличие | цена | покупка: на телефоне наличие и цена одной строкой, покупка ниже;
         с md — колонки фиксированной ширины (grid-cols-product-row), одна под другой во всех строках;
         с xl — справа от названия, до xl — под фото и названием --}}
    <div class="col-span-2 grid grid-cols-2 items-start gap-x-3 gap-y-3 md:grid-cols-product-row xl:col-span-1" data-row-columns>
        @if(prices_visible())
            <x-stock :product="$product" class="text-sm md:pt-2.5" />

            <x-product-price :product="$product" class="whitespace-nowrap" data-row-price />
        @else
            {{-- Цены скрыты (B2B, гость): вместо наличия и цены — одна строка на месте обеих колонок --}}
            <div class="col-span-2 text-sm text-ink-muted md:pt-2.5" data-row-price data-guest-price>Цена и наличие — после входа</div>
        @endif

        <x-product-row.buy :product="$product" :in-cart="$inCart" class="col-span-2 md:col-span-1" />
    </div>
</article>
