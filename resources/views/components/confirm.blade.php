{{--
    Действие с подтверждением в модальном окне (Alpine) вместо onclick="return confirm(...)".
    Кнопка открывает окно; в окне — своя форма (POST + @csrf), поэтому x-confirm можно ставить
    и внутри другой формы: окно переносится в <body> (x-teleport).

    <x-confirm action="/admin/product/{{ $product->id }}/delete" label="Удалить"
               title="Удалить товар?" confirm-label="Удалить">
        Товар «{{ $product->name }}» будет удалён без возможности восстановления.
        <x-slot:fields>…доп. поля формы, например x-textarea причины…</x-slot:fields>
    </x-confirm>

    label — текст кнопки-триггера; variant — danger | primary (кнопка подтверждения и триггер);
    trigger-variant / trigger-size — вид триггера, если нужен другой (например link, sm);
    method — POST | PUT | PATCH | DELETE (не-POST — через @method);
    open — показать окно сразу (после ошибки валидации полей из слота fields: :open="$errors->has('reason')").
    Закрытие: «Отмена», Esc, клик по фону. Фокус — на поле с ошибкой, иначе на первом поле
    из fields, иначе на кнопке подтверждения; после закрытия — на триггере.
    Фокус удерживается в окне (x-trap, @alpinejs/focus): Tab / Shift+Tab — по кругу внутри,
    остальная страница на время окна недоступна скринридерам (.inert) и не прокручивается (.noscroll).
--}}
@props([
    'action',
    'label',
    'title',
    'confirmLabel' => 'Подтвердить',
    'cancelLabel' => 'Отмена',
    'variant' => 'danger',
    'triggerVariant' => null,
    'triggerSize' => 'md',
    'method' => 'POST',
    'open' => false,
])

@php
    $id = 'confirm-'.\Illuminate\Support\Str::random(8);
    $method = strtoupper($method);
@endphp

<div
    x-data="{
        open: @js((bool) $open),
        show() { this.open = true },
        hide() { this.open = false; this.$nextTick(() => this.$refs.trigger.focus()) },
    }"
    {{ $attributes->class('inline-block') }}
    data-confirm
>
    <x-button
        :variant="$triggerVariant ?? $variant"
        :size="$triggerSize"
        type="button"
        x-ref="trigger"
        x-on:click="show()"
        aria-haspopup="dialog"
        aria-controls="{{ $id }}"
    >{{ $label }}</x-button>

    <template x-teleport="body">
        <div
            id="{{ $id }}"
            x-show="open"
            x-cloak
            x-init="
                const focusFirst = () => $nextTick(() => (
                    $el.querySelector('[aria-invalid=true]')
                    ?? $el.querySelector('[data-confirm-fields] :is(input, select, textarea)')
                    ?? $el.querySelector('[data-confirm-submit]')
                ).focus());
                $watch('open', value => value && focusFirst());
                open && focusFirst();
            "
            x-on:keydown.escape.window="open && hide()"
            x-trap.inert.noscroll.noautofocus="open"
            class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center"
            role="dialog"
            aria-modal="true"
            aria-labelledby="{{ $id }}-title"
            aria-describedby="{{ $id }}-text"
        >
            <div
                class="fixed inset-0 bg-slate-900/50"
                x-show="open"
                x-transition.opacity
                x-on:click="hide()"
                aria-hidden="true"
            ></div>

            <form
                method="POST"
                action="{{ $action }}"
                x-show="open"
                x-transition
                class="relative w-full max-w-md rounded-card bg-white p-card text-left shadow-xl"
            >
                @csrf
                @if($method !== 'POST')
                    @method($method)
                @endif

                <h2 id="{{ $id }}-title" class="text-lg font-semibold text-slate-800">{{ $title }}</h2>

                <div id="{{ $id }}-text" class="mt-2 text-slate-600">{{ $slot }}</div>

                @isset($fields)
                    <div class="mt-4 space-y-3" data-confirm-fields>{{ $fields }}</div>
                @endisset

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <x-button variant="secondary" type="button" x-on:click="hide()">{{ $cancelLabel }}</x-button>
                    <x-button :variant="$variant" data-confirm-submit>{{ $confirmLabel }}</x-button>
                </div>
            </form>
        </div>
    </template>
</div>
