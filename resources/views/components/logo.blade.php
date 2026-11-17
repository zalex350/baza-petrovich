{{--
    Логотип-ссылка на главную: знак (первая буква на фирменном жёлтом) + название магазина
    (config shop.name) + подпись (config shop.tagline, «металлобаза»). Название с доменной зоной
    (SMESITEL.BY) — зона цветом ссылки.
    <x-logo />            — шапка, 22px
    <x-logo size="sm" />  — мобильная шапка и меню, 19px
    href: null — без ссылки (текстом).
--}}
@props(['size' => 'md', 'href' => '/'])

@php
    $name = config('shop.name');
    $tagline = config('shop.tagline');
    $dot = strrpos($name, '.');
    [$base, $zone] = $dot === false ? [$name, ''] : [substr($name, 0, $dot), substr($name, $dot)];
    $tag = $href === null ? 'span' : 'a';
    $sm = $size === 'sm';
@endphp

<{{ $tag }}
    @if($href !== null) href="{{ $href }}" aria-label="{{ $name }} — на главную" @endif
    {{ $attributes->class(['inline-flex items-center gap-2 whitespace-nowrap text-ink']) }}
    data-logo
>
    <span @class(['grid place-items-center rounded-control bg-accent font-extrabold text-accent-fg', $sm ? 'h-8 w-8 text-lg' : 'h-10 w-10 text-xl']) aria-hidden="true">{{ mb_substr($base, 0, 1) }}</span>
    <span class="leading-none">
        <span @class(['block font-extrabold tracking-tight', $sm ? 'text-logo-sm' : 'text-logo'])>{{ $base }}@if($zone)<span class="text-link">{{ $zone }}</span>@endif</span>
        @if($tagline)
            <span class="mt-0.5 block text-[11px] font-semibold uppercase tracking-[0.14em] text-ink-muted">{{ $tagline }}</span>
        @endif
    </span>
</{{ $tag }}>
