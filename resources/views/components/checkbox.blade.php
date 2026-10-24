{{--
    Галочка с подписью, подсказкой и ошибкой валидации.
    <x-checkbox name="notify_new_order" label="Новый заказ" hint="Письмо при каждом заказе" :checked="$user->notify_new_order" />
    Снятая галочка не отправляется браузером — поэтому по умолчанию перед ней скрытое поле
    со значением unchecked (0): сервер получает и «включено», и «выключено».
    :unchecked="false" — без скрытого поля (группы галочек name="ids[]"); null не подходит:
    Blade подставляет вместо него значение по умолчанию.
    Отмечена: old(name, checked) — после ошибки валидации сохраняется выбор пользователя.
    class — на обёртку, остальные атрибуты — на <input>; form="…" — и на скрытое поле
    (галочка вне своей <form>, как в форме товара).
--}}
@props([
    'name',
    'label',
    'hint' => null,
    'checked' => false,
    'value' => '1',
    'unchecked' => '0',
    'id' => null,
])

@php
    $key = trim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $id ??= 'f-'.str_replace('.', '-', $key);
    $invalid = isset($errors) && $errors->has($key);
    // old() у пары hidden+checkbox: '1' или '0'; без отправки формы — значение из модели
    $isChecked = $unchecked !== false
        ? (string) old($key, $checked ? $value : $unchecked) === (string) $value
        : (bool) old($key, $checked);
    $describedBy = trim(($hint ? "$id-hint " : '').($invalid ? "$id-error" : ''));
@endphp

<div {{ $attributes->only('class')->class('min-w-0') }}>
    <label for="{{ $id }}" class="flex items-start gap-3 cursor-pointer">
        @if($unchecked !== false)
            <input {{ $attributes->only(['form', 'disabled'])->merge(['type' => 'hidden', 'name' => $name, 'value' => $unchecked]) }}>
        @endif

        <input
            type="checkbox"
            id="{{ $id }}"
            name="{{ $name }}"
            value="{{ $value }}"
            @checked($isChecked)
            @if($invalid) aria-invalid="true" @endif
            @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->except('class')->class([
                'mt-0.5 h-5 w-5 shrink-0 rounded border-line-strong text-primary focus:ring-primary',
                'border-tone-danger-border' => $invalid,
            ]) }}
        >

        <span>
            <span class="block text-ink">{{ $label }}</span>
            @if($hint)
                <span id="{{ $id }}-hint" class="block text-sm text-ink-muted">{{ $hint }}</span>
            @endif
        </span>
    </label>

    @if($invalid)
        <p id="{{ $id }}-error" class="mt-1 text-sm text-tone-danger-text" data-field-error>{{ $errors->first($key) }}</p>
    @endif
</div>
