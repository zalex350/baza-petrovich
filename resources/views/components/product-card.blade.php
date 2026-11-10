{{--
    Плитка товара: вид «плитка» в каталоге и подборках, карусели на главной.
    <x-product-card :product="$product" :in-cart="$cartItems[$product->id] ?? null" />
    Фото, бейджи (%, НОВИНКА, ХИТ), «Код:» (артикул) и «Ваш код:» (client_code(), коды страницы
    загружает контроллер — ClientCodes::load), название, наличие, цена (без НДС и с НДС),
    покупка — x-product-row.buy layout="tile" (количество + «В корзину», «В корзине: N шт.»).
    product — с mainImage. Плитка тянется на высоту ряда: покупка прижата к низу.
--}}
@props(['product', 'inCart' => null])

@php
    $name = $product->title();
    $url = route('product.show', $product->id);
    $clientCode = client_code($product);
@endphp

<article
    {{ $attributes->class('flex h-full min-w-0 flex-col gap-2 rounded-card border border-line bg-surface p-3') }}
    data-product-card
    data-product-id="{{ $product->id }}"
>
    <a href="{{ $url }}" class="relative flex aspect-square items-center justify-center overflow-hidden rounded-control bg-media" tabindex="-1" data-product-link>
        @include('partials.product_image', [
            'image' => $product->mainImage,
            'alt'   => $name,
            'class' => 'h-full w-full object-contain p-2',
        ])
        <x-product-badge :product="$product" class="absolute left-2 top-2" />
    </a>

    <div class="text-meta text-ink-muted" data-sku>
        Код: {{ $product->sku }}
        @if($clientCode)
            <span class="block text-ink" data-client-code>Ваш код: <span class="font-semibold">{{ $clientCode }}</span></span>
        @endif
    </div>

    <a href="{{ $url }}" class="line-clamp-2 min-h-10 text-sm font-semibold text-ink hover:text-link" data-product-link title="{{ $name }}">{{ $name }}</a>

    <x-stock :product="$product" class="text-sm" />

    <div class="tabular-nums" data-card-price>
        @auth
            @if($product->price === null)
                <span class="text-ink-muted" data-price-tbd>Цена уточняется</span>
            @else
                <x-price :amount="$product->price" class="text-lg font-bold text-ink" />
                <div class="text-meta text-ink-muted">
                    без НДС · {{ $product->vat_rate === null ? 'НДС не облагается' : 'с НДС '.money($product->price_with_vat, false) }}
                </div>
            @endif
        @else
            <span class="text-sm text-ink-muted">Цена доступна после входа</span>
        @endauth
    </div>

    <x-product-row.buy :product="$product" :in-cart="$inCart" layout="tile" class="mt-auto" />
</article>
