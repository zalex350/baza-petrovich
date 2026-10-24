{{--
    Флеш-сообщения сессии (success / error / warning) и ошибки валидации.
    Стоит в layouts/app, layouts/admin, layouts/guest — по одному разу; на экранах не подключать.
    [data-flash] всегда в DOM и содержит только алерты: cart-actions.js показывает в нём сообщения поверх страницы.

    validation — что показать при ошибках валидации:
      list    — список всех ошибок (формы, ещё не переведённые на x-input/x-select);
      summary — один алерт «Проверьте выделенные поля», сами ошибки — у полей (x-input, x-select, x-textarea);
      none    — ничего (auth-страницы: ошибки у полей Breeze).
    Экран на ките включает summary: @section('flash-validation', 'summary').
--}}
@props(['validation' => 'list'])

@php
    $messages = array_filter([
        'success' => session('success'),
        'error'   => session('error'),
        'warning' => session('warning'),
    ]);
    $mode = match ($validation) {
        true, 'list' => 'list',
        'summary'    => 'summary',
        default      => 'none',
    };
    $invalid = $mode !== 'none' && isset($errors) && $errors->any();
@endphp

<div data-flash {{ $attributes->class(['space-y-3', 'mb-4' => $messages || $invalid]) }}>
    @foreach($messages as $type => $message)
        <x-alert :type="$type">{{ $message }}</x-alert>
    @endforeach

    @if($invalid && $mode === 'summary')
        <x-alert type="error" data-validation-summary>Проверьте выделенные поля</x-alert>
    @elseif($invalid)
        <x-alert type="error">
            <ul class="list-disc pl-5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif
</div>
