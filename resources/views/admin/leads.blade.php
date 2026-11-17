@extends('layouts.admin')

@section('title', 'Заявки с сайта')

@section('content')

<h1 class="text-3xl font-bold mb-2">Заявки с сайта</h1>

<p class="text-sm text-ink-muted mb-6 max-w-3xl">
    «Узнать цену», «Заказать услугу», «Обратный звонок». Письмо о новой заявке получают администраторы
    с галочкой «Заявки с сайта» (карточка пользователя); заявки также уходят в Telegram и Битрикс24, если они подключены
    в «Настройках».
</p>

<form method="GET" action="/admin/leads" class="mb-6 grid gap-4 rounded-card border border-line bg-surface p-4 sm:grid-cols-2 lg:flex lg:flex-wrap lg:items-end">
    <x-input name="q" type="search" label="Поиск" :value="$filters['q'] ?? ''" placeholder="Имя, телефон, email, товар" class="sm:col-span-2 lg:grow lg:min-w-64" />
    <x-select name="type" label="Тип" placeholder="Все" :options="\App\Models\Lead::TYPES" :value="$filters['type'] ?? ''" />
    <x-select
        name="status"
        label="Статус"
        placeholder="Все"
        :options="collect(\App\Http\Controllers\Admin\AdminLeadController::STATUSES)->map(fn ($label, $key) => $label.($key === 'new' && $newCount ? ' ('.$newCount.')' : ''))"
        :value="$filters['status'] ?? ''"
    />
    <div class="flex gap-2">
        <x-button>Показать</x-button>
        @if(array_filter($filters))
            <x-button variant="secondary" href="/admin/leads">Сбросить</x-button>
        @endif
    </div>
</form>

@if($leads->isEmpty())
    <x-empty-state title="Заявок нет" icon="inbox" />
@else
    <x-table stack="lg" caption="Заявки с сайта">
        <x-slot:head>
            <x-table.th>Дата</x-table.th>
            <x-table.th>Тип и запрос</x-table.th>
            <x-table.th>Контакт</x-table.th>
            <x-table.th>Комментарий</x-table.th>
            <x-table.th>Статус</x-table.th>
        </x-slot:head>

        @foreach($leads as $lead)
            <tr data-lead-row="{{ $lead->id }}">
                <x-table.td label="Дата" nowrap>{{ date_ru($lead->created_at, time: true) }}</x-table.td>
                <x-table.td label="Тип и запрос">
                    <span class="block font-medium text-ink">{{ $lead->typeLabel() }}</span>
                    @if($lead->product)
                        <a href="/admin/product/{{ $lead->product->id }}" class="block text-meta text-link hover:underline">{{ $lead->subject }}</a>
                    @elseif($lead->subject)
                        <span class="block text-meta text-ink-muted">{{ $lead->subject }}</span>
                    @endif
                </x-table.td>
                <x-table.td label="Контакт">
                    <span class="block">{{ $lead->name }}</span>
                    <a href="tel:{{ preg_replace('/[^+\d]/', '', $lead->phone) }}" class="block text-link hover:underline">{{ $lead->phone }}</a>
                    @if($lead->email)
                        <a href="mailto:{{ $lead->email }}" class="block text-link hover:underline">{{ $lead->email }}</a>
                    @endif
                </x-table.td>
                <x-table.td label="Комментарий" class="max-w-xs whitespace-pre-line break-words">{{ $lead->comment ?: '—' }}</x-table.td>
                <x-table.td label="Статус">
                    <form method="POST" action="/admin/lead/{{ $lead->id }}/status" class="flex flex-wrap items-center gap-2">
                        @csrf
                        <x-badge :tone="$lead->status === 'new' ? 'info' : 'success'">{{ \App\Http\Controllers\Admin\AdminLeadController::STATUSES[$lead->status] ?? $lead->status }}</x-badge>
                        <input type="hidden" name="status" value="{{ $lead->status === 'new' ? 'done' : 'new' }}">
                        <x-button variant="secondary" size="sm">{{ $lead->status === 'new' ? 'Обработана' : 'Вернуть в новые' }}</x-button>
                    </form>
                </x-table.td>
            </tr>
        @endforeach
    </x-table>

    <div class="mt-4">
        <x-pagination :paginator="$leads" />
    </div>
@endif

@endsection
