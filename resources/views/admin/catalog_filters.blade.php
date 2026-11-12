@extends('layouts.admin')

@section('title', 'Админка — Фильтры каталога')

@section('content')

<h1 class="text-3xl font-bold mb-2">
    Фильтры каталога
</h1>

<p class="text-sm text-slate-500 mb-6 max-w-3xl">
    Набор фильтров для каждой категории: производитель, цена, наличие и характеристики, отмеченные в
    <a href="/admin/attributes" class="underline">справочнике</a> как «используется в фильтрах».
    Подкатегория без своей настройки наследует фильтры родителя; если настройки нет нигде — производитель, цена, наличие.
</p>

{{-- На узком экране таблица прокручивается вбок; категория и «Настроить» закреплены --}}
<x-table caption="Фильтры каталога">
    <x-slot:head>
        <x-table.th sticky="start">Категория</x-table.th>
        <x-table.th>Фильтры</x-table.th>
        <x-table.th sticky="end"><span class="sr-only">Действия</span></x-table.th>
    </x-slot:head>

    @foreach($rows as $row)
        @php
            $category = $row['category'];
            $names = $row['keys']->map(fn ($key) => $available[$key] ?? null)->filter();
        @endphp

        <tr data-category-row="{{ $category->id }}">
            <x-table.td sticky="start">
                <span class="block min-w-32 max-w-60" style="padding-left: {{ $row['depth'] * 1.5 }}rem">{{ $category->name }}</span>
            </x-table.td>
            <x-table.td>
                <span class="block min-w-48 text-ink">{{ $names->implode(', ') }}</span>
                <span class="block text-meta text-ink-muted">
                    @if($row['own'])
                        своя настройка
                    @elseif($row['source'])
                        наследует от «{{ $row['source']->name }}»
                    @else
                        стандартный набор
                    @endif
                </span>
            </x-table.td>
            <x-table.td sticky="end" nowrap>
                <x-button variant="link" size="sm" href="/admin/catalog-filters/{{ $category->id }}">Настроить</x-button>
            </x-table.td>
        </tr>
    @endforeach
</x-table>

@endsection
