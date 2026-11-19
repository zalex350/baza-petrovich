@extends('layouts.admin')

@section('title', 'Отзывы')

@section('content')

<div class="mb-2 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-3xl font-bold">Отзывы</h1>
    <x-button href="/admin/review/create">Добавить отзыв</x-button>
</div>

<p class="mb-6 max-w-3xl text-sm text-ink-muted">Страница <a href="/otzyvy" class="text-link hover:underline" target="_blank">«Отзывы»</a> и блок на главной. Отзыв — текст и/или фото (скан письменного отзыва).</p>

@if($reviews->isEmpty())
    <x-empty-state title="Отзывов пока нет" icon="chat-bubble-left-right" />
@else
    <x-table stack="md" caption="Отзывы">
        <x-slot:head>
            <x-table.th>Фото</x-table.th>
            <x-table.th>Отзыв</x-table.th>
            <x-table.th>Группа</x-table.th>
            <x-table.th>Статус</x-table.th>
        </x-slot:head>
        @foreach($reviews as $review)
            <tr data-review-row="{{ $review->id }}">
                <x-table.td label="Фото">@if($review->imageUrl())<img src="{{ $review->imageUrl() }}" alt="" class="h-16 w-12 rounded-control object-cover">@endif</x-table.td>
                <x-table.td label="Отзыв">
                    <a href="/admin/review/{{ $review->id }}" class="font-medium text-link hover:underline">{{ $review->author ?: 'Без автора' }}</a>
                    <span class="block max-w-md truncate text-meta text-ink-muted">{{ \Illuminate\Support\Str::limit((string) $review->text, 120) ?: 'скан' }}</span>
                </x-table.td>
                <x-table.td label="Группа">{{ $review->group ?: '—' }}</x-table.td>
                <x-table.td label="Статус"><x-badge :tone="$review->is_published ? 'success' : 'neutral'">{{ $review->is_published ? 'опубликован' : 'скрыт' }}</x-badge></x-table.td>
            </tr>
        @endforeach
    </x-table>
    <div class="mt-4"><x-pagination :paginator="$reviews" /></div>
@endif

@endsection
