{{-- «Отзывы» (WorkController::reviews): по группам (Google и Яндекс — первыми, сканы писем — в конце);
     ссылки на отзывы в Яндекс Картах и Google (config shop.reviews_url, shop.google_reviews_url) --}}
@extends('layouts.app')

@section('title', 'Отзывы — '.config('shop.name'))

@php(seo()->description('Отзывы покупателей металлобазы «'.\App\Support\Seo::siteName().'»: отзывы в Google и Яндексе о заборах «под ключ», воротах, доставке металла и монтаже.'))

@section('content')

<x-breadcrumbs :items="[['label' => 'Главная', 'url' => '/'], ['label' => 'Отзывы']]" />

<div class="mt-3 mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-3xl font-bold tracking-tight">Отзывы</h1>
        <p class="mt-1 max-w-2xl text-ink-muted">Что говорят клиенты о доставке металла и заборах «под ключ». По согласованию дадим телефоны клиентов — сможете уточнить сами.</p>
    </div>
    <div class="flex flex-wrap gap-3">
        @if($google)
            <x-button variant="secondary" :href="$google" target="_blank" rel="noopener" data-reviews-google>Отзывы в Google ↗</x-button>
        @endif
        @if($yandex)
            <x-button variant="secondary" :href="$yandex" target="_blank" rel="noopener" data-reviews-yandex>Отзывы на Яндекс Картах ↗</x-button>
        @endif
        <x-button :href="route('lead.create', ['type' => 'callback'])">Задать вопрос</x-button>
    </div>
</div>

@forelse($groups as $group => $reviews)
    <section class="mb-8" data-review-group>
        <h2 class="mb-3 text-xl font-bold">{{ $group }}</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach($reviews as $review)
                <x-review-card :review="$review" />
            @endforeach
        </div>
    </section>
@empty
    <x-empty-state title="Отзывов пока нет" icon="chat-bubble-left-right" />
@endforelse

@endsection
