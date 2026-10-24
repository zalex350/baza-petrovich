{{--
    Выпадающий список с подписью и ошибкой.
    <x-select name="status" label="Статус" :options="[value => label]" :value="$current" placeholder="Все" />
    Вместо :options можно передать <option> слотом (выбор — на стороне вызывающего).
    Выбранное значение: old(name, value). class — на обёртку, остальные атрибуты — на <select>.
--}}
@props([
    'name',
    'options' => null,
    'value' => null,
    'placeholder' => null,
    'label' => null,
    'hint' => null,
    'required' => false,
    'id' => null,
])

@php
    $key = trim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $id ??= 'f-'.str_replace('.', '-', $key);
    $invalid = isset($errors) && $errors->has($key);
    $selected = (string) old($key, $value);
    $describedBy = trim(($hint ? "$id-hint " : '').($invalid ? "$id-error" : ''));
@endphp

<x-field :id="$id" :label="$label" :hint="$hint" :error="$key" :required="$required" class="{{ $attributes->get('class') }}">
    <select
        id="{{ $id }}"
        name="{{ $name }}"
        @required($required)
        @if($invalid) aria-invalid="true" @endif
        @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->except('class')->class([
            'block w-full rounded-control pl-control-x py-control-y bg-surface text-ink disabled:bg-canvas disabled:text-ink-muted',
            'border-line-strong focus:border-primary focus:ring-primary' => ! $invalid,
            'border-tone-danger-border focus:border-danger focus:ring-danger' => $invalid,
        ]) }}
    >
        @if($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif

        @if($options !== null)
            @foreach($options as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected($selected === (string) $optionValue)>{{ $optionLabel }}</option>
            @endforeach
        @else
            {{ $slot }}
        @endif
    </select>
</x-field>
