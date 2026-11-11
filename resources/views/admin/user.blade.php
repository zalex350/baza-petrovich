@extends('layouts.admin')

@section('title', 'Пользователь — '.$user->name)

@section('content')

@php($isSelf = $user->is(auth()->user()))

<a
    href="/admin/users"
    class="text-sm text-slate-500 hover:text-blue-600"
>
    ← Назад к списку пользователей
</a>

<div class="flex items-center justify-between mt-4 mb-6">

    <h1 class="text-3xl font-bold">
        {{ $user->name }}
    </h1>

    <a
        href="/admin/user/{{ $user->id }}/edit"
        class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded"
    >
        Изменить
    </a>

</div>

{{-- Данные --}}
<div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6">

    <h2 class="text-lg font-semibold mb-4">
        Данные пользователя
    </h2>

    <dl class="grid grid-cols-1 sm:grid-cols-[220px_1fr] gap-x-6 gap-y-3">

        <dt class="text-slate-500">ФИО</dt>
        <dd>{{ $user->name }}</dd>

        <dt class="text-slate-500">Email</dt>
        <dd>{{ $user->email }}</dd>

        <dt class="text-slate-500">Телефон</dt>
        <dd>{{ $user->phone ?? '—' }}</dd>

        <dt class="text-slate-500">Организация</dt>
        <dd>
            @if($user->organization)
                <a href="/admin/organization/{{ $user->organization->id }}" class="underline">
                    {{ $user->organization->name }}
                </a>
            @else
                —
            @endif
        </dd>

        <dt class="text-slate-500">Роль</dt>
        <dd>{{ $user->role?->label() }}</dd>

        <dt class="text-slate-500">Статус</dt>
        <dd>
            @if($user->isBlocked())
                <span class="inline-flex rounded-full bg-red-100 text-red-700 px-2 py-0.5 text-xs">
                    Заблокирован
                </span>
                <span class="text-sm text-slate-400 ml-2">с {{ date_ru($user->blocked_at, true) }}</span>
            @else
                <span class="inline-flex rounded-full bg-green-100 text-green-700 px-2 py-0.5 text-xs">
                    Активен
                </span>
            @endif
        </dd>

        <dt class="text-slate-500">Заказов</dt>
        <dd>{{ $user->orders_count }}</dd>

        <dt class="text-slate-500">Создан</dt>
        <dd>{{ date_ru($user->created_at, true) }}</dd>

    </dl>

</div>

{{-- Приглашение: пока не принято (App\Services\UserInvitations) --}}
@if($invitation)

    <div class="bg-white rounded-card border border-slate-200 p-card mb-6 space-y-4" data-invitation>

        <div>
            <h2 class="text-lg font-semibold">Приглашение</h2>
            <p class="text-sm text-slate-500">
                Пароль ещё не установлен. Письмо со ссылкой отправлено на {{ $user->email }};
                ссылку можно передать и самому — по ней пользователь установит пароль.
            </p>
        </div>

        @if($invitation['expired'])
            <x-alert type="warning">Срок ссылки истёк {{ date_ru($invitation['expires_at'], true) }}. Выпустите новую.</x-alert>
        @elseif($invitation['url'])
            <x-field id="invite-url" label="Ссылка приглашения" hint="Действует до {{ date_ru($invitation['expires_at'], true) }}">
                <x-copy field id="invite-url" :text="$invitation['url']" label="Скопировать ссылку" data-invite-url />
            </x-field>
        @else
            <x-alert type="info">Ссылка выпущена до того, как её стали показывать здесь (действует до {{ date_ru($invitation['expires_at'], true) }}). Чтобы увидеть ссылку, выпустите новую.</x-alert>
        @endif

        <x-confirm
            action="/admin/user/{{ $user->id }}/invitation"
            label="Выпустить новую ссылку"
            title="Выпустить новую ссылку?"
            confirm-label="Выпустить"
            variant="primary"
            trigger-variant="secondary"
        >
            Прежняя ссылка перестанет действовать. Новая будет отправлена на {{ $user->email }}
            и появится здесь; срок — {{ \App\Notifications\UserInvitation::expireText() }}.
        </x-confirm>

    </div>

@endif

{{-- Уведомления на почту: только у администратора --}}
@if($user->isAdmin())

    <form
        method="POST"
        action="/admin/user/{{ $user->id }}/notifications"
        class="bg-white rounded-card border border-slate-200 p-card mb-6 space-y-4"
        data-notifications
    >
        @csrf

        <div>
            <h2 class="text-lg font-semibold">Уведомления на почту</h2>
            <p class="text-sm text-slate-500">Письма уходят на {{ $user->email }}.</p>
        </div>

        @if($user->isBlocked())
            <x-alert type="warning">Пользователь заблокирован — письма ему не отправляются.</x-alert>
        @endif

        <x-checkbox
            name="notify_new_order"
            label="Новый заказ"
            hint="Письмо при оформлении каждого заказа клиентом."
            :checked="$user->notify_new_order"
        />

        <x-checkbox
            name="notify_cancel_request"
            label="Запрос на отмену"
            hint="Письмо, когда клиент запрашивает отмену заказа."
            :checked="$user->notify_cancel_request"
        />

        <x-checkbox
            name="notify_partner_request"
            label="Заявка партнёра"
            hint="Письмо о новой заявке «Стать партнёром» с сайта."
            :checked="$user->notify_partner_request"
        />

        <x-checkbox
            name="notify_exchange"
            label="Сбои обмена с 1С"
            hint="Письмо, если задание обмена не обработано, в нём много ошибок или цены давно не приходили."
            :checked="$user->notify_exchange"
        />

        <x-button size="sm">Сохранить</x-button>
    </form>

@endif

{{-- Действия --}}
<div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6">

    <h2 class="text-lg font-semibold mb-4">
        Доступ
    </h2>

    <div class="flex flex-wrap items-center gap-4">

        <x-confirm
            action="/admin/user/{{ $user->id }}/reset-password"
            label="Сбросить пароль"
            title="Сбросить пароль?"
            confirm-label="Отправить ссылку"
            variant="primary"
            trigger-variant="secondary"
        >
            На {{ $user->email }} уйдёт письмо со ссылкой для установки нового пароля.
            Текущий пароль действует, пока пользователь его не сменит.
        </x-confirm>

        @if($user->isBlocked())

            <form method="POST" action="/admin/user/{{ $user->id }}/unblock" class="inline">
                @csrf
                <button
                    type="submit"
                    class="bg-green-700 hover:bg-green-600 text-white px-4 py-2 rounded"
                >
                    Разблокировать
                </button>
            </form>

        @elseif($isSelf)

            <span class="text-sm text-slate-400">Нельзя заблокировать самого себя</span>

        @else

            <form method="POST" action="/admin/user/{{ $user->id }}/block" class="inline">
                @csrf
                <button
                    type="submit"
                    class="border border-red-300 text-red-700 hover:bg-red-50 px-4 py-2 rounded"
                    onclick="return confirm('Заблокировать пользователя {{ addslashes($user->email) }}? Он не сможет войти, активная сессия будет завершена.')"
                >
                    Заблокировать
                </button>
            </form>

        @endif

    </div>

    <p class="text-xs text-slate-400 mt-3">
        «Сбросить пароль» отправляет на email пользователя ссылку для восстановления пароля.
        Удаление пользователей не предусмотрено: используйте блокировку, история заказов сохраняется.
    </p>

</div>

@endsection
