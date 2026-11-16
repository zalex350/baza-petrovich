{{--
    Превью иконок категорий (AdminCategoryController@icons, только админ): проверка набора и соответствий.
    Данные: $rows [category, depth] — категории 1-го и 2-го уровня по порядку; $library — CategoryIcons::library();
    $used — ключ => сколько категорий с этой иконкой; $missing — видимых категорий без иконки;
    $tiles — id раздела => URL фото плитки (CatalogMenu) или null.
    Иконки — 24, 48 и 72 px; цвета — CSS-переменные --ic-stroke, --ic-fill, --ic-water, --ic-accent.
--}}
@extends('layouts.admin')

@section('title', 'Иконки категорий')

@section('content')

<a href="/admin/categories" class="text-sm text-ink-muted hover:text-link">← Все категории</a>

<h1 class="text-3xl font-bold mt-4 mb-2">Иконки категорий</h1>

<p class="text-sm text-ink-muted mb-6 max-w-3xl">
    Иконку и фото плитки раздела выбирают в «Изменить». Иконки — в меню, чипах и на плитках без фото;
    плитки разделов на главной и в «Каталоге» — фото типичного товара (своя картинка, выбранный или самый популярный товар с фото и в наличии).
    @if($missing)
        <b class="text-ink">Без иконки: {{ $missing }}.</b>
    @endif
</p>

<h2 class="text-2xl font-bold mb-3">Набор ({{ count($library) }})</h2>
<ul class="mb-10 grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6" data-icon-library="plumb">
    @foreach($library as $icon)
        <li class="flex flex-col items-center gap-1 rounded-card border border-line bg-surface p-2 text-center" title="{{ $icon['words'] }}">
            <span class="flex items-end gap-2">
                <x-category-icon :icon="$icon['key']" class="h-6 w-6" />
                <x-category-icon :icon="$icon['key']" class="h-12 w-12" />
                <x-category-icon :icon="$icon['key']" class="h-[72px] w-[72px]" />
            </span>
            <span class="text-meta break-all text-ink">{{ $icon['name'] }}</span>
            <span class="text-meta text-ink-muted tabular-nums">категорий: {{ $used[$icon['key']] ?? 0 }}</span>
        </li>
    @endforeach
</ul>

<h2 class="text-2xl font-bold mb-3">Категории</h2>
<x-table caption="Иконки категорий">
    <x-slot:head>
        <x-table.th sticky="start">Категория</x-table.th>
        <x-table.th>24 · 48 · 72</x-table.th>
        <x-table.th>Иконка</x-table.th>
        <x-table.th>Плитка</x-table.th>
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
                <div class="flex items-end gap-3">
                    <x-category-icon :category="$category" class="h-6 w-6" />
                    <x-category-icon :category="$category" class="h-12 w-12" />
                    <x-category-icon :category="$category" class="h-[72px] w-[72px]" />
                </div>
            </x-table.td>
            <x-table.td nowrap>
                @if($category->icon && app(\App\Services\CategoryIcons::class)->render($category->icon, $category->icon_svg))
                    <code class="text-meta">{{ $category->icon }}</code>
                @elseif($category->icon)
                    <x-badge tone="warning">нет в наборе: {{ $category->icon }}</x-badge>
                @else
                    <x-badge tone="warning">нет иконки</x-badge>
                @endif
            </x-table.td>
            <x-table.td>
                @if($depth === 0)
                    @if(! empty($tiles[$category->id]))
                        <img src="{{ $tiles[$category->id] }}" alt="" class="h-14 w-20 rounded-control border border-line bg-media object-contain" data-tile-preview>
                    @else
                        <span class="text-meta text-ink-muted">иконка</span>
                    @endif
                @endif
            </x-table.td>
            <x-table.td sticky="end" nowrap>
                <x-button variant="link" size="sm" href="/admin/category/{{ $category->id }}/edit">Изменить</x-button>
            </x-table.td>
        </tr>
    @endforeach
</x-table>

@endsection
