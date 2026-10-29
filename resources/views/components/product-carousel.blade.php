{{--
    Карусель товаров на главной: заголовок, ссылка «Все», лента плиток (x-product-card)
    со стрелками (resources/js/product-carousel.js) и прокруткой пальцем.
    <x-product-carousel title="Акции" url="/promo" :products="$promo" :cart-items="$cartItems" />
    Нет товаров — ничего не выводится.
--}}
@props(['title', 'url', 'products', 'cartItems' => collect()])

@if($products->isNotEmpty())
<section {{ $attributes->class('flex flex-col gap-3') }} x-data="productCarousel()" aria-label="{{ $title }}" data-carousel>
    <div class="flex items-center justify-between gap-3">
        <h2 class="text-2xl font-bold tracking-tight">{{ $title }}</h2>
        <div class="flex items-center gap-2">
            <a href="{{ $url }}" class="mr-2 text-sm font-medium text-link hover:underline" data-carousel-all>Все</a>
            <button type="button" @click="scroll(-1)" :disabled="atStart" class="hidden h-10 w-10 items-center justify-center rounded-full border border-line-strong bg-surface text-primary hover:border-primary disabled:opacity-40 sm:flex" aria-label="Прокрутить назад">
                @svg('heroicon-o-chevron-left', 'h-5 w-5')
            </button>
            <button type="button" @click="scroll(1)" :disabled="atEnd" class="hidden h-10 w-10 items-center justify-center rounded-full border border-line-strong bg-surface text-primary hover:border-primary disabled:opacity-40 sm:flex" aria-label="Прокрутить вперёд">
                @svg('heroicon-o-chevron-right', 'h-5 w-5')
            </button>
        </div>
    </div>

    {{-- relative: скрытые подписи (sr-only, absolute) внутри ленты не расширяют страницу --}}
    <ul x-ref="track" class="relative -mx-1 flex snap-x snap-mandatory gap-3 overflow-x-auto px-1 pb-2">
        @foreach($products as $product)
            <li class="w-carousel-item shrink-0 snap-start sm:w-carousel-item-sm lg:w-carousel-item-lg">
                <x-product-card :product="$product" :in-cart="$cartItems[$product->id] ?? null" />
            </li>
        @endforeach
    </ul>
</section>
@endif
