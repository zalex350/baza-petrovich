{{-- Страница услуги (ServiceController::show): текст и «Заказать услугу» (заявка), справа — другие услуги --}}
@extends('layouts.app')

@section('title', ($page->seo_title ?: $page->title).' — '.config('shop.name'))

@php
    seo()->description($page->seo_description ?: $page->safeBody())
        ->breadcrumbs([['label' => 'Главная', 'url' => '/'], ['label' => 'Услуги', 'url' => '/uslugi'], ['label' => $page->title]])
        ->schema([
            '@type' => 'Service',
            'name' => $page->title,
            'description' => seo()->description,
            'areaServed' => ['@type' => 'Country', 'name' => 'Беларусь'],
            'provider' => ['@type' => 'Organization', 'name' => \App\Support\Seo::siteName(), 'url' => \App\Support\Seo::absolute('/')],
        ]);
@endphp

@section('content')

<x-breadcrumbs :items="[['label' => 'Главная', 'url' => '/'], ['label' => 'Услуги', 'url' => '/uslugi'], ['label' => $page->title]]" />

@unless($page->is_published)
    <x-alert type="warning" class="mt-3">Услуга не опубликована — покупатели её не видят.</x-alert>
@endunless

<div class="mt-3 grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-start">
    <article class="rounded-card border border-line bg-surface p-card" data-service-page="{{ $page->slug }}">
        <h1 class="mb-4 text-3xl font-bold tracking-tight">{{ $page->title }}</h1>

        <div class="prose-page text-ink" data-page-body>{!! $page->safeBody() !!}</div>

        <div class="mt-6">
            <x-button size="lg" :href="route('lead.create', ['type' => 'service', 'subject' => $page->title])" data-service-order>Заказать услугу</x-button>
        </div>
    </article>

    @if($others->isNotEmpty())
        <aside class="rounded-card border border-line bg-surface p-card lg:sticky lg:top-28" aria-label="Другие услуги">
            <h2 class="mb-3 font-semibold">Другие услуги</h2>
            <ul class="space-y-2 text-sm">
                @foreach($others as $other)
                    <li><a href="{{ $other->url() }}" class="text-link hover:underline">{{ $other->title }}</a></li>
                @endforeach
            </ul>
        </aside>
    @endif
</div>

@endsection
