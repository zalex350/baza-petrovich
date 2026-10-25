@extends('layouts.admin')

@php($isNew = ! $attribute->exists)

@section('title', $isNew ? 'Новая характеристика' : 'Характеристика')

@section('content')

<a href="/admin/attributes" class="text-sm text-slate-500 hover:text-blue-600">
    ← К справочнику характеристик
</a>

<h1 class="text-3xl font-bold mt-4 mb-6">
    {{ $isNew ? 'Новая характеристика' : $attribute->name }}
</h1>

@if($errors->any())
    <div class="bg-red-50 border border-red-200 rounded-2xl p-4 mb-6">
        <ul class="list-disc list-inside text-red-700 space-y-1">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form
    method="POST"
    action="{{ $isNew ? '/admin/attribute' : "/admin/attribute/{$attribute->id}" }}"
    class="bg-white rounded-2xl border border-slate-200 p-6 space-y-6 max-w-2xl"
>
    @csrf

    <div>
        <label for="a-name" class="block mb-2 font-semibold">Название</label>
        <input id="a-name" type="text" name="name" value="{{ old('name', $attribute->name) }}" class="w-full border rounded px-3 py-2" required maxlength="255">
        @unless($isNew)
            <p class="text-sm text-slate-500 mt-1">Переименование меняет название у всех товаров ({{ $attribute->values_count }}).</p>
        @endunless
    </div>

    <div class="grid gap-6 sm:grid-cols-2">

        <div>
            <label for="a-unit" class="block mb-2 font-semibold">Единица измерения</label>
            <input id="a-unit" type="text" name="unit" value="{{ old('unit', $attribute->unit) }}" placeholder="мм, л, кг" class="w-full border rounded px-3 py-2" maxlength="50">
        </div>

        <div>
            <label for="a-type" class="block mb-2 font-semibold">Тип значения</label>
            <select id="a-type" name="type" class="w-full border rounded px-3 py-2">
                @foreach(\App\Models\ProductAttribute::TYPES as $value => $label)
                    <option value="{{ $value }}" @selected(old('type', $attribute->type) === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <p class="text-sm text-slate-500 mt-1">Число — фильтр в каталоге «от/до», текст — список значений.</p>
        </div>

    </div>

    <label class="flex items-center gap-3">
        <input type="checkbox" name="is_filterable" value="1" @checked(old('is_filterable', $attribute->is_filterable))>
        Используется в фильтрах каталога
    </label>

    <x-checkbox name="is_logistics" label="Упаковка и логистика" hint="В карточке товара — в свёрнутом блоке «Упаковка и логистика», а не в характеристиках" :checked="$attribute->is_logistics" />

    <x-checkbox name="is_admin_only" label="Только в админке" hint="На сайте не показывается (например, код ТН ВЭД)" :checked="$attribute->is_admin_only" />

    <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-white px-6 py-2.5 rounded">
        Сохранить
    </button>

</form>

@endsection
