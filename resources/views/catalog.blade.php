@extends('layouts.app')

@section('title', $category->name)

@section('content')

<x-breadcrumbs :items="$breadcrumbs" />

<div class="mt-3 mb-5 flex flex-wrap items-baseline gap-x-3 gap-y-1">
    <h1 class="text-3xl font-bold tracking-tight">{{ $category->name }}</h1>
    {{-- Обновляется вместе со списком (catalog-filters.js) --}}
    <span class="text-ink-muted" data-found aria-live="polite">{{ \App\Support\Plural::ru($products->total(), ['товар', 'товара', 'товаров']) }}</span>
</div>

{{-- Фильтры слева (на мобильном — выезжающая панель), список справа (SRS §3.9, §3.10) --}}
<div
    class="lg:grid lg:grid-cols-[17.5rem_minmax(0,1fr)] lg:items-start lg:gap-6"
    x-data="catalogFilters()"
    :class="loading && 'opacity-60 transition-opacity'"
    data-catalog
>

    <div>
        @include('partials.catalog_filters')
    </div>

    <div>
        @include('partials.catalog_results')
    </div>

</div>

@endsection
