@extends('layouts.admin')

@section('title', 'Фильтры — ' . $category->name)

@section('content')

<a href="/admin/catalog-filters" class="text-sm text-slate-500 hover:text-blue-600">
    ← К фильтрам каталога
</a>

<h1 class="text-3xl font-bold mt-4 mb-6">
    Фильтры: {{ $category->name }}
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

<form method="POST" action="/admin/catalog-filters/{{ $category->id }}" class="bg-white rounded-2xl border border-slate-200 p-6 max-w-2xl space-y-6" x-data="{ mode: @js(old('mode', $inherits ? 'inherit' : 'own')) }">

    @csrf

    <fieldset class="space-y-2">

        <label class="flex items-start gap-3">
            <input type="radio" name="mode" value="inherit" x-model="mode" @checked(old('mode', $inherits ? 'inherit' : 'own') === 'inherit') class="mt-1">
            <span>
                Наследовать
                <span class="block text-sm text-slate-500">
                    @if($category->parent)
                        от «{{ $category->parent->name }}» —
                    @endif
                    сейчас: {{ $source ? '«' . $source->name . '»' : 'стандартный набор' }}
                </span>
            </span>
        </label>

        <label class="flex items-start gap-3">
            <input type="radio" name="mode" value="own" x-model="mode" @checked(old('mode', $inherits ? 'inherit' : 'own') === 'own') class="mt-1">
            <span>Своя настройка для этой категории и её подкатегорий без своей</span>
        </label>

    </fieldset>

    <div :class="mode === 'inherit' && 'opacity-50'">

        <p class="text-sm text-slate-500 mb-3">Отметьте фильтры; № — порядок на сайте (меньше — выше).</p>

        <ul class="divide-y divide-slate-200 border border-slate-200 rounded-xl" data-filter-options>

            @foreach($ordered as $i => $key)

                <li class="flex items-center gap-3 p-3">
                    <input
                        type="number"
                        name="order[{{ $key }}]"
                        value="{{ old("order.{$key}", $i + 1) }}"
                        min="1"
                        class="w-16 border rounded px-2 py-1"
                        aria-label="Порядок: {{ $available[$key] }}"
                    >
                    <label class="flex items-center gap-3 grow">
                        <input type="checkbox" name="filters[]" value="{{ $key }}" @checked(isset($enabled[$key]))>
                        {{ $available[$key] }}
                        @if(! array_key_exists($key, \App\Models\CategoryFilter::STANDARD))
                            <span class="text-xs text-slate-400">характеристика</span>
                        @endif
                    </label>
                </li>

            @endforeach

        </ul>

    </div>

    <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-white px-6 py-2.5 rounded">
        Сохранить
    </button>

</form>

@endsection
