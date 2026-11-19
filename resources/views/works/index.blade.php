{{-- «Наши работы» (WorkController::index): карточки объектов, фильтр по метке, пагинация --}}
@extends('layouts.app')

@section('title', 'Наши работы — '.config('shop.name'))

@section('content')

<x-breadcrumbs :items="[['label' => 'Главная', 'url' => '/'], ['label' => 'Наши работы']]" />

<div class="mt-3 mb-5 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-3xl font-bold tracking-tight">Наши работы</h1>
        <p class="mt-1 max-w-2xl text-ink-muted">Заборы, ворота и кровля «под ключ». Многие объекты можно посмотреть вживую — спросите у менеджера.</p>
    </div>
    <x-button :href="route('lead.create', ['type' => 'service', 'subject' => 'Монтаж под ключ'])">Рассчитать мой объект</x-button>
</div>

@if($tags->count() > 1)
    <nav class="mb-5 flex flex-wrap gap-2" aria-label="Тип работ" data-work-tags>
        <a href="/nashi-raboty" @class(['rounded-full border px-3 py-1.5 text-sm', 'border-primary bg-primary text-white' => ! $tag, 'border-line bg-surface hover:border-primary' => $tag])>Все</a>
        @foreach($tags as $t)
            <a href="/nashi-raboty?tag={{ urlencode($t) }}" @class(['rounded-full border px-3 py-1.5 text-sm', 'border-primary bg-primary text-white' => $tag === $t, 'border-line bg-surface hover:border-primary' => $tag !== $t])>{{ $t }}</a>
        @endforeach
    </nav>
@endif

@if($works->isEmpty())
    <x-empty-state title="Пока нет опубликованных работ" icon="photo" />
@else
    <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-works>
        @foreach($works as $work)
            <li>
                <x-work-card :work="$work" />
            </li>
        @endforeach
    </ul>
    <div class="mt-6"><x-pagination :paginator="$works" /></div>
@endif

@endsection
