{{--
    Задание обмена с 1С: сведения, ошибки по строкам (по 50), скачивание исходного файла.
    Данные: $job, $rowErrors (пагинатор ExchangeJobError).
--}}
@extends('layouts.admin')

@section('title', 'Задание обмена')

@section('content')

<a href="/admin/exchange" class="text-sm text-ink-muted hover:text-link">← Журнал обмена</a>

<h1 class="text-3xl font-bold mt-4 mb-6">{{ $job->type->label() }} — {{ date_ru($job->received_at, time: true) }}</h1>

<div class="mb-6 rounded-card border border-line bg-surface p-card" data-exchange-job-card>
    <dl class="grid gap-x-8 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
        <div><dt class="text-meta text-ink-muted">Статус</dt><dd><x-badge :tone="$job->status->tone()">{{ $job->status->label() }}</x-badge></dd></div>
        <div><dt class="text-meta text-ink-muted">Операция</dt><dd>{{ $job->direction === 'export' ? 'Экспорт (сайт → 1С)' : 'Импорт (1С → сайт)' }}@if($job->mode), {{ $job->mode }}@endif</dd></div>
        <div><dt class="text-meta text-ink-muted">Задание</dt><dd><code class="break-all">{{ $job->id }}</code></dd></div>
        <div><dt class="text-meta text-ink-muted">Строк</dt><dd>всего {{ $job->rows_total }}, принято {{ $job->rows_ok }}, пропущено {{ $job->rows_skipped }}, с ошибкой {{ $job->rows_error }}</dd></div>
        <div><dt class="text-meta text-ink-muted">Обработка</dt><dd>
            @if($job->finished_at)
                {{ date_ru($job->started_at ?? $job->received_at, time: true) }} — {{ $job->finished_at->format('H:i:s') }}
            @else
                ещё не завершена
            @endif
        </dd></div>
        <div><dt class="text-meta text-ink-muted">Источник</dt><dd>
            @if($job->isManual())
                Вручную — {{ $job->user->name ?? '—' }}
            @else
                1С: {{ $job->token->name ?? '—' }} · {{ $job->ip ?? '—' }}
            @endif
        </dd></div>
        <div><dt class="text-meta text-ink-muted">X-Exchange-Id</dt><dd><code class="break-all">{{ $job->exchange_id ?? '—' }}</code></dd></div>
        <div class="sm:col-span-2"><dt class="text-meta text-ink-muted">Исходный файл</dt><dd>
            @if($job->hasFile())
                <x-button variant="secondary" size="sm" href="/admin/exchange/job/{{ $job->id }}/file">Скачать</x-button>
                <span class="text-meta text-ink-muted">{{ number_format($job->file_size / 1024, 0, ',', ' ') }} КБ{{ $job->gzip ? ', пришёл сжатым (gzip)' : '' }}</span>
            @elseif($job->file_pruned_at)
                удалён {{ date_ru($job->file_pruned_at) }} по сроку хранения
            @else
                нет
            @endif
        </dd></div>
    </dl>

    @if($job->message)
        @if($job->status === \App\Enums\ExchangeJobStatus::Failed)
            <x-alert type="error" title="Задание не обработано" class="mt-4">{{ $job->message }}</x-alert>
        @else
            <x-alert type="info" class="mt-4">{{ $job->message }}</x-alert>
        @endif
    @endif
</div>

<h2 class="text-xl font-semibold mb-4">Ошибки по строкам</h2>

@if($rowErrors->isEmpty())
    <x-empty-state title="Ошибок нет" icon="check-circle" />
@else
    <x-table stack="lg" caption="Ошибки по строкам">
        <x-slot:head>
            <x-table.th align="right">Строка</x-table.th>
            <x-table.th>GUID</x-table.th>
            <x-table.th>Артикул</x-table.th>
            <x-table.th>Ошибка</x-table.th>
        </x-slot:head>

        @foreach($rowErrors as $error)
            <tr data-exchange-error>
                <x-table.td label="Строка" align="right" nowrap>{{ $error->line ?? '—' }}</x-table.td>
                <x-table.td label="GUID"><code class="break-all">{{ $error->guid ?? '—' }}</code></x-table.td>
                <x-table.td label="Артикул">{{ $error->sku ?? '—' }}</x-table.td>
                <x-table.td label="Ошибка">{{ $error->message }}</x-table.td>
            </tr>
        @endforeach
    </x-table>

    <div class="mt-4">
        <x-pagination :paginator="$rowErrors" />
    </div>
@endif

@endsection
