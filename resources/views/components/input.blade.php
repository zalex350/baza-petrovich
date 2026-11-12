{{--
    Поле ввода с подписью и ошибкой валидации.
    <x-input name="q" label="Поиск" :value="$filters['q']" class="grow" />
    name — также ключ ошибки и old(): items[0][qty] → items.0.qty. Значение: old(name, value), у password — пустое.
    warning — жёлтая справочная подсказка под полем (x-field), не ошибка.
    class — на обёртку; остальные атрибуты (placeholder, autocomplete, min…) — на <input>.
--}}
@props([
    'name',
    'type' => 'text',
    'value' => null,
    'label' => null,
    'hint' => null,
    'warning' => null,
    'required' => false,
    'id' => null,
])

@php
    $key = trim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $id ??= 'f-'.str_replace('.', '-', $key);
    $invalid = isset($errors) && $errors->has($key);
    $value = $type === 'password' ? null : old($key, $value);
    $describedBy = trim(($hint ? "$id-hint " : '').($warning ? "$id-warning " : '').($invalid ? "$id-error" : ''));
@endphp

<x-field :id="$id" :label="$label" :hint="$hint" :warning="$warning" :error="$key" :required="$required" class="{{ $attributes->get('class') }}">
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if($value !== null) value="{{ $value }}" @endif
        @required($required)
        @if($invalid) aria-invalid="true" @endif
        @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->except('class')->class([
            'block w-full rounded-control bg-surface px-control-x py-control-y text-ink placeholder:text-ink-muted disabled:bg-canvas disabled:text-ink-muted',
            'border-line-strong focus:border-primary focus:ring-primary' => ! $invalid,
            'border-tone-danger-border focus:border-danger focus:ring-danger' => $invalid,
        ]) }}
    >
</x-field>
