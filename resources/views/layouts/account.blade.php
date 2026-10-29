{{--
    Личный кабинет (SRS «4. Личный кабинет», §8): меню разделов слева на десктопе,
    вкладками с прокруткой сверху на телефоне; содержимое — @section('account').
    Пункты — App\Support\AccountMenu (тот же список — в меню пользователя в шапке и в панели ☰).
    Страница: @extends('layouts.account') + @section('title') + @section('account').
--}}
@extends('layouts.app')

@php
    $user = auth()->user();

    $accountMenu = \App\Support\AccountMenu::items($user);
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
