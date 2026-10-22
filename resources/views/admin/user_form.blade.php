@extends('layouts.admin')

@section('title', $user->exists ? 'Редактирование пользователя' : 'Новый пользователь')

@section('content')

@php
    $isSelf = $user->exists && $user->is(auth()->user());
    $currentRole = old('role', $user->role?->value ?? \App\Enums\UserRole::Client->value);
    $passwordMode = old('password_mode', \App\Http\Controllers\Admin\AdminUserController::PASSWORD_INVITE);
    // Уведомления: у нового пользователя — включены (old() после ошибки учитывает x-checkbox)
    $notify = fn (string $field) => $user->exists ? (bool) $user->{$field} : true;
@endphp

<a
    href="{{ $user->exists ? '/admin/user/'.$user->id : '/admin/users' }}"
    class="text-sm text-slate-500 hover:text-blue-600"
>
    {{ $user->exists ? '← Назад к карточке пользователя' : '← Назад к списку пользователей' }}
</a>

<h1 class="text-3xl font-bold mt-4 mb-6">
    {{ $user->exists ? 'Редактирование пользователя' : 'Новый пользователь' }}
</h1>

@if($errors->any())

    <div class="bg-red-50 border border-red-200 rounded-2xl p-4 mb-6">
        <ul class="list-disc list-inside text-red-700 space-y-1">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>

@endif

<div class="bg-white rounded-2xl border border-slate-200 p-6">

    <form
        method="POST"
        action="{{ $user->exists ? '/admin/user/'.$user->id : '/admin/user' }}"
        class="space-y-6"
        x-data="{ role: @js($isSelf ? \App\Enums\UserRole::Admin->value : $currentRole) }"
    >

        @csrf

        <!-- Name -->
        <div>
            <label class="block mb-2 font-semibold">
                ФИО <span class="text-red-600">*</span>
            </label>
            <input
                type="text"
                name="name"
                value="{{ old('name', $user->name) }}"
                class="w-full border rounded px-3 py-2"
                required
            >
        </div>

        <!-- Email -->
        <div>
            <label class="block mb-2 font-semibold">
                Email <span class="text-red-600">*</span>
            </label>
            <input
                type="email"
                name="email"
                value="{{ old('email', $user->email) }}"
                class="w-full border rounded px-3 py-2"
                required
            >
        </div>

        <!-- Phone -->
        <div>
            <label class="block mb-2 font-semibold">Телефон</label>
            <input
                type="text"
                name="phone"
                value="{{ old('phone', $user->phone) }}"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <!-- Role -->
        <div>
            <label class="block mb-2 font-semibold">
                Роль <span class="text-red-600">*</span>
            </label>

            @if($isSelf)

                <input type="hidden" name="role" value="{{ \App\Enums\UserRole::Admin->value }}">

                <select class="w-full border rounded px-3 py-2 bg-slate-100 text-slate-500" disabled>
                    <option>{{ \App\Enums\UserRole::Admin->label() }}</option>
                </select>

                <p class="text-xs text-slate-400 mt-1">Нельзя снять с себя роль администратора.</p>

            @else

                <select name="role" class="w-full border rounded px-3 py-2" x-model="role">
                    @foreach(\App\Enums\UserRole::cases() as $case)
                        <option value="{{ $case->value }}" @selected($currentRole === $case->value)>
                            {{ $case->label() }}
                        </option>
                    @endforeach
                </select>

            @endif
        </div>

        <!-- Organization -->
        <div>
            <label class="block mb-2 font-semibold">
                Организация
                <span class="text-slate-400 font-normal text-sm">(обязательна для клиента)</span>
            </label>
            <select name="organization_id" class="w-full border rounded px-3 py-2">
                <option value="">— не выбрана —</option>
                @foreach($organizations as $organization)
                    <option
                        value="{{ $organization->id }}"
                        @selected((string) old('organization_id', $user->organization_id) === (string) $organization->id)
                    >
                        {{ $organization->name }}{{ $organization->unp ? ' (УНП '.$organization->unp.')' : '' }}
                    </option>
                @endforeach
            </select>
        </div>

        <!-- Notifications: только для администратора -->
        <fieldset
            class="space-y-3"
            data-notifications
            x-show="role === @js(\App\Enums\UserRole::Admin->value)"
            @unless($currentRole === \App\Enums\UserRole::Admin->value || $isSelf) x-cloak @endunless
        >

            <legend class="mb-2 font-semibold">Уведомления на почту</legend>

            <x-checkbox
                name="notify_new_order"
                label="Новый заказ"
                hint="Письмо при оформлении каждого заказа клиентом."
                :checked="$notify('notify_new_order')"
            />

            <x-checkbox
                name="notify_cancel_request"
                label="Запрос на отмену"
                hint="Письмо, когда клиент запрашивает отмену заказа."
                :checked="$notify('notify_cancel_request')"
            />

            <p class="text-sm text-slate-500">Письма уходят на email пользователя: {{ $user->email ?: 'указанный выше' }}.</p>

        </fieldset>

        @unless($user->exists)

            <!-- Password -->
            <fieldset class="space-y-3">

                <legend class="mb-2 font-semibold">Пароль</legend>

                <label class="flex items-start gap-2">
                    <input
                        type="radio"
                        name="password_mode"
                        value="{{ \App\Http\Controllers\Admin\AdminUserController::PASSWORD_INVITE }}"
                        @checked($passwordMode === \App\Http\Controllers\Admin\AdminUserController::PASSWORD_INVITE)
                        class="mt-1"
                    >
                    <span>
                        Отправить ссылку для установки пароля
                        <span class="block text-sm text-slate-400">На email придёт приглашение на smesitel.by со ссылкой для установки пароля.</span>
                    </span>
                </label>

                <label class="flex items-start gap-2">
                    <input
                        type="radio"
                        name="password_mode"
                        value="{{ \App\Http\Controllers\Admin\AdminUserController::PASSWORD_MANUAL }}"
                        @checked($passwordMode === \App\Http\Controllers\Admin\AdminUserController::PASSWORD_MANUAL)
                        class="mt-1"
                    >
                    <span>Задать пароль вручную</span>
                </label>

                <div>
                    <input
                        type="password"
                        name="password"
                        autocomplete="new-password"
                        placeholder="Пароль (не короче 8 символов)"
                        class="w-full border rounded px-3 py-2"
                    >
                    <p class="text-xs text-slate-400 mt-1">Используется только при выборе «Задать пароль вручную».</p>
                </div>

            </fieldset>

        @endunless

        <!-- Submit -->
        <div>
            <button
                type="submit"
                class="bg-slate-800 hover:bg-slate-700 text-white px-6 py-3 rounded"
            >
                {{ $user->exists ? 'Сохранить' : 'Создать пользователя' }}
            </button>
        </div>

    </form>

</div>

@endsection
