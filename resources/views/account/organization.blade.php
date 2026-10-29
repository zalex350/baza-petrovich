@extends('layouts.account')

@section('title', 'Организация')

{{--
    Реквизиты организации клиента (SRS §8.5) — только просмотр: данные ведёт менеджер
    (админка), позже — 1С. Данные: AccountController::organization — $organization, $addressesCount.
--}}

@section('account')

<h1 class="text-3xl font-bold tracking-tight mb-1">Организация</h1>
<p class="mb-5 text-ink-muted">Реквизиты ведёт менеджер. Нашли ошибку — сообщите менеджеру.</p>

<div class="rounded-card border border-line bg-surface p-card" data-organization>
    <dl class="grid gap-x-8 gap-y-4 sm:grid-cols-2">
        @foreach([
            'Название' => $organization->name,
            'Полное наименование' => $organization->legal_name,
            'УНП' => $organization->unp,
            'Юридический адрес' => $organization->address,
            'Телефон' => $organization->phone,
            'Email' => $organization->email,
        ] as $label => $value)
            <div class="min-w-0">
                <dt class="text-sm text-ink-muted">{{ $label }}</dt>
                <dd class="mt-0.5 break-words font-medium text-ink">{{ filled($value) ? $value : '—' }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="mt-6 flex flex-wrap gap-3 border-t border-line-soft pt-4 text-sm">
        <a href="/account/users" class="text-link hover:underline">Пользователи организации</a>
        <a href="/addresses" class="text-link hover:underline">Адреса доставки ({{ $addressesCount }})</a>
    </div>
</div>

@endsection
