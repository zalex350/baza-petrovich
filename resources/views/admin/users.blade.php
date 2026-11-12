{{--
    Пользователи (AdminUserController@index): поиск по ФИО, email, организации; роль, статус.
    Таблица на узком экране прокручивается вбок; ФИО и «Действия» закреплены.
--}}
@extends('layouts.admin')

@section('title', 'Админка — Пользователи')

@section('content')

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-3xl font-bold">Админка — Пользователи</h1>
    <x-button href="/admin/user/create">Добавить пользователя</x-button>
</div>

<form method="GET" action="/admin/users" class="mb-6 flex flex-wrap items-end gap-3">
    <x-input name="q" type="search" label="Поиск" :value="$search" placeholder="ФИО, email или организация" class="w-full max-w-md" />
    <x-select
        name="role"
        label="Роль"
        :options="collect(\App\Enums\UserRole::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()"
        :value="$role?->value"
        placeholder="Все роли"
    />
    <x-select name="status" label="Статус" :options="['active' => 'Активен', 'blocked' => 'Заблокирован']" :value="$status" placeholder="Все статусы" />
    <x-button>Найти</x-button>
    @if($search !== '' || $role || $status)
        <x-button variant="link" href="/admin/users">Сбросить</x-button>
    @endif
</form>

@if($users->isEmpty())
    <x-empty-state title="Пользователи не найдены" icon="users" />
@else
    <x-table caption="Пользователи">
        <x-slot:head>
            <x-table.th sticky="start">ФИО</x-table.th>
            <x-table.th>Email</x-table.th>
            <x-table.th>Организация</x-table.th>
            <x-table.th>Роль</x-table.th>
            <x-table.th>Статус</x-table.th>
            <x-table.th align="right">ID</x-table.th>
            <x-table.th sticky="end">Действия</x-table.th>
        </x-slot:head>

        @foreach($users as $user)
            <tr data-user-row="{{ $user->id }}">
                <x-table.td sticky="start">
                    <a href="/admin/user/{{ $user->id }}" class="block min-w-32 max-w-56 font-medium text-ink hover:text-link">{{ $user->name }}</a>
                </x-table.td>
                <x-table.td>{{ $user->email }}</x-table.td>
                <x-table.td>
                    @if($user->organization)
                        <a href="/admin/organization/{{ $user->organization->id }}" class="text-link hover:underline">{{ $user->organization->name }}</a>
                    @else
                        <span class="text-ink-muted">—</span>
                    @endif
                </x-table.td>
                <x-table.td nowrap>{{ $user->role?->label() }}</x-table.td>
                <x-table.td nowrap>
                    @if($user->isBlocked())
                        <x-badge tone="danger">Заблокирован</x-badge>
                    @else
                        <x-badge tone="success">Активен</x-badge>
                    @endif
                </x-table.td>
                <x-table.td align="right" nowrap>{{ $user->id }}</x-table.td>
                <x-table.td sticky="end" nowrap>
                    <x-button variant="link" size="sm" href="/admin/user/{{ $user->id }}/edit">Изменить</x-button>
                </x-table.td>
            </tr>
        @endforeach
    </x-table>
@endif

<div class="mt-8">
    <x-pagination :paginator="$users" />
</div>

@endsection
