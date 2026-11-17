{{--
    Главная: баннеры (админка «Баннеры») или первый экран металлобазы; разделы каталога; карусели
    «Акции», «Новинки», «Хиты»; популярные товары; преимущества и услуги.
--}}
@extends('layouts.app')

@section('content')

<div data-home>

    @if($banners->isNotEmpty())
        <x-banner-slider :banners="$banners" class="mb-8" />
    @else
        {{-- Первый экран: без баннеров --}}
        <section class="relative mb-8 overflow-hidden rounded-card bg-primary text-white" data-home-hero>
            <div class="absolute -right-24 -top-24 h-80 w-80 rounded-full bg-accent/20 blur-3xl" aria-hidden="true"></div>
            <div class="relative grid gap-8 px-6 py-10 md:px-10 md:py-14 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-center">
                <div>
                    <p class="mb-3 inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-meta font-semibold text-accent">
                        @svg('heroicon-s-map-pin', 'h-4 w-4') Минск (МКАД) и Брест
                    </p>
                    <h1 class="text-3xl font-extrabold leading-tight tracking-tight md:text-5xl">
                        Металл для заборов и стройки — <span class="text-accent">в розницу</span>, по ценам базы
                    </h1>
                    <p class="mt-4 max-w-2xl text-lg text-primary-fg">
                        Металлопрокат, профнастил, сетка, металлоштакетник, ворота и комплектующие.
                        Резка в размер, доставка «по звонку» в день заказа, монтаж заборов под ключ.
                    </p>
                    <div class="mt-6 flex flex-wrap gap-3">
                        <x-button size="lg" :href="route('catalog.index')">Перейти в каталог</x-button>
                        <a href="/uslugi" class="inline-flex h-12 items-center rounded-control border border-white/30 px-5 font-semibold text-white hover:bg-white/10">Услуги и монтаж</a>
                    </div>
                </div>
                <ul class="grid grid-cols-2 gap-3 text-sm" aria-label="Преимущества">
                    @foreach([
                        ['heroicon-o-truck', 'Доставка в день заказа', 'заказ до 12:00'],
                        ['heroicon-o-scissors', 'Резка в размер', 'без учёта кратности'],
                        ['heroicon-o-building-storefront', 'Самовывоз', 'без очередей'],
                        ['heroicon-o-wrench-screwdriver', 'Монтаж под ключ', 'заборы и ворота'],
                    ] as [$icon, $title, $text])
                        <li class="rounded-card bg-white/5 p-4 ring-1 ring-white/10">
                            @svg($icon, 'mb-2 h-6 w-6 text-accent')
                            <span class="block font-semibold">{{ $title }}</span>
                            <span class="block text-meta text-primary-fg">{{ $text }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- Разделы (CatalogMenu: только с товарами, порядок — CategoryOrder) --}}
    @if(count($categories))
        <section class="mb-10" data-home-categories>
            <div class="mb-4 flex items-end justify-between gap-4">
                <h2 class="text-2xl font-bold tracking-tight">Каталог</h2>
                <a href="{{ route('catalog.index') }}" class="text-sm font-medium text-link hover:underline">Все разделы →</a>
            </div>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                @foreach($categories as $node)
                    <x-category-tile :node="$node" :tile="$tiles[$node['id']] ?? null" />
                @endforeach
            </div>
        </section>
    @endif

    {{-- Карусели «Акции», «Новинки», «Хиты»: пустая не выводится --}}
    <div class="mb-10 flex flex-col gap-8" data-home-carousels>
        @foreach($carousels as $carousel)
            <x-product-carousel :title="$carousel['title']" :url="$carousel['url']" :products="$carousel['products']" :cart-items="$cartItems" />
        @endforeach
    </div>

    @if($featuredProducts->isNotEmpty())
        <section class="mb-10" data-home-popular>
            <h2 class="mb-3 text-2xl font-bold tracking-tight">Популярные товары</h2>

            {{-- Те же строки, что в каталоге и поиске: «В корзину» без перезагрузки (cart-actions.js) --}}
            <div class="divide-y divide-line-soft overflow-hidden rounded-card border border-line bg-surface" data-product-list>
                @foreach($featuredProducts as $product)
                    <x-product-row :product="$product" :in-cart="$cartItems[$product->id] ?? null" />
                @endforeach
            </div>
        </section>
    @endif

    {{-- Услуги и звонок --}}
    <section class="mb-6 grid gap-4 rounded-card border border-line bg-surface p-card md:grid-cols-[minmax(0,1fr)_auto] md:items-center" data-home-services>
        <div>
            <h2 class="text-xl font-bold tracking-tight">Посчитаем забор и привезём материал</h2>
            <p class="mt-1 text-ink-muted">Бесплатно рассчитаем количество материалов, подготовим заказ к приезду, порежем в размер и доставим на участок. Монтаж заборов и ворот — под ключ.</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <x-button :href="route('lead.create', ['type' => 'callback'])">Заказать звонок</x-button>
            <x-button variant="secondary" href="/uslugi">Все услуги</x-button>
        </div>
    </section>

</div>

@endsection
