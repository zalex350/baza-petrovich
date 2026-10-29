@extends('layouts.admin')

@section('title', 'Категории')

@section('content')

<h1 class="text-3xl font-bold mb-2">Категории</h1>

<p class="text-sm text-ink-muted mb-6 max-w-3xl">
    Картинка категории показывается плиткой над списком товаров родительского раздела
    (если картинка есть хотя бы у одной подкатегории; иначе — чипы с названиями).
    JPG, PNG или WebP до {{ (int) round(config('shop.images.max_kb') / 1024) }} МБ, сохраняется в WebP
    ({{ \App\Http\Controllers\Admin\AdminCategoryController::IMAGE_MAX }} px по длинной стороне).
    Дерево и названия категорий приходят из импорта каталога.
</p>

<form method="GET" action="/admin/categories" class="mb-4 flex flex-wrap items-end gap-2">
    <x-input name="q" type="search" label="Поиск" :value="$search" placeholder="Название категории" class="grow max-w-md" />
    <x-button>Найти</x-button>
    @if($search !== '')
        <x-button variant="link" href="/admin/categories">Сбросить</x-button>
    @endif
</form>

@if($categories->isEmpty())
    <x-empty-state title="Категорий не найдено" icon="folder" />
@else
    <x-table stack="md" caption="Категории">
        <x-slot:head>
            <x-table.th>Картинка</x-table.th>
            <x-table.th>Категория</x-table.th>
            <x-table.th align="right">Товаров</x-table.th>
            <x-table.th>Загрузить</x-table.th>
        </x-slot:head>

        @foreach($categories as $category)
            <tr data-category-row="{{ $category->id }}">
                <x-table.td label="Картинка">
                    @if($category->imageUrl())
                        <img src="{{ $category->imageUrl() }}" alt="" class="h-14 w-14 rounded-control border border-line bg-media object-contain" data-category-image>
                    @else
                        <span class="text-ink-muted">—</span>
                    @endif
                </x-table.td>
                <x-table.td label="Категория">
                    @if($category->parent)
                        <span class="block text-meta text-ink-muted">{{ $category->parent->name }}</span>
                    @endif
                    <span class="font-medium text-ink">{{ $category->name }}</span>
                    @if($category->is_hidden)
                        <x-badge>служебная</x-badge>
                    @endif
                </x-table.td>
                <x-table.td label="Товаров" align="right" nowrap>{{ $category->products_count }}</x-table.td>
                <x-table.td label="Загрузить">
                    <div class="flex flex-wrap items-center gap-2">
                        <form method="POST" action="/admin/category/{{ $category->id }}/image" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2">
                            @csrf
                            <x-file name="image" :id="'f-image-'.$category->id" accept="image/jpeg,image/png,image/webp" aria-label="Картинка категории {{ $category->name }}" class="max-w-64" />
                            <x-button size="sm">{{ $category->image ? 'Заменить' : 'Загрузить' }}</x-button>
                        </form>
                        @if($category->image)
                            <x-confirm
                                action="/admin/category/{{ $category->id }}/image/delete"
                                label="Удалить"
                                title="Удалить картинку?"
                                confirm-label="Удалить"
                                trigger-variant="link"
                                trigger-size="sm"
                            >
                                Картинка категории «{{ $category->name }}» будет удалена.
                            </x-confirm>
                        @endif
                    </div>
                </x-table.td>
            </tr>
        @endforeach
    </x-table>

    <div class="mt-4">
        <x-pagination :paginator="$categories" />
    </div>
@endif

@endsection
