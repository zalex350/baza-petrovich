@extends('layouts.admin')

@section('title', 'Админка — Клиенты')

@section('content')

<div class="flex items-center justify-between mb-6">

    <h1 class="text-3xl font-bold">
        Админка — Клиенты
    </h1>

    <a
        href="/admin/organization/create"
        class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded"
    >
        Добавить клиента
    </a>

</div>

<form
    method="GET"
    action="/admin/organizations"
    class="flex items-center gap-3 mb-6"
>

    <input
        type="text"
        name="q"
        value="{{ $search }}"
        placeholder="Наименование, УНП или email"
        class="w-full max-w-md border rounded px-3 py-2"
    >

    <button
        type="submit"
        class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded"
    >
        Найти
    </button>

    @if($search !== '')

        <a
            href="/admin/organizations"
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
                    Наименование
                </th>

                <th class="border p-3 text-left">
                    УНП
                </th>

                <th class="border p-3 text-left">
                    Email
                </th>

                <th class="border p-3 text-left">
                    Телефон
                </th>

                <th class="border p-3 text-left">
                    Пользователей
                </th>

                <th class="border p-3 text-left">
                    Действия
                </th>

            </tr>

        </thead>

        <tbody>

            @forelse($organizations as $organization)

                <tr class="hover:bg-gray-50">

                    <td class="border p-3">
                        {{ $organization->id }}
                    </td>

                    <td class="border p-3">

                        <a
                            href="/admin/organization/{{ $organization->id }}"
                            class="underline"
                        >
                            {{ $organization->name }}
                        </a>

                    </td>

                    <td class="border p-3">
                        {{ $organization->unp }}
                    </td>

                    <td class="border p-3">
                        {{ $organization->email }}
                    </td>

                    <td class="border p-3">
                        {{ $organization->phone }}
                    </td>

                    <td class="border p-3">
                        {{ $organization->users_count }}
                    </td>

                    <td class="border p-3">

                        <a
                            href="/admin/organization/{{ $organization->id }}/edit"
                            class="underline"
                        >
                            Изменить
                        </a>

                    </td>

                </tr>

            @empty

                <tr>

                    <td colspan="7" class="border p-6 text-center text-slate-500">

                        @if($search !== '')
                            По запросу «{{ $search }}» клиентов не найдено
                        @else
                            Клиентов пока нет
                        @endif

                    </td>

                </tr>

            @endforelse

        </tbody>

    </table>

</div>

<div class="mt-8">
    {{ $organizations->links() }}
</div>

@endsection
