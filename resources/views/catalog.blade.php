@extends('layouts.app')

@section('title', $category->name)

@section('content')

<x-breadcrumbs :items="$breadcrumbs" />

{{-- Аналитика: товары страницы списка (параметры считаются, только если счётчики подключены) --}}
@if(\App\Support\Analytics::config())
<x-track event="view_item_list" :params="[
    'item_list_id' => $category->slug,
    'item_list_name' => $category->name,
    'items' => $products->getCollection()->take(20)->values()->map(fn ($p, $i) => \App\Support\Analytics::item($p, 1, $i + ($products->currentPage() - 1) * $products->perPage()))->all(),
]" />
@endif

<div class="mt-3 mb-5 flex flex-wrap items-baseline gap-x-3 gap-y-1">
    <h1 class="text-3xl font-bold tracking-tight">{{ $category->name }}</h1>
    {{-- Обновляется вместе со списком (catalog-filters.js) --}}
    <span class="text-ink-muted" data-found aria-live="polite">{{ \App\Support\Plural::ru($products->total(), ['товар', 'товара', 'товаров']) }}</span>
</div>

{{-- Подкатегории: страница раздела показывает товары всех вложенных подкатегорий, плитка/чип — переход в одну.
     Плитки с картинкой — если картинка есть хотя бы у одной подкатегории (у остальных — заглушка), иначе чипы --}}
@if($subcategories && $subcategoryTiles)
    @php($brief = 12)
    @php($more = count($subcategories) > $brief)
    <nav aria-label="Подкатегории" class="mb-5" data-subcategories data-subcategory-tiles @if($more) x-data="{ all: false }" @endif>
        <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6">
            @foreach($subcategories as $sub)
                <li @if($more && $loop->index >= $brief) x-show="all" x-cloak @endif>
                    <a href="{{ $sub['url'] }}" class="flex h-full flex-col items-center gap-2 rounded-card border border-line bg-surface p-3 text-center text-sm text-ink hover:border-primary hover:text-primary" data-subcategory>
                        <span class="flex aspect-square w-full items-center justify-center overflow-hidden rounded-control bg-media">
                            {{-- Картинка; без неё — иконка категории; без обеих — заглушка --}}
                            @if(empty($sub['image']) && ! empty($sub['icon']))
                                <x-category-icon :icon="$sub['icon']" :svg="$sub['icon_svg'] ?? null" class="h-1/2 w-1/2" />
                            @else
                                <img src="{{ $sub['image'] ?? \App\Models\ProductImage::placeholderUrl() }}" alt="" loading="lazy" class="h-full w-full object-contain p-2">
                            @endif
                        </span>
                        <span class="font-medium leading-tight">{{ $sub['name'] }}</span>
                        <span class="text-meta text-ink-muted tabular-nums">{{ $sub['count'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>

        @if($more)
            <button type="button" class="mt-2 px-2 py-1.5 text-sm text-link hover:underline" @click="all = ! all" :aria-expanded="all.toString()" x-text="all ? 'Свернуть' : 'Показать все ({{ count($subcategories) }})'">
                Показать все ({{ count($subcategories) }})
            </button>
        @endif
    </nav>
@elseif($subcategories)
    {{-- Видно первые $brief, остальные — по «Показать все» --}}
    @php($brief = 12)
    @php($more = count($subcategories) > $brief)
    <nav aria-label="Подкатегории" class="mb-5" data-subcategories @if($more) x-data="{ all: false }" @endif>
        <ul class="flex flex-wrap gap-2">
            @foreach($subcategories as $sub)
                <li @if($more && $loop->index >= $brief) x-show="all" x-cloak @endif>
                    <a href="{{ $sub['url'] }}" class="inline-flex items-center gap-1.5 rounded-full border border-line bg-surface px-3 py-1.5 text-sm text-ink hover:border-primary hover:text-primary" data-subcategory>
                        <x-category-icon :icon="$sub['icon'] ?? null" :svg="$sub['icon_svg'] ?? null" class="h-6 w-6" />
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

{{-- Текст раздела (HTML, очищенный) — на первой странице списка --}}
@if(filled($category->description ?? null) && $products->currentPage() === 1)
    <section class="prose-page mt-10 max-w-4xl rounded-card border border-line bg-surface p-card text-ink" data-category-description>
        {!! \App\Support\Html::clean($category->description) !!}
    </section>
@endif

{{-- Частые вопросы раздела (админка «Категории»; разметка FAQPage — CatalogController::seo) --}}
@if(! empty($category->faq) && $products->currentPage() === 1)
    <x-faq :items="$category->faq" :title="'Вопросы о разделе «'.$category->name.'»'" class="mt-6 max-w-4xl" />
@endif

@endsection
