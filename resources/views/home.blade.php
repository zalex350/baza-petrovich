@extends('layouts.app')

@section('content')

<div class="bg-slate-50 min-h-screen">

    {{-- Баннеры (админка «Баннеры»); нет ни одного включённого — прежний первый экран --}}
    @if($banners->isNotEmpty())
        <x-banner-slider :banners="$banners" class="mb-8" />
    @else
    <section class="bg-gradient-to-r from-slate-800 to-slate-600 text-white overflow-hidden">

    <div class="max-w-7xl mx-auto px-6 py-16">

        <div class="grid lg:grid-cols-2 gap-10 items-center">

            <div>

                <h1 class="text-5xl lg:text-6xl font-bold leading-tight mb-6">
                    Сантехника для дома и бизнеса
                </h1>

                <p class="text-xl text-slate-200 mb-8">
                    Смесители, душевые системы, инсталляции и аксессуары.
                    Только проверенные бренды и официальная гарантия.
                </p>

                <a
                    href="/catalog/smesiteli"
                    class="inline-flex bg-white text-slate-800 px-8 py-4 rounded-xl font-semibold hover:bg-slate-100 transition">

                    Перейти в каталог

                </a>

            </div>

            <div class="hidden lg:flex justify-center">

                <img
                    src="{{ asset('images/hero/hero.jpg') }}"
                    alt="Смеситель"
                    class="max-h-[520px] object-contain">

            </div>

        </div>

    </div>

</section>
    @endif

    {{-- Карусели «Акции», «Новинки», «Хиты»: пустая не выводится --}}
    <div class="flex flex-col gap-8 py-8" data-home-carousels>
        @foreach($carousels as $carousel)
            <x-product-carousel :title="$carousel['title']" :url="$carousel['url']" :products="$carousel['products']" :cart-items="$cartItems" />
        @endforeach
    </div>

    @if($featuredProducts->isNotEmpty())
    <section class="pb-12" data-home-popular>

        <h2 class="text-2xl font-bold tracking-tight mb-3">
            Популярные товары
        </h2>

        {{-- Те же строки, что в каталоге и поиске: «В корзину» без перезагрузки (cart-actions.js) --}}
        <div class="divide-y divide-line-soft overflow-hidden rounded-card border border-line bg-surface" data-product-list>
            @foreach($featuredProducts as $product)
                <x-product-row :product="$product" :in-cart="$cartItems[$product->id] ?? null" />
            @endforeach
        </div>

    </section>
    @endif

    <section class="bg-white border-y border-slate-200">

        <div class="max-w-7xl mx-auto px-6 py-10">

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-8 text-center">

                <div>
                    <div class="text-4xl mb-3">🚚</div>
                    <h3 class="font-semibold">Быстрая доставка</h3>
                    <p class="text-sm text-slate-500 mt-2">
                        По всей Беларуси
                    </p>
                </div>

                <div>
                    <div class="text-4xl mb-3">🛡</div>
                    <h3 class="font-semibold">Гарантия</h3>
                    <p class="text-sm text-slate-500 mt-2">
                        Только официальный товар
                    </p>
                </div>

                <div>
                    <div class="text-4xl mb-3">💳</div>
                    <h3 class="font-semibold">Удобная оплата</h3>
                    <p class="text-sm text-slate-500 mt-2">
                        Онлайн и при получении
                    </p>
                </div>

                <div>
                    <div class="text-4xl mb-3">☎</div>
                    <h3 class="font-semibold">Поддержка</h3>
                    <p class="text-sm text-slate-500 mt-2">
                        Поможем с выбором
                    </p>
                </div>

            </div>

        </div>

    </section>
    <section class="max-w-7xl mx-auto px-6 py-16">

        <h2 class="text-3xl font-bold mb-8">
            Категории
        </h2>

        {{-- Разделы (CatalogMenu: только с товарами): фото типичного товара, без фото — иконка --}}
        <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
            @foreach($categories as $node)
                <x-category-tile :node="$node" :tile="$tiles[$node['id']] ?? null" />
            @endforeach
        </div>

    </section>


</div>

@endsection