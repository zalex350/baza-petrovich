{{--
    Флеш-сообщения сессии (success / error / warning) и список ошибок валидации.
    Замена partials/flash — экраны переводятся постепенно; [data-flash] нужен cart-add.js.
    :validation="false" — не выводить общий список ошибок, если они показаны у полей (x-input и др.).
--}}
@props(['validation' => true])

<div data-flash {{ $attributes->class('space-y-3') }}>
    @foreach(['success' => 'success', 'error' => 'error', 'warning' => 'warning'] as $key => $type)
        @if(session($key))
            <x-alert :type="$type">{{ session($key) }}</x-alert>
        @endif
    @endforeach

    @if($validation && isset($errors) && $errors->any())
        <x-alert type="error">
            <ul class="list-disc pl-5">
                @foreach($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif
</div>
