@extends('layouts.account')

@section('title', 'Пользователи организации')

{{--
    Пользователи организации (UC-031) — только просмотр: создаёт, приглашает и блокирует
    администратор (BR-115). Данные: AccountController::users — $organization, $users, $statuses.
--}}

@section('account')

<h1 class="text-3xl font-bold tracking-tight mb-1">Пользователи организации</h1>
<p class="mb-5 text-ink-muted">
    Сотрудники {{ $organization->name }} с доступом к сайту. Добавить сотрудника или закрыть доступ — через менеджера.
</p>

<x-table stack caption="Пользователи организации">
    <x-slot:head>
        <x-table.th>ФИО</x-table.th>
        <x-table.th>Email</x-table.th>
        <x-table.th>Роль</x-table.th>
        <x-table.th>Статус</x-table.th>
    </x-slot:head>

    @foreach($users as $member)
        <tr data-member="{{ $member->id }}">
            <x-table.td label="ФИО">
                <span><span class="font-medium text-ink">{{ $member->name }}</span>@if($member->is(auth()->user()))<span class="text-ink-muted"> (вы)</span>@endif</span>
            </x-table.td>
            <x-table.td label="Email" class="break-all">{{ $member->email }}</x-table.td>
            <x-table.td label="Роль">{{ $member->role->label() }}</x-table.td>
            <x-table.td label="Статус">
                <x-badge :tone="$statuses[$member->id][1]">{{ $statuses[$member->id][0] }}</x-badge>
            </x-table.td>
        </tr>
    @endforeach
</x-table>

@endsection
