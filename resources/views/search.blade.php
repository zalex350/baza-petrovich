@extends('layouts.app')

@section('title', 'Поиск')

@section('content')

<div class="mb-10">

    <h1 class="text-4xl font-bold text-slate-800">
        Результаты поиска по запросу: {{ $query }}
    </h1>

    <p class="mt-2 text-slate-500">
        Найдено товаров: {{ $products->count() }}
    </p>

</div>

@if($products->isEmpty())

    <div class="bg-white rounded-2xl border border-slate-200 p-12 text-center">

        <p class="text-xl text-slate-600">
            По вашему запросу ничего не найдено.
        </p>

    </div>

@else

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-8">

        @foreach($products as $product)

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-xl transition duration-300 overflow-hidden flex flex-col">

                <a href="{{ route('product.show', $product->id) }}">

                    <div class="aspect-square bg-slate-100 flex items-center justify-center">

                        @if($product->image)

                            <img
                                src="{{ asset('storage/'.$product->image) }}"
                                alt="{{ $product->name }}"
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

                        <a href="{{ route('product.show', $product->id) }}" class="hover:text-blue-600 transition">
                            {{ $product->name }}
                        </a>

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

                        @auth

                            <form action="/order/add/{{ $product->id }}" method="POST">

                                @csrf

                                <button
                                    type="submit"
                                    class="block w-full rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-center py-3 transition">

                                    В корзину

                                </button>

                            </form>

                        @else

                            <a
                                href="{{ route('product.show', $product->id) }}"
                                class="block w-full rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-center py-3 transition">

                                Подробнее

                            </a>

                        @endauth

                    </div>

                </div>

            </div>

        @endforeach

    </div>

@endif

@endsection
