{{-- «Цены»: выгрузка в Excel и загрузка изменений (AdminPriceController, App\Services\PriceSheet) --}}
@extends('layouts.admin')

@section('title', 'Цены')

@section('content')

<h1 class="text-3xl font-bold mb-2">Цены и скидки</h1>

<p class="text-sm text-ink-muted mb-6 max-w-3xl">
    Выгрузите файл, поменяйте цены, скидки и наличие в Excel и загрузите обратно. Товар находится по SKU.
    Пустая цена — «Цена по запросу» (кнопка «Узнать цену»); пустая скидка — без скидки товара
    (действует скидка раздела, если задана в «Категориях»). «В наличии» — 1 или 0.
    Колонки «Название», «Раздел» и «Цена со скидкой» — для справки, при загрузке не меняются.
    Цены {{ \App\Services\Vat\VatCalculator::pricesIncludeVat() ? 'с НДС (розничные)' : 'без НДС' }}.
</p>

<div class="grid gap-6 lg:grid-cols-2">
    <section class="rounded-card border border-line bg-surface p-card">
        <h2 class="mb-2 text-lg font-semibold">1. Выгрузить</h2>
        <p class="mb-4 text-sm text-ink-muted">Все товары каталога, .xlsx.</p>
        <x-button href="/admin/prices/export" variant="secondary" data-prices-export>Скачать файл цен</x-button>
    </section>

    <section class="rounded-card border border-line bg-surface p-card">
        <h2 class="mb-2 text-lg font-semibold">2. Загрузить изменения</h2>
        <form method="POST" action="/admin/prices/import" enctype="multipart/form-data" class="flex flex-col gap-4" data-prices-import>
            @csrf
            <x-file name="file" label="Файл .xlsx" accept=".xlsx" required />
            <div><x-button>Загрузить</x-button></div>
        </form>
    </section>
</div>

@if(session('price_errors'))
    <x-alert type="warning" title="Не загружено" class="mt-6" data-price-errors>
        <ul class="list-disc pl-5">
            @foreach(session('price_errors') as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-alert>
@endif

@endsection
