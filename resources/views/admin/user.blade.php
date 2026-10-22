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
                <span class="text-sm text-slate-400 ml-2">с {{ $user->blocked_at->format('d.m.Y H:i') }}</span>
            @else
                <span class="inline-flex rounded-full bg-green-100 text-green-700 px-2 py-0.5 text-xs">
                    Активен
                </span>
            @endif
        </dd>

        <dt class="text-slate-500">Заказов</dt>
        <dd>{{ $user->orders_count }}</dd>

        <dt class="text-slate-500">Создан</dt>
        <dd>{{ $user->created_at?->format('d.m.Y H:i') }}</dd>

    </dl>

</div>

{{-- Уведомления на почту: только у администратора --}}
@if($user->isAdmin())

    <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6" data-notifications>

        <div class="flex items-center justify-between gap-4 mb-4">
            <h2 class="text-lg font-semibold">Уведомления на почту</h2>
            <a href="/admin/user/{{ $user->id }}/edit" class="text-sm underline">Изменить</a>
        </div>

        <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-3">
            @foreach(['notify_new_order' => 'Новый заказ', 'notify_cancel_request' => 'Запрос на отмену'] as $field => $title)
                <dt class="text-slate-500">{{ $title }}</dt>
                <dd>
                    @if($user->{$field})
                        <span class="inline-flex rounded-full bg-green-100 text-green-700 px-2 py-0.5 text-xs">Включено</span>
                    @else
                        <span class="inline-flex rounded-full bg-slate-100 text-slate-600 px-2 py-0.5 text-xs">Выключено</span>
                    @endif
                </dd>
            @endforeach
        </dl>

        @if($user->isBlocked())
            <p class="text-sm text-slate-400 mt-3">Заблокированным пользователям письма не отправляются.</p>
        @endif

    </div>

@endif

{{-- Действия --}}
<div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6">

    <h2 class="text-lg font-semibold mb-4">
        Доступ
    </h2>

    <div class="flex flex-wrap items-center gap-4">

        <form method="POST" action="/admin/user/{{ $user->id }}/reset-password" class="inline">
            @csrf
            <button
                type="submit"
                class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded"
            >
                Сбросить пароль
            </button>
        </form>

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
