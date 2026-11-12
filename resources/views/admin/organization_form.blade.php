{{--
    Клиент (организация): создание и редактирование (AdminOrganizationController@create / @edit).
    УНП — 9 цифр с контрольной цифрой (App\Rules\Unp); банковские реквизиты необязательные:
    BIC — 8 или 11 символов, IBAN — BY + 26 символов с проверкой контрольных цифр (пробелы при
    вводе допускаются, хранится без них). Ошибки — у полей. GUID 1С — только чтение.
--}}
@extends('layouts.admin')

@section('title', $organization->exists ? 'Редактирование клиента' : 'Новый клиент')

@section('flash-validation', 'summary')

@section('content')

<a href="{{ $organization->exists ? '/admin/organization/'.$organization->id : '/admin/organizations' }}" class="text-sm text-ink-muted hover:text-link">
    {{ $organization->exists ? '← Назад к карточке клиента' : '← Назад к списку клиентов' }}
</a>

<h1 class="text-3xl font-bold mt-4 mb-6">{{ $organization->exists ? 'Редактирование клиента' : 'Новый клиент' }}</h1>

<form
    method="POST"
    action="{{ $organization->exists ? '/admin/organization/'.$organization->id : '/admin/organization' }}"
    class="max-w-3xl space-y-5 rounded-card border border-line bg-surface p-card"
    data-organization-form
>
    @csrf

    <x-input name="name" label="Наименование" :value="$organization->name" maxlength="255" required />
    <x-input name="legal_name" label="Полное юридическое наименование" :value="$organization->legal_name" maxlength="255" />
    <x-input name="unp" label="УНП" :value="$organization->unp" hint="9 цифр, последняя — контрольная" inputmode="numeric" maxlength="11" autocomplete="off" />
    <x-input name="address" label="Адрес" :value="$organization->address" maxlength="255" />
    <x-input name="phone" label="Телефон" :value="$organization->phone" maxlength="50" />
    <x-input name="email" type="email" label="Email" :value="$organization->email" maxlength="255" />

    <fieldset class="space-y-5 border-t border-line-soft pt-5" data-organization-bank>
        <legend class="text-lg font-semibold">Банковские реквизиты <span class="text-sm font-normal text-ink-muted">(необязательно)</span></legend>
        <x-input name="bank_name" label="Банк" :value="$organization->bank_name" maxlength="255" />
        <div class="grid gap-5 sm:grid-cols-[minmax(0,12rem)_1fr]">
            <x-input name="bic" label="BIC" :value="$organization->bic" hint="8 или 11 символов" maxlength="11" autocomplete="off" />
            <x-input name="iban" label="IBAN" :value="$organization->ibanFormatted() ?? $organization->iban" hint="BY и 26 символов; пробелы можно" maxlength="40" autocomplete="off" />
        </div>
        <x-input name="bank_address" label="Адрес банка" :value="$organization->bank_address" maxlength="255" />
    </fieldset>

    <x-textarea name="comment" label="Комментарий" :value="$organization->comment" rows="4" />

    @if($organization->exists)
        <x-input name="guid_readonly" label="GUID 1С" :value="$organization->guid ?? '—'" hint="Заполняется только при обмене с 1С" disabled />
    @endif

    <x-button>{{ $organization->exists ? 'Сохранить' : 'Создать клиента' }}</x-button>
</form>

@endsection
