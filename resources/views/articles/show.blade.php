{{-- Статья (ArticleController::show): дата, текст с фото, «Рассчитать забор», другие статьи --}}
@extends('layouts.app')

@section('title', ($page->seo_title ?: $page->title).' — '.config('shop.name'))

@php
    seo()->description($page->seo_description ?: $page->summary(200))
        ->image($page->imageUrl())
        ->type('article')
        ->breadcrumbs([['label' => 'Главная', 'url' => '/'], ['label' => 'Статьи', 'url' => '/stati'], ['label' => $page->title]])
        ->schema(array_filter([
            '@type' => 'Article',
            'headline' => \Illuminate\Support\Str::limit($page->title, 110, ''),
            'datePublished' => $page->published_at?->toAtomString(),
            'dateModified' => $page->updated_at?->toAtomString(),
            'image' => $page->imageUrl() ? [\App\Support\Seo::absolute($page->imageUrl())] : null,
            'author' => ['@type' => 'Organization', 'name' => \App\Support\Seo::siteName()],
            'publisher' => ['@type' => 'Organization', 'name' => \App\Support\Seo::siteName(), 'logo' => ['@type' => 'ImageObject', 'url' => \App\Support\Seo::absolute(config('seo.og_image'))]],
            'mainEntityOfPage' => \App\Support\Seo::absolute($page->url()),
        ]));
@endphp

@section('content')

<x-breadcrumbs :items="[['label' => 'Главная', 'url' => '/'], ['label' => 'Статьи', 'url' => '/stati'], ['label' => $page->title]]" />

@unless($page->is_published)
    <x-alert type="warning" class="mt-3">Статья не опубликована — покупатели её не видят.</x-alert>
@endunless

<article class="mt-3 max-w-3xl rounded-card border border-line bg-surface p-card" data-article="{{ $page->slug }}">
    <h1 class="text-3xl font-bold tracking-tight">{{ $page->title }}</h1>
    @if($page->published_at)
        <time datetime="{{ $page->published_at->toDateString() }}" class="mt-1 block text-sm text-ink-muted">{{ date_ru($page->published_at) }}</time>
    @endif

    <div class="prose-page mt-5 text-ink" data-page-body>{!! $page->safeBody() !!}</div>

    <div class="mt-6 flex flex-wrap gap-3 border-t border-line pt-5">
        <x-button :href="route('lead.create', ['type' => 'service', 'subject' => $page->title])">Рассчитать стоимость</x-button>
        <x-button variant="secondary" href="/catalog">Перейти в каталог</x-button>
    </div>
</article>

@if($more->isNotEmpty())
    <section class="mt-8" aria-label="Другие статьи">
        <h2 class="mb-3 text-xl font-semibold">Читайте также</h2>
        <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($more as $article)
                <li><x-article-card :article="$article" /></li>
            @endforeach
        </ul>
    </section>
@endif

@endsection
