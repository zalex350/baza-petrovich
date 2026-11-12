{{--
    «Стать партнёром» (PartnerController): форма заявки организации. После отправки —
    благодарность вместо формы. Поле website — ловушка для ботов (скрыто от людей и диктора).
--}}
@extends('layouts.app')

@section('title', 'Стать партнёром — '.config('shop.name'))

@section('flash-validation', 'summary')

@section('content')

<x-breadcrumbs :items="[['label' => 'Главная', 'url' => '/'], ['label' => 'Стать партнёром']]" />

<div class="mt-3 max-w-2xl">

    <h1 class="mb-2 text-3xl font-bold tracking-tight">Стать партнёром</h1>

    @if(session('partner_sent'))
        <x-empty-state title="Заявка отправлена" icon="check-circle" data-partner-sent>
            Спасибо! Менеджер свяжется с вами, чтобы подключить организацию.
            <x-slot:action>
                <x-button href="/catalog">В каталог</x-button>
            </x-slot:action>
        </x-empty-state>
    @else
        <p class="mb-5 text-ink-muted">
            Оставьте заявку — менеджер свяжется с вами, создаст учётную запись организации и пришлёт приглашение на сайт.
        </p>

        <form method="POST" action="/partner" class="space-y-4 rounded-card border border-line bg-surface p-card" data-partner-form novalidate>
            @csrf

            <x-input name="organization" label="Организация" required maxlength="255" autocomplete="organization" />
            <x-input name="unp" label="УНП" required inputmode="numeric" maxlength="9" placeholder="9 цифр" :warning="\App\Rules\Unp::warning(old('unp'))" class="max-w-xs" />
            <x-input name="contact_name" label="Контактное лицо" required maxlength="255" autocomplete="name" />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-input name="phone" type="tel" label="Телефон" required maxlength="50" placeholder="+375 (29) 000-00-00" autocomplete="tel" />
                <x-input name="email" type="email" label="Email" required maxlength="255" autocomplete="email" />
            </div>

            <x-textarea name="comment" label="Комментарий" rows="3" maxlength="2000" hint="Например: что планируете закупать, город доставки." />

            <x-checkbox name="consent" label="Согласен на обработку персональных данных для рассмотрения заявки" :unchecked="false" required />

            <x-button class="w-full sm:w-auto">Отправить заявку</x-button>

            {{-- Ловушка для ботов (в конце: скрытый блок не даёт отступа над полями) --}}
            <div class="hidden" aria-hidden="true">
                <label for="f-website">Сайт</label>
                <input id="f-website" type="text" name="{{ \App\Http\Controllers\PartnerController::HONEYPOT }}" tabindex="-1" autocomplete="off">
            </div>
        </form>
    @endif

</div>

@endsection
