{{--
    Категории каталога (AdminCategoryController@index). Без поиска — дерево в порядке витрины
    (CategoryOrder: по количеству товаров с закреплёнными первыми / вручную / по алфавиту), подкатегории —
    с отступом; с поиском — найденные с путём.
    Данные: $rows [category, depth], $search, $path(category) — «Родитель / …», $siblings — parent_id => [id…]
    в порядке витрины (для «вверх / вниз»: первая и последняя среди соседей), $counts — id => опубликованных
    товаров с подкатегориями, $mode — режим порядка.
    Сверху — «Порядок категорий» (режим). В строке: «Товаров», пометка «закреплена» и «Вернуть автопорядок»,
    «↑ ↓» (в режиме «по количеству» закрепляют), «Изменить», «Скрыть / Показать», «Удалить» (только пустую).
--}}
@extends('layouts.admin')

@section('title', 'Категории')

@section('content')

@php
    $byCount = $mode === \App\Services\Catalog\CategoryOrder::COUNT;
    $arrows = $mode !== \App\Services\Catalog\CategoryOrder::ALPHA;
@endphp

<div class="mb-2 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-3xl font-bold">Категории</h1>
    <div class="flex flex-wrap gap-2">
        <x-button variant="secondary" href="/admin/category-icons">Иконки категорий</x-button>
        <x-button href="/admin/category/create">Добавить категорию</x-button>
    </div>
</div>

<p class="text-sm text-ink-muted mb-4 max-w-3xl">
    Порядок здесь — порядок в меню, «Каталоге», на главной и в подкатегориях. «Товаров» — опубликованных,
    вместе с подкатегориями: по ним идёт порядок «по количеству». Скрытая категория не показывается в меню
    и каталоге вместе с подкатегориями, её товары остаются в поиске. Удалить можно только пустую категорию.
</p>

<form method="POST" action="/admin/categories/order-mode" class="mb-6 flex flex-wrap items-end gap-2 rounded-card border border-line bg-surface p-card" data-order-mode>
    @csrf
    <x-select name="mode" label="Порядок категорий" :options="\App\Services\Catalog\CategoryOrder::MODES" :value="$mode" class="min-w-72" />
    <x-button variant="secondary">Применить</x-button>
    <p class="basis-full text-meta text-ink-muted">
        @if($byCount)
            Больше товаров — выше. ↑ / ↓ закрепляют категорию (и тех, кто окажется выше неё): закреплённые идут первыми в своём порядке.
        @elseif($arrows)
            Только ручной порядок ↑ / ↓, количество товаров не учитывается.
        @else
            По алфавиту, ↑ / ↓ не действуют.
        @endif
    </p>
</form>

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
            @if($search === '' && $arrows)<x-table.th>Порядок</x-table.th>@endif
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
                        @if($byCount && $category->is_pinned)
                            <x-badge tone="info" data-pinned>закреплена</x-badge>
                        @endif
                    </div>
                </x-table.td>
                <x-table.td align="right" nowrap>
                    <span title="Опубликованных, с подкатегориями. Всего в самой категории: {{ $category->products_count }}" data-category-count>{{ $counts[$category->id] ?? 0 }}</span>
                </x-table.td>
                <x-table.td align="right" nowrap>{{ $category->children_count }}</x-table.td>
                <x-table.td>
                    @if($category->imageUrl())
                        <img src="{{ $category->imageUrl() }}" alt="" class="h-10 w-10 rounded-control border border-line bg-media object-contain" data-category-image>
                    @else
                        <span class="text-ink-muted">—</span>
                    @endif
                </x-table.td>
                @if($search === '' && $arrows)
                    <x-table.td nowrap>
                        <div class="flex items-center gap-1">
                            @foreach(['up' => ['↑', 'Выше'], 'down' => ['↓', 'Ниже']] as $direction => [$arrow, $title])
                                @php($edge = $direction === 'up' ? reset($group) : end($group))
                                <form method="POST" action="/admin/category/{{ $category->id }}/move">
                                    @csrf
                                    <input type="hidden" name="direction" value="{{ $direction }}">
                                    <x-button size="sm" variant="secondary" :disabled="$edge === $category->id" aria-label="{{ $title }}: {{ $category->name }}" data-category-move="{{ $direction }}">{{ $arrow }}</x-button>
                                </form>
                            @endforeach
                            @if($byCount && $category->is_pinned)
                                <form method="POST" action="/admin/category/{{ $category->id }}/unpin">
                                    @csrf
                                    <x-button size="sm" variant="link" data-category-unpin>Вернуть автопорядок</x-button>
                                </form>
                            @endif
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
