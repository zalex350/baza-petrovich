@extends('layouts.admin')

@section('title', $user->exists ? 'Редактирование пользователя' : 'Новый пользователь')

{{-- Ошибки — у полей (x-input / x-select), сверху — один alert «Проверьте выделенные поля» --}}
@section('flash-validation', 'summary')

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

<div class="bg-white rounded-2xl border border-slate-200 p-6">

    <form
        method="POST"
        action="{{ $user->exists ? '/admin/user/'.$user->id : '/admin/user' }}"
        class="space-y-6"
        x-data="{ role: @js($isSelf ? \App\Enums\UserRole::Admin->value : $currentRole) }"
    >

        @csrf

        <x-input name="name" label="ФИО" :value="$user->name" required autocomplete="name" />

        <x-input name="email" type="email" label="Email" :value="$user->email" required autocomplete="email" />

        <x-input name="phone" label="Телефон" :value="$user->phone" autocomplete="tel" />

        @if($isSelf)
            <input type="hidden" name="role" value="{{ \App\Enums\UserRole::Admin->value }}">
            <x-select
                name="role_display"
                id="f-role"
                label="Роль"
                :options="[\App\Enums\UserRole::Admin->value => \App\Enums\UserRole::Admin->label()]"
                :value="\App\Enums\UserRole::Admin->value"
                hint="Нельзя снять с себя роль администратора."
                disabled
            />
        @else
            <x-select
                name="role"
                label="Роль"
                required
                :options="collect(\App\Enums\UserRole::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])"
                :value="$currentRole"
                x-model="role"
            />
        @endif

        <x-select
            name="organization_id"
            label="Организация"
            hint="Обязательна для клиента."
            placeholder="— не выбрана —"
            :options="$organizations->mapWithKeys(fn ($organization) => [$organization->id => $organization->name.($organization->unp ? ' (УНП '.$organization->unp.')' : '')])"
            :value="$user->organization_id"
        />

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

            <x-checkbox
                name="notify_partner_request"
                label="Заявки с сайта и партнёров"
                hint="Письмо о новой заявке «Стать партнёром» с сайта."
                :checked="$notify('notify_partner_request')"
            />

            <x-checkbox
                name="notify_exchange"
                label="Сбои обмена с 1С"
                hint="Письмо, если задание обмена не обработано, в нём много ошибок или цены давно не приходили."
                :checked="$notify('notify_exchange')"
            />

            <p class="text-sm text-slate-500">Письма уходят на email пользователя: {{ $user->email ?: 'указанный выше' }}.</p>

        </fieldset>

        @unless($user->exists)

            <!-- Password -->
            <fieldset class="space-y-3">

                <legend class="mb-2 font-semibold">Пароль</legend>

                <x-field id="f-password_mode" error="password_mode" class="space-y-3">
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
                </x-field>

                <x-input
                    name="password"
                    type="password"
                    label="Пароль"
                    autocomplete="new-password"
                    placeholder="Не короче 8 символов"
                    hint="Используется только при выборе «Задать пароль вручную»."
                />

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
