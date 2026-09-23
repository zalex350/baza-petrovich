@extends('layouts.app')

@section('title', $category->name)

@section('content')

<div class="mb-10">

    <a
        href="{{ route('catalog.index') }}"
        class="text-sm text-slate-500 hover:text-blue-600"
    >
        ← Все категории
    </a>

    <h1 class="text-4xl font-bold text-slate-800 mt-4">
        {{ $category->name }}
    </h1>

    <p class="mt-2 text-slate-500">
        Найдено товаров: {{ $products->total() }}
    </p>

</div>

<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-8">

    @foreach($products as $product)

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-xl transition duration-300 overflow-hidden flex flex-col">

            <a href="{{ route('product.show', $product->id) }}">

                <div class="aspect-square bg-slate-100 flex items-center justify-center">

                    @if($product->image)

                        <img
                            src="{{ asset('storage/'.$product->image) }}"
                            alt="{{ $product->display_name ?: $product->name }}"
                            class="w-full h-full object-contain p-6">

                    @else

                        <div class="text-center">

                            <div class="text-6xl mb-4">
                                🚰
                            </div>

                            <div class="text-sm text-slate-500">
                                Фото появится позже
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

                <h2 class="text-lg font-semibold leading-6 min-h-[56px]">

                    {{ $product->display_name ?: $product->name }}

                </h2>

                @if($product->sku)

                    <div class="text-sm text-slate-400 mt-2">

                        Артикул: {{ $product->sku }}

                    </div>

                @endif

                <div class="mt-5">

                    @auth

                        <div class="text-3xl font-bold text-slate-800">

                            {{ number_format($product->price,2) }} BYN

                        </div>

                    @else

                        <div class="text-slate-500">

                            Цена доступна после входа

                        </div>

                    @endauth

                </div>

                <div class="mt-3">

                    @if($product->stock > 0)

                        <span class="inline-flex rounded-full bg-green-100 text-green-700 px-3 py-1 text-sm">

                            В наличии ({{ $product->stock }})

                        </span>

                    @else

                        <span class="inline-flex rounded-full bg-red-100 text-red-700 px-3 py-1 text-sm">

                            Нет в наличии

                        </span>

                    @endif

                </div>

                <div class="mt-auto pt-6">

                    <a
                        href="{{ route('product.show', $product->id) }}"
                        class="block w-full rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-center py-3 transition">

                        Подробнее

                    </a>

                </div>

            </div>

        </div>

    @endforeach

</div>

<div class="mt-8">
    {{ $products->links() }}
</div>

@endsection