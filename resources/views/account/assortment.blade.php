@extends('layouts.account')

@section('title', 'Выгрузка ассортимента')

{{--
    Выгрузка ассортимента (docs/MODULES.md §11) — только у организации с кодами товаров.
    Файл .xlsx (App\Services\AssortmentExport): ваш код, наш артикул, название, цены без НДС
    и с НДС, остаток. Данные: AccountController::assortment — $organization, $codesCount, $stockCap.
--}}

@section('account')

<h1 class="text-3xl font-bold tracking-tight mb-1">Выгрузка ассортимента</h1>
<p class="mb-5 text-ink-muted">Файл Excel с вашими кодами, нашими артикулами, текущими ценами и остатками.</p>

<form method="GET" action="/account/assortment/download" class="max-w-xl space-y-4 rounded-card border border-line bg-surface p-card" data-assortment-form>
    <dl class="space-y-1 text-sm text-ink-muted">
        <div>Товаров с вашими кодами: <span class="font-semibold text-ink" data-codes-count>{{ $codesCount }}</span></div>
        <div>Столбцы: ваш код, наш артикул, название, цена без НДС, цена с НДС, остаток.</div>
        <div>Остаток — на момент выгрузки, точным числом до {{ $stockCap }} шт., больше — «более {{ $stockCap }}».</div>
    </dl>

    <x-checkbox
        name="without_code"
        label="Включить товары без кода"
        hint="Все опубликованные товары каталога; у товаров без вашего кода столбец «Ваш код» пустой."
        :unchecked="false"
    />

    <x-button>
        @svg('heroicon-o-arrow-down-tray', 'h-5 w-5')
        Скачать .xlsx
    </x-button>
</form>

@endsection
