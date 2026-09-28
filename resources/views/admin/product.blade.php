@extends('layouts.admin')

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

@if($errors->any())

    <div class="bg-red-50 border border-red-200 rounded-2xl p-4 mb-6">
        <ul class="list-disc list-inside text-red-700 space-y-1">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>

@endif

{{-- Основные поля (применяются сразу) --}}
<div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6">

    <h2 class="text-lg font-semibold mb-4">
        Основные данные
        <span class="text-sm font-normal text-slate-400 ml-2">сохраняются сразу</span>
    </h2>

    <form
        method="POST"
        action="/admin/product/{{ $product->id }}"
        class="space-y-6"
    >

        @csrf

        <!-- Name -->
        <div>
            <label class="block mb-2 font-semibold">Название (системное)</label>
            <input
                type="text"
                name="name"
                value="{{ old('name', $product->name) }}"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <!-- SKU -->
        <div>
            <label class="block mb-2 font-semibold">SKU</label>
            <input
                type="text"
                name="sku"
                value="{{ old('sku', $product->sku) }}"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <!-- Brand -->
        <div>
            <label class="block mb-2 font-semibold">Бренд</label>
            <input
                type="text"
                name="brand"
                value="{{ old('brand', $product->brand) }}"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <!-- Slug -->
        <div>
            <label class="block mb-2 font-semibold">Slug</label>
            <input
                type="text"
                name="slug"
                value="{{ old('slug', $product->slug) }}"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <!-- Price -->
        <div>
            <label class="block mb-2 font-semibold">Цена</label>
            <input
                type="number"
                step="0.01"
                name="price"
                value="{{ old('price', $product->price) }}"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <!-- VAT rate -->
        <div>

            <label class="block mb-2 font-semibold">
                Ставка НДС, %
            </label>

            <input
                type="number"
                step="0.01"
                min="0"
                max="100"
                name="vat_rate"
                value="{{ old('vat_rate', $product->vat_rate) }}"
                class="w-full border rounded px-3 py-2"
                required
            >

            @error('vat_rate')
                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
            @enderror

        </div>

        <!-- Stock -->
        <div>
            <label class="block mb-2 font-semibold">Остаток</label>
            <input
                type="number"
                name="stock"
                value="{{ old('stock', $product->stock) }}"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <!-- Category -->
        <div>
            <label class="block mb-2 font-semibold">Категория</label>
            <select name="category_id" class="w-full border rounded px-3 py-2">
                <option value="">— не выбрана —</option>
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

        <!-- Submit -->
        <div>
            <button
                type="submit"
                class="bg-slate-800 hover:bg-slate-700 text-white px-6 py-3 rounded"
            >
                Сохранить
            </button>
        </div>

    </form>

</div>

{{-- Текстовые поля черновика --}}
<div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6">

    <h2 class="text-lg font-semibold mb-1">
        Текстовый контент
        <span class="text-sm font-normal text-slate-400 ml-2">сохраняется в черновик, на сайт — только после публикации</span>
    </h2>

    @if($draft)

        <p class="text-sm text-amber-600 mb-4">
            Есть несохранённый черновик — значения ниже показывают черновую версию.
        </p>

    @endif

    <form
        method="POST"
        action="/admin/product/{{ $product->id }}/draft"
        class="space-y-6"
    >

        @csrf

        <!-- Display Name -->
        <div>
            <label class="block mb-2 font-semibold">
                Отображаемое название
                <span class="text-slate-400 font-normal text-sm">(если задано — показывается на сайте вместо системного)</span>
            </label>
            <input
                type="text"
                name="display_name"
                value="{{ old('display_name', $draft?->data['display_name'] ?? $product->display_name) }}"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <!-- Description -->
        <div>
            <label class="block mb-2 font-semibold">Описание</label>
            <textarea
                name="description"
                rows="5"
                class="w-full border rounded px-3 py-2"
            >{{ old('description', $draft?->data['description'] ?? $product->description) }}</textarea>
        </div>

        <!-- Specifications -->
        <div>
            <label class="block mb-2 font-semibold">Характеристики</label>
            <textarea
                name="specifications"
                rows="5"
                class="w-full border rounded px-3 py-2"
            >{{ old('specifications', $draft?->data['specifications'] ?? $product->specifications) }}</textarea>
        </div>

        <!-- Package -->
        <div>
            <label class="block mb-2 font-semibold">Комплектация</label>
            <textarea
                name="package_contents"
                rows="5"
                class="w-full border rounded px-3 py-2"
            >{{ old('package_contents', $draft?->data['package_contents'] ?? $product->package_contents) }}</textarea>
        </div>

        <!-- Save draft -->
        <div>
            <button
                type="submit"
                class="bg-slate-800 hover:bg-slate-700 text-white px-6 py-3 rounded"
            >
                Сохранить черновик
            </button>
        </div>

    </form>

</div>

{{-- Действия публикации --}}
<div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6">

    <h2 class="text-lg font-semibold mb-4">
        Публикация
    </h2>

    <div class="flex flex-wrap items-center gap-4">

        {{-- Предпросмотр --}}
        <a
            href="/admin/product/{{ $product->id }}/preview"
            target="_blank"
            class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded"
        >
            Предпросмотр ↗
        </a>

        @if($draft)

            {{-- Опубликовать черновик --}}
            <form method="POST" action="/admin/product/{{ $product->id }}/publish" class="inline">
                @csrf
                <button
                    type="submit"
                    class="bg-green-700 hover:bg-green-600 text-white px-4 py-2 rounded"
                >
                    Опубликовать черновик
                </button>
            </form>

            {{-- Удалить черновик --}}
            <form method="POST" action="/admin/product/{{ $product->id }}/draft/delete" class="inline">
                @csrf
                <button
                    type="submit"
                    class="text-amber-600 hover:text-amber-700 text-sm underline"
                    onclick="return confirm('Удалить черновик? Несохранённые изменения будут потеряны.')"
                >
                    Удалить черновик
                </button>
            </form>

        @else

            {{-- Публикация без черновика --}}
            @if(!$product->is_published)

                <form method="POST" action="/admin/product/{{ $product->id }}/publish" class="inline">
                    @csrf
                    <button
                        type="submit"
                        class="bg-green-700 hover:bg-green-600 text-white px-4 py-2 rounded"
                    >
                        Опубликовать
                    </button>
                </form>

            @endif

        @endif

        <span class="text-sm text-slate-400">
            @if(!$product->is_published)
                Статус: <strong class="text-slate-600">Черновик</strong>
                @unless($product->is_active)
                    <span class="text-amber-600">· после публикации будет скрыт</span>
                @endunless
            @elseif($product->is_active)
                Статус: <strong class="text-green-700">Опубликован</strong>
            @else
                Статус: <strong class="text-amber-600">Скрыт</strong>
            @endif
        </span>

        {{-- Видимость на сайте (is_active) --}}
        <form method="POST" action="/admin/product/{{ $product->id }}/toggle-visibility" class="inline">
            @csrf
            @if($product->is_active)
                <button
                    type="submit"
                    class="border border-slate-300 hover:bg-slate-100 text-slate-700 px-4 py-2 rounded"
                >
                    Скрыть товар
                </button>
            @else
                <button
                    type="submit"
                    class="border border-slate-300 hover:bg-slate-100 text-slate-700 px-4 py-2 rounded"
                >
                    Показать товар
                </button>
            @endif
        </form>

    </div>

</div>

{{-- Опасная зона --}}
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
