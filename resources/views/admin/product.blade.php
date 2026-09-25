@extends('layouts.app')

@section('title', 'Редактирование товара')

@section('content')

<a
    href="/admin/products"
    class="text-sm text-slate-500 hover:text-blue-600"
>
    ← Назад к списку товаров
</a>

<h1 class="text-3xl font-bold mt-4 mb-6">
    Редактирование товара
</h1>

<!-- Visibility -->
<div class="bg-white border rounded p-4 mb-6 flex items-center justify-between gap-4">

    <div class="flex items-center gap-3">

        @if($product->is_active)

            <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                <span class="w-2 h-2 rounded-full bg-green-500"></span>
                Виден на сайте
            </span>

        @else

            <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-sm font-medium bg-slate-200 text-slate-700">
                <span class="w-2 h-2 rounded-full bg-slate-500"></span>
                Скрыт с сайта
            </span>

        @endif

    </div>

    <form
        method="POST"
        action="/admin/product/{{ $product->id }}/toggle-visibility"
    >

        @csrf

        @if($product->is_active)

            <button
                type="submit"
                class="border border-slate-300 hover:bg-slate-100 text-slate-800 px-4 py-2 rounded"
            >
                Скрыть товар
            </button>

        @else

            <button
                type="submit"
                class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded"
            >
                Показать товар
            </button>

        @endif

    </form>

</div>

<div class="bg-white border rounded p-6">

    <form
        method="POST"
        action="/admin/product/{{ $product->id }}"
        class="space-y-6"
    >

        @csrf

        <!-- Name -->
        <div>

            <label class="block mb-2 font-semibold">
                Название
            </label>

            <input
                type="text"
                name="name"
                value="{{ old('name', $product->name) }}"
                class="w-full border rounded px-3 py-2"
            >

        </div>

        <!-- SKU -->
        <div>

            <label class="block mb-2 font-semibold">
                SKU
            </label>

            <input
                type="text"
                name="sku"
                value="{{ old('sku', $product->sku) }}"
                class="w-full border rounded px-3 py-2"
            >

        </div>

        <!-- Brand -->
        <div>

            <label class="block mb-2 font-semibold">
                Бренд
            </label>

            <input
                type="text"
                name="brand"
                value="{{ old('brand', $product->brand) }}"
                class="w-full border rounded px-3 py-2"
            >

        </div>

        <!-- Slug -->
        <div>

            <label class="block mb-2 font-semibold">
                Slug
            </label>

            <input
                type="text"
                name="slug"
                value="{{ old('slug', $product->slug) }}"
                class="w-full border rounded px-3 py-2"
            >

        </div>

        <!-- Price -->
        <div>

            <label class="block mb-2 font-semibold">
                Цена
            </label>

            <input
                type="number"
                step="0.01"
                name="price"
                value="{{ old('price', $product->price) }}"
                class="w-full border rounded px-3 py-2"
            >

        </div>

        <!-- Stock -->
        <div>

            <label class="block mb-2 font-semibold">
                Остаток
            </label>

            <input
                type="number"
                name="stock"
                value="{{ old('stock', $product->stock) }}"
                class="w-full border rounded px-3 py-2"
            >

        </div>

        <!-- Category -->
        <div>

            <label class="block mb-2 font-semibold">
                Категория
            </label>

            <select
                name="category_id"
                class="w-full border rounded px-3 py-2"
            >

                @foreach($categories as $category)

                    <option
                        value="{{ $category->id }}"
                        @selected($product->category_id == $category->id)
                    >
                        {{ $category->name }}
                    </option>

                @endforeach

            </select>

        </div>

        <!-- Description -->
        <div>

            <label class="block mb-2 font-semibold">
                Описание
            </label>

            <textarea
                name="description"
                rows="5"
                class="w-full border rounded px-3 py-2"
            >{{ old('description', $product->description) }}</textarea>

        </div>

        <!-- Specifications -->
        <div>

            <label class="block mb-2 font-semibold">
                Характеристики
            </label>

            <textarea
                name="specifications"
                rows="5"
                class="w-full border rounded px-3 py-2"
            >{{ old('specifications', $product->specifications) }}</textarea>

        </div>

        <!-- Package -->
        <div>

            <label class="block mb-2 font-semibold">
                Комплектация
            </label>

            <textarea
                name="package_contents"
                rows="5"
                class="w-full border rounded px-3 py-2"
            >{{ old('package_contents', $product->package_contents) }}</textarea>

        </div>

        <!-- Submit -->
        <div>

            <button
                type="submit"
                class="bg-black text-white px-6 py-3 rounded"
            >
                Сохранить
            </button>

        </div>

    </form>

</div>

<div class="mt-6">

    <form
        method="POST"
        action="/admin/product/{{ $product->id }}/delete"
        class="inline"
    >

        @csrf

        <button
            type="submit"
            class="text-red-600 hover:text-red-700 text-sm underline"
            onclick="return confirm('Удалить товар «{{ addslashes($product->name) }}»? Это действие необратимо.')"
        >
            Удалить товар
        </button>

    </form>

</div>

@endsection