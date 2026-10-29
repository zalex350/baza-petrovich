@extends('layouts.account')

@section('title', 'Профиль')

{{--
    Профиль (SRS §8.12): пользователь меняет только свои ФИО и телефон (BR-116); email —
    логин, только просмотр (меняет администратор). Пароль — отдельный раздел «Смена пароля».
--}}

@section('flash-validation', 'summary')

@section('account')

<h1 class="text-3xl font-bold tracking-tight mb-5">Профиль</h1>

<form method="POST" action="{{ route('profile.update') }}" class="max-w-xl space-y-4 rounded-card border border-line bg-surface p-card" data-profile-form>
    @csrf
    @method('PATCH')

    <x-input name="name" label="ФИО" :value="$user->name" required maxlength="255" autocomplete="name" />
    <x-input name="phone" type="tel" label="Телефон" :value="$user->phone" maxlength="50" autocomplete="tel" placeholder="+375 (29) 000-00-00" />

    <x-field id="f-email" label="Email">
        <p class="text-ink" data-profile-email>{{ $user->email }}</p>
        <p class="mt-1 text-sm text-ink-muted">Email — логин для входа; изменить его может менеджер.</p>
    </x-field>

    @if($user->organization)
        <x-field id="f-organization" label="Организация">
            <p class="text-ink">{{ $user->organization->name }}</p>
        </x-field>
    @endif

    <x-button>Сохранить</x-button>
</form>

@endsection
