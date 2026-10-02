@extends('layouts.admin')

@section('title', 'Клиент — '.$organization->name)

@section('content')

<a
    href="/admin/organizations"
    class="text-sm text-slate-500 hover:text-blue-600"
>
    ← Назад к списку клиентов
</a>

<div class="flex items-center justify-between mt-4 mb-6">

    <h1 class="text-3xl font-bold">
        {{ $organization->name }}
    </h1>

    <a
        href="/admin/organization/{{ $organization->id }}/edit"
        class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded"
    >
        Изменить
    </a>

</div>

{{-- Реквизиты --}}
<div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6">

    <h2 class="text-lg font-semibold mb-4">
        Реквизиты
    </h2>

    <dl class="grid grid-cols-1 sm:grid-cols-[220px_1fr] gap-x-6 gap-y-3">

        <dt class="text-slate-500">Наименование</dt>
        <dd>{{ $organization->name }}</dd>

        <dt class="text-slate-500">Юридическое наименование</dt>
        <dd>{{ $organization->legal_name ?? '—' }}</dd>

        <dt class="text-slate-500">УНП</dt>
        <dd>{{ $organization->unp ?? '—' }}</dd>

        <dt class="text-slate-500">Адрес</dt>
        <dd>{{ $organization->address ?? '—' }}</dd>

        <dt class="text-slate-500">Телефон</dt>
        <dd>{{ $organization->phone ?? '—' }}</dd>

        <dt class="text-slate-500">Email</dt>
        <dd>{{ $organization->email ?? '—' }}</dd>

        <dt class="text-slate-500">Комментарий</dt>
        <dd class="whitespace-pre-line">{{ $organization->comment ?? '—' }}</dd>

        <dt class="text-slate-500">GUID 1С</dt>
        <dd>
            <span class="font-mono text-sm text-slate-600">{{ $organization->guid ?? '—' }}</span>
            <span class="block text-xs text-slate-400">только чтение, заполняется при обмене с 1С</span>
        </dd>

    </dl>

</div>

{{-- Пользователи --}}
<div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6">

    <h2 class="text-lg font-semibold mb-4">
        Пользователи
        <span class="text-sm font-normal text-slate-400 ml-2">{{ $organization->users->count() }}</span>
    </h2>

    @if($organization->users->isEmpty())

        <p class="text-slate-500">
            К клиенту пока не привязан ни один пользователь.
        </p>

    @else

        <table class="w-full border-collapse">

            <thead class="bg-gray-100">

                <tr>
                    <th class="border p-3 text-left">Имя</th>
                    <th class="border p-3 text-left">Email</th>
                    <th class="border p-3 text-left">Роль</th>
                </tr>

            </thead>

            <tbody>

                @foreach($organization->users as $user)

                    <tr class="hover:bg-gray-50">
                        <td class="border p-3">
                            <a href="/admin/user/{{ $user->id }}" class="underline">{{ $user->name }}</a>
                        </td>
                        <td class="border p-3">{{ $user->email }}</td>
                        <td class="border p-3">{{ $user->role?->label() }}</td>
                    </tr>

                @endforeach

            </tbody>

        </table>

    @endif

</div>

{{-- Адреса доставки --}}
<div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6">

    <div class="flex items-center justify-between mb-4">

        <h2 class="text-lg font-semibold">
            Адреса доставки
            <span class="text-sm font-normal text-slate-400 ml-2">{{ $organization->deliveryAddresses->count() }}</span>
        </h2>

        <a
            href="/admin/organization/{{ $organization->id }}/address/create"
            class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded text-sm"
        >
            Добавить адрес
        </a>

    </div>

    @if($organization->deliveryAddresses->isEmpty())

        <p class="text-slate-500">
            У клиента пока нет адресов доставки — оформить заказ он не сможет.
        </p>

    @else

        @include('partials.address_list', [
            'addresses' => $organization->deliveryAddresses,
            'baseUrl'   => '/admin/organization/'.$organization->id.'/address',
        ])

    @endif

</div>

{{-- Опасная зона --}}
<div class="mt-6">

    <form
        method="POST"
        action="/admin/organization/{{ $organization->id }}/delete"
        class="inline"
    >

        @csrf

        <button
            type="submit"
            class="text-red-600 hover:text-red-700 text-sm underline"
            onclick="return confirm('Удалить клиента «{{ addslashes($organization->name) }}»?')"
        >
            Удалить клиента
        </button>

    </form>

    <p class="text-xs text-slate-400 mt-1">
        Удаление невозможно, если к клиенту привязаны пользователи или заказы.
    </p>

</div>

@endsection
