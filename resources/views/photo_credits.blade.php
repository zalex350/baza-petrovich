{{-- Авторы фото разделов каталога (лицензии Creative Commons): database/data/petrovich/category-photos/credits.json --}}
@extends('layouts.app')

@section('title', 'Авторы фото — '.config('shop.name'))

@section('content')

<x-breadcrumbs :items="[['label' => 'Главная', 'url' => '/'], ['label' => 'Авторы фото']]" />

<article class="mt-3 max-w-3xl rounded-card border border-line bg-surface p-card" data-photo-credits>
    <h1 class="mb-4 text-3xl font-bold tracking-tight">Авторы фото</h1>
    <p class="mb-4 text-ink-muted">Фото разделов каталога — со StockSnap и rawpixel, в общественном достоянии (CC0): использовать можно свободно, ссылки на авторов — из уважения к их работе.</p>
    <ul class="space-y-2 text-sm">
        @foreach($credits as $credit)
            <li>
                <span class="font-medium">{{ $credit['section'] }}:</span>
                <a href="{{ $credit['landing'] }}" class="text-link hover:underline" rel="nofollow noopener" target="_blank">{{ $credit['title'] ?: 'фото' }}</a>,
                @if($credit['creator']) автор — {{ $credit['creator'] }}, @endif источник — {{ $credit['source'] }},
                <a href="{{ $credit['license_url'] }}" class="text-link hover:underline" rel="nofollow noopener" target="_blank">{{ $credit['license_label'] }}</a>.
                Изображение уменьшено и обрезано.
            </li>
        @endforeach
    </ul>
</article>

@endsection
