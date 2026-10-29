{{--
    Единая форма товара — создание и редактирование (SRS §10.4).
    «Данные учётной системы (1С)» — сохраняются сразу; при shop.catalog.manual_products = false
    только для просмотра, сервер их не принимает. «Данные сайта» — тексты идут в черновик
    и попадают на сайт после публикации, фото применяются сразу.
    Поля связаны с <form id="product-form"> атрибутом form — поэтому формы блока фото
    (порядок, замена, удаление) стоят рядом, а не вложены.
    Данные: $product (новый — не сохранён), $categories, $draft (null у нового).
--}}
@extends('layouts.admin')

@php
    $isNew = ! $product->exists;
    $locked = ! config('shop.catalog.manual_products');
    $form = 'product-form';

    // Тексты сайта: введённое → черновик → опубликованное
    $site = fn (string $field) => old($field, $draft?->data[$field] ?? $product->{$field});

    // Ошибки фото показываются в блоке фото
    $formErrors = collect($errors->getMessages())->except('images')->flatten();

    $input = 'w-full border rounded px-3 py-2 disabled:bg-slate-50 disabled:text-slate-500';
@endphp

@section('title', $isNew ? 'Новый товар' : 'Редактирование товара')

@section('content')

<a
    href="/admin/products"
    class="text-sm text-slate-500 hover:text-blue-600"
>
    ← Назад к списку товаров
</a>

<h1 class="text-3xl font-bold mt-4 mb-6">
    {{ $isNew ? 'Новый товар' : 'Редактирование товара' }}
</h1>

