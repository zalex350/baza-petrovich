@extends('layouts.account')

@section('title', 'Смена пароля')

{{--
    Смена пароля (SRS §8.13): текущий пароль, новый (не короче 8 символов) и повтор.
    Сохраняет Auth\PasswordController (PUT /password). Забыли текущий — выйти и «Забыли пароль?».
--}}

@section('flash-validation', 'summary')

@section('account')

<h1 class="text-3xl font-bold tracking-tight mb-5">Смена пароля</h1>

<form method="POST" action="{{ route('password.update') }}" class="max-w-xl space-y-4 rounded-card border border-line bg-surface p-card" data-password-form>
    @csrf
    @method('PUT')

    <x-input name="current_password" type="password" label="Текущий пароль" required autocomplete="current-password" />
    <x-input name="password" type="password" label="Новый пароль" required autocomplete="new-password" hint="Не короче 8 символов." />
    <x-input name="password_confirmation" type="password" label="Новый пароль ещё раз" required autocomplete="new-password" />

    <x-button>Сменить пароль</x-button>
</form>

@endsection
