{{--
    Вкладка «Коды товаров» клиента в админке: загрузка из Excel / CSV (→ предпросмотр
    admin/organization_codes_import), отчёт после применения, список с поиском и удалением.
    Данные: $organization (с product_codes_count), $codes (пагинатор ProductClientCode с product), $search.
--}}
@php
    $base = '/admin/organization/'.$organization->id;
    $report = session('codes_report');
    $maxMb = intdiv(\App\Services\ClientCodeImport::MAX_KB, 1024);
@endphp

@if($report)
    <div class="mb-6 rounded-card border border-line bg-surface p-card" data-codes-report>
        <h2 class="text-lg font-semibold mb-2">Отчёт о загрузке</h2>
        <p class="text-sm text-ink-muted mb-3">Файл «{{ $report['file'] }}», режим «{{ $report['mode'] }}».</p>

        <dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
            <div><dt class="text-ink-muted">Добавлено</dt><dd class="text-lg font-semibold" data-report-added>{{ $report['added'] }}</dd></div>
            <div><dt class="text-ink-muted">Обновлено</dt><dd class="text-lg font-semibold" data-report-updated>{{ $report['updated'] }}</dd></div>
            <div><dt class="text-ink-muted">Удалено</dt><dd class="text-lg font-semibold" data-report-deleted>{{ $report['deleted'] }}</dd></div>
            <div><dt class="text-ink-muted">Без изменений</dt><dd class="text-lg font-semibold">{{ $report['unchanged'] }}</dd></div>
        </dl>

        @if($report['errors'])
            <x-alert type="warning" class="mt-4">
                Пропущено строк с ошибками: {{ count($report['errors']) }} — коды этих товаров не изменились.
            </x-alert>
            <div class="mt-3">
                @include('partials.admin_client_code_errors', ['rowErrors' => $report['errors']])
            </div>
        @endif
    </div>
@endif

{{-- Загрузка из файла --}}
<div class="mb-6 rounded-card border border-line bg-surface p-card">
    <h2 class="text-lg font-semibold mb-1">Загрузить из Excel / CSV</h2>
    <p class="text-sm text-ink-muted mb-4">
        Столбцы «{{ \App\Services\ClientCodeImport::SKU_HEADER }}» и «{{ \App\Services\ClientCodeImport::CODE_HEADER }}» (строка заголовков — в начале листа, порядок столбцов любой).
        Сначала — предпросмотр: что добавится, изменится и какие строки с ошибками; база меняется только после «Применить».
        Коды с ведущими нулями (00123) храните в Excel как текст — в шаблоне столбцы уже текстовые.
    </p>

    <form method="POST" action="{{ $base }}/codes/import" enctype="multipart/form-data" novalidate class="grid gap-4 md:grid-cols-2" data-codes-upload>
        @csrf
        <x-file name="file" label="Файл" accept=".xlsx,.ods,.csv,text/csv" required hint="Excel (.xlsx), LibreOffice (.ods) или CSV, до {{ $maxMb }} МБ." />
        <x-select
            name="mode"
            label="Режим"
            :options="\App\Services\ClientCodeImport::MODES"
            :value="\App\Services\ClientCodeImport::MODE_ADD"
            required
            hint="«Добавить и обновить» — остальные коды клиента не меняются. «Заменить полностью» — коды, которых нет в файле, удаляются."
        />
        <div class="flex flex-wrap items-center gap-3 md:col-span-2">
            <x-button>Проверить файл</x-button>
            <x-button variant="secondary" href="{{ $base }}/codes/template" data-codes-template>Скачать шаблон (.xlsx)</x-button>
        </div>
    </form>
</div>

{{-- Список --}}
<div class="rounded-card border border-line bg-surface p-card">
    <h2 class="text-lg font-semibold mb-4">
        Коды товаров
        <span class="text-sm font-normal text-ink-muted ml-2">{{ $organization->product_codes_count }}</span>
    </h2>

    <form method="GET" action="{{ $base }}" class="mb-4 flex flex-wrap items-end gap-3" role="search">
        <input type="hidden" name="tab" value="codes">
        <x-input name="q" id="codes-q" type="search" label="Поиск" :value="$search" placeholder="Наш артикул, название, код клиента" class="grow min-w-64" />
        <x-button variant="secondary">Найти</x-button>
        @if($search !== '')
            <x-button variant="link" href="{{ $base }}?tab=codes">Сбросить</x-button>
        @endif
    </form>

    @if($codes->isEmpty())

        <x-empty-state :title="$search !== '' ? 'Ничего не найдено' : 'Кодов пока нет'" icon="{{ $search !== '' ? 'magnifying-glass' : 'inbox' }}">
            @if($search !== '')
                По запросу «{{ $search }}» кодов нет.
            @else
                Загрузите файл выше или задайте код в карточке товара — блок «Коды клиентов».
            @endif
        </x-empty-state>

    @else

        <x-table stack caption="Коды товаров клиента">
            <x-slot:head>
                <x-table.th>Наш артикул</x-table.th>
                <x-table.th>Название</x-table.th>
                <x-table.th>Код клиента</x-table.th>
                <x-table.th><span class="sr-only">Действия</span></x-table.th>
            </x-slot:head>
            @foreach($codes as $code)
                <tr data-client-code="{{ $code->id }}">
                    <x-table.td label="Наш артикул" class="font-mono">{{ $code->product?->sku ?? '—' }}</x-table.td>
                    <x-table.td label="Название">
                        @if($code->product)
                            <a href="/admin/product/{{ $code->product->id }}#client-codes" class="text-link hover:underline">{{ $code->product->title() }}</a>
                        @else
                            <span class="text-ink-muted">Товар удалён</span>
                        @endif
                    </x-table.td>
                    <x-table.td label="Код клиента" class="font-mono">{{ $code->code }}</x-table.td>
                    <x-table.td align="right">
                        <x-confirm
                            :action="$base.'/codes/'.$code->id.'/delete'.($search !== '' ? '?q='.urlencode($search) : '')"
                            label="Удалить"
                            title="Удалить код?"
                            confirm-label="Удалить"
                            trigger-variant="link"
                            trigger-size="sm"
                        >
                            Код «{{ $code->code }}» товара {{ $code->product?->sku ?? '' }} будет удалён у клиента «{{ $organization->name }}».
                        </x-confirm>
                    </x-table.td>
                </tr>
            @endforeach
        </x-table>

        <x-pagination :paginator="$codes" />

    @endif
</div>