@if($formErrors->isNotEmpty())

    <div class="bg-red-50 border border-red-200 rounded-2xl p-4 mb-6">
        <ul class="list-disc list-inside text-red-700 space-y-1">
            @foreach($formErrors as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>

@endif

<form
    id="{{ $form }}"
    method="POST"
    action="{{ $isNew ? '/admin/product' : "/admin/product/{$product->id}" }}"
    enctype="multipart/form-data"
>
    @csrf
</form>

{{-- Данные учётной системы --}}
<div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6" data-erp-block>

    <h2 class="text-lg font-semibold mb-1">
        Данные учётной системы (1С)
    </h2>

    <p class="text-sm text-slate-500 mb-4">
        @if($locked)
            Приходят из 1С и здесь не редактируются.
        @else
            Сохраняются сразу, без черновика. После подключения обмена с 1С будут приходить оттуда.
        @endif
    </p>

    <div class="grid gap-6 md:grid-cols-2">

        <div class="md:col-span-2">
            <label for="f-name" class="block mb-2 font-semibold">Название (системное)</label>
            <input id="f-name" type="text" name="name" form="{{ $form }}" value="{{ old('name', $product->name) }}" class="{{ $input }}" @disabled($locked) @unless($locked) required @endunless>
        </div>

        <div>
            <label for="f-sku" class="block mb-2 font-semibold">Артикул (SKU)</label>
            <input id="f-sku" type="text" name="sku" form="{{ $form }}" value="{{ old('sku', $product->sku) }}" class="{{ $input }}" @disabled($locked) @unless($locked) required @endunless>
        </div>

        <div>
            <label for="f-brand" class="block mb-2 font-semibold">Бренд</label>
            <input id="f-brand" type="text" name="brand" form="{{ $form }}" value="{{ old('brand', $product->brand) }}" class="{{ $input }}" @disabled($locked)>
        </div>

        <div>
            <label for="f-price" class="block mb-2 font-semibold">Цена без НДС, BYN</label>
            <input id="f-price" type="number" step="0.01" min="0" name="price" form="{{ $form }}" value="{{ old('price', $product->price) }}" class="{{ $input }}" @disabled($locked) @unless($locked) required @endunless>
        </div>

        <div>
            <label for="f-vat" class="block mb-2 font-semibold">Ставка НДС, %</label>
            @include('partials.vat_rate_select', ['current' => old('vat_rate', $product->vat_rate), 'form' => $form, 'disabled' => $locked, 'id' => 'f-vat'])
            @error('vat_rate')
                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="f-stock" class="block mb-2 font-semibold">Остаток</label>
            <input id="f-stock" type="number" min="0" name="stock" form="{{ $form }}" value="{{ old('stock', $product->stock) }}" class="{{ $input }}" @disabled($locked) @unless($locked) required @endunless>
            <label class="flex items-center gap-2 mt-2 text-sm">
                <input type="hidden" name="on_order" value="0" form="{{ $form }}" @disabled($locked)>
                <input type="checkbox" name="on_order" value="1" form="{{ $form }}" @checked(old('on_order', $product->on_order)) @disabled($locked)>
                Под заказ, когда остатка нет
            </label>
        </div>

        {{-- Кратность заказа: поле количества на витрине (x-qty) начинается с default_qty и шагает от min_qty на qty_step --}}
        <div class="md:col-span-2 grid gap-6 sm:grid-cols-3" data-qty-rules>
            <x-input name="min_qty" type="number" min="1" step="1" label="Минимум в заказе, шт." :value="$product->min_qty" :form="$form" :disabled="$locked" :required="! $locked" />
            <x-input name="qty_step" type="number" min="1" step="1" label="Шаг (кратность), шт." :value="$product->qty_step" :form="$form" :disabled="$locked" :required="! $locked" />
            <x-input name="default_qty" type="number" min="1" step="1" label="По умолчанию, шт." hint="Минимум, минимум + шаг, …" :value="$product->default_qty" :form="$form" :disabled="$locked" :required="! $locked" />
        </div>

    </div>

</div>

{{-- Данные сайта --}}
<div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6" data-site-block>

    <h2 class="text-lg font-semibold mb-1">
        Данные сайта
    </h2>

    <p class="text-sm text-slate-500 mb-4">
        @if($isNew)
            Новый товар сохраняется как черновик и не отображается на сайте до публикации.
        @else
            Тексты сохраняются в черновик и попадают на сайт после публикации. Категория и адрес страницы — сразу.
        @endif
    </p>

    @if($draft)
        <p class="text-sm text-amber-600 mb-4">
            Есть неопубликованный черновик — значения ниже показывают черновую версию.
        </p>
    @endif

    <div class="space-y-6">

        <div>
            <label for="f-category" class="block mb-2 font-semibold">Категория</label>
            <select id="f-category" name="category_id" form="{{ $form }}" class="{{ $input }}">
                <option value="">— не выбрана —</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}" @selected(old('category_id', $product->category_id) == $category->id)>
                        {{ $category->name }}
                    </option>
                @endforeach
            </select>
            <p class="text-sm text-slate-500 mt-1">Раздел каталога на сайте — задаётся здесь, из 1С не приходит.</p>
        </div>

        <fieldset data-product-badges>
            <legend class="mb-2 font-semibold">Бейджи на витрине</legend>
            <p class="text-sm text-slate-500 mb-3">Сохраняются сразу, без черновика.</p>
            <div class="flex flex-wrap gap-6">
                <x-checkbox name="is_hit" label="ХИТ" :checked="$product->is_hit" :form="$form" />
                <x-checkbox name="is_new" label="НОВИНКА" :checked="$product->is_new" :form="$form" />
                <x-checkbox name="is_promo" label="Акция (бейдж «%», страница «Акции»)" :checked="$product->is_promo" :form="$form" />
            </div>
        </fieldset>

        <div>
            <label for="f-display-name" class="block mb-2 font-semibold">
                Отображаемое название
                <span class="text-slate-400 font-normal text-sm">(если задано — показывается на сайте вместо системного)</span>
            </label>
            <input id="f-display-name" type="text" name="display_name" form="{{ $form }}" value="{{ $site('display_name') }}" class="{{ $input }}">
        </div>

        <div>
            <label for="f-slug" class="block mb-2 font-semibold">Адрес страницы (slug)</label>
            <input id="f-slug" type="text" name="slug" form="{{ $form }}" value="{{ old('slug', $product->slug) }}" class="{{ $input }}">
        </div>

        <div>
            <label for="f-description" class="block mb-2 font-semibold">Описание</label>

            {{-- Сгенерированное (импорт каталога): на сайте не показывается, пока не одобрено --}}
            @if($product->description_generated)
                <div class="mb-2 flex flex-wrap items-center gap-3" data-description-generated>
                    <x-badge tone="warning">Описание сгенерировано — на сайте не показывается</x-badge>
                    <form method="POST" action="/admin/product/{{ $product->id }}/approve-description">
                        @csrf
                        <x-button variant="secondary" size="sm">Одобрить описание</x-button>
                    </form>
                </div>
            @endif

            <textarea id="f-description" name="description" form="{{ $form }}" rows="5" class="{{ $input }}">{{ $site('description') }}</textarea>
        </div>

        <div>
            <label for="f-specifications" class="block mb-2 font-semibold">
                Дополнительная информация
                <span class="text-slate-400 font-normal text-sm">(свободный текст под таблицей характеристик)</span>
            </label>
            <textarea id="f-specifications" name="specifications" form="{{ $form }}" rows="5" class="{{ $input }}">{{ $site('specifications') }}</textarea>
        </div>

        <div>
            @php
                // Раздел «Комплектация» на сайте: это поле, а если оно пусто — характеристика «Комплектация»
                $packageField = trim((string) $site('package_contents'));
                $packageAttribute = trim((string) (collect($attributeRows)->first(fn ($row) => \App\Models\Product::isPackageAttribute($row['name'] ?? ''))['value'] ?? ''));
            @endphp
            <label for="f-package" class="block mb-2 font-semibold">Комплектация</label>
            <textarea id="f-package" name="package_contents" form="{{ $form }}" rows="5" class="{{ $input }}">{{ $site('package_contents') }}</textarea>

            <div class="mt-2 text-sm text-slate-500" data-package-source="{{ $packageField !== '' ? 'field' : ($packageAttribute !== '' ? 'attribute' : 'none') }}">
                @if($packageField !== '')
                    На сайте — это поле.
                    @if($packageAttribute !== '')
                        Характеристика «Комплектация» (из импорта) не показывается.
                    @endif
                @elseif($packageAttribute !== '')
                    Поле пусто — на сайте показывается характеристика «Комплектация» (из импорта):
                    <div class="mt-1 rounded border border-slate-200 bg-slate-50 p-2 whitespace-pre-line break-words text-slate-700" data-package-attribute>{{ $packageAttribute }}</div>
                @else
                    Пусто — раздел на сайте не показывается.
                @endif
            </div>
        </div>

        @if($isNew)

            <label class="flex items-center gap-3">
                <input type="checkbox" name="is_active" value="1" form="{{ $form }}" @checked(old('is_active', true))>
                Показывать на сайте после публикации
            </label>

            <div>
                <h3 class="font-semibold mb-1">Фото</h3>
                <p class="text-sm text-slate-500 mb-3">
                    JPG, PNG или WebP, до {{ (int) round(config('shop.images.max_kb') / 1024) }} МБ на файл.
                    Загрузятся при сохранении товара, первое станет основным.
                </p>
                @error('images')
                    <p class="text-red-600 text-sm mb-2">{{ $message }}</p>
                @enderror
                @include('partials.image_queue', ['form' => $form])
            </div>

        @endif

    </div>

