{{--
    Обёртка поля формы: подпись, подсказка, ошибка валидации. Используется x-input, x-select, x-textarea;
    напрямую — для нестандартных полей.
    Параметры: id, label, hint, error (ключ в $errors, точечная запись), required (рисует *).
    class — на обёртку (ширина поля в раскладке формы).
--}}
@props(['id', 'label' => null, 'hint' => null, 'error' => null, 'required' => false])

<div {{ $attributes->class('min-w-0') }}>

    @if($label)
        <label for="{{ $id }}" class="block text-sm font-medium text-ink-muted mb-1">
            {{ $label }}@if($required) <span class="text-tone-danger-text" aria-hidden="true">*</span>@endif
        </label>
    @endif

    {{ $slot }}

    @if($hint)
        <p id="{{ $id }}-hint" class="mt-1 text-sm text-ink-muted">{{ $hint }}</p>
    @endif

    @if($error && isset($errors) && $errors->has($error))
        <p id="{{ $id }}-error" class="mt-1 text-sm text-tone-danger-text" data-field-error>
            {{ $errors->first($error) }}
        </p>
    @endif

</div>
