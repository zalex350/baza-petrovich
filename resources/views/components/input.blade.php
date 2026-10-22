{{--
    Поле ввода с подписью и ошибкой валидации.
    <x-input name="q" label="Поиск" :value="$filters['q']" class="grow" />
    name — также ключ ошибки и old(): items[0][qty] → items.0.qty. Значение: old(name, value), у password — пустое.
    class — на обёртку; остальные атрибуты (placeholder, autocomplete, min…) — на <input>.
--}}
@props([
    'name',
    'type' => 'text',
    'value' => null,
    'label' => null,
    'hint' => null,
    'required' => false,
    'id' => null,
])

@php
    $key = trim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $id ??= 'f-'.str_replace('.', '-', $key);
    $invalid = isset($errors) && $errors->has($key);
    $value = $type === 'password' ? null : old($key, $value);
    $describedBy = trim(($hint ? "$id-hint " : '').($invalid ? "$id-error" : ''));
@endphp

<x-field :id="$id" :label="$label" :hint="$hint" :error="$key" :required="$required" class="{{ $attributes->get('class') }}">
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if($value !== null) value="{{ $value }}" @endif
        @required($required)
        @if($invalid) aria-invalid="true" @endif
        @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->except('class')->class([
            'block w-full rounded-control px-control-x py-control-y shadow-sm disabled:bg-slate-50 disabled:text-slate-500',
            'border-slate-300 focus:border-primary focus:ring-primary' => ! $invalid,
            'border-tone-danger-border focus:border-danger focus:ring-danger' => $invalid,
        ]) }}
    >
</x-field>
