{{--
    Страница ошибки в макете витрины (403, 404, 419): x-empty-state, ссылки «На главную» и «В каталог».
    @extends('errors.layout', ['code' => 404, 'icon' => 'magnifying-glass', 'heading' => '…', 'text' => '…'])
    back — ещё ссылка «Вернуться назад» (419: форма устарела). 500 — отдельный шаблон без шапки
    (шапка обращается к БД, а ошибка может быть в ней).
--}}
@extends('layouts.app')

@section('title', $heading.' — '.config('shop.name'))

@section('content')
    <x-empty-state :title="$heading" :icon="$icon" class="mx-auto my-8 max-w-xl" data-error-page="{{ $code }}">
        <p class="text-meta uppercase tracking-wide">Ошибка {{ $code }}</p>
        <p class="mt-1">{{ $text }}</p>

        <x-slot:action>
            @if($back ?? false)
                <x-button variant="secondary" href="{{ url()->previous() }}">Вернуться назад</x-button>
            @endif
            <x-button href="/">На главную</x-button>
            <x-button variant="secondary" href="/catalog">В каталог</x-button>
        </x-slot:action>
    </x-empty-state>
@endsection
