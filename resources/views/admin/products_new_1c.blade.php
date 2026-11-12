{{--
    «Товары → Новые из 1С» (AdminNewFrom1cController): черновики, созданные обменом номенклатуры
    (есть GUID, не опубликованы). Фильтр без категории / с категорией / все, поиск, группа 1С;
    массово — назначить категорию, назначить и опубликовать, опубликовать.
    Данные: $products (пагинатор), $state, $search, $group, $counts, $groups, $categories.
--}}
@extends('layouts.admin')

@section('title', 'Новые из 1С')

@section('flash-validation', 'summary')

@section('content')

<a href="/admin/products" class="text-sm text-ink-muted hover:text-link">← Товары</a>

<h1 class="text-3xl font-bold mt-4 mb-2">Новые из 1С</h1>

<p class="text-sm text-ink-muted mb-6 max-w-3xl">
    Товары, которые обмен номенклатуры создал черновиками: позиции 1С без пары на сайте. На витрине их нет, пока не назначена
    категория и товар не опубликован. Поля из 1С (название 1С, артикул, бренд, НДС…) меняются только в 1С; описание,
    фото и характеристики — в карточке товара. Группа 1С подсказывает раздел, на категорию сайта она не влияет.
</p>

<x-tabs label="Фильтр" :active="$state" :tabs="[
    'none' => ['label' => 'Без категории ('.$counts['none'].')', 'url' => '/admin/products/new-1c'],
    'ready' => ['label' => 'С категорией ('.$counts['ready'].')', 'url' => '/admin/products/new-1c?state=ready'],
    'all' => ['label' => 'Все ('.$counts['all'].')', 'url' => '/admin/products/new-1c?state=all'],
]" />

<form method="GET" action="/admin/products/new-1c" class="mb-4 grid gap-4 rounded-card border border-line bg-surface p-4 sm:grid-cols-2 lg:flex lg:flex-wrap lg:items-end" data-new1c-filters>
    <input type="hidden" name="state" value="{{ $state }}">
    <x-input name="q" type="search" label="Поиск" :value="$search" placeholder="SKU, артикул, название, бренд" class="lg:grow lg:max-w-md" />
    <x-select name="group" label="Группа 1С" placeholder="Все" :options="$groups->combine($groups)" :value="$group" />
    <div class="flex gap-2">
        <x-button>Показать</x-button>
        @if($search !== '' || $group !== '')
            <x-button variant="secondary" href="/admin/products/new-1c?state={{ $state }}">Сбросить</x-button>
        @endif
    </div>
</form>

@if($products->isEmpty())
    <x-empty-state title="Новых товаров из 1С нет" icon="check-circle">
        Черновики появляются, когда в номенклатуре 1С есть позиция без пары на сайте.
    </x-empty-state>
@else
    <form method="POST" action="/admin/products/new-1c" x-data data-new1c-form>
        @csrf

        <div class="mb-4 grid gap-4 rounded-card border border-line bg-surface p-4 sm:grid-cols-2 lg:flex lg:flex-wrap lg:items-end">
            <x-checkbox name="select_all" label="Выбрать все на странице" :unchecked="false"
                x-on:change="$root.querySelectorAll('[data-new1c-id]').forEach(c => c.checked = $event.target.checked)" />
            <x-select name="category_id" label="Категория" placeholder="— выберите —" :options="$categories" class="lg:min-w-64" />
            <div class="flex flex-wrap gap-2">
                <x-button name="action" value="category" variant="secondary">Назначить категорию</x-button>
                <x-button name="action" value="category_publish">Назначить и опубликовать</x-button>
                <x-button name="action" value="publish" variant="secondary">Опубликовать</x-button>
            </div>
        </div>

        <x-table stack="lg" caption="Новые из 1С">
            <x-slot:head>
                <x-table.th>SKU</x-table.th>
                <x-table.th>Наименование 1С</x-table.th>
                <x-table.th>Бренд · артикул</x-table.th>
                <x-table.th>Группа 1С</x-table.th>
                <x-table.th>Категория</x-table.th>
                <x-table.th>Создан</x-table.th>
            </x-slot:head>

            @foreach($products as $product)
                <tr data-new1c-row="{{ $product->id }}">
                    <x-table.td label="SKU" nowrap>
                        <x-checkbox name="ids[]" :id="'new1c-'.$product->id" :value="$product->id" :label="$product->sku" :unchecked="false" data-new1c-id />
                    </x-table.td>
                    <x-table.td label="Наименование 1С">
                        <a href="/admin/product/{{ $product->id }}" class="text-link hover:text-link-hover">{{ $product->name }}</a>
                        @unless($product->is_active)
                            <x-badge tone="warning">выведен в 1С</x-badge>
                        @endunless
                    </x-table.td>
                    <x-table.td label="Бренд · артикул">{{ $product->brand ?? '—' }} · {{ $product->article ?? '—' }}</x-table.td>
                    <x-table.td label="Группа 1С">{{ $product->group_1c ?? '—' }}</x-table.td>
                    <x-table.td label="Категория">{{ $product->category?->name ?? '—' }}</x-table.td>
                    <x-table.td label="Создан" nowrap>{{ date_ru($product->created_at) }}</x-table.td>
                </tr>
            @endforeach
        </x-table>
    </form>

    <div class="mt-4">
        <x-pagination :paginator="$products" />
    </div>
@endif

@endsection
