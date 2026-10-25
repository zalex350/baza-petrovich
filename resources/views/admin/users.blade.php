@extends('layouts.admin')

@section('title', 'Админка — Пользователи')

@section('content')

<div class="flex items-center justify-between mb-6">

    <h1 class="text-3xl font-bold">
        Админка — Пользователи
    </h1>

    <a
        href="/admin/user/create"
        class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded"
    >
        Добавить пользователя
    </a>

</div>

<form
    method="GET"
    action="/admin/users"
    class="flex flex-wrap items-center gap-3 mb-6"
>

    <input
        type="text"
        name="q"
        value="{{ $search }}"
        placeholder="ФИО, email или организация"
        class="w-full max-w-md border rounded px-3 py-2"
    >

    <select name="role" class="border rounded px-3 py-2">
        <option value="">Все роли</option>
        @foreach(\App\Enums\UserRole::cases() as $case)
            <option value="{{ $case->value }}" @selected($role === $case)>
                {{ $case->label() }}
            </option>
        @endforeach
    </select>

    <select name="status" class="border rounded px-3 py-2">
        <option value="">Все статусы</option>
        <option value="active" @selected($status === 'active')>Активен</option>
        <option value="blocked" @selected($status === 'blocked')>Заблокирован</option>
    </select>

    <button
        type="submit"
        class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded"
    >
        Найти
    </button>

    @if($search !== '' || $role || $status)

        <a
            href="/admin/users"
            class="text-sm text-slate-500 hover:text-slate-900 underline"
        >
            Сбросить
        </a>

    @endif

</form>

<div class="bg-white border rounded overflow-hidden">

    <table class="w-full border-collapse">

        <thead class="bg-gray-100">

            <tr>

                <th class="border p-3 text-left">
                    ID
                </th>

                <th class="border p-3 text-left">
                    ФИО
                </th>

                <th class="border p-3 text-left">
                    Email
                </th>

                <th class="border p-3 text-left">
                    Организация
                </th>

                <th class="border p-3 text-left">
                    Роль
                </th>

                <th class="border p-3 text-left">
                    Статус
                </th>

                <th class="border p-3 text-left">
                    Действия
                </th>

            </tr>

        </thead>

        <tbody>

            @forelse($users as $user)

                <tr class="hover:bg-gray-50">

                    <td class="border p-3">
                        {{ $user->id }}
                    </td>

                    <td class="border p-3">

                        <a
                            href="/admin/user/{{ $user->id }}"
                            class="underline"
                        >
                            {{ $user->name }}
                        </a>

                    </td>

                    <td class="border p-3">
                        {{ $user->email }}
                    </td>

                    <td class="border p-3">

                        @if($user->organization)

                            <a
                                href="/admin/organization/{{ $user->organization->id }}"
                                class="underline"
                            >
                                {{ $user->organization->name }}
                            </a>

                        @else

                            <span class="text-slate-400">—</span>

                        @endif

                    </td>

                    <td class="border p-3">
                        {{ $user->role?->label() }}
                    </td>

                    <td class="border p-3">

                        @if($user->isBlocked())

                            <span class="inline-flex rounded-full bg-red-100 text-red-700 px-2 py-0.5 text-xs">
                                Заблокирован
                            </span>

                        @else

                            <span class="inline-flex rounded-full bg-green-100 text-green-700 px-2 py-0.5 text-xs">
                                Активен
                            </span>

                        @endif

                    </td>

                    <td class="border p-3">

                        <a
                            href="/admin/user/{{ $user->id }}/edit"
                            class="underline"
                        >
                            Изменить
                        </a>

                    </td>

                </tr>

            @empty

                <tr>

                    <td colspan="7" class="border p-6 text-center text-slate-500">
                        Пользователи не найдены
                    </td>

                </tr>

            @endforelse

        </tbody>

    </table>

</div>

<div class="mt-8">
    <x-pagination :paginator="$users" />
</div>

@endsection
