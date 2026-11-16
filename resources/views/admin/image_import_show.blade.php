{{--
    Загрузка фото (AdminImageImportController@show, BulkImages): черновик — предпросмотр и «Применить»
    (режим «добавить» / «заменить»); в очереди и в работе — прогресс (опрос /status каждые 2 с, по
    окончании страница перезагружается); готово / ошибка — итог и ошибки.
    Данные: $import (ImageImport), $found — product_id => [items] (первые $limit товаров), $products —
    id => Product (найденные и кандидаты неоднозначных), $limit.
--}}
@extends('layouts.admin')

@section('title', 'Загрузка фото товаров')

@section('content')

@use('App\Models\ImageImport')

@php
    $report = $import->report ?? [];
    $notFound = $report['not_found'] ?? [];
    $ambiguous = $report['ambiguous'] ?? [];
    $skipped = $report['skipped'] ?? [];
    $errorsList = $report['errors'] ?? [];
    $draft = $import->status === ImageImport::DRAFT;
    $label = fn ($id) => ($p = $products->get($id)) ? trim(($p->brand ? $p->brand.' ' : '').($p->article ?: $p->sku)).' — '.$p->name : "товар #{$id}";
    $problems = count($notFound) + count($ambiguous) + count($errorsList);
@endphp

<a href="/admin/products/images-import" class="text-sm text-ink-muted hover:text-link">← Загрузка фото</a>

<div class="mt-4 mb-2 flex flex-wrap items-center gap-3">
    <h1 class="text-3xl font-bold">{{ $draft ? 'Предпросмотр загрузки фото' : 'Загрузка фото' }}</h1>
    <x-badge :tone="$import->statusTone()" size="md" data-import-status="{{ $import->status }}">{{ $import->statusLabel() }}</x-badge>
</div>

<p class="mb-6 text-sm text-ink-muted">
    {{ ImageImport::SOURCES[$import->source] ?? $import->source }}: «{{ $import->name }}»
    @if($import->mode) · {{ ImageImport::MODES[$import->mode] }} @endif
    · {{ date_ru($import->created_at, time: true) }}@if($import->user) · {{ $import->user->name }} @endif
    @if($draft) · база ещё не менялась @endif
</p>

@if($import->status === ImageImport::FAILED || $import->status === ImageImport::EXPIRED)
    <x-alert :type="$import->status === ImageImport::FAILED ? 'error' : 'info'" class="mb-6" data-import-message>{{ $import->message ?: 'Черновик не применён' }}</x-alert>
@endif

<dl class="mb-6 grid gap-4 sm:grid-cols-3 lg:grid-cols-5" data-import-summary>
    @foreach([
        'Товаров найдено' => $import->products_total,
        'Фото к загрузке' => $import->files_total,
        'Не найдено' => count($notFound),
        'Неоднозначно' => count($ambiguous),
        'Пропущено' => count($skipped),
    ] as $title => $value)
        <div class="rounded-card border border-line bg-surface p-4">
            <dt class="text-meta text-ink-muted">{{ $title }}</dt>
            <dd class="text-2xl font-semibold" data-import-count="{{ $title }}">{{ $value }}</dd>
        </div>
    @endforeach
</dl>

