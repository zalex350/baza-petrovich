{{--
    Сообщение.
    <x-alert type="success">Заказ оформлен</x-alert>
    type: success | error | warning | info; title — необязательный заголовок;
    dismissible — кнопка «×» (Alpine).
    Флеш-сообщения сессии целиком — <x-alert.flash />.
--}}
@props(['type' => 'info', 'title' => null, 'dismissible' => false])

@php
    $tone = match ($type) {
        'success' => 'bg-tone-success-bg text-tone-success-text border-tone-success-border',
        'error'   => 'bg-tone-danger-bg text-tone-danger-text border-tone-danger-border',
        'warning' => 'bg-tone-warning-bg text-tone-warning-text border-tone-warning-border',
        'info'    => 'bg-tone-info-bg text-tone-info-text border-tone-info-border',
    };
@endphp

<div
    role="{{ $type === 'error' ? 'alert' : 'status' }}"
    data-alert="{{ $type }}"
    @if($dismissible) x-data="{ shown: true }" x-show="shown" @endif
    {{ $attributes->class(['flex items-start gap-3 rounded-panel border px-4 py-3', $tone]) }}
>
    <div class="min-w-0 grow">
        @if($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        {{ $slot }}
    </div>

    @if($dismissible)
        <button type="button" class="-my-1 -mr-1 shrink-0 rounded-sm p-1 opacity-70 hover:opacity-100" @click="shown = false" aria-label="Закрыть">
            <span aria-hidden="true">&times;</span>
        </button>
    @endif
</div>
