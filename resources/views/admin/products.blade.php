@extends('layouts.admin')

@section('title', 'Админка — Товары')

@section('content')

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-3xl font-bold">Админка — Товары</h1>

    <div class="flex flex-wrap items-center gap-3">
        <x-button variant="secondary" href="/admin/products/new-1c" data-new1c-link>Новые из 1С ({{ $new1cCount }})</x-button>
        @if(config('shop.catalog.manual_products'))
            <x-button href="/admin/product/create">Добавить товар</x-button>
        @else
            <span class="text-sm text-ink-muted">Товары приходят из 1С</span>
        @endif
    </div>
</div>

@php
    // Ссылки фильтров сохраняют поиск, поиск — фильтр
    $withSearch = fn (array $params = []) => '/admin/products'.(($query = http_build_query(array_filter($params + ['q' => $search]))) !== '' ? '?'.$query : '');
@endphp

{{-- Поиск по артикулу, названию, бренду (вместе с текущим фильтром) --}}
<form method="GET" action="/admin/products" class="mb-4 flex flex-wrap items-end gap-2" data-admin-product-search>
    @foreach($filter as $key => $value)
        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
    @endforeach
    <x-input
        name="q"
        id="q"
        type="search"
        label="Поиск"
        :value="$search"
        placeholder="Артикул, название, бренд"
        class="grow max-w-xl"
    />
    <x-button>Найти</x-button>
    @if($search !== '')
        <x-button variant="link" :href="$withSearch($filter + ['q' => null])">Сбросить поиск</x-button>
    @endif
</form>

{{-- Фильтры: без категории (служебная «Без категории» скрыта на сайте), без названия,
     описание не одобрено (сгенерированное, на сайте не показывается) --}}
<div class="mb-4 flex flex-wrap items-center gap-2 text-sm" data-admin-product-filter>
    @if($uncategorized || $unnamed || $unapproved)
        <x-badge tone="warning" size="md">{{ $uncategorized ? 'Без категории' : ($unnamed ? 'Без названия' : 'Описание не одобрено') }}: {{ $products->total() }}</x-badge>
        <x-button variant="link" size="sm" :href="$withSearch()">Показать все</x-button>
    @else
        @if($search !== '')
            <x-badge tone="info" size="md">Найдено: {{ $products->total() }}</x-badge>
        @endif
        @if($uncategorizedCount)
            <x-button variant="secondary" size="sm" :href="$withSearch(['category' => 'none'])">Без категории ({{ $uncategorizedCount }})</x-button>
        @endif
        @if($unnamedCount)
            <x-button variant="secondary" size="sm" :href="$withSearch(['name' => 'none'])">Без названия ({{ $unnamedCount }})</x-button>
        @endif
        @if($unapprovedCount)
            <x-button variant="secondary" size="sm" :href="$withSearch(['description' => 'unapproved'])">Описание не одобрено ({{ $unapprovedCount }})</x-button>
        @endif
    @endif
</div>

{{-- На узком экране таблица прокручивается вбок; название и «Действия» закреплены --}}
<x-table caption="Товары">
    <x-slot:head>
        <x-table.th sticky="start">Название</x-table.th>
        <x-table.th>Артикул</x-table.th>
        <x-table.th>SKU</x-table.th>
        <x-table.th align="right">Цена</x-table.th>
        <x-table.th align="right">Остаток</x-table.th>
        <x-table.th>Статус</x-table.th>
        <x-table.th align="right">ID</x-table.th>
        <x-table.th sticky="end">Действия</x-table.th>
    </x-slot:head>

    @foreach($products as $product)
        <tr data-product-row="{{ $product->id }}">
            <x-table.td sticky="start">
                <div class="min-w-40 max-w-72">
                    <a href="/admin/product/{{ $product->id }}" class="font-medium text-ink hover:text-link">{{ $product->title() }}</a>
                    @unless($product->hasRealName())
                        <x-badge tone="warning">без названия</x-badge>
                    @endunless
                </div>
            </x-table.td>
            <x-table.td nowrap>{{ $product->article ?: '—' }}</x-table.td>
            <x-table.td nowrap>{{ $product->sku }}</x-table.td>
            <x-table.td align="right" nowrap><x-price :amount="$product->price" vat="net" placeholder="—" /></x-table.td>
            <x-table.td align="right" nowrap>{{ $product->stock }}</x-table.td>
            <x-table.td nowrap>
                @if(! $product->is_published)
                    <x-badge>Черновик</x-badge>
                @elseif($product->is_active)
                    <x-badge tone="success">Опубликован</x-badge>
                @else
                    <x-badge tone="warning">Скрыт</x-badge>
                @endif
                @if($product->draft)
                    <x-badge tone="info">есть черновик</x-badge>
                @endif
            </x-table.td>
            <x-table.td align="right" nowrap>{{ $product->id }}</x-table.td>
            <x-table.td sticky="end" nowrap>
                {{-- На телефоне столбиком: закреплённая колонка — узкая --}}
                <div class="flex flex-col items-start gap-1 sm:flex-row sm:items-center sm:gap-3">
                    <x-button variant="link" size="sm" href="/admin/product/{{ $product->id }}">Изменить</x-button>
                    <form method="POST" action="/admin/product/{{ $product->id }}/toggle-visibility">
                        @csrf
                        <x-button variant="link" size="sm">{{ $product->is_active ? 'Скрыть' : 'Показать' }}</x-button>
                    </form>
                    <x-confirm
                        action="/admin/product/{{ $product->id }}/delete"
                        label="Удалить"
                        title="Удалить товар?"
                        confirm-label="Удалить"
                        trigger-variant="link"
                        trigger-size="sm"
                    >
                        Товар «{{ $product->title() }}» будет удалён.
                    </x-confirm>
                </div>
            </x-table.td>
        </tr>
    @endforeach
</x-table>

<div class="mt-8">
    <x-pagination :paginator="$products" />
</div>

@endsection