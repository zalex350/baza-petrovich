@extends('layouts.admin')

@section('title', 'Клиент — '.$organization->name)

{{-- Вкладка «Коды товаров» — на ките: ошибки у полей, сверху одна сводка --}}
@section('flash-validation', $tab === 'codes' ? 'summary' : 'list')

@section('content')

<a
    href="/admin/organizations"
    class="text-sm text-slate-500 hover:text-blue-600"
>
    ← Назад к списку клиентов
</a>

<div class="mt-4 mb-6 flex flex-wrap items-center justify-between gap-3">
    <h1 class="min-w-0 break-words text-3xl font-bold">{{ $organization->name }}</h1>
    <x-button href="/admin/organization/{{ $organization->id }}/edit">Изменить</x-button>
</div>

<x-tabs label="Разделы клиента" :active="$tab" :tabs="[
    'main' => ['label' => 'Клиент', 'url' => '/admin/organization/'.$organization->id],
    'codes' => ['label' => 'Коды товаров', 'url' => '/admin/organization/'.$organization->id.'?tab=codes', 'count' => $organization->product_codes_count],
]" />

@if($tab === 'codes')

    @include('partials.admin_organization_codes', ['organization' => $organization, 'codes' => $codes, 'search' => $search])

@else

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
        <dd>
            {{ $organization->unp ?? '—' }}
            {{-- Контрольная цифра — справочно --}}
            @if($unpWarning = \App\Rules\Unp::warning($organization->unp))
                <x-badge tone="warning" data-unp-warning>{{ $unpWarning }}</x-badge>
            @endif
        </dd>

        <dt class="text-slate-500">Адрес</dt>
        <dd>{{ $organization->address ?? '—' }}</dd>

        {{-- Банковские реквизиты (необязательные) --}}
        <dt class="text-slate-500">Банк</dt>
        <dd data-org-bank>{{ $organization->bank_name ?? '—' }}</dd>

        <dt class="text-slate-500">BIC</dt>
        <dd class="font-mono text-sm">{{ $organization->bic ?? '—' }}</dd>

        <dt class="text-slate-500">IBAN</dt>
        <dd class="break-all font-mono text-sm" data-org-iban>{{ $organization->ibanFormatted() ?? '—' }}</dd>

        <dt class="text-slate-500">Адрес банка</dt>
        <dd>{{ $organization->bank_address ?? '—' }}</dd>

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

        <x-table caption="Пользователи клиента">
            <x-slot:head>
                <x-table.th sticky="start">Имя</x-table.th>
                <x-table.th>Email</x-table.th>
                <x-table.th>Роль</x-table.th>
            </x-slot:head>
            @foreach($organization->users as $user)
                <tr>
                    <x-table.td sticky="start"><a href="/admin/user/{{ $user->id }}" class="block min-w-28 font-medium text-ink hover:text-link">{{ $user->name }}</a></x-table.td>
                    <x-table.td>{{ $user->email }}</x-table.td>
                    <x-table.td nowrap>{{ $user->role?->label() }}</x-table.td>
                </tr>
            @endforeach
        </x-table>

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

@endif

@endsection
