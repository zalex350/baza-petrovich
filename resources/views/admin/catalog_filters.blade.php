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

<div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">

    <table class="w-full text-sm">

        <thead class="bg-slate-50 text-left text-slate-600">
            <tr>
                <th class="p-3">Категория</th>
                <th class="p-3">Фильтры</th>
                <th class="p-3"></th>
            </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">

            @foreach($rows as $row)

                @php
                    $category = $row['category'];
                    $names = $row['keys']->map(fn ($key) => $available[$key] ?? null)->filter();
                @endphp

                <tr data-category-row="{{ $category->id }}">
                    <td class="p-3" style="padding-left: {{ 0.75 + $row['depth'] * 1.5 }}rem">
                        {{ $category->name }}
                    </td>
                    <td class="p-3">
                        <span class="text-slate-800">{{ $names->implode(', ') }}</span>
                        <span class="block text-xs text-slate-400">
                            @if($row['own'])
                                своя настройка
                            @elseif($row['source'])
                                наследует от «{{ $row['source']->name }}»
                            @else
                                стандартный набор
                            @endif
                        </span>
                    </td>
                    <td class="p-3 text-right">
                        <a href="/admin/catalog-filters/{{ $category->id }}" class="text-blue-600 hover:underline">Настроить</a>
                    </td>
                </tr>

            @endforeach

        </tbody>

    </table>

</div>

@endsection
