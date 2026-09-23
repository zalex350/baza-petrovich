@extends('layouts.app')

@section('title', 'Новый товар')

@section('content')

<div class="flex items-center justify-between mb-6">

    <h1 class="text-3xl font-bold">
        Новый товар
    </h1>

    <a
        href="/admin/products"
        class="text-slate-600 underline"
    >
        ← Назад к списку товаров
    </a>

</div>

@if($errors->any())

    <div class="bg-red-50 border border-red-200 rounded-2xl p-4 mb-6">

        <ul class="list-disc list-inside text-red-700 space-y-1">

            @foreach($errors->all() as $error)

                <li>{{ $error }}</li>

            @endforeach

        </ul>

    </div>

@endif

<div class="bg-white rounded-2xl border border-slate-200 p-6">

    <form
        method="POST"
        action="/admin/product"
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
                value="{{ old('name') }}"
                class="w-full border rounded px-3 py-2"
                required
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
                value="{{ old('sku') }}"
                class="w-full border rounded px-3 py-2"
                required
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
                value="{{ old('brand') }}"
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
                value="{{ old('slug') }}"
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
                value="{{ old('price', 0) }}"
                class="w-full border rounded px-3 py-2"
                required
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
                value="{{ old('stock', 0) }}"
                class="w-full border rounded px-3 py-2"
                required
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

                <option value="">— не выбрана —</option>

                @foreach($categories as $category)

                    <option
                        value="{{ $category->id }}"
                        @selected(old('category_id') == $category->id)
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
            >{{ old('description') }}</textarea>

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
            >{{ old('specifications') }}</textarea>

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
            >{{ old('package_contents') }}</textarea>

        </div>

        <!-- Active -->
        <div class="flex items-center gap-3">

            <input
                type="checkbox"
                name="is_active"
                value="1"
                @checked(old('is_active'))
            >

            <label>
                Активен
            </label>

        </div>

        <p class="text-sm text-slate-500">
            Новый товар сохраняется как черновик и не отображается на сайте до публикации.
        </p>

        <!-- Submit -->
        <div>

            <button
                type="submit"
                class="bg-slate-800 hover:bg-slate-700 text-white px-6 py-3 rounded"
            >
                Создать товар
            </button>

        </div>

    </form>

</div>

@endsection
