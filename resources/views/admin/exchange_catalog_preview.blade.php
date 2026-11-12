{{--
    Предпросмотр ручной загрузки номенклатуры (ManualCatalogUpload::preview): база не менялась.
    Данные: $draft [token, mode, name]; $plan (CatalogPlan) или $failed (текст отказа);
    $products — товары для таблиц (id => Product).
    Таблицы: привязка GUID (по SKU / по «бренд + артикул»), изменения полей у товаров с GUID, затем
    созданные, предупреждения, товары без пары (partials/exchange_report) и ошибки строк.
--}}
@extends('layouts.admin')

@section('title', 'Предпросмотр загрузки номенклатуры')

@section('content')

<a href="/admin/exchange?tab=catalog" class="text-sm text-ink-muted hover:text-link">← Загрузка номенклатуры</a>

<h1 class="text-3xl font-bold mt-4 mb-2">Предпросмотр загрузки номенклатуры</h1>

<p class="text-sm text-ink-muted mb-6">
    Файл «{{ $draft['name'] }}», режим <strong>{{ $draft['mode'] }}</strong>. База ещё не менялась.
</p>

@php
    $limit = \App\Services\Exchange\ManualUpload::PREVIEW_LIMIT;
    $title = fn ($id) => $products->get($id)?->title() ?? '—';
    $sku = fn ($id) => $products->get($id)?->sku ?? '—';
    $how = ['sku' => 'по SKU', 'key' => 'по бренду и артикулу', 'guid' => 'по GUID'];
    $fieldLabels = [
        'name' => 'наименование', 'article' => 'артикул', 'brand' => 'бренд', 'unit' => 'единица',
        'vat_rate' => 'НДС', 'min_qty' => 'мин. количество', 'qty_step' => 'кратность', 'barcode' => 'штрихкод',
        'group_1c' => 'группа 1С', 'is_active' => 'показывать',
    ];
    $show = fn ($value) => match (true) {
        $value === null || $value === '' => '—',
        is_bool($value) => $value ? 'да' : 'нет',
        default => (string) $value,
    };
@endphp

@if($failed)

    <x-alert type="error" title="Файл не будет применён" class="mb-6" data-preview-failed>{{ $failed }}</x-alert>

