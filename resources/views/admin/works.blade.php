@extends('layouts.admin')

@section('title', 'Наши работы')

@section('content')

<div class="mb-2 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-3xl font-bold">Наши работы</h1>
    <x-button href="/admin/work/create">Добавить объект</x-button>
</div>

<p class="mb-6 max-w-3xl text-sm text-ink-muted">Объекты на странице <a href="/nashi-raboty" class="text-link hover:underline" target="_blank">«Наши работы»</a> и на главной. Первое фото — обложка.</p>

@if($works->isEmpty())
    <x-empty-state title="Объектов пока нет" icon="photo" />
@else
    <x-table stack="md" caption="Наши работы">
        <x-slot:head>
            <x-table.th>Фото</x-table.th>
            <x-table.th>Объект</x-table.th>
            <x-table.th>Метка</x-table.th>
            <x-table.th align="right">Фото</x-table.th>
            <x-table.th>Статус</x-table.th>
        </x-slot:head>
        @foreach($works as $work)
            <tr data-work-row="{{ $work->id }}">
                <x-table.td label="Фото">
                    @if($work->cover)<img src="{{ $work->cover->url() }}" alt="" class="h-14 w-20 rounded-control object-cover">@endif
                </x-table.td>
                <x-table.td label="Объект">
                    <a href="/admin/work/{{ $work->id }}" class="font-medium text-link hover:underline">{{ $work->title }}</a>
                    @if($work->location)<span class="block text-meta text-ink-muted">{{ $work->location }}</span>@endif
                </x-table.td>
                <x-table.td label="Метка">{{ $work->tag ?: '—' }}</x-table.td>
                <x-table.td label="Фото" align="right">{{ $work->images_count }}</x-table.td>
                <x-table.td label="Статус"><x-badge :tone="$work->is_published ? 'success' : 'neutral'">{{ $work->is_published ? 'опубликован' : 'скрыт' }}</x-badge></x-table.td>
            </tr>
        @endforeach
    </x-table>
    <div class="mt-4"><x-pagination :paginator="$works" /></div>
@endif

@endsection
