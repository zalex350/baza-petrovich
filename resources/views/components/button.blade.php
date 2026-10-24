{{--
    Кнопка. С href — ссылка <a> с тем же видом.
    <x-button>Сохранить</x-button>
    <x-button variant="secondary" size="sm" href="/admin/orders">Сбросить</x-button>
    variant: primary | secondary | danger | link; size: md | sm; type (для <button>): submit по умолчанию.
--}}
@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'type' => 'submit'])

@php
    $classes = [
        'inline-flex items-center justify-center gap-2 font-medium transition whitespace-nowrap',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2',
        'disabled:opacity-50 disabled:cursor-not-allowed',
        match ($variant) {
            'primary'   => 'rounded-control bg-primary text-white hover:bg-primary-hover',
            'secondary' => 'rounded-control border border-line bg-surface text-ink hover:bg-canvas',
            'danger'    => 'rounded-control bg-danger text-white hover:bg-danger-hover focus-visible:ring-danger',
            'link'      => 'rounded-sm text-link underline underline-offset-2 hover:text-link-hover',
        },
        // У ссылки-кнопки нет горизонтальных отступов: она стоит в строке текста
        match ($variant === 'link' ? "link-$size" : $size) {
            'md'      => 'px-btn-x py-btn-y',
            'sm'      => 'px-btn-x-sm py-btn-y-sm text-sm',
            'link-md' => 'py-btn-y',
            'link-sm' => 'py-btn-y-sm text-sm',
        },
    ];
@endphp

@if($href !== null)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