@if($draft)
    <form method="POST" action="/admin/products/images-import/{{ $import->id }}/apply" class="mb-8 space-y-4 rounded-card border border-line bg-surface p-card" data-import-apply>
        @csrf
        <fieldset>
            <legend class="mb-2 font-medium text-ink">Режим</legend>
            <div class="space-y-2">
                @foreach([
                    ImageImport::MODE_APPEND => 'новые фото встают после имеющихся; основное фото не меняется (у товара без фото — первое новое)',
                    ImageImport::MODE_REPLACE => 'прежние фото товара удаляются, если добавилось хотя бы одно новое; основным становится первое новое',
                ] as $value => $hint)
                    <label class="flex items-start gap-2 text-sm">
                        <input type="radio" name="mode" value="{{ $value }}" class="mt-1" @checked(old('mode', ImageImport::MODE_APPEND) === $value)>
                        <span><strong class="text-ink">{{ ImageImport::MODES[$value] }}</strong> — <span class="text-ink-muted">{{ $hint }}</span></span>
                    </label>
                @endforeach
            </div>
            @error('mode')<p class="mt-2 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
        </fieldset>
        <div class="flex flex-wrap items-center gap-3">
            <x-button :disabled="$import->files_total === 0" data-import-apply-button>Применить — {{ $import->files_total }} фото</x-button>
            <x-confirm :action="'/admin/products/images-import/'.$import->id.'/discard'" label="Отменить" title="Отменить загрузку?" confirm-label="Отменить загрузку" trigger-variant="secondary">
                Загруженный файл будет удалён, фото товаров не изменятся.
            </x-confirm>
            <span class="text-sm text-ink-muted">Обработка — в фоне, страницу можно закрыть.</span>
        </div>
    </form>
@elseif($import->isActive() || $import->status === ImageImport::DONE)
    {{-- Прогресс: пока идёт — опрос /status; закончилось — перезагрузка за итогом --}}
    <section class="mb-8 rounded-card border border-line bg-surface p-card" data-import-progress
             x-data="{ s: @js(['active' => $import->isActive(), 'processed' => $import->processed, 'total' => $import->files_total, 'percent' => $import->percent(), 'added' => $import->added, 'duplicates' => $import->duplicates, 'failed' => $import->failed]) }"
             x-init="if (s.active) { const t = setInterval(async () => { try { const r = await fetch('/admin/products/images-import/{{ $import->id }}/status', { headers: { Accept: 'application/json' } }); const d = await r.json(); s = d; if (! d.active) { clearInterval(t); location.reload(); } } catch (e) {} }, 2000) }">
        <div class="mb-2 flex flex-wrap justify-between gap-2 text-sm">
            <span>Обработано <strong x-text="s.processed">{{ $import->processed }}</strong> из <span x-text="s.total">{{ $import->files_total }}</span></span>
            <span class="text-ink-muted">
                добавлено <span x-text="s.added">{{ $import->added }}</span>,
                дублей <span x-text="s.duplicates">{{ $import->duplicates }}</span>,
                ошибок <span x-text="s.failed">{{ $import->failed }}</span>
                @if($import->mode === ImageImport::MODE_REPLACE && $import->status === ImageImport::DONE), удалено прежних {{ $import->removed }}@endif
            </span>
        </div>
        <div class="h-3 overflow-hidden rounded-full bg-canvas" role="progressbar" aria-label="Прогресс загрузки" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="s.percent" aria-valuenow="{{ $import->percent() }}">
            <div class="h-full rounded-full bg-primary transition-all" :style="`width: ${s.percent}%`" style="width: {{ $import->percent() }}%"></div>
        </div>
        @if($import->status === ImageImport::DONE)
            <p class="mt-3 text-sm" data-import-result>{{ $import->message }}</p>
        @endif
    </section>
@endif

@if($problems > 0)
    <p class="mb-6">
        <x-button variant="secondary" size="sm" :href="'/admin/products/images-import/'.$import->id.'/not-found.csv'" data-import-csv>
            Скачать CSV: не найдено, неоднозначно, ошибки ({{ $problems }})
        </x-button>
    </p>
@endif

@if($errorsList)
    <h2 class="mb-4 text-xl font-semibold">Ошибки — {{ $import->failed }}</h2>
    <x-table stack caption="Ошибки загрузки" class="mb-2">
        <x-slot:head>
            <x-table.th>Файл</x-table.th>
            <x-table.th>Ошибка</x-table.th>
        </x-slot:head>
        @foreach(array_slice($errorsList, 0, $limit) as $row)
            <tr data-import-error>
                <x-table.td label="Файл"><span class="break-all">{{ $row['name'] }}</span></x-table.td>
                <x-table.td label="Ошибка">{{ $row['message'] }}</x-table.td>
            </tr>
        @endforeach
    </x-table>
    <p class="mb-6 text-sm text-ink-muted">@if(count($errorsList) > $limit) Показаны первые {{ $limit }}, все — в CSV. @endif</p>