</div>

@include('partials.admin_product_attributes', ['attributeRows' => $attributeRows, 'form' => $form])

@unless($isNew)
    @include('partials.admin_product_images', ['product' => $product, 'form' => $form])
@endunless

{{-- Сохранение и публикация --}}
<div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6" data-product-actions>

    <h2 class="text-lg font-semibold mb-4">
        Публикация
    </h2>

    <div class="flex flex-wrap items-center gap-3 mb-4">

        <button type="submit" form="{{ $form }}" name="action" value="draft" class="bg-slate-800 hover:bg-slate-700 text-white px-5 py-2.5 rounded">
            Сохранить черновик
        </button>

        <button type="submit" form="{{ $form }}" name="action" value="preview" class="border border-slate-300 hover:bg-slate-100 text-slate-700 px-5 py-2.5 rounded">
            Сохранить и предпросмотр
        </button>

        <button type="submit" form="{{ $form }}" name="action" value="publish" class="bg-green-700 hover:bg-green-600 text-white px-5 py-2.5 rounded">
            Опубликовать
        </button>

    </div>

    @unless($isNew)

        <div class="flex flex-wrap items-center gap-4 border-t border-slate-200 pt-4">

            <span class="text-sm text-slate-400">
                @if(! $product->is_published)
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
                <button type="submit" class="border border-slate-300 hover:bg-slate-100 text-slate-700 px-4 py-2 rounded">
                    {{ $product->is_active ? 'Скрыть товар' : 'Показать товар' }}
                </button>
            </form>

            @if($draft)
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
            @endif

        </div>

    @endunless

</div>

@unless($isNew)

    {{-- Опасная зона --}}
    <form method="POST" action="/admin/product/{{ $product->id }}/delete" class="mt-6">
        @csrf
        <button
            type="submit"
            class="text-red-600 hover:text-red-700 text-sm underline"
            onclick="return confirm('Удалить товар «{{ addslashes($product->name) }}»? Это действие необратимо.')"
        >
            Удалить товар
        </button>
    </form>

@endunless

@endsection
