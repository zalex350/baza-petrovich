{{--
    Баннер о cookie внизу экрана (resources/js/analytics.js показывает его, пока посетитель не выбрал).
    <x-cookie-banner mode="notice" />   — уведомление: «Понятно» (счётчики уже работают), «Отказаться»
    <x-cookie-banner mode="required" /> — согласие: «Принять» включает cookie аналитики и рекламы, «Отклонить»
    Выбор — cookie cookie_consent (granted | denied) на год. Ссылка на политику — страница privacy, если опубликована.
--}}
@props(['mode' => 'notice'])

@php($policy = \App\Models\Page::published()->where('slug', 'privacy')->first())

<div hidden data-cookie-banner="{{ $mode }}" role="region" aria-label="Cookie"
     class="fixed inset-x-0 bottom-0 z-50 border-t border-line bg-surface shadow-lg">
    <div class="mx-auto flex max-w-page flex-col gap-3 px-4 py-3 text-sm text-ink sm:flex-row sm:items-center lg:px-6">
        <p class="flex-1 text-ink-muted">
            @if($mode === 'required')
                Мы используем cookie для аналитики и рекламы, чтобы сайт был удобнее. Разрешите их — или откажитесь, сайт будет работать.
            @else
                Сайт использует cookie: они помогают понять, что удобно покупателям, и показывать полезную рекламу.
            @endif
            @if($policy)
                <a href="{{ $policy->url() }}" class="text-link hover:underline">Подробнее</a>
            @endif
        </p>
        <div class="flex gap-2">
            <x-button size="sm" type="button" data-cookie-accept>{{ $mode === 'required' ? 'Принять' : 'Понятно' }}</x-button>
            <x-button size="sm" variant="secondary" type="button" data-cookie-decline>{{ $mode === 'required' ? 'Отклонить' : 'Отказаться' }}</x-button>
        </div>
    </div>
</div>
