{{--
    Заявка с сайта (LeadController): «Узнать цену» товара, «Заказать услугу», «Обратный звонок».
    Данные: $type, $title, $subject, $product (для цены), $back. После отправки — благодарность.
--}}
@extends('layouts.app')

@section('title', $title.' — '.config('shop.name'))

@section('flash-validation', 'summary')

@section('content')

<x-breadcrumbs :items="[['label' => 'Главная', 'url' => '/'], ['label' => $title]]" />

<div class="mt-3 max-w-2xl" data-lead="{{ $type }}">

    <h1 class="mb-2 text-3xl font-bold tracking-tight">{{ $title }}</h1>

    @if(session('lead_sent'))
        {{-- Заявка — конверсия «Заявка» (resources/js/analytics.js) --}}
        <x-track event="generate_lead" :params="['lead_type' => $type, 'value' => 1]" />
        <x-empty-state title="Заявка отправлена" icon="check-circle" data-lead-sent>
            Спасибо! Менеджер перезвонит вам в рабочее время.
            <x-slot:action>
                <x-button :href="$back">Вернуться</x-button>
            </x-slot:action>
        </x-empty-state>
    @else
        @if($subject)
            <p class="mb-5 text-ink-muted" data-lead-subject>{{ $subject }}</p>
        @endif

        <form method="POST" action="{{ route('lead.store') }}" class="space-y-4 rounded-card border border-line bg-surface p-card" data-lead-form novalidate>
            @csrf
            <input type="hidden" name="type" value="{{ $type }}">
            @if($product)
                <input type="hidden" name="product_id" value="{{ $product->id }}">
            @elseif($subject)
                <input type="hidden" name="subject" value="{{ $subject }}">
            @endif

            <x-input name="name" label="Имя" required maxlength="255" autocomplete="name" />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-input name="phone" type="tel" label="Телефон" required maxlength="50" placeholder="+375 (29) 000-00-00" autocomplete="tel" />
                <x-input name="email" type="email" label="Email" maxlength="255" autocomplete="email" />
            </div>

            <x-textarea name="comment" label="Комментарий" rows="3" maxlength="2000" :hint="$type === 'price' ? 'Например: нужное количество, длина, резка в размер.' : null" />

            <x-checkbox name="consent" label="Согласен на обработку персональных данных для ответа на заявку" :unchecked="false" required />
            <x-legal-note />

            <x-button class="w-full sm:w-auto">Отправить заявку</x-button>

            <div class="hidden" aria-hidden="true">
                <label for="f-website">Сайт</label>
                <input id="f-website" type="text" name="{{ \App\Http\Controllers\LeadController::HONEYPOT }}" tabindex="-1" autocomplete="off">
            </div>
        </form>
    @endif

</div>

@endsection
