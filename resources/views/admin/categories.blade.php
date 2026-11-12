{{--
    Категории каталога (AdminCategoryController@index). Без поиска — дерево по порядку
    (sort_order, при равном — название), подкатегории — с отступом; с поиском — найденные с путём.
    Данные: $rows [category, depth], $search, $path(category) — «Родитель / …», $siblings — parent_id => [id…]
    (для «вверх / вниз»: первая и последняя среди соседей).
    В строке: «↑ ↓», «Изменить» (название, родитель, адрес, картинка), «Скрыть / Показать», «Удалить»
    (только пустую — иначе ошибка со счётчиками).
--}}
@extends('layouts.admin')

@section('title', 'Категории')

@section('content')

<div class="mb-2 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-3xl font-bold">Категории</h1>
    <x-button href="/admin/category/create">Добавить категорию</x-button>
</div>

<p class="text-sm text-ink-muted mb-6 max-w-3xl">
    Порядок здесь — порядок в меню и каталоге. Скрытая категория не показывается в меню и каталоге
    вместе с подкатегориями, её товары остаются в поиске. Удалить можно только пустую категорию.
    Картинка (плитка над списком товаров родительского раздела) — в «Изменить».
</p>

<form method="GET" action="/admin/categories" class="mb-4 flex flex-wrap items-end gap-2">
    <x-input name="q" type="search" label="Поиск" :value="$search" placeholder="Название категории" class="grow max-w-md" />
    <x-button>Найти</x-button>
    @if($search !== '')
        <x-button variant="link" href="/admin/categories">Сбросить</x-button>
    @endif
</form>

@if($rows->isEmpty())
    <x-empty-state title="Категорий не найдено" icon="folder" />
@else
    <x-table caption="Категории">
        <x-slot:head>
            <x-table.th sticky="start">Категория</x-table.th>
            <x-table.th align="right">Товаров</x-table.th>
            <x-table.th align="right">Подкатегорий</x-table.th>
            <x-table.th>Картинка</x-table.th>
            @if($search === '')<x-table.th>Порядок</x-table.th>@endif
            <x-table.th sticky="end">Действия</x-table.th>
        </x-slot:head>

        @foreach($rows as ['category' => $category, 'depth' => $depth])
            @php
                $group = $siblings[(int) $category->parent_id] ?? [];
                $parentPath = $search !== '' ? $path($category) : '';
            @endphp
            <tr data-category-row="{{ $category->id }}" data-depth="{{ $depth }}">
                <x-table.td sticky="start">
                    <div style="padding-left: {{ $depth * 1.25 }}rem" class="min-w-40 max-w-72">
                        @if($parentPath !== '')
                            <span class="block text-meta text-ink-muted">{{ $parentPath }}</span>
                        @endif
                        <a href="/admin/category/{{ $category->id }}/edit" class="font-medium text-ink hover:text-link">{{ $category->name }}</a>
                        @if($category->is_hidden)
                            <x-badge>скрыта</x-badge>
                        @endif
                    </div>
                </x-table.td>
                <x-table.td align="right" nowrap>{{ $category->products_count }}</x-table.td>
                <x-table.td align="right" nowrap>{{ $category->children_count }}</x-table.td>
                <x-table.td>
                    @if($category->imageUrl())
                        <img src="{{ $category->imageUrl() }}" alt="" class="h-10 w-10 rounded-control border border-line bg-media object-contain" data-category-image>
                    @else
                        <span class="text-ink-muted">—</span>
                    @endif
                </x-table.td>
                @if($search === '')
                    <x-table.td nowrap>
                        <div class="flex gap-1">
                            @foreach(['up' => ['↑', 'Выше'], 'down' => ['↓', 'Ниже']] as $direction => [$arrow, $title])
                                @php($edge = $direction === 'up' ? reset($group) : end($group))
                                <form method="POST" action="/admin/category/{{ $category->id }}/move">
                                    @csrf
                                    <input type="hidden" name="direction" value="{{ $direction }}">
                                    <x-button size="sm" variant="secondary" :disabled="$edge === $category->id" aria-label="{{ $title }}: {{ $category->name }}" data-category-move="{{ $direction }}">{{ $arrow }}</x-button>
                                </form>
                            @endforeach
                        </div>
                    </x-table.td>
                @endif
                <x-table.td sticky="end" nowrap>
                    {{-- На телефоне столбиком: закреплённая колонка действий — узкая --}}
                    <div class="flex flex-col items-start gap-1 sm:flex-row sm:items-center sm:gap-3">
                        <x-button variant="link" size="sm" href="/admin/category/{{ $category->id }}/edit">Изменить</x-button>
                        <form method="POST" action="/admin/category/{{ $category->id }}/toggle-visibility">
                            @csrf
                            <x-button variant="link" size="sm">{{ $category->is_hidden ? 'Показать' : 'Скрыть' }}</x-button>
                        </form>
                        <x-confirm
                            action="/admin/category/{{ $category->id }}/delete"
                            label="Удалить"
                            title="Удалить категорию?"
                            confirm-label="Удалить"
                            trigger-variant="link"
                            trigger-size="sm"
                        >
                            Категория «{{ $category->name }}» будет удалена. Удалить можно только пустую — без товаров и подкатегорий.
                        </x-confirm>
                    </div>
                </x-table.td>
            </tr>
        @endforeach
    </x-table>
@endif

@endsection
