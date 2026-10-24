@extends('layouts.app')

@section('title', $category->name)

@section('content')

<x-breadcrumbs :items="$breadcrumbs" />

<div class="mt-3 mb-5 flex flex-wrap items-baseline gap-x-3 gap-y-1">
    <h1 class="text-3xl font-bold tracking-tight">{{ $category->name }}</h1>
    {{-- Обновляется вместе со списком (catalog-filters.js) --}}
    <span class="text-ink-muted" data-found aria-live="polite">{{ \App\Support\Plural::ru($products->total(), ['товар', 'товара', 'товаров']) }}</span>
</div>

{{-- Подкатегории: страница раздела показывает товары всех вложенных подкатегорий, чипы — переход в одну --}}
@if($subcategories)
    {{-- Видно первые $brief, остальные — по «Показать все» --}}
    @php($brief = 12)
    @php($more = count($subcategories) > $brief)
    <nav aria-label="Подкатегории" class="mb-5" data-subcategories @if($more) x-data="{ all: false }" @endif>
        <ul class="flex flex-wrap gap-2">
            @foreach($subcategories as $sub)
                <li @if($more && $loop->index >= $brief) x-show="all" x-cloak @endif>
                    <a href="{{ $sub['url'] }}" class="inline-flex items-center gap-1.5 rounded-full border border-line bg-surface px-3 py-1.5 text-sm text-ink hover:border-primary hover:text-primary" data-subcategory>
                        {{ $sub['name'] }}
                        <span class="text-meta text-ink-muted tabular-nums">{{ $sub['count'] }}</span>
                    </a>
                </li>
            @endforeach

            @if($more)
                <li>
                    <button type="button" class="px-2 py-1.5 text-sm text-link hover:underline" @click="all = ! all" :aria-expanded="all.toString()" x-text="all ? 'Свернуть' : 'Показать все ({{ count($subcategories) }})'">
                        Показать все ({{ count($subcategories) }})
                    </button>
                </li>
            @endif
        </ul>
    </nav>
@endif

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
