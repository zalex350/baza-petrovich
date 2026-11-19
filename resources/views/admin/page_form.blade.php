@extends('layouts.admin')

@section('title', $page->exists ? $page->title : 'Новая страница')

{{-- Ошибки — у полей, сверху — один alert «Проверьте выделенные поля» --}}
@section('flash-validation', 'summary')

@push('head')
    @vite('resources/js/editor.js')
@endpush

@php
    $menu = $page->exists && isset(\App\Models\Page::MENU[$page->slug]);
@endphp

@section('content')

<a href="/admin/pages" class="text-sm text-ink-muted hover:text-link">← Все страницы</a>

<div class="mt-4 mb-6 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-3xl font-bold">{{ $page->exists ? $page->title : 'Новая страница' }}</h1>
    @if($page->exists)
        <x-button variant="link" :href="$page->url()" target="_blank" rel="noopener">Открыть на сайте ↗</x-button>
    @endif
</div>

<form
    method="POST"
    action="{{ $page->exists ? '/admin/page/'.$page->id : '/admin/page' }}"
    class="max-w-4xl space-y-5 rounded-card border border-line bg-surface p-card"
    data-page-form
>
    @csrf

    <x-input name="title" label="Заголовок" :value="$page->title" required maxlength="255" />

    @if($menu)
        <x-field id="f-slug" label="Адрес">
            <p class="text-ink" data-page-slug>{{ $page->url() }} <span class="text-sm text-ink-muted">— страница главного меню, адрес не меняется</span></p>
        </x-field>
    @else
        <x-input name="slug" label="Адрес" :value="$page->slug" maxlength="100" placeholder="garantiya" hint="Латиница, цифры и дефис: страница — /page/адрес, услуга — /uslugi/адрес, статья — /stati/адрес. Пусто — из заголовка. Сменили — старый адрес ведёт на новый." />
        <x-select name="type" label="Тип" :options="\App\Models\Page::TYPES" :value="$page->type ?: 'page'" class="max-w-xs" hint="Услуга — в разделе «Услуги» с кнопкой «Заказать услугу»; статья — в разделе «Статьи»." />
    @endif

    @if(! $menu)
        <fieldset class="space-y-4 rounded-card border border-line p-4" data-article-fields>
            <legend class="px-1 text-sm font-semibold text-ink">Для статьи</legend>
            <x-input name="published_at" type="date" label="Дата" :value="$page->published_at?->toDateString()" class="max-w-xs" />
            <x-textarea name="excerpt" label="Анонс" :value="$page->excerpt" rows="2" maxlength="500" hint="Под заголовком в списке статей. Пусто — начало текста." />
            <x-input name="image" label="Обложка (адрес картинки)" :value="$page->image" hint="Адрес на сайте (/storage/…) или https://…; пусто — без обложки." />
        </fieldset>
    @endif

    {{-- Текст: Trix пишет HTML в скрытое поле body; на сайте HTML очищается (App\Support\Html) --}}
    <x-field id="f-body" label="Текст" error="body">
        <input id="f-body" type="hidden" name="body" value="{{ old('body', $page->body) }}">
        <trix-editor input="f-body" class="prose-page min-h-64 rounded-control border border-line-strong bg-surface px-control-x py-control-y" aria-label="Текст страницы"></trix-editor>
    </x-field>

    <fieldset class="space-y-4 rounded-card border border-line p-4">
        <legend class="px-1 text-sm font-semibold text-ink">Для поисковиков (SEO)</legend>
        <x-input name="seo_title" label="Заголовок окна (title)" :value="$page->seo_title" maxlength="255" hint="Пусто — заголовок страницы." />
        <x-textarea name="seo_description" label="Описание (description)" :value="$page->seo_description" rows="2" maxlength="500" />
    </fieldset>

    <x-checkbox name="is_published" label="Опубликована" hint="Неопубликованную видит только администратор." :checked="$page->is_published" />

    <div class="flex flex-wrap items-center gap-3">
        <x-button>{{ $page->exists ? 'Сохранить' : 'Создать страницу' }}</x-button>
        <x-button variant="secondary" href="/admin/pages">Отмена</x-button>

        @if($page->exists && ! $menu)
            <x-confirm
                :action="'/admin/page/'.$page->id.'/delete'"
                label="Удалить"
                title="Удалить страницу?"
                confirm-label="Удалить"
                variant="danger"
                trigger-variant="link"
                class="ml-auto"
            >
                Страница «{{ $page->title }}» будет удалена.
            </x-confirm>
        @endif
    </div>
</form>

@endsection
