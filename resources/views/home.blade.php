@extends('layouts.app')

@section('content')

<div class="bg-slate-50 min-h-screen">

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

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">

            @foreach($categories as $category)

                <a href="/catalog/{{ $category->slug }}"
                   class="bg-white rounded-xl shadow hover:shadow-lg transition p-8 border">

                    <div class="text-5xl mb-5">
                        🚿
                    </div>

                    <h3 class="text-xl font-semibold">
                        {{ $category->name }}
                    </h3>

                </a>

            @endforeach

        </div>

    </section>

    <section class="max-w-7xl mx-auto px-6 pb-20">

        <h2 class="text-3xl font-bold mb-8">
            Популярные товары
        </h2>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">

            @foreach($featuredProducts as $product)

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-xl transition overflow-hidden flex flex-col">

        <a href="/product/{{ $product->id }}">

            <div class="aspect-square bg-slate-100 flex items-center justify-center">

                @if($product->image)

                    <img
                        src="{{ asset('storage/' . $product->image) }}"
                        alt="{{ $product->name }}"
                        class="w-full h-full object-contain p-6">

                @else

                    <div class="text-center">

                        <div class="text-6xl mb-3">
                            🚰
                        </div>

                        <div class="text-sm text-slate-500">
                            Фото скоро появится
                        </div>

                    </div>

                @endif

            </div>

        </a>

        <div class="p-5 flex flex-col flex-grow">

            @if($product->brand)

                <div class="text-xs uppercase tracking-widest text-slate-400 mb-2">
                    {{ $product->brand }}
                </div>

            @endif

            <h3 class="text-lg font-semibold leading-6 min-h-[56px]">
                {{ $product->name }}
            </h3>

            <div class="mt-5 flex items-center justify-between">

                <div class="text-2xl font-bold">
                    {{ number_format($product->price, 2) }} BYN
                </div>

                @if($product->stock > 0)

                    <span class="text-green-600 text-sm">
                        В наличии
                    </span>

                @else

                    <span class="text-red-600 text-sm">
                        Нет в наличии
                    </span>

                @endif

            </div>

            <a
                href="/product/{{ $product->id }}"
                class="mt-5 block w-full text-center bg-slate-800 hover:bg-slate-700 text-white py-3 rounded-xl transition">

                Подробнее

            </a>

        </div>

    </div>

@endforeach

        </div>

    </section>

</div>

@endsection