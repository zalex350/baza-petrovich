{{--
    Категория: создание и редактирование (AdminCategoryController@create / @edit).
    Название, родитель (дерево; при редактировании — без самой категории и её подкатегорий),
    адрес (slug; пусто при создании — из названия; при переименовании сохраняется), скрыть,
    иконка (x-category-icon-picker: набор сантехники или загрузка SVG — поэтому форма multipart),
    у раздела верхнего уровня — фото на плитке (x-tile-product-picker).
    При редактировании — картинка (отдельные формы: загрузка и удаление) и удаление пустой категории.
    Данные: $category, $parents (id => «— — название»), $tile — null или [candidates, chosen, url].
--}}
@extends('layouts.admin')

@section('title', $category->exists ? 'Категория — '.$category->name : 'Новая категория')

@section('flash-validation', 'summary')

@section('content')

<a href="/admin/categories" class="text-sm text-ink-muted hover:text-link">← Все категории</a>

<h1 class="text-3xl font-bold mt-4 mb-6">{{ $category->exists ? $category->name : 'Новая категория' }}</h1>

{{-- Закреплена кнопками ↑ / ↓ (порядок «по количеству товаров», CategoryOrder) --}}
@if($category->exists && $category->is_pinned && \App\Services\Catalog\CategoryOrder::mode() === \App\Services\Catalog\CategoryOrder::COUNT)
    <div class="mb-6 flex max-w-3xl flex-wrap items-center gap-3 rounded-card border border-line bg-surface p-card" data-category-pinned>
        <x-badge tone="info">закреплена</x-badge>
        <span class="text-sm text-ink-muted">Идёт среди соседей в ручном порядке, а не по количеству товаров.</span>
        <form method="POST" action="/admin/category/{{ $category->id }}/unpin">
            @csrf
            <x-button size="sm" variant="secondary">Вернуть автопорядок</x-button>
        </form>
    </div>
@endif

<form
    method="POST"
    action="{{ $category->exists ? '/admin/category/'.$category->id : '/admin/category' }}"
    enctype="multipart/form-data"
    class="max-w-3xl space-y-5 rounded-card border border-line bg-surface p-card"
    data-category-form
>
    @csrf

    <x-input name="name" label="Название" :value="$category->name" maxlength="255" required />

    <x-select
        name="parent_id"
        label="Родительская категория"
        :options="$parents"
        :value="$category->parent_id"
        placeholder="— верхний уровень —"
        :hint="$category->exists ? 'Смена родителя переносит категорию вместе с подкатегориями и товарами; в новом месте она встаёт последней' : null"
    />

    <x-input
        name="slug"
        label="Адрес в каталоге"
        :value="$category->slug"
        maxlength="255"
        :hint="$category->exists ? 'Ссылка /catalog/'.$category->slug.'. Меняйте, только если нужно: старая ссылка перестанет работать' : 'Пусто — из названия латиницей'"
    />

    <x-input name="discount_percent" type="number" step="0.01" min="0" max="99.99" label="Скидка на раздел, %" :value="$category->discount_percent" class="max-w-xs"
        hint="Для товаров раздела и подразделов, у которых нет своей скидки. Пусто — без скидки" />

    <x-checkbox name="is_hidden" label="Скрыть" hint="Нет в меню и каталоге вместе с подкатегориями; товары — в поиске" :checked="$category->is_hidden" />

    <x-category-icon-picker :category="$category" />

    {{-- Плитка раздела — только у верхнего уровня (главная, «Каталог») --}}
    @if($tile)
        <x-tile-product-picker :category="$category" :candidates="$tile['candidates']" :chosen="$tile['chosen']" :url="$tile['url']" />
    @endif

    <div class="flex flex-wrap gap-3">
        <x-button>{{ $category->exists ? 'Сохранить' : 'Создать' }}</x-button>
        <x-button variant="secondary" href="/admin/categories">Отмена</x-button>
    </div>
</form>

@if($category->exists)
    <section class="mt-6 max-w-3xl rounded-card border border-line bg-surface p-card" data-category-image-form>
        <h2 class="text-xl font-semibold mb-2">Картинка</h2>
        <p class="text-sm text-ink-muted mb-4">
            У раздела верхнего уровня — фото на плитке главной и «Каталога» (важнее выбранного товара);
            у подкатегории — плитка над списком товаров родительского раздела (если картинка есть хотя бы у одной
            подкатегории; иначе — чипы с иконками и названиями). JPG, PNG или WebP до {{ (int) round(config('shop.images.max_kb') / 1024) }} МБ,
            сохраняется в WebP ({{ \App\Http\Controllers\Admin\AdminCategoryController::IMAGE_MAX }} px по длинной стороне).
        </p>
        <form method="POST" action="/admin/category/{{ $category->id }}/image" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
            @csrf
            <x-file name="image" label="Файл" accept="image/jpeg,image/png,image/webp" :preview="$category->imageUrl()" class="max-w-sm" />
            <x-button>{{ $category->image ? 'Заменить' : 'Загрузить' }}</x-button>
        </form>
        @if($category->image)
            <div class="mt-3">
                <x-confirm
                    action="/admin/category/{{ $category->id }}/image/delete"
                    label="Удалить картинку"
                    title="Удалить картинку?"
                    confirm-label="Удалить"
                    trigger-variant="link"
                    trigger-size="sm"
                >
                    Картинка категории «{{ $category->name }}» будет удалена.
                </x-confirm>
            </div>
        @endif
    </section>

    <section class="mt-6 max-w-3xl rounded-card border border-line bg-surface p-card" data-category-delete>
        <h2 class="text-xl font-semibold mb-2">Удаление</h2>
        @if($category->products_count || $category->children_count)
            <p class="text-sm text-ink-muted">
                Удалить нельзя: товаров — {{ $category->products_count }}, подкатегорий — {{ $category->children_count }}.
                Сначала перенесите их в другую категорию.
            </p>
        @else
            <x-confirm
                action="/admin/category/{{ $category->id }}/delete"
                label="Удалить категорию"
                title="Удалить категорию?"
                confirm-label="Удалить"
                trigger-variant="danger"
            >
                Категория «{{ $category->name }}» будет удалена.
            </x-confirm>
        @endif
    </section>
@endif

@endsection
