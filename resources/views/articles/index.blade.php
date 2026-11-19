{{-- «Статьи» (ArticleController::index): карточки статей, новые сверху, пагинация --}}
@extends('layouts.app')

@section('title', 'Статьи о заборах, воротах и металле — '.config('shop.name'))

@section('content')

<x-breadcrumbs :items="[['label' => 'Главная', 'url' => '/'], ['label' => 'Статьи']]" />

<div class="mt-3 mb-5">
    <h1 class="text-3xl font-bold tracking-tight">Статьи</h1>
    <p class="mt-1 max-w-2xl text-ink-muted">Как выбрать забор и ворота, какой профнастил и штакетник взять, нормы установки и примеры наших объектов.</p>
</div>

@if($articles->isEmpty())
    <x-empty-state title="Статей пока нет" icon="document-text" />
@else
    <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-articles>
        @foreach($articles as $article)
            <li><x-article-card :article="$article" /></li>
        @endforeach
    </ul>
    <div class="mt-6"><x-pagination :paginator="$articles" /></div>
@endif

@endsection
