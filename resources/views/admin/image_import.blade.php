{{--
    «Товары → Загрузить фото» (AdminImageImportController@index, BulkImages): загрузка ZIP с фото или CSV
    «код;ссылка» → предпросмотр; правила имён файлов; журнал загрузок (в том числе с сервера — images:import).
    Данные: $imports — последние записи журнала (ImageImport).
--}}
@extends('layouts.admin')

@section('title', 'Загрузка фото товаров')

@section('content')

<a href="/admin/products" class="text-sm text-ink-muted hover:text-link">← Товары</a>

<h1 class="mt-4 mb-2 text-3xl font-bold">Загрузка фото товаров</h1>

<div class="mb-6 max-w-3xl space-y-2 text-sm text-ink-muted" data-import-rules>
    <p>
        <strong class="text-ink">Имя файла — артикул или SKU товара</strong>, без учёта регистра: <code>L4755-2.jpg</code>, <code>l4755-2.png</code>.
        Несколько фото одного товара — суффиксы <code>_1</code>, <code>_2</code> или <code>-2</code>: файл без суффикса идёт первым, дальше — по номеру.
        Если артикул есть у товаров разных брендов — назовите файл <code>БРЕНД_АРТИКУЛ</code> (<code>Grohe_32665001.jpg</code>).
    </p>
    <p>
        JPG, PNG, WebP до 10 МБ. Фото уменьшаются до 1600 px по длинной стороне, сохраняются в WebP с миниатюрами, исходник не хранится.
        Такой же файл, как уже есть у товара, не добавляется. Битые файлы попадают в ошибки и не мешают остальным.
        Сначала — предпросмотр, база не меняется до «Применить».
    </p>
</div>

<div class="mb-8 grid gap-6 lg:grid-cols-2">
    <form method="POST" action="/admin/products/images-import" enctype="multipart/form-data" class="space-y-4 rounded-card border border-line bg-surface p-card" data-upload-zip>
        @csrf
        <input type="hidden" name="source" value="zip">
        <h2 class="text-lg font-semibold">ZIP-архив с фото</h2>
        <x-file name="file" id="zip-file" label="Архив, до 500 МБ" accept=".zip,application/zip" required
                hint="Фото — в корне архива или в папках; служебные файлы macOS пропускаются." />
        <x-button>Загрузить и проверить</x-button>
    </form>

    <form method="POST" action="/admin/products/images-import" enctype="multipart/form-data" class="space-y-4 rounded-card border border-line bg-surface p-card" data-upload-csv>
        @csrf
        <input type="hidden" name="source" value="csv">
        <h2 class="text-lg font-semibold">CSV со ссылками на фото</h2>
        <x-file name="file" id="csv-file" label="CSV «код;ссылка», до 5 МБ" accept=".csv,.txt,text/csv" required
                hint="Код — SKU, артикул или БРЕНД_АРТИКУЛ; разделитель «;». Несколько строк одного товара — фото по порядку строк. Фото скачиваются при применении." />
        <x-button>Загрузить и проверить</x-button>
    </form>
</div>

<p class="mb-8 text-sm text-ink-muted">
    Папка с фото на сервере: <code>php artisan images:import /путь/к/папке</code> — предпросмотр,
    <code>--apply</code> — загрузить, <code>--mode=replace</code> — заменить фото товаров. Так же — ZIP или CSV на сервере.
</p>

<h2 class="mb-4 text-xl font-semibold">Журнал загрузок</h2>

@if($imports->isEmpty())
    <x-empty-state title="Загрузок ещё не было" icon="photo" />
@else
    <x-table stack="lg" caption="Журнал загрузок фото">
        <x-slot:head>
            <x-table.th>Дата</x-table.th>
            <x-table.th>Источник</x-table.th>
            <x-table.th>Статус</x-table.th>
            <x-table.th align="right">Товаров</x-table.th>
            <x-table.th align="right">Фото</x-table.th>
            <x-table.th>Итог</x-table.th>
            <x-table.th>Кто</x-table.th>
        </x-slot:head>
        @foreach($imports as $import)
            <tr data-import-row="{{ $import->id }}">
                <x-table.td label="Дата" nowrap>
                    <a href="/admin/products/images-import/{{ $import->id }}" class="text-link hover:underline">{{ date_ru($import->created_at, time: true) }}</a>
                </x-table.td>
                <x-table.td label="Источник">
                    <span class="text-ink-muted">{{ \App\Models\ImageImport::SOURCES[$import->source] ?? $import->source }}:</span>
                    <span class="break-all">{{ $import->name }}</span>
                    @if($import->mode)
                        <span class="block text-meta text-ink-muted">{{ \App\Models\ImageImport::MODES[$import->mode] }}</span>
                    @endif
                </x-table.td>
                <x-table.td label="Статус" nowrap>
                    <x-badge :tone="$import->statusTone()">{{ $import->statusLabel() }}@if($import->isActive()) {{ $import->percent() }} %@endif</x-badge>
                </x-table.td>
                <x-table.td label="Товаров" align="right">{{ $import->products_total }}</x-table.td>
                <x-table.td label="Фото" align="right">{{ $import->files_total }}</x-table.td>
                <x-table.td label="Итог">
                    @if($import->status === \App\Models\ImageImport::DONE)
                        добавлено {{ $import->added }}, дублей {{ $import->duplicates }}, ошибок {{ $import->failed }}
                        @if($import->removed) , удалено прежних {{ $import->removed }} @endif
                    @elseif($import->message)
                        <span class="text-ink-muted">{{ $import->message }}</span>
                    @endif
                </x-table.td>
                <x-table.td label="Кто">{{ $import->user?->name ?? 'сервер' }}</x-table.td>
            </tr>
        @endforeach
    </x-table>
@endif

@endsection
