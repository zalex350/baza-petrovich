{{--
    Пустое состояние списка.
    <x-empty-state title="Заказов нет" icon="inbox">
        Текст-пояснение.
        <x-slot:action><x-button href="/admin/orders">Сбросить фильтры</x-button></x-slot:action>
    </x-empty-state>
    icon — имя Heroicons (outline), без префикса: inbox, magnifying-glass, shopping-cart…
--}}
@props(['title', 'icon' => 'inbox'])

<div {{ $attributes->class('rounded-card border border-line bg-surface p-card text-center') }} data-empty-state>

    <x-dynamic-component :component="'heroicon-o-'.$icon" class="mx-auto h-10 w-10 text-ink-muted" aria-hidden="true" />

    <p class="mt-3 font-semibold text-ink">{{ $title }}</p>

    @if($slot->isNotEmpty())
        <div class="mt-1 text-sm text-ink-muted">{{ $slot }}</div>
    @endif

    @isset($action)
        <div class="mt-4 flex justify-center gap-3">{{ $action }}</div>
    @endisset

</div>
