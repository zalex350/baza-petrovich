{{--
    Поле выбора файла с подписью, подсказкой, ошибкой и превью текущей картинки.
    <x-file name="image_desktop" label="Картинка (десктоп)" accept="image/jpeg,image/png,image/webp" :preview="$banner->desktopUrl()" />
    preview — URL текущей картинки (null — без превью). class — на обёртку, остальные атрибуты — на <input>.
--}}
@props([
    'name',
    'label' => null,
    'hint' => null,
    'required' => false,
    'preview' => null,
    'id' => null,
])

@php
    $key = trim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $id ??= 'f-'.str_replace('.', '-', $key);
    $invalid = isset($errors) && $errors->has($key);
    $describedBy = trim(($hint ? "$id-hint " : '').($invalid ? "$id-error" : ''));
@endphp

<x-field :id="$id" :label="$label" :hint="$hint" :error="$key" :required="$required" class="{{ $attributes->get('class') }}">
    @if($preview)
        <img src="{{ $preview }}" alt="" class="mb-2 max-h-40 rounded-control border border-line bg-media object-contain" data-file-preview>
    @endif

    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="file"
        @required($required)
        @if($invalid) aria-invalid="true" @endif
        @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->except('class')->class([
            'block w-full text-sm text-ink file:mr-3 file:rounded-control file:border file:border-line-control file:bg-surface file:px-btn-x-sm file:py-btn-y-sm file:font-medium file:text-ink hover:file:bg-canvas',
            'text-tone-danger-text' => $invalid,
        ]) }}
    >
</x-field>
