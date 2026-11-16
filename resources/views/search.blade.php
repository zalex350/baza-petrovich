@extends('layouts.app')

@section('title', 'Поиск')

@section('content')

{{-- Результаты поиска — те же строки товаров, что в каталоге (x-product-row). Данные: $query, $products, $cartItems --}}

<x-breadcrumbs :items="[['label' => 'Главная', 'url' => '/'], ['label' => 'Поиск']]" />

<div class="mt-3 mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-3xl font-bold tracking-tight">
            @if($query !== '')
                Поиск: «{{ $query }}»
            @else
                Поиск
            @endif
        </h1>
        <p class="mt-1 text-ink-muted" data-found>Найдено товаров: {{ $products->total() }}</p>
    </div>

    {{-- Сортировка (SearchController::sorts): по цене — только после входа; в адресе — sort, с первой страницы --}}
    @if($products->total() > 1 && count(\App\Http\Controllers\SearchController::sorts()) > 1)
        <form method="GET" action="{{ route('search.index') }}" class="flex items-center gap-2" data-search-sort>
            <input type="hidden" name="q" value="{{ $query }}">
            <label for="f-sort" class="text-sm text-ink-muted">Сортировка</label>
            <x-select
                name="sort"
                :options="\App\Http\Controllers\SearchController::sorts()"
                :value="$sort ?? 'relevance'"
                class="text-sm"
                x-data
                x-on:change="$el.form.submit()"
            />
            <noscript><x-button size="sm" variant="secondary">Применить</x-button></noscript>
        </form>
    @endif
</div>

@if($products->isEmpty())

    <x-empty-state title="По вашему запросу ничего не найдено" icon="magnifying-glass" data-empty>
        @if($didYouMean ?? [])
            <span class="block" data-did-you-mean>Возможно, вы искали
                @foreach($didYouMean as $article)
                    <x-button variant="link" :href="route('search.index', ['q' => $article])">{{ $article }}</x-button>@if(! $loop->last), @endif
                @endforeach
            </span>
        @endif
        Проверьте артикул или название, попробуйте запрос короче.
        <x-slot:action>
            <x-button variant="secondary" :href="route('catalog.index')">Перейти в каталог</x-button>
        </x-slot:action>
    </x-empty-state>

@else

    <div class="divide-y divide-line-soft overflow-hidden rounded-card border border-line bg-surface" data-product-list>
        @foreach($products as $product)
            <x-product-row :product="$product" :in-cart="$cartItems[$product->id] ?? null" />
        @endforeach
    </div>

    <div class="mt-5" data-pagination>
        <x-pagination :paginator="$products" />
    </div>

@endif

@endsection
