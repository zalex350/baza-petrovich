@extends('layouts.app')

@section('title', $category->name)

@section('content')

<div class="mb-8">

    <a
        href="{{ route('catalog.index') }}"
        class="text-sm text-slate-500 hover:text-blue-600"
    >
        ← Все категории
    </a>

    <h1 class="text-4xl font-bold text-slate-800 mt-4">
        {{ $category->name }}
    </h1>

</div>

{{-- Фильтры слева (на мобильном — выезжающая панель), список справа (SRS §3.9, §3.10) --}}
<div
    class="lg:grid lg:grid-cols-[16rem_minmax(0,1fr)] lg:gap-8"
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
