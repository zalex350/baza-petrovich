{{--
    Флеш-сообщения сессии (success / error / warning) и список ошибок валидации.
    Стоит в layouts/app, layouts/admin, layouts/guest — по одному разу; на экранах не подключать.
    [data-flash] всегда в DOM и содержит только алерты: cart-add.js переносит его поверх страницы.
    :validation="false" — не выводить общий список ошибок (auth-страницы показывают их у полей).
--}}
@props(['validation' => true])

@php
    $messages = array_filter([
        'success' => session('success'),
        'error'   => session('error'),
        'warning' => session('warning'),
    ]);
    $invalid = $validation && isset($errors) && $errors->any();
@endphp

<div data-flash {{ $attributes->class(['space-y-3', 'mb-4' => $messages || $invalid]) }}>
    @foreach($messages as $type => $message)
        <x-alert :type="$type">{{ $message }}</x-alert>
    @endforeach

    @if($invalid)
        <x-alert type="error">
            <ul class="list-disc pl-5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif
</div>
