{{-- Объект «Наши работы» (WorkController::show): галерея с просмотром, описание, отзывы, «Хочу так же», похожие --}}
@extends('layouts.app')

@section('title', ($work->seo_title ?: $work->title).' — Наши работы — '.config('shop.name'))

@php
    seo()->description($work->seo_description ?: $work->safeDescription() ?: $work->title.' — выполненный объект металлобазы «'.\App\Support\Seo::siteName().'».')
        ->image($work->images->first()?->url())
        ->type('article')
        ->breadcrumbs([['label' => 'Главная', 'url' => '/'], ['label' => 'Наши работы', 'url' => '/nashi-raboty'], ['label' => $work->title]]);
@endphp

@section('content')

<x-breadcrumbs :items="[['label' => 'Главная', 'url' => '/'], ['label' => 'Наши работы', 'url' => '/nashi-raboty'], ['label' => $work->title]]" />

@unless($work->is_published)
    <x-alert type="warning" class="mt-3">Объект не опубликован — покупатели его не видят.</x-alert>
@endunless

<div class="mt-3 grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start" data-work="{{ $work->slug }}">
    <div class="min-w-0">
        <h1 class="mb-1 text-3xl font-bold tracking-tight">{{ $work->title }}</h1>
        <p class="mb-4 text-ink-muted">
            {{ collect([$work->tag, $work->location, $work->completed_on ? $work->completed_on->format('Y') : null])->filter()->implode(' · ') }}
        </p>

        @if($work->images->isNotEmpty())
            <x-photo-gallery :urls="$work->images->map->url()->all()" :alt="$work->title" />
        @endif

        @if(trim(strip_tags($work->safeDescription())) !== '')
            <div class="prose-page mt-6 rounded-card border border-line bg-surface p-card text-ink" data-work-description>{!! $work->safeDescription() !!}</div>
        @endif

        @if($reviews->isNotEmpty())
            <section class="mt-6" aria-label="Отзыв клиента">
                <h2 class="mb-3 text-xl font-bold">Отзыв клиента</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach($reviews as $review)
                        <x-review-card :review="$review" />
                    @endforeach
                </div>
            </section>
        @endif
    </div>

    <aside class="flex flex-col gap-3 rounded-card border border-line bg-surface p-card lg:sticky lg:top-28">
        <h2 class="text-lg font-bold">Хотите так же?</h2>
        <p class="text-sm text-ink-muted">Бесплатно рассчитаем материалы и стоимость монтажа под ваш участок.</p>
        <x-button :href="route('lead.create', ['type' => 'service', 'subject' => 'Как на объекте: '.$work->title])" data-work-order>Рассчитать стоимость</x-button>
        <x-button variant="secondary" href="/uslugi">Все услуги</x-button>
    </aside>
</div>

@if($more->isNotEmpty())
    <section class="mt-10" aria-label="Похожие работы">
        <div class="mb-3 flex items-end justify-between gap-4">
            <h2 class="text-2xl font-bold tracking-tight">Похожие работы</h2>
            <a href="/nashi-raboty" class="text-sm font-medium text-link hover:underline">Все работы →</a>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($more as $w)
                <x-work-card :work="$w" />
            @endforeach
        </div>
    </section>
@endif

@endsection
