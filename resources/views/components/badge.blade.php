{{--
    Бейдж.
    <x-badge :status="$order->status" />           — статус заказа: цвет и подпись из OrderStatus (единая карта)
    <x-badge tone="success">Оплачен</x-badge>
    status: OrderStatus | OrderHistoryEvent; tone: neutral | info | success | warning | danger | attention;
    size: sm (списки) | md.
--}}
@props(['status' => null, 'tone' => 'neutral', 'size' => 'sm'])

@php
    $color = $status?->color() ?? match ($tone) {
        'neutral'   => 'bg-tone-neutral-bg text-tone-neutral-text',
        'info'      => 'bg-tone-info-bg text-tone-info-text',
        'success'   => 'bg-tone-success-bg text-tone-success-text',
        'warning'   => 'bg-tone-warning-bg text-tone-warning-text',
        'danger'    => 'bg-tone-danger-bg text-tone-danger-text',
        'attention' => 'bg-tone-attention-bg text-tone-attention-text',
    };
@endphp

<span
    @if($status) data-status="{{ $status->value }}" @endif
    {{ $attributes->class([
        'inline-flex items-center rounded-full font-medium whitespace-nowrap',
        $color,
        $size === 'md' ? 'px-3 py-1 text-sm' : 'px-2 py-0.5 text-xs',
    ]) }}
>{{ $slot->isEmpty() && $status ? $status->label() : $slot }}</span>
