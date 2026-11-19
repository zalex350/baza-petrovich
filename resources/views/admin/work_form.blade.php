@extends('layouts.admin')

@section('title', $work->exists ? $work->title : 'Новый объект')

@section('flash-validation', 'summary')

@push('head')
    @vite('resources/js/editor.js')
@endpush

@section('content')

<a href="/admin/works" class="text-sm text-ink-muted hover:text-link">← Все объекты</a>

<div class="mt-4 mb-6 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-3xl font-bold">{{ $work->exists ? $work->title : 'Новый объект' }}</h1>
    @if($work->exists)
        <x-button variant="link" :href="$work->url()" target="_blank" rel="noopener">Открыть на сайте ↗</x-button>
    @endif
</div>

<form method="POST" action="{{ $work->exists ? '/admin/work/'.$work->id : '/admin/work' }}" enctype="multipart/form-data" class="max-w-4xl space-y-5 rounded-card border border-line bg-surface p-card" data-work-form>
    @csrf

    <x-input name="title" label="Название" :value="$work->title" required maxlength="255" placeholder="Забор из профнастила, д. Прудищи" />

    <div class="grid gap-4 sm:grid-cols-3">
        <x-input name="location" label="Место" :value="$work->location" maxlength="255" placeholder="Минский р-н, д. Прудищи" />
        <x-input name="tag" label="Метка (фильтр)" :value="$work->tag" maxlength="100" placeholder="Заборы" hint="Заборы, Ворота, Калитки, Кровля…" list="work-tags" />
        <x-input name="completed_on" type="date" label="Когда сделано" :value="$work->completed_on?->format('Y-m-d')" />
    </div>
    <datalist id="work-tags">
        @foreach(\App\Models\Work::query()->whereNotNull('tag')->distinct()->pluck('tag') as $t)<option value="{{ $t }}">@endforeach
    </datalist>

    <x-input name="slug" label="Адрес" :value="$work->slug" maxlength="150" hint="Латиница и дефис: /nashi-raboty/адрес. Пусто — из названия." />

    <x-field id="f-description" label="Описание" error="description">
        <input id="f-description" type="hidden" name="description" value="{{ old('description', $work->description) }}">
        <trix-editor input="f-description" class="prose-page min-h-48 rounded-control border border-line-strong bg-surface px-control-x py-control-y" aria-label="Описание объекта"></trix-editor>
    </x-field>

    <x-field id="f-photos" label="Добавить фото" error="photos" hint="Можно выбрать несколько (JPG, PNG, WebP). Первое по порядку — обложка.">
        <input id="f-photos" type="file" name="photos[]" multiple accept="image/jpeg,image/png,image/webp" class="block text-sm">
    </x-field>

    <fieldset class="grid gap-4 rounded-card border border-line p-4 sm:grid-cols-2">
        <legend class="px-1 text-sm font-semibold text-ink">Для поисковиков (SEO)</legend>
        <x-input name="seo_title" label="Заголовок окна (title)" :value="$work->seo_title" maxlength="255" />
        <x-input name="seo_description" label="Описание (description)" :value="$work->seo_description" maxlength="500" />
    </fieldset>

    <div class="flex flex-wrap items-end gap-6">
        <x-input name="sort_order" type="number" label="Порядок" :value="$work->sort_order" class="w-32" hint="Меньше — выше" />
        <x-checkbox name="is_published" label="Опубликован" :checked="$work->is_published" />
    </div>

    <div class="flex flex-wrap gap-3">
        <x-button>{{ $work->exists ? 'Сохранить' : 'Создать' }}</x-button>
        <x-button variant="secondary" href="/admin/works">Отмена</x-button>
    </div>
</form>

@if($work->exists && $work->images->isNotEmpty())
    <section class="mt-6 max-w-4xl rounded-card border border-line bg-surface p-card" data-work-photos>
        <h2 class="mb-3 text-lg font-semibold">Фото ({{ $work->images->count() }})</h2>
        <ul class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach($work->images as $image)
                <li class="flex flex-col gap-2">
                    <img src="{{ $image->url() }}" alt="" class="aspect-[4/3] w-full rounded-control object-cover">
                    <div class="flex flex-wrap gap-2">
                        @if(! $loop->first)
                            <form method="POST" action="/admin/work/{{ $work->id }}/image/{{ $image->id }}/cover">@csrf<x-button variant="secondary" size="sm">Обложка</x-button></form>
                        @else
                            <x-badge tone="info">обложка</x-badge>
                        @endif
                        <x-confirm :action="'/admin/work/'.$work->id.'/image/'.$image->id.'/delete'" label="Удалить" title="Удалить фото?" confirm-label="Удалить" trigger-variant="link" trigger-size="sm" />
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
@endif

@if($work->exists)
    <div class="mt-6">
        <x-confirm :action="'/admin/work/'.$work->id.'/delete'" label="Удалить объект" title="Удалить объект «{{ $work->title }}» вместе с фото?" confirm-label="Удалить" trigger-variant="link" trigger-size="sm" class="text-danger" />
    </div>
@endif

@endsection
