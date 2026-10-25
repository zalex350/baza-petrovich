@extends('layouts.app')

@section('title', 'Поиск')

@section('content')

{{-- Результаты поиска — те же строки товаров, что в каталоге (x-product-row). Данные: $query, $products, $cartItems --}}

<x-breadcrumbs :items="[['label' => 'Главная', 'url' => '/'], ['label' => 'Поиск']]" />

<div class="mt-3 mb-5">
    <h1 class="text-3xl font-bold tracking-tight">
        @if($query !== '')
            Поиск: «{{ $query }}»
        @else
            Поиск
        @endif
    </h1>
    <p class="mt-1 text-ink-muted" data-found>Найдено товаров: {{ $products->total() }}</p>
</div>

@if($products->isEmpty())

    <x-empty-state title="По вашему запросу ничего не найдено" icon="magnifying-glass" data-empty>
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
