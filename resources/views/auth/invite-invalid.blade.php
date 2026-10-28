{{--
    Ссылка приглашения недействительна (NewPasswordController::create): пароль по ней уже
    установлен, срок истёк или ссылку заменили новой. Формы установки пароля нет.
--}}
@extends('layouts.app')

@section('title', 'Ссылка недействительна — '.config('shop.name'))

@section('content')
    <x-empty-state title="Ссылка недействительна или устарела" icon="link" class="mx-auto my-8 max-w-xl" data-invite-invalid>
        <p>
            Пароль по этой ссылке уже установлен, срок её действия ({{ \App\Notifications\UserInvitation::expireText() }}) истёк
            или менеджер выпустил новую ссылку.
        </p>
        <p class="mt-1">Войдите со своим паролем или попросите менеджера отправить приглашение ещё раз.</p>

        <x-slot:action>
            <x-button :href="route('login')">Войти</x-button>
            <x-button variant="secondary" :href="route('password.request')">Забыли пароль?</x-button>
        </x-slot:action>
    </x-empty-state>
@endsection
