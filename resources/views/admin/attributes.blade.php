@extends('layouts.admin')

@section('title', 'Админка — Характеристики')

@section('content')

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-3xl font-bold">Характеристики</h1>
    <x-button href="/admin/attribute/create">Добавить характеристику</x-button>
</div>

<p class="text-sm text-slate-500 mb-4 max-w-3xl">
    Справочник для блока «Характеристики» в карточке товара. Название уникально без учёта регистра:
    «Цвет» и «цвет» — одна характеристика. Дубли с разным написанием («Цвет корпуса» и «Цвет») сводятся объединением.
</p>

<form method="GET" action="/admin/attributes" class="flex flex-wrap items-center gap-3 mb-6">

    <input type="text" name="q" value="{{ $search }}" placeholder="Название" class="w-full max-w-md border rounded px-3 py-2">

    <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded">
        Найти
    </button>

    @if($search !== '')
        <a href="/admin/attributes" class="text-sm text-slate-500 hover:text-slate-900 underline">Сбросить</a>
    @endif

</form>

@if($errors->any())
    <div class="bg-red-50 border border-red-200 rounded-2xl p-4 mb-6">
        <ul class="list-disc list-inside text-red-700 space-y-1">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if($attributes->isEmpty())

    <div class="bg-white rounded-2xl border border-slate-200 p-6 text-slate-500">
        {{ $search !== '' ? 'Ничего не найдено.' : 'Характеристик пока нет. Их можно добавить здесь или прямо в форме товара.' }}
    </div>

@else

    {{-- Объединение: отметить дубли, выбрать основную --}}
    <form id="merge-form" method="POST" action="/admin/attributes/merge" onsubmit="return confirm('Объединить отмеченные характеристики? Их значения перейдут к основной, сами они будут удалены.')">
        @csrf
    </form>

    {{-- На узком экране таблица прокручивается вбок; название и действия закреплены --}}
    <x-table caption="Характеристики" class="mb-4">
        <x-slot:head>
            <x-table.th class="w-10"><span class="sr-only">Объединить</span></x-table.th>
            <x-table.th sticky="start">Название</x-table.th>
            <x-table.th>Единица</x-table.th>
            <x-table.th>Тип</x-table.th>
            <x-table.th>В фильтрах</x-table.th>
            <x-table.th align="right">Товаров</x-table.th>
            <x-table.th sticky="end"><span class="sr-only">Действия</span></x-table.th>
        </x-slot:head>

        @foreach($attributes as $attribute)
            <tr data-attribute-row="{{ $attribute->id }}">
                <x-table.td>
                    <input type="checkbox" name="source_ids[]" value="{{ $attribute->id }}" form="merge-form" aria-label="Объединить «{{ $attribute->name }}»">
                </x-table.td>
                <x-table.td sticky="start"><span class="block min-w-32 max-w-56 font-medium text-ink">{{ $attribute->name }}</span></x-table.td>
                <x-table.td nowrap>{{ $attribute->unit ?? '—' }}</x-table.td>
                <x-table.td nowrap>{{ \App\Models\ProductAttribute::TYPES[$attribute->type] ?? $attribute->type }}</x-table.td>
                <x-table.td nowrap>{{ $attribute->is_filterable ? 'да' : '—' }}</x-table.td>
                <x-table.td align="right" nowrap>{{ $attribute->values_count }}</x-table.td>
                <x-table.td sticky="end" nowrap>
                    <div class="flex flex-col items-start gap-1 sm:flex-row sm:items-center sm:gap-3">
                        <x-button variant="link" size="sm" href="/admin/attribute/{{ $attribute->id }}/edit">Изменить</x-button>
                        @if($attribute->values_count === 0)
                            <x-confirm
                                action="/admin/attribute/{{ $attribute->id }}/delete"
                                label="Удалить"
                                title="Удалить характеристику?"
                                confirm-label="Удалить"
                                trigger-variant="link"
                                trigger-size="sm"
                            >
                                Характеристика «{{ $attribute->name }}» будет удалена.
                            </x-confirm>
                        @endif
                    </div>
                </x-table.td>
            </tr>
        @endforeach
    </x-table>

    <div class="bg-white rounded-2xl border border-slate-200 p-4 mb-6 flex flex-wrap items-center gap-3 text-sm">
        <span class="font-semibold">Объединить отмеченные в</span>
        <select name="target_id" form="merge-form" class="border rounded px-3 py-2" required>
            <option value="">— основная характеристика —</option>
            @foreach($attributes as $attribute)
                <option value="{{ $attribute->id }}">{{ $attribute->name }}{{ $attribute->unit ? ", {$attribute->unit}" : '' }}</option>
            @endforeach
        </select>
        <button type="submit" form="merge-form" class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded">
            Объединить
        </button>
        <span class="text-slate-500">Если у товара есть обе — остаётся значение основной.</span>
    </div>

    <x-pagination :paginator="$attributes" />

@endif

@endsection
