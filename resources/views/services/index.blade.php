{{-- Раздел «Услуги» (ServiceController::index): карточки услуг — название, начало текста, «Подробнее» --}}
@extends('layouts.app')

@section('title', 'Услуги — '.config('shop.name'))

@section('content')

<x-breadcrumbs :items="[['label' => 'Главная', 'url' => '/'], ['label' => 'Услуги']]" />

<div class="mt-3 mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-3xl font-bold tracking-tight">Услуги</h1>
        <p class="mt-1 text-ink-muted">Резка, доставка, подготовка заказа и монтаж заборов и ворот «под ключ».</p>
    </div>
    <x-button :href="route('lead.create', ['type' => 'callback'])" variant="secondary">Заказать звонок</x-button>
</div>

@if($services->isEmpty())
    <x-empty-state title="Услуги пока не добавлены" icon="wrench-screwdriver" />
@else
    <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-services>
        @foreach($services as $service)
            <li>
                <a href="{{ $service->url() }}" class="flex h-full flex-col gap-2 rounded-card border border-line bg-surface p-card hover:border-primary" data-service>
                    <span class="text-lg font-semibold text-ink">{{ $service->title }}</span>
                    <span class="line-clamp-3 text-sm text-ink-muted">{{ \Illuminate\Support\Str::limit(trim(html_entity_decode(strip_tags($service->safeBody()))), 220) }}</span>
                    <span class="mt-auto text-sm font-medium text-link">Подробнее →</span>
                </a>
            </li>
        @endforeach
    </ul>
@endif

@endsection
