@extends('layouts.admin')

@section('title', 'Страницы')

@section('content')

<div class="flex flex-wrap items-center justify-between gap-3 mb-2">
    <h1 class="text-3xl font-bold">Страницы</h1>
    <x-button href="/admin/page/create">Добавить страницу</x-button>
</div>

<p class="text-sm text-ink-muted mb-6 max-w-3xl">
    Информационные страницы сайта. «Доставка и оплата», «О компании» и «Контакты» — в главном меню и подвале,
    их адрес не меняется и удалить их нельзя. Не заполненное отмечено [ЗАПОЛНИТЬ].
</p>

<x-table stack caption="Страницы">
    <x-slot:head>
        <x-table.th>Заголовок</x-table.th>
        <x-table.th>Адрес</x-table.th>
        <x-table.th>Статус</x-table.th>
        <x-table.th><span class="sr-only">Действия</span></x-table.th>
    </x-slot:head>

    @foreach($pages as $page)
        <tr data-page-row="{{ $page->slug }}">
            <x-table.td label="Заголовок">
                <a href="/admin/page/{{ $page->id }}" class="font-medium text-link hover:underline">{{ $page->title }}</a>
                @if(str_contains((string) $page->body, \App\Models\Setting::PLACEHOLDER))
                    <x-badge tone="warning">не заполнена</x-badge>
                @endif
            </x-table.td>
            <x-table.td label="Адрес"><a href="{{ $page->url() }}" target="_blank" rel="noopener" class="text-ink-muted hover:text-link">{{ $page->url() }}</a></x-table.td>
            <x-table.td label="Статус">
                <x-badge :tone="$page->is_published ? 'success' : 'neutral'">{{ $page->is_published ? 'Опубликована' : 'Черновик' }}</x-badge>
            </x-table.td>
            <x-table.td>
                <x-button variant="secondary" size="sm" :href="'/admin/page/'.$page->id">Изменить</x-button>
            </x-table.td>
        </tr>
    @endforeach
</x-table>

@endsection