@endif

@if($ambiguous)
    <h2 class="mb-2 text-xl font-semibold">Неоднозначно — {{ count($ambiguous) }}</h2>
    <p class="mb-4 text-sm text-ink-muted">Артикул есть у нескольких товаров. Переименуйте файл в <code>БРЕНД_АРТИКУЛ</code> или SKU товара и загрузите снова.</p>
    <x-table stack caption="Неоднозначные файлы" class="mb-2">
        <x-slot:head>
            <x-table.th>Файл</x-table.th>
            <x-table.th>Подходят товары</x-table.th>
        </x-slot:head>
        @foreach(array_slice($ambiguous, 0, $limit) as $row)
            <tr data-import-ambiguous>
                <x-table.td label="Файл"><span class="break-all">{{ $row['name'] }}</span></x-table.td>
                <x-table.td label="Подходят товары">
                    <ul class="min-w-0 space-y-1 text-left">
                        @foreach($row['products'] as $id)
                            <li><a href="/admin/product/{{ $id }}/edit" class="text-link hover:underline">{{ $label($id) }}</a>
                                @if($products->get($id)?->sku)<span class="text-meta text-ink-muted"> · SKU {{ $products->get($id)->sku }}</span>@endif</li>
                        @endforeach
                    </ul>
                </x-table.td>
            </tr>
        @endforeach
    </x-table>
    <p class="mb-6 text-sm text-ink-muted">@if(count($ambiguous) > $limit) Показаны первые {{ $limit }}, все — в CSV. @endif</p>
@endif

@if($notFound)
    <h2 class="mb-4 text-xl font-semibold">Не найдено — {{ count($notFound) }}</h2>
    <ul class="mb-2 columns-1 gap-6 rounded-card border border-line bg-surface p-card text-sm sm:columns-2 lg:columns-3" data-import-not-found>
        @foreach(array_slice($notFound, 0, $limit) as $row)
            <li class="break-all">{{ $row['name'] }}</li>
        @endforeach
    </ul>
    <p class="mb-6 text-sm text-ink-muted">@if(count($notFound) > $limit) Показаны первые {{ $limit }}, все — в CSV. @endif</p>
@endif

@if($found->isNotEmpty() && $import->status !== ImageImport::EXPIRED)
    <h2 class="mb-4 text-xl font-semibold">Найдено — {{ $import->products_total }}</h2>
    <x-table stack caption="Фото по товарам" class="mb-2">
        <x-slot:head>
            <x-table.th>Товар</x-table.th>
            <x-table.th>Фото по порядку</x-table.th>
        </x-slot:head>
        @foreach($found as $productId => $items)
            <tr data-import-found="{{ $productId }}">
                <x-table.td label="Товар"><a href="/admin/product/{{ $productId }}/edit" class="text-link hover:underline">{{ $label($productId) }}</a></x-table.td>
                <x-table.td label="Фото по порядку"><span class="break-all">{{ collect($items)->pluck('name')->implode(', ') }}</span></x-table.td>
            </tr>
        @endforeach
    </x-table>
    <p class="mb-6 text-sm text-ink-muted">@if($import->products_total > $limit) Показаны первые {{ $limit }} товаров. @endif</p>
@endif

@if($skipped)
    <details class="mb-6 text-sm" data-import-skipped>
        <summary class="cursor-pointer text-ink-muted">Пропущено — {{ count($skipped) }} (не картинки, строки без ссылки)</summary>
        <ul class="mt-2 space-y-1">
            @foreach(array_slice($skipped, 0, $limit) as $row)
                <li class="break-all">{{ $row['name'] }} — <span class="text-ink-muted">{{ $row['reason'] }}</span></li>
            @endforeach
        </ul>
    </details>
@endif

@endsection
