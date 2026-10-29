{{--
    Личный кабинет (SRS «4. Личный кабинет», §8): меню разделов слева на десктопе,
    вкладками с прокруткой сверху на телефоне; содержимое — @section('account').
    Пункты организации (реквизиты, пользователи, адреса) — только клиенту с организацией.
    Страница: @extends('layouts.account') + @section('title') + @section('account').
--}}
@extends('layouts.app')

@php
    $user = auth()->user();
    $hasOrganization = $user->isClient() && $user->organization_id;

    $accountMenu = array_values(array_filter([
        ['label' => 'Мои заказы', 'url' => '/orders', 'icon' => 'heroicon-o-clipboard-document-list', 'active' => request()->is('orders') || request()->is('order/*')],
        $hasOrganization ? ['label' => 'Организация', 'url' => '/account/organization', 'icon' => 'heroicon-o-building-office-2', 'active' => request()->is('account/organization')] : null,
        $hasOrganization ? ['label' => 'Пользователи организации', 'url' => '/account/users', 'icon' => 'heroicon-o-users', 'active' => request()->is('account/users')] : null,
        $user->isClient() ? ['label' => 'Адреса доставки', 'url' => '/addresses', 'icon' => 'heroicon-o-map-pin', 'active' => request()->is('addresses*')] : null,
        ['label' => 'Профиль', 'url' => '/profile', 'icon' => 'heroicon-o-user', 'active' => request()->is('profile')],
        ['label' => 'Смена пароля', 'url' => '/account/password', 'icon' => 'heroicon-o-key', 'active' => request()->is('account/password')],
    ]));
@endphp

@section('content')

<div class="lg:grid lg:grid-cols-[16rem_minmax(0,1fr)] lg:items-start lg:gap-6" data-account>

    <nav aria-label="Личный кабинет" class="mb-5 lg:sticky lg:top-40 lg:mb-0" data-account-menu>
        <div class="mb-2 hidden px-1 lg:block">
            <div class="text-meta text-ink-muted">Личный кабинет</div>
            <div class="truncate font-semibold text-ink">{{ $user->name }}</div>
            @if($user->organization)
                <div class="truncate text-sm text-ink-muted">{{ $user->organization->name }}</div>
            @endif
        </div>

        {{-- relative: скрытые подписи внутри прокручиваемой полосы не расширяют страницу --}}
        <ul class="relative -mx-4 flex gap-1 overflow-x-auto border-b border-line px-4 lg:mx-0 lg:flex-col lg:overflow-visible lg:rounded-card lg:border lg:bg-surface lg:p-2">
            @foreach($accountMenu as $item)
                <li class="shrink-0">
                    <a
                        href="{{ $item['url'] }}"
                        @class([
                            'flex items-center gap-2.5 whitespace-nowrap px-3 py-2.5 text-sm font-medium transition lg:whitespace-normal lg:rounded-control',
                            'border-b-2 border-link text-link lg:border-b-0 lg:bg-chip' => $item['active'],
                            'border-b-2 border-transparent text-ink hover:text-link lg:border-b-0 lg:hover:bg-canvas' => ! $item['active'],
                        ])
                        @if($item['active']) aria-current="page" @endif
                        data-account-item
                    >
                        @svg($item['icon'], 'hidden h-5 w-5 shrink-0 lg:block')
                        {{ $item['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    <div class="min-w-0">
        @yield('account')
    </div>

</div>

@endsection
