{{--
    Клиенты (AdminOrganizationController@index): поиск по наименованию, УНП, email.
    Таблица на узком экране прокручивается вбок; наименование и «Действия» закреплены.
--}}
@extends('layouts.admin')

@section('title', 'Админка — Клиенты')

@section('content')

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-3xl font-bold">Админка — Клиенты</h1>
    <x-button href="/admin/organization/create">Добавить клиента</x-button>
</div>

<form method="GET" action="/admin/organizations" class="mb-6 flex flex-wrap items-end gap-3">
    <x-input name="q" type="search" label="Поиск" :value="$search" placeholder="Наименование, УНП или email" class="w-full max-w-md" />
    <x-button>Найти</x-button>
    @if($search !== '')
        <x-button variant="link" href="/admin/organizations">Сбросить</x-button>
    @endif
</form>

@if($organizations->isEmpty())
    <x-empty-state :title="$search !== '' ? 'По запросу «'.$search.'» клиентов не найдено' : 'Клиентов пока нет'" icon="building-office" />
@else
    <x-table caption="Клиенты">
        <x-slot:head>
            <x-table.th sticky="start">Наименование</x-table.th>
            <x-table.th>УНП</x-table.th>
            <x-table.th>Email</x-table.th>
            <x-table.th>Телефон</x-table.th>
            <x-table.th align="right">Пользователей</x-table.th>
            <x-table.th align="right">ID</x-table.th>
            <x-table.th sticky="end">Действия</x-table.th>
        </x-slot:head>

        @foreach($organizations as $organization)
            <tr data-organization-row="{{ $organization->id }}">
                <x-table.td sticky="start">
                    <a href="/admin/organization/{{ $organization->id }}" class="block min-w-32 max-w-56 font-medium text-ink hover:text-link">{{ $organization->name }}</a>
                </x-table.td>
                <x-table.td nowrap>{{ $organization->unp ?? '—' }}</x-table.td>
                <x-table.td>{{ $organization->email ?? '—' }}</x-table.td>
                <x-table.td nowrap>{{ $organization->phone ?? '—' }}</x-table.td>
                <x-table.td align="right" nowrap>{{ $organization->users_count }}</x-table.td>
                <x-table.td align="right" nowrap>{{ $organization->id }}</x-table.td>
                <x-table.td sticky="end" nowrap>
                    <x-button variant="link" size="sm" href="/admin/organization/{{ $organization->id }}/edit">Изменить</x-button>
                </x-table.td>
            </tr>
        @endforeach
    </x-table>
@endif

<div class="mt-8">
    <x-pagination :paginator="$organizations" />
</div>

@endsection
