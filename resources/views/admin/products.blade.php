@extends('layouts.admin')

@section('title', 'Админка — Товары')

@section('content')

<div class="flex items-center justify-between mb-6">

    <h1 class="text-3xl font-bold">
        Админка — Товары
    </h1>

    @if(config('shop.catalog.manual_products'))
        <a
            href="/admin/product/create"
            class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded"
        >
            Добавить товар
        </a>
    @else
        <span class="text-sm text-slate-500">Товары приходят из 1С</span>
    @endif

</div>

{{-- Фильтр: товары без категории (служебная «Без категории» скрыта на сайте) --}}
<div class="mb-4 flex flex-wrap items-center gap-2 text-sm" data-admin-product-filter>
    @if($uncategorized)
        <x-badge tone="warning" size="md">Без категории: {{ $products->total() }}</x-badge>
        <x-button variant="link" size="sm" href="/admin/products">Показать все</x-button>
    @elseif($uncategorizedCount)
        <x-button variant="secondary" size="sm" href="/admin/products?category=none">Без категории ({{ $uncategorizedCount }})</x-button>
    @endif
</div>

<div class="bg-white border rounded overflow-hidden">

    <table class="w-full border-collapse">

        <thead class="bg-gray-100">

            <tr>

                <th class="border p-3 text-left">
                    ID
                </th>

                <th class="border p-3 text-left">
                    Название
                </th>

                <th class="border p-3 text-left">
                    SKU
                </th>

                <th class="border p-3 text-left">
                    Цена
                </th>

                <th class="border p-3 text-left">
                    Остаток
                </th>

                <th class="border p-3 text-left">
                    Статус
                </th>

                <th class="border p-3 text-left">
                    Действия
                </th>

            </tr>

        </thead>

        <tbody>

            @foreach($products as $product)

                <tr class="hover:bg-gray-50">

                    <td class="border p-3">
                        {{ $product->id }}
                    </td>

                    <td class="border p-3">
                        {{ $product->name }}
                    </td>

                    <td class="border p-3">
                        {{ $product->sku }}
                    </td>

                    <td class="border p-3">
                        {{ $product->price }} BYN
                    </td>

                    <td class="border p-3">
                        {{ $product->stock }}
                    </td>

                    <td class="border p-3">

                        @if(!$product->is_published)

                            <span class="inline-flex rounded-full bg-slate-100 text-slate-600 px-2 py-0.5 text-xs">
                                Черновик
                            </span>

                        @elseif($product->is_active)

                            <span class="inline-flex rounded-full bg-green-100 text-green-700 px-2 py-0.5 text-xs">
                                Опубликован
                            </span>

                        @else

                            <span class="inline-flex rounded-full bg-amber-100 text-amber-700 px-2 py-0.5 text-xs">
                                Скрыт
                            </span>

                        @endif

                        @if($product->draft)

                            <span class="inline-flex rounded-full bg-blue-100 text-blue-600 px-2 py-0.5 text-xs ml-1">
                                есть черновик
                            </span>

                        @endif

                    </td>

                    <td class="border p-3">

                        <div class="flex items-center gap-3">

                            <a
                                href="/admin/product/{{ $product->id }}"
                                class="underline"
                            >
                                Edit
                            </a>

                            <form
                                method="POST"
                                action="/admin/product/{{ $product->id }}/toggle-visibility"
                                class="inline"
                            >

                                @csrf

                                <button
                                    type="submit"
                                    class="text-slate-700 hover:text-slate-900 text-sm underline"
                                >
                                    {{ $product->is_active ? 'Скрыть' : 'Показать' }}
                                </button>

                            </form>

                            <form
                                method="POST"
                                action="/admin/product/{{ $product->id }}/delete"
                                class="inline"
                            >

                                @csrf

                                <button
                                    type="submit"
                                    class="text-red-600 hover:text-red-700 text-sm underline"
                                    onclick="return confirm('Удалить товар «{{ addslashes($product->name) }}»?')"
                                >
                                    Удалить
                                </button>

                            </form>

                        </div>

                    </td>

                </tr>

            @endforeach

        </tbody>

    </table>

</div>

<div class="mt-8">
    <x-pagination :paginator="$products" />
</div>

@endsection