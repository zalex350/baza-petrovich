@extends('layouts.admin')

@section('title', 'Настройки')

@section('flash-validation', 'summary')

@section('content')

<h1 class="text-3xl font-bold mb-2">Настройки</h1>

<p class="text-sm text-ink-muted mb-6 max-w-3xl">
    Реквизиты продавца и короткие тексты — в подвале каждой страницы сайта. Пустое поле на сайте
    показывается как {{ \App\Models\Setting::PLACEHOLDER }}.
</p>

<form method="POST" action="/admin/settings" class="max-w-3xl space-y-6 rounded-card border border-line bg-surface p-card" data-settings-form>
    @csrf

    <fieldset class="space-y-4">
        <legend class="mb-1 text-lg font-semibold text-ink">Реквизиты продавца</legend>
        <x-input name="seller_name" :label="\App\Models\Setting::FIELDS['seller_name']" :value="$values['seller_name'] ?? ''" placeholder="ООО «…»" />
        <x-input name="seller_unp" :label="\App\Models\Setting::FIELDS['seller_unp']" :value="$values['seller_unp'] ?? ''" inputmode="numeric" class="max-w-xs" />
        <x-input name="seller_address" :label="\App\Models\Setting::FIELDS['seller_address']" :value="$values['seller_address'] ?? ''" />
        <x-input name="seller_registry" :label="\App\Models\Setting::FIELDS['seller_registry']" :value="$values['seller_registry'] ?? ''" hint="Номер и дата регистрации в Торговом реестре Республики Беларусь." />
    </fieldset>

    <fieldset class="space-y-4">
        <legend class="mb-1 text-lg font-semibold text-ink">Подвал сайта</legend>
        <x-textarea name="footer_about" :label="\App\Models\Setting::FIELDS['footer_about']" :value="$values['footer_about'] ?? ''" rows="3" hint="Две-три строки; подробно — страница «О компании»." />
        <x-textarea name="footer_delivery" :label="\App\Models\Setting::FIELDS['footer_delivery']" :value="$values['footer_delivery'] ?? ''" rows="3" hint="Кратко; подробно — страница «Доставка и оплата»." />
    </fieldset>

    <x-button>Сохранить</x-button>
</form>

@endsection
