@extends('layouts.admin')

@section('title', 'Заявки')

@section('content')

<h1 class="text-3xl font-bold mb-2">Заявки «Стать партнёром»</h1>

<p class="text-sm text-ink-muted mb-6 max-w-3xl">
    Заявки организаций с сайта. Письмо о новой заявке получают администраторы с галочкой
    «Заявка партнёра» (карточка пользователя). Подключить организацию — «Клиенты» и «Пользователи».
</p>

<form method="GET" action="/admin/partners" class="mb-6 grid gap-4 rounded-card border border-line bg-surface p-4 sm:grid-cols-2 lg:flex lg:flex-wrap lg:items-end">
    <x-input name="q" type="search" label="Поиск" :value="$filters['q'] ?? ''" placeholder="Организация, УНП, контакт, email, телефон" class="sm:col-span-2 lg:grow lg:min-w-64" />
    <x-select
        name="status"
        label="Статус"
        placeholder="Все"
        :options="collect($statuses)->mapWithKeys(fn ($status) => [$status->value => $status->label().($status === \App\Enums\PartnerRequestStatus::New && $newCount ? ' ('.$newCount.')' : '')])"
        :value="$filters['status'] ?? ''"
    />
    <div class="flex gap-2">
        <x-button>Показать</x-button>
        @if(($filters['q'] ?? '') !== '' || ($filters['status'] ?? null))
            <x-button variant="secondary" href="/admin/partners">Сбросить</x-button>
        @endif
    </div>
</form>

@if($requests->isEmpty())
    <x-empty-state title="Заявок нет" icon="briefcase" />
@else
    <x-table stack="lg" caption="Заявки">
        <x-slot:head>
            <x-table.th>Дата</x-table.th>
            <x-table.th>Организация</x-table.th>
            <x-table.th>Контакт</x-table.th>
            <x-table.th>Комментарий</x-table.th>
            <x-table.th>Статус</x-table.th>
        </x-slot:head>

        @foreach($requests as $request)
            <tr data-partner-row="{{ $request->id }}">
                <x-table.td label="Дата" nowrap>{{ date_ru($request->created_at, time: true) }}</x-table.td>
                <x-table.td label="Организация">
                    <span class="block font-medium text-ink">{{ $request->organization }}</span>
                    <span class="block text-meta text-ink-muted">УНП {{ $request->unp }}</span>
                </x-table.td>
                <x-table.td label="Контакт">
                    <span class="block">{{ $request->contact_name }}</span>
                    <a href="tel:{{ preg_replace('/[^+\d]/', '', $request->phone) }}" class="block text-link hover:underline">{{ $request->phone }}</a>
                    <a href="mailto:{{ $request->email }}" class="block text-link hover:underline">{{ $request->email }}</a>
                </x-table.td>
                <x-table.td label="Комментарий" class="max-w-xs whitespace-pre-line break-words">{{ $request->comment ?: '—' }}</x-table.td>
                <x-table.td label="Статус">
                    <form method="POST" action="/admin/partner/{{ $request->id }}/status" class="flex flex-wrap items-center gap-2">
                        @csrf
                        <x-badge :tone="$request->status->tone()">{{ $request->status->label() }}</x-badge>
                        <x-select
                            name="status"
                            :id="'f-status-'.$request->id"
                            :options="collect($statuses)->mapWithKeys(fn ($status) => [$status->value => $status->label()])"
                            :value="$request->status->value"
                            aria-label="Статус заявки {{ $request->organization }}"
                            class="text-sm"
                        />
                        <x-button variant="secondary" size="sm">Сохранить</x-button>
                    </form>
                </x-table.td>
            </tr>
        @endforeach
    </x-table>

    <div class="mt-4">
        <x-pagination :paginator="$requests" />
    </div>
@endif

@endsection
