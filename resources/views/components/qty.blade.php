{{--
    Количество: «−» поле «+» с кратностью заказа (Alpine).
    <x-qty :product="$product" />                               — min_qty, qty_step, default_qty из товара
    <x-qty name="items[3][qty]" :value="$item->qty" :min="2" :step="2" :max="100" size="sm" />
    Допустимые значения: min, min + step, …; кнопки на границе отключаются.
    Ручной ввод применяется по Enter и уходу из поля: округляется вверх до допустимого с подсказкой
    на 3 с («Округлено до 5 — мин. 2, кратно 3»); больше max (остаток) — не применяется, подсказка
    «Доступно N шт.» до следующего изменения. Значение больше max с сервера (остаток уменьшился)
    показывается как есть — не урезается.
    События change на поле: ручной ввод — обычное; кнопки — detail.source = 'button' (формы
    корзины отправляют их после паузы); не применённый ввод дальше поля не всплывает.
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
        value: @js($value), min: @js($min), step: @js($step), max: @js($max), hint: '', timer: null,
        // Вверх до допустимого (min, min + step, …); не число — null
        round(v) {
            v = parseInt(v, 10);
            if (isNaN(v)) return null;
            return v <= this.min ? this.min : this.min + Math.ceil((v - this.min) / this.step) * this.step;
        },
        rule() {
            const parts = [];
            if (this.min > 1 || this.step === 1) parts.push('мин. ' + this.min);
            if (this.step > 1) parts.push('кратно ' + this.step);
            return parts.join(', ');
        },
        say(text, ms = 0) {
            clearTimeout(this.timer);
            this.hint = text;
            if (ms) this.timer = setTimeout(() => this.hint = '', ms);
        },
        // Кнопки «−/+»
        set(v) {
            if (v < this.min || (this.max !== null && v > this.max && v > this.value)) return;
            this.say('');
            this.value = v;
            this.$refs.input.value = v;
            this.$nextTick(() => this.$refs.input.dispatchEvent(new CustomEvent('change', { bubbles: true, detail: { source: 'button' } })));
        },
        // Ручной ввод (Enter, уход из поля); false — значение не применено
        apply(event) {
            const input = this.$refs.input;
            const typed = parseInt(input.value, 10);
            const next = this.round(input.value);
            const blocked = next === null || (this.max !== null && next > this.max && next !== this.value);

            if (blocked) {
                input.value = this.value;
                if (next !== null) this.say('Доступно ' + this.max + ' шт.');
                event.preventDefault();
                event.stopImmediatePropagation();
                return false;
            }

            input.value = next;
            // change после Enter приходит с тем же значением — подсказку не трогать
            if (next !== typed) this.say('Округлено до ' + next + ' — ' + this.rule(), 3000);
            else if (next !== this.value) this.say('');
            this.value = next;
            return true;
        },
        // change: от кнопок и с сервера — только синхронизация, от ручного ввода — проверка
        changed(event) {
            if (event.detail?.source || event.detail?.fromServer) {
                this.value = parseInt(this.$refs.input.value, 10) || this.value;
                if (event.detail.fromServer) this.say('');
                return;
            }
            this.apply(event);
        },
    }"
    {{ $attributes->only('class')->class(['inline-flex flex-col gap-1']) }}
    data-qty
>
<div @class(['inline-flex items-stretch self-start overflow-hidden border border-line-strong bg-surface', $size === 'lg' ? 'rounded-panel' : 'rounded-control'])>
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
        @change="changed($event)"
        @keydown.enter="apply($event)"
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
    {{-- Подсказка: округление (3 с) или «Доступно N шт.» --}}
    <p class="max-w-56 text-meta text-tone-warning-text" x-show="hint" x-text="hint" x-cloak role="status" data-qty-hint></p>
</div>
