{{--
    Количество: «−» поле «+» с кратностью заказа (Alpine).
    <x-qty :product="$product" />                               — min_qty, qty_step, default_qty из товара
    <x-qty name="items[3][qty]" :value="$item->qty" :min="2" :step="2" :max="100" size="sm" />
    Допустимые значения: min, min + step, …; введённое вручную округляется вверх до допустимого,
    больше max — вниз. Кнопки на границе отключаются. После изменения кнопками поле шлёт change.
    label — подпись для экранного диктора (визуально скрыта); size: sm (таблицы, 40px) | md (44px) | lg (карточка товара, 48px).
    class — на обёртку, остальные атрибуты (form, data-*…) — на <input>.
--}}
@props([
    'name' => 'qty',
    'product' => null,
    'value' => null,
    'min' => null,
    'step' => null,
    'max' => null,
    'label' => 'Количество',
    'size' => 'md',
    'id' => null,
])

@php
    $min = max(1, (int) ($min ?? $product?->min_qty ?? 1));
    $step = max(1, (int) ($step ?? $product?->qty_step ?? 1));
    $max = $max !== null ? (int) $max : null;
    $key = trim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $id ??= 'f-'.str_replace('.', '-', $key);

    // Начальное значение — тоже допустимое
    $value = (int) old($key, $value ?? $product?->default_qty ?? $min);
    $value = $value <= $min ? $min : $min + (int) ceil(($value - $min) / $step) * $step;
    if ($max !== null && $max >= $min && $value > $max) {
        $value = $min + intdiv($max - $min, $step) * $step;
    }

    $button = [
        'flex shrink-0 items-center justify-center bg-field text-primary transition hover:bg-canvas',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary',
        'disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-field',
        match ($size) {
            'sm'    => 'h-10 w-9',
            'lg'    => 'h-12 w-touch',
            default => 'h-touch w-touch',
        },
    ];
@endphp

<div
    x-data="{
        value: @js($value), min: @js($min), step: @js($step), max: @js($max),
        normalize(v) {
            v = parseInt(v, 10);
            if (isNaN(v) || v <= this.min) return this.min;
            v = this.min + Math.ceil((v - this.min) / this.step) * this.step;
            if (this.max !== null && this.max >= this.min && v > this.max) {
                v = this.min + Math.floor((this.max - this.min) / this.step) * this.step;
            }
            return v;
        },
        set(v) {
            const next = this.normalize(v);
            const changed = next !== this.value;
            this.value = next;
            this.$refs.input.value = next;
            if (changed) this.$nextTick(() => this.$refs.input.dispatchEvent(new Event('change', { bubbles: true })));
        },
    }"
    {{ $attributes->only('class')->class(['inline-flex items-stretch overflow-hidden border border-line-strong bg-surface', $size === 'lg' ? 'rounded-panel' : 'rounded-control']) }}
    data-qty
>
    <button
        type="button"
        @class($button)
        @click="set(value - step)"
        :disabled="value <= min"
        @disabled($value <= $min)
        aria-label="Уменьшить количество"
        aria-controls="{{ $id }}"
    >@svg('heroicon-o-minus', 'h-4 w-4')</button>

    <label for="{{ $id }}" class="sr-only">{{ $label }}</label>
    <input
        id="{{ $id }}"
        x-ref="input"
        type="number"
        name="{{ $name }}"
        value="{{ $value }}"
        min="{{ $min }}"
        step="{{ $step }}"
        @if($max !== null) max="{{ $max }}" @endif
        inputmode="numeric"
        @change="value = normalize($event.target.value); $event.target.value = value"
        {{ $attributes->except('class')->class([
            'border-0 border-x border-line bg-transparent px-1 text-center font-semibold text-ink tabular-nums',
            'focus:border-line focus:ring-2 focus:ring-inset focus:ring-primary',
            '[appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none',
            match ($size) {
                'sm'    => 'w-14 py-1',
                'lg'    => 'w-16 py-2 text-base',
                default => 'w-14 py-2',
            },
        ]) }}
    >

    <button
        type="button"
        @class($button)
        @click="set(value + step)"
        :disabled="max !== null && max < value + step"
        @disabled($max !== null && $value + $step > $max)
        aria-label="Увеличить количество"
        aria-controls="{{ $id }}"
    >@svg('heroicon-o-plus', 'h-4 w-4')</button>
</div>
