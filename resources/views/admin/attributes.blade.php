@extends('layouts.admin')

@section('title', 'Админка — Характеристики')

@section('content')

<div class="flex items-center justify-between mb-6">

    <h1 class="text-3xl font-bold">
        Характеристики
    </h1>

    <a
        href="/admin/attribute/create"
        class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded"
    >
        Добавить характеристику
    </a>

</div>

<p class="text-sm text-slate-500 mb-4 max-w-3xl">
    Справочник для блока «Характеристики» в карточке товара. Название уникально без учёта регистра:
    «Цвет» и «цвет» — одна характеристика. Дубли с разным написанием («Цвет корпуса» и «Цвет») сводятся объединением.
</p>

<form method="GET" action="/admin/attributes" class="flex items-center gap-3 mb-6">

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

    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden mb-4">

        <table class="w-full text-sm">

            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="p-3 w-10" title="Отметить для объединения"><span class="sr-only">Объединить</span></th>
                    <th class="p-3">Название</th>
                    <th class="p-3">Единица</th>
                    <th class="p-3">Тип</th>
                    <th class="p-3">В фильтрах</th>
                    <th class="p-3 text-right">Товаров</th>
                    <th class="p-3"></th>
                </tr>
            </thead>

            <tbody class="divide-y divide-slate-200">

                @foreach($attributes as $attribute)

                    <tr data-attribute-row="{{ $attribute->id }}">
                        <td class="p-3">
                            <input type="checkbox" name="source_ids[]" value="{{ $attribute->id }}" form="merge-form" aria-label="Объединить «{{ $attribute->name }}»">
                        </td>
                        <td class="p-3 font-medium">{{ $attribute->name }}</td>
                        <td class="p-3 text-slate-500">{{ $attribute->unit ?? '—' }}</td>
                        <td class="p-3">{{ \App\Models\ProductAttribute::TYPES[$attribute->type] ?? $attribute->type }}</td>
                        <td class="p-3">{{ $attribute->is_filterable ? 'да' : '—' }}</td>
                        <td class="p-3 text-right">{{ $attribute->values_count }}</td>
                        <td class="p-3 text-right whitespace-nowrap">
                            <a href="/admin/attribute/{{ $attribute->id }}/edit" class="text-blue-600 hover:underline">Изменить</a>
                            @if($attribute->values_count === 0)
                                <form method="POST" action="/admin/attribute/{{ $attribute->id }}/delete" class="inline ml-3" onsubmit="return confirm('Удалить характеристику «{{ addslashes($attribute->name) }}»?')">
                                    @csrf
                                    <button type="submit" class="text-red-600 hover:underline">Удалить</button>
                                </form>
                            @endif
                        </td>
                    </tr>

                @endforeach

            </tbody>

        </table>

    </div>

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
