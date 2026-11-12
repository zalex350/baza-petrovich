{{--
    Отчёт задания обмена сверх счётчиков (exchange_jobs.report, ExchangeResult::report):
    сводка номенклатуры, созданные товары с кодами сайта, предупреждения, товары с GUID без пары
    в файле (mode=full). Списки — первые $limit строк.
    Данные: $report (array).
--}}
@php
    $limit = 200;
    $summary = $report['summary'] ?? [];
    $fieldLabels = [
        'name' => 'наименование', 'article' => 'артикул', 'brand' => 'бренд', 'unit' => 'единица',
        'vat_rate' => 'НДС', 'min_qty' => 'мин. количество', 'qty_step' => 'кратность', 'barcode' => 'штрихкод',
        'group_1c' => 'группа 1С', 'guid' => 'GUID присвоен', 'is_active' => 'видимость',
    ];
    $lists = [
        'created' => ['Созданные товары (черновики)', 'data-exchange-created'],
        'warnings' => ['Предупреждения (строка применена)', 'data-exchange-warning'],
        'missing' => ['Товары с GUID, которых нет в файле (не скрыты)', 'data-exchange-missing'],
        'unmatched' => ['Товары сайта без пары в файле', 'data-exchange-unmatched'],
    ];
@endphp

@if($summary)
    <div class="mb-6 rounded-card border border-line bg-surface p-card" data-exchange-summary>
        <h2 class="text-xl font-semibold mb-4">Итог</h2>
        <dl class="grid gap-x-8 gap-y-3 sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-meta text-ink-muted">Сопоставлено</dt><dd>по GUID {{ $summary['matched_by_guid'] ?? 0 }}, по SKU {{ $summary['matched_by_sku'] ?? 0 }}, по бренду и артикулу {{ $summary['matched_by_key'] ?? 0 }}</dd></div>
            <div><dt class="text-meta text-ink-muted">Создано</dt><dd>{{ $summary['created'] ?? 0 }}</dd></div>
            <div><dt class="text-meta text-ink-muted">Изменено товаров</dt><dd>{{ $summary['updated'] ?? 0 }}, без изменений {{ $summary['unchanged'] ?? 0 }}</dd></div>
            <div><dt class="text-meta text-ink-muted">Видимость</dt><dd>скрыто {{ $summary['hidden'] ?? 0 }}, показано {{ $summary['shown'] ?? 0 }}</dd></div>
            <div class="sm:col-span-2 lg:col-span-4"><dt class="text-meta text-ink-muted">Изменится полей</dt><dd>
                @forelse($summary['fields'] ?? [] as $field => $count)
                    {{ $fieldLabels[$field] ?? $field }} — {{ $count }}@if(! $loop->last), @endif
                @empty
                    —
                @endforelse
            </dd></div>
        </dl>
        @isset($summary['guard'])
            <x-alert type="warning" class="mt-4">Предохранитель mode=full сработал бы: {{ $summary['guard'] }}</x-alert>
        @endisset
    </div>
@endif

@foreach($lists as $part => [$title, $marker])
    @continue(empty($report[$part]))
    @php($rows = $report[$part])

    <h2 class="text-xl font-semibold mb-4">{{ $title }} — {{ count($rows) }}</h2>

    <x-table stack="lg" :caption="$title" class="mb-2">
        <x-slot:head>
            @if(! in_array($part, ['missing', 'unmatched'], true))<x-table.th align="right">Строка</x-table.th>@endif
            <x-table.th>GUID</x-table.th>
            <x-table.th>SKU</x-table.th>
            @if($part === 'warnings')<x-table.th>Предупреждение</x-table.th>@endif
        </x-slot:head>

        @foreach(array_slice($rows, 0, $limit) as $row)
            <tr {{ $marker }}>
                @if(! in_array($part, ['missing', 'unmatched'], true))<x-table.td label="Строка" align="right" nowrap>{{ $row['line'] ?? '—' }}</x-table.td>@endif
                <x-table.td label="GUID"><code class="break-all">{{ $row['guid'] ?? '—' }}</code></x-table.td>
                <x-table.td label="SKU">{{ $row['sku'] ?? '—' }}</x-table.td>
                @if($part === 'warnings')<x-table.td label="Предупреждение">{{ $row['message'] }}</x-table.td>@endif
            </tr>
        @endforeach
    </x-table>

    <p class="mb-6 text-meta text-ink-muted">
        @if(count($rows) > $limit)
            Показаны первые {{ $limit }} из {{ count($rows) }}.
        @endif
    </p>
@endforeach
