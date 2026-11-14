{{--
    Превью иконок категорий (AdminCategoryController@icons, только админ): проверка набора и соответствий.
    Данные: $rows [category, depth] — категории 1-го и 2-го уровня по порядку; $library — CategoryIcons::library();
    $used — ключ => сколько категорий с этой иконкой; $missing — видимых категорий без иконки.
    Иконки — в трёх размерах (16, 24, 40 px), на светлом и на цветном фоне: цвет — currentColor.
--}}
@extends('layouts.admin')

@section('title', 'Иконки категорий')

@section('content')

<a href="/admin/categories" class="text-sm text-ink-muted hover:text-link">← Все категории</a>

<h1 class="text-3xl font-bold mt-4 mb-2">Иконки категорий</h1>

<p class="text-sm text-ink-muted mb-6 max-w-3xl">
    Иконку категории выбирают в «Изменить» (поиск по набору или своя SVG). Нет иконки — категория показывается без неё.
    Цвет иконок — из темы сайта: здесь они показаны на светлом и на цветном фоне.
    @if($missing)
        <b class="text-ink">Без иконки: {{ $missing }}.</b>
    @endif
</p>

<x-table caption="Иконки категорий">
    <x-slot:head>
        <x-table.th sticky="start">Категория</x-table.th>
        <x-table.th>16 · 24 · 40</x-table.th>
        <x-table.th>На цветном</x-table.th>
        <x-table.th>Иконка</x-table.th>
        <x-table.th sticky="end">Действия</x-table.th>
    </x-slot:head>

    @foreach($rows as ['category' => $category, 'depth' => $depth])
        <tr data-icon-row="{{ $category->id }}">
            <x-table.td sticky="start">
                <div style="padding-left: {{ $depth * 1.25 }}rem" class="min-w-48 max-w-80 {{ $depth ? '' : 'font-semibold' }}">
                    {{ $category->name }}
                    @if($category->is_hidden)
                        <x-badge>скрыта</x-badge>
                    @endif
                </div>
            </x-table.td>
            <x-table.td>
                <div class="flex items-end gap-3 text-ink">
                    <x-category-icon :category="$category" class="h-4 w-4" />
                    <x-category-icon :category="$category" class="h-6 w-6" />
                    <x-category-icon :category="$category" class="h-10 w-10" />
                </div>
            </x-table.td>
            <x-table.td>
                <span class="inline-flex h-10 w-10 items-center justify-center rounded-control bg-primary text-white">
                    <x-category-icon :category="$category" class="h-6 w-6" />
                </span>
            </x-table.td>
            <x-table.td nowrap>
                @if($category->icon)
                    <code class="text-meta">{{ $category->icon }}</code>
                @else
                    <x-badge tone="warning">нет иконки</x-badge>
                @endif
            </x-table.td>
            <x-table.td sticky="end" nowrap>
                <x-button variant="link" size="sm" href="/admin/category/{{ $category->id }}/edit">Изменить</x-button>
            </x-table.td>
        </tr>
    @endforeach
</x-table>

<h2 class="text-2xl font-bold mt-10 mb-2">Набор иконок</h2>
<p class="text-sm text-ink-muted mb-4 max-w-3xl">
    Material Symbols (Outlined, weight 300) — только из списка <code>resources/icons/material.json</code>;
    свои — <code>resources/icons/custom</code> (24×24, контур 1.5). Под иконкой — имя и число категорий, где она выбрана.
</p>

@foreach(collect($library)->groupBy('set') as $set => $items)
    <h3 class="text-lg font-semibold mt-6 mb-3">{{ $set === 'custom' ? 'Свои' : 'Material Symbols' }} ({{ $items->count() }})</h3>
    <ul class="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8 xl:grid-cols-10" data-icon-library="{{ $set }}">
        @foreach($items as $icon)
            <li class="flex flex-col items-center gap-1 rounded-card border border-line bg-surface p-2 text-center" title="{{ $icon['words'] }}">
                <span class="flex items-center gap-2 text-ink">
                    <x-category-icon :icon="$icon['key']" class="h-6 w-6" />
                    <x-category-icon :icon="$icon['key']" class="h-10 w-10" />
                </span>
                <span class="text-meta break-all text-ink">{{ $icon['name'] }}</span>
                <span class="text-meta text-ink-muted tabular-nums">{{ $used[$icon['key']] ?? 0 }}</span>
            </li>
        @endforeach
    </ul>
@endforeach

@endsection
