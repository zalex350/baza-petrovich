{{--
    Единая форма товара — создание и редактирование (SRS §10.4).
    «Данные учётной системы (1С)» — сохраняются сразу; при shop.catalog.manual_products = false
    только для просмотра, сервер их не принимает. «Данные сайта» — тексты идут в черновик
    и попадают на сайт после публикации, фото применяются сразу. Бейджи у существующего товара —
    сразу по галочке (fetch, admin-product-badges.js), в форму не входят; у нового — с товаром.
    Проверка — только серверная (novalidate): ошибки у полей, сверху alert «Проверьте выделенные поля».
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
@endphp

@section('title', $isNew ? 'Новый товар' : 'Редактирование товара')

{{-- Ошибки — у полей (кит), характеристик и фото — в их блоках; сверху один alert (F-7) --}}
@section('flash-validation', 'summary')

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

{{-- novalidate: проверку делает сервер, ошибки — текстом у полей, без подсказок браузера (F-7) --}}
<form
    id="{{ $form }}"
    method="POST"
    action="{{ $isNew ? '/admin/product' : "/admin/product/{$product->id}" }}"
    enctype="multipart/form-data"
    novalidate
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
            Сохраняются любой кнопкой внизу сразу, без черновика. После подключения обмена с 1С будут приходить оттуда.
        @endif
    </p>

    <div class="grid gap-6 md:grid-cols-2">

        <x-input name="name" label="Название (системное)" :value="$product->name" :form="$form" :disabled="$locked" :required="! $locked" class="md:col-span-2" />

        <x-input name="sku" label="Артикул (SKU)" :value="$product->sku" :form="$form" :disabled="$locked" :required="! $locked" />

        <x-input name="brand" label="Бренд" :value="$product->brand" :form="$form" :disabled="$locked" />

        {{-- Цена нового товара — обязательна и больше 0 при любой кнопке (F-6). У товара без цены
             (из импорта, цена придёт из 1С) поле не обязательно (AdminProductController::erpRules) --}}
        @php
            $priceOptional = ! $isNew && $product->price === null;
        @endphp
        <x-input
            name="price"
            type="number"
            step="0.01"
            :min="$isNew ? '0.01' : '0'"
            label="Цена без НДС, BYN"
            :value="$product->price"
            :hint="$priceOptional ? 'Цены нет — на сайте «Цена уточняется», заказать товар нельзя. Можно сохранить без цены.' : ($isNew ? 'Обязательна, больше 0.' : null)"
            :form="$form"
            :disabled="$locked"
            :required="! $locked && ! $priceOptional"
        />

        <x-field id="f-vat" label="Ставка НДС, %" error="vat_rate" :required="! $locked">
            @include('partials.vat_rate_select', ['current' => old('vat_rate', $product->vat_rate), 'form' => $form, 'disabled' => $locked, 'id' => 'f-vat'])
        </x-field>

        <div class="space-y-2">
            <x-input name="stock" type="number" min="0" step="1" label="Остаток" :value="$product->stock" :form="$form" :disabled="$locked" :required="! $locked" />
            <x-checkbox name="on_order" label="Под заказ, когда остатка нет" :checked="$product->on_order" :form="$form" :disabled="$locked" />
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
            Тексты и характеристики сохраняются кнопками внизу в черновик и попадают на сайт после публикации.
            Категория и адрес страницы — теми же кнопками, сразу. Бейджи — сразу по галочке.
        @endif
    </p>

    @if($draft)
        <p class="text-sm text-amber-600 mb-4">
            Есть неопубликованный черновик — значения ниже показывают черновую версию.
        </p>
    @endif

    <div class="space-y-6">

        <x-select
            name="category_id"
            id="f-category"
            label="Категория"
            :options="$categories->pluck('name', 'id')"
            :value="$product->category_id"
            placeholder="— не выбрана —"
            hint="Раздел каталога на сайте — задаётся здесь, из 1С не приходит. Сохраняется кнопками внизу, без черновика."
            :form="$form"
        />

        {{-- Бейджи: у нового товара — вместе с товаром (кнопки внизу); у существующего — сразу
             по галочке (admin-product-badges.js), в форму товара не входят --}}
        @if($isNew)
            <fieldset data-product-badges>
                <legend class="mb-2 font-semibold">Бейджи на витрине</legend>
                <p class="text-sm text-slate-500 mb-3">Сохраняются вместе с товаром.</p>
                <div class="flex flex-wrap gap-6">
                    <x-checkbox name="is_hit" label="ХИТ" :form="$form" />
                    <x-checkbox name="is_new" label="НОВИНКА" :form="$form" />
                    <x-checkbox name="is_promo" label="Акция (бейдж «%», страница «Акции»)" :form="$form" />
                </div>
            </fieldset>
        @else
            <fieldset
                data-product-badges
                x-data="productBadges('/admin/product/{{ $product->id }}/badge')"
                data-csrf="{{ csrf_token() }}"
            >
                <legend class="mb-2 font-semibold">Бейджи на витрине</legend>
                <p class="text-sm text-slate-500 mb-3">
                    Сохраняются сразу при отметке галочки — кнопки внизу для них не нужны.
                    <span x-show="status" x-cloak x-text="status" :class="statusError ? 'text-red-600' : 'text-green-700'" class="ml-2 font-medium" role="status" data-badges-status></span>
                </p>
                <div class="flex flex-wrap gap-6">
                    <x-checkbox name="is_hit" label="ХИТ" :checked="$product->is_hit" :unchecked="false" x-on:change="save($event.target)" />
                    <x-checkbox name="is_new" label="НОВИНКА" :checked="$product->is_new" :unchecked="false" x-on:change="save($event.target)" />
                    <x-checkbox name="is_promo" label="Акция (бейдж «%», страница «Акции»)" :checked="$product->is_promo" :unchecked="false" x-on:change="save($event.target)" />
                </div>
            </fieldset>
        @endif

        <x-input name="display_name" label="Отображаемое название" hint="Если задано — показывается на сайте вместо системного." :value="$site('display_name')" :form="$form" />

        <x-input name="slug" label="Адрес страницы (slug)" hint="Сохраняется кнопками внизу, без черновика." :value="$product->slug" :form="$form" />

        <div>
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

            <x-textarea name="description" label="Описание" rows="5" :value="$site('description')" :form="$form" />
        </div>

        <x-textarea name="specifications" label="Дополнительная информация" hint="Свободный текст под таблицей характеристик." rows="5" :value="$site('specifications')" :form="$form" />

        <div>
            @php
                // Раздел «Комплектация» на сайте: это поле, а если оно пусто — характеристика «Комплектация»
                $packageField = trim((string) $site('package_contents'));
                $packageAttribute = trim((string) (collect($attributeRows)->first(fn ($row) => \App\Models\Product::isPackageAttribute($row['name'] ?? ''))['value'] ?? ''));
            @endphp
            <x-textarea name="package_contents" id="f-package" label="Комплектация" rows="5" :value="$site('package_contents')" :form="$form" />

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

            <x-checkbox name="is_active" label="Показывать на сайте после публикации" :checked="true" :form="$form" />

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
    @include('partials.admin_product_documents', ['product' => $product])
@endunless

{{-- Сохранение и публикация --}}
<div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6" data-product-actions>

    <h2 class="text-lg font-semibold mb-1">
        Публикация
    </h2>

    {{-- Что сохраняет каждая кнопка — AdminProductController::store / update / afterSave --}}
    <p class="text-sm text-slate-500 mb-4" data-actions-hint>
        @if($isNew)
            Любая кнопка создаёт товар со всеми полями формы. «Сохранить черновик» — на сайте не виден,
            «Опубликовать» — сразу на сайте.
        @else
            Каждая кнопка сохраняет всю форму: данные 1С, категорию и адрес — сразу, тексты и характеристики — в черновик,
            фото из очереди — загружает. «Опубликовать» дополнительно переносит черновик на сайт.
        @endif
    </p>

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
