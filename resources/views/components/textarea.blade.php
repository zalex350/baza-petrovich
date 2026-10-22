{{--
    Многострочное поле с подписью и ошибкой.
    <x-textarea name="cancel_reason" label="Причина" rows="3" required maxlength="1000" />
    Значение: old(name, value) или слот. class — на обёртку, остальные атрибуты — на <textarea>.
--}}
@props([
    'name',
    'value' => null,
    'label' => null,
    'hint' => null,
    'required' => false,
    'rows' => 3,
    'id' => null,
])

@php
    $key = trim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $id ??= 'f-'.str_replace('.', '-', $key);
    $invalid = isset($errors) && $errors->has($key);
    $value = old($key, $value ?? ($slot->isEmpty() ? null : trim($slot)));
    $describedBy = trim(($hint ? "$id-hint " : '').($invalid ? "$id-error" : ''));
@endphp

<x-field :id="$id" :label="$label" :hint="$hint" :error="$key" :required="$required" class="{{ $attributes->get('class') }}">
    <textarea
        id="{{ $id }}"
        name="{{ $name }}"
        rows="{{ $rows }}"
        @required($required)
        @if($invalid) aria-invalid="true" @endif
        @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->except('class')->class([
            'block w-full rounded-control px-control-x py-control-y shadow-sm disabled:bg-slate-50 disabled:text-slate-500',
            'border-slate-300 focus:border-primary focus:ring-primary' => ! $invalid,
            'border-tone-danger-border focus:border-danger focus:ring-danger' => $invalid,
        ]) }}
    >{{ $value }}</textarea>
</x-field>
