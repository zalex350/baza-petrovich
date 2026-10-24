{{--
    Логотип-ссылка на главную: название магазина (config shop.name), доменная зона — цветом ссылки
    (SMESITEL<span>.BY</span>, как в макетах docs/design).
    <x-logo />            — шапка, 22px
    <x-logo size="sm" />  — мобильная шапка и меню, 19px
    href: null — без ссылки (текстом).
--}}
@props(['size' => 'md', 'href' => '/'])

@php
    $name = config('shop.name');
    $dot = strrpos($name, '.');
    [$base, $zone] = $dot === false ? [$name, ''] : [substr($name, 0, $dot), substr($name, $dot)];
    $tag = $href === null ? 'span' : 'a';
@endphp

<{{ $tag }}
    @if($href !== null) href="{{ $href }}" aria-label="{{ $name }} — на главную" @endif
    {{ $attributes->class([
        'font-bold tracking-tight text-ink whitespace-nowrap',
        $size === 'sm' ? 'text-logo-sm' : 'text-logo',
    ]) }}
    data-logo
>{{ $base }}@if($zone)<span class="text-link">{{ $zone }}</span>@endif</{{ $tag }}>
