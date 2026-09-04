@extends('layouts.app')

@section('title', 'Редактирование товара')

@section('content')

<h1 class="text-3xl font-bold mb-6">
    Редактирование товара
</h1>

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

        <!-- Active -->
        <div class="flex items-center gap-3">

            <input
                type="checkbox"
                name="is_active"
                value="1"
                @checked($product->is_active)
            >

            <label>
                Активен
            </label>

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

@endsection