@else

    @php
        $r = $plan->result;
        $binding = array_values(array_filter($plan->matches, fn ($m) => $m['how'] !== 'guid'));
        $changed = array_values(array_filter($plan->matches, fn ($m) => $m['how'] === 'guid'));
        // Товары сайта без пары: список — только в full; в delta файл частичный, это нормально
        $full = $draft['mode'] === 'full';
    @endphp

    @if($plan->guard)
        <x-alert type="error" title="Файл не будет применён: сработает предохранитель mode=full" class="mb-6" data-preview-guard>
            {{ $plan->guard }} — файл похож на неполный. Проверьте выгрузку или загрузите её в режиме delta.
        </x-alert>
    @endif

    <dl class="mb-6 grid gap-4 sm:grid-cols-3 lg:grid-cols-5" data-preview-summary>
        @foreach([
            'Строк в файле' => $r->total,
            'Найдено по GUID' => $plan->byGuid,
            'Привязка по SKU' => $plan->bySku,
            'Привязка по бренду и артикулу' => $plan->byKey,
            'Будет создано' => count($plan->creates),
            'Будет изменено' => $plan->updated,
            'Без изменений' => $plan->unchanged,
            'Скроется / покажется' => count($plan->hidden).' / '.count($plan->shown),
            'С ошибкой' => count($r->errors),
            'Товары сайта без пары' => count($r->unmatched),
        ] as $label => $value)
            <div class="rounded-card border border-line bg-surface p-4">
                <dt class="text-meta text-ink-muted">{{ $label }}</dt>
                <dd class="text-2xl font-semibold" data-preview-count="{{ $label }}">{{ $value }}</dd>
                @if($label === 'Товары сайта без пары' && ! $full)
                    <dd class="text-meta text-ink-muted" data-preview-unmatched-note>нормально для частичного файла (delta); список — в режиме full</dd>
                @endif
            </div>
        @endforeach
    </dl>

    @foreach([
        ['Привязка GUID к товарам сайта', $binding, 'data-preview-binding'],
        ['Изменения у товаров с GUID', $changed, 'data-preview-changed'],
    ] as [$heading, $rows, $marker])
        @continue($rows === [])

        <h2 class="text-xl font-semibold mb-4">{{ $heading }} — {{ count($rows) }}</h2>
        <x-table stack="lg" :caption="$heading" class="mb-2">
            <x-slot:head>
                <x-table.th align="right">Строка</x-table.th>
                <x-table.th>Товар сайта</x-table.th>
                <x-table.th>Найден</x-table.th>
                <x-table.th>Изменится</x-table.th>
            </x-slot:head>
            @foreach(array_slice($rows, 0, $limit) as $match)
                <tr {{ $marker }}="{{ $match['id'] }}">
                    <x-table.td label="Строка" align="right" nowrap>{{ $match['line'] }}</x-table.td>
                    <x-table.td label="Товар сайта"><span class="font-medium">{{ $sku($match['id']) }}</span> — {{ $title($match['id']) }}</x-table.td>
                    <x-table.td label="Найден" nowrap>{{ $how[$match['how']] }}</x-table.td>
                    <x-table.td label="Изменится">
                        {{-- Один элемент-список: в карточке (stack) ячейка — flex-строка, отдельные блоки встали бы в ряд --}}
                        <ul class="min-w-0 space-y-1 text-left" data-preview-changes>
                            @foreach($match['changes'] as $field => [$before, $after])
                                @if($field === 'guid')
                                    <li data-preview-change="guid">GUID: <code class="break-all">{{ $after }}</code></li>
                                @else
                                    <li class="break-words" data-preview-change="{{ $field }}">{{ $fieldLabels[$field] ?? $field }}: {{ $show($before) }} → <strong>{{ $show($after) }}</strong></li>
                                @endif
                            @endforeach
                        </ul>
                    </x-table.td>
                </tr>
            @endforeach
        </x-table>
        <p class="mb-6 text-sm text-ink-muted">
            @if(count($rows) > $limit) Показаны первые {{ $limit }} из {{ count($rows) }}. @endif
        </p>
    @endforeach

    @php($report = $r->report())
    @if($report)
        @include('admin.partials.exchange_report', ['report' => array_diff_key($report, ['summary' => true] + ($full ? [] : ['unmatched' => true]))])
    @endif

    @if($r->errors)
        <h2 class="text-xl font-semibold mb-4">Ошибки — строки не будут применены — {{ count($r->errors) }}</h2>
        <x-table stack="lg" caption="Ошибки по строкам" class="mb-6">
            <x-slot:head>
                <x-table.th align="right">Строка</x-table.th>
                <x-table.th>GUID</x-table.th>
                <x-table.th>SKU</x-table.th>
                <x-table.th>Ошибка</x-table.th>
            </x-slot:head>
            @foreach(array_slice($r->errors, 0, $limit) as $error)
                <tr data-preview-error>
                    <x-table.td label="Строка" align="right" nowrap>{{ $error['line'] ?? '—' }}</x-table.td>
                    <x-table.td label="GUID"><code class="break-all">{{ $error['guid'] ?? '—' }}</code></x-table.td>
                    <x-table.td label="SKU">{{ $error['sku'] ?? '—' }}</x-table.td>
                    <x-table.td label="Ошибка">{{ $error['message'] }}</x-table.td>
                </tr>
            @endforeach
        </x-table>
    @endif

@endif

<div class="flex flex-wrap gap-3">
    @unless($failed || $plan?->guard)
        <form method="POST" action="/admin/exchange/catalog/apply" data-catalog-apply>
            @csrf
            <x-button>Применить</x-button>
        </form>
    @endunless
    <form method="POST" action="/admin/exchange/catalog/cancel">
        @csrf
        <x-button variant="secondary">{{ $failed || $plan?->guard ? 'Загрузить другой файл' : 'Отменить' }}</x-button>
    </form>
</div>

@endsection
