{{--
    Предпросмотр ручной загрузки цен и остатков (ManualPricesUpload::preview): база не менялась.
    Данные: $draft [token, mode, name]; $plan (PricesStockPlan) или $failed (текст отказа);
    $products — товары для таблиц (id => Product).
--}}
@extends('layouts.admin')

@section('title', 'Предпросмотр загрузки цен')

@section('content')

<a href="/admin/exchange?tab=upload" class="text-sm text-ink-muted hover:text-link">← Загрузка цен</a>

<h1 class="text-3xl font-bold mt-4 mb-2">Предпросмотр загрузки цен и остатков</h1>

<p class="text-sm text-ink-muted mb-6">
    Файл «{{ $draft['name'] }}», режим <strong>{{ $draft['mode'] }}</strong>. База ещё не менялась.
</p>

@php
    $limit = \App\Services\Exchange\ManualPricesUpload::PREVIEW_LIMIT;
    $title = fn ($id) => $products->get($id)?->title() ?? '—';
    $sku = fn ($id) => $products->get($id)?->sku ?? '—';
    $money = fn ($price) => $price === null ? 'Цена уточняется' : money((float) $price);
@endphp

@if($failed)

    <x-alert type="error" title="Файл не будет применён" class="mb-6" data-preview-failed>{{ $failed }}</x-alert>

@else

    @php $r = $plan->result; @endphp

    <dl class="mb-6 grid gap-4 sm:grid-cols-3 lg:grid-cols-6" data-preview-summary>
        @foreach([
            'Строк в файле' => $r->total,
            'Будет обновлено' => count($plan->updated),
            'Без изменений' => $plan->unchanged,
            'Устаревшие (пропуск)' => $r->skipped,
            'С ошибкой' => count($r->errors),
            'Остаток → 0 (full)' => count($plan->zeroed),
        ] as $label => $value)
            <div class="rounded-card border border-line bg-surface p-4">
                <dt class="text-meta text-ink-muted">{{ $label }}</dt>
                <dd class="text-2xl font-semibold" data-preview-count="{{ $label }}">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    @if($plan->updated)
        <h2 class="text-xl font-semibold mb-4">Будет обновлено</h2>
        <x-table stack="lg" caption="Будет обновлено" class="mb-2">
            <x-slot:head>
                <x-table.th>Артикул</x-table.th>
                <x-table.th>Товар</x-table.th>
                <x-table.th>Цена без НДС</x-table.th>
                <x-table.th>Остаток 1С</x-table.th>
                <x-table.th>Под заказ</x-table.th>
            </x-slot:head>
            @foreach(array_slice($plan->updated, 0, $limit, true) as $id => ['before' => $b, 'after' => $a])
                <tr data-preview-updated="{{ $id }}">
                    <x-table.td label="Артикул" nowrap>{{ $sku($id) }}</x-table.td>
                    <x-table.td label="Товар">{{ $title($id) }}</x-table.td>
                    <x-table.td label="Цена без НДС" nowrap>
                        @if($b['price'] !== $a['price']){{ $money($b['price']) }} → <strong>{{ $money($a['price']) }}</strong>@else {{ $money($a['price']) }} @endif
                    </x-table.td>
                    <x-table.td label="Остаток 1С" nowrap>
                        @if($b['stock_1c'] !== $a['stock_1c']){{ $b['stock_1c'] }} → <strong>{{ $a['stock_1c'] }}</strong>@else {{ $a['stock_1c'] }} @endif
                    </x-table.td>
                    <x-table.td label="Под заказ" nowrap>
                        @if($b['on_order'] !== $a['on_order']){{ $b['on_order'] ? 'да' : 'нет' }} → <strong>{{ $a['on_order'] ? 'да' : 'нет' }}</strong>@else {{ $a['on_order'] ? 'да' : 'нет' }} @endif
                    </x-table.td>
                </tr>
            @endforeach
        </x-table>
        @if(count($plan->updated) > $limit)
            <p class="mb-6 text-sm text-ink-muted">Показаны первые {{ $limit }} из {{ count($plan->updated) }}.</p>
        @else
            <div class="mb-6"></div>
        @endif
    @endif

    @if($plan->zeroed)
        <h2 class="text-xl font-semibold mb-4">Остаток станет 0 (нет в полном файле)</h2>
        <x-table stack="lg" caption="Остаток станет 0" class="mb-6">
            <x-slot:head>
                <x-table.th>Артикул</x-table.th>
                <x-table.th>Товар</x-table.th>
            </x-slot:head>
            @foreach(array_slice($plan->zeroed, 0, $limit) as $id)
                <tr data-preview-zeroed="{{ $id }}">
                    <x-table.td label="Артикул" nowrap>{{ $sku($id) }}</x-table.td>
                    <x-table.td label="Товар">{{ $title($id) }}</x-table.td>
                </tr>
            @endforeach
        </x-table>
    @endif

    @if($r->errors)
        <h2 class="text-xl font-semibold mb-4">Ошибки — строки не будут применены</h2>
        <x-table stack="lg" caption="Ошибки по строкам" class="mb-6">
            <x-slot:head>
                <x-table.th align="right">Строка</x-table.th>
                <x-table.th>GUID</x-table.th>
                <x-table.th>Артикул</x-table.th>
                <x-table.th>Ошибка</x-table.th>
            </x-slot:head>
            @foreach(array_slice($r->errors, 0, $limit) as $error)
                <tr data-preview-error>
                    <x-table.td label="Строка" align="right" nowrap>{{ $error['line'] ?? '—' }}</x-table.td>
                    <x-table.td label="GUID"><code class="break-all">{{ $error['guid'] ?? '—' }}</code></x-table.td>
                    <x-table.td label="Артикул">{{ $error['sku'] ?? '—' }}</x-table.td>
                    <x-table.td label="Ошибка">{{ $error['message'] }}</x-table.td>
                </tr>
            @endforeach
        </x-table>
    @endif

@endif

<div class="flex flex-wrap gap-3">
    @unless($failed)
        <form method="POST" action="/admin/exchange/prices/apply" data-prices-apply>
            @csrf
            <x-button>Применить</x-button>
        </form>
    @endunless
    <form method="POST" action="/admin/exchange/prices/cancel">
        @csrf
        <x-button variant="secondary">{{ $failed ? 'Загрузить другой файл' : 'Отменить' }}</x-button>
    </form>
</div>

@endsection
