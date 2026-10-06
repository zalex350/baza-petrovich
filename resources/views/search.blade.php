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

    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">

        <table class="w-full text-left">

            <thead class="bg-slate-50 border-b border-slate-200">

                <tr>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                        Фото
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                        Товар
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                        Артикул
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                        Бренд
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600 text-right">
                        Цена
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                        Наличие
                    </th>

                    <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                    </th>

                </tr>

            </thead>

            <tbody class="divide-y divide-slate-200">

                @foreach($products as $product)

                    <tr>

                        <td class="px-4 py-3">

                            @if($product->image)

                                <img
                                    src="{{ asset('storage/'.$product->image) }}"
                                    alt="{{ $product->display_name ?: $product->name }}"
                                    class="w-12 h-12 object-contain rounded border border-slate-200">

                            @else

                                <div class="w-12 h-12 bg-slate-100 flex items-center justify-center rounded border border-slate-200 text-2xl">
                                    🚰
                                </div>

                            @endif

                        </td>

                        <td class="px-4 py-3">

                            <a
                                href="{{ route('product.show', $product->id) }}"
                                class="font-medium text-slate-800 hover:text-blue-600 transition"
                            >
                                {{ $product->display_name ?: $product->name }}
                            </a>

                        </td>

                        <td class="px-4 py-3 text-sm text-slate-400">
                            {{ $product->sku }}
                        </td>

                        <td class="px-4 py-3 text-sm text-slate-500">
                            {{ $product->brand }}
                        </td>

                        <td class="px-4 py-3 text-right">

                            @auth

                                <span class="font-semibold text-slate-800">
                                    {{ number_format($product->price, 2) }} BYN
                                </span>

                                <span class="block text-xs text-slate-500">
                                    (с НДС: {{ number_format($product->price_with_vat, 2) }} BYN)
                                </span>

                            @else

                                <span class="text-sm text-slate-500">
                                    Цена доступна после входа
                                </span>

                            @endauth

                        </td>

                        <td class="px-4 py-3">

                            @if($product->stock > 0)

                                <span class="inline-flex rounded-full bg-green-100 text-green-700 px-3 py-1 text-sm">
                                    В наличии ({{ $product->stock }})
                                </span>

                            @else

                                <span class="inline-flex rounded-full bg-red-100 text-red-700 px-3 py-1 text-sm">
                                    Нет в наличии
                                </span>

                            @endif

                        </td>

                        <td class="px-4 py-3 w-60">

                            <a
                                href="{{ route('product.show', $product->id) }}"
                                class="block rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-50 text-center px-3 py-2 text-sm transition"
                            >
                                Подробнее
                            </a>

                            @include('partials.product_buy', ['product' => $product, 'compact' => true])

                        </td>

                    </tr>

                @endforeach

            </tbody>

        </table>

    </div>

@endif

@endsection
