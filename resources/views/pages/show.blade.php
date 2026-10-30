{{--
    Информационная страница (PageController): заголовок и текст из редактора админки
    (очищенный HTML — Page::safeBody()). SEO — seo_title / seo_description, иначе заголовок.
    На странице «Контакты» под текстом — блоки контактов и реквизитов (partials/contacts_page).
--}}
@extends('layouts.app')

@section('title', ($page->seo_title ?: $page->title).' — '.config('shop.name'))

@if($page->seo_description)
    @section('meta')
        <meta name="description" content="{{ $page->seo_description }}">
    @endsection
@endif

@section('content')

<x-breadcrumbs :items="[['label' => 'Главная', 'url' => '/'], ['label' => $page->title]]" />

@unless($page->is_published)
    <x-alert type="warning" class="mt-3">Страница не опубликована — покупатели её не видят.</x-alert>
@endunless

<article class="mt-3 max-w-3xl rounded-card border border-line bg-surface p-card" data-page="{{ $page->slug }}">
    <h1 class="mb-4 text-3xl font-bold tracking-tight">{{ $page->title }}</h1>

    <div class="prose-page text-ink" data-page-body>{!! $page->safeBody() !!}</div>

    {{-- «Контакты»: контакты и реквизиты — из «Настроек» --}}
    @if($page->slug === 'contacts')
        @include('partials.contacts_page')
    @endif
</article>

@endsection
