{{--
    Ссылки на юридические документы под согласием в формах (заказ, заявка, квиз).
    <x-legal-note />                 — «Политика обработки персональных данных»
    <x-legal-note :oferta="true" />  — ещё «договор-оферта» и «обмен и возврат» (оформление заказа)
    Неопубликованная страница не показывается; нет ни одной — ничего не выводится.
--}}
@props(['oferta' => false])

@php
    $slugs = $oferta ? array_keys(\App\Models\Page::LEGAL) : ['privacy'];
    $pages = \App\Models\Page::published()->whereIn('slug', $slugs)->get()->keyBy('slug');
    $links = collect($slugs)->filter(fn ($slug) => $pages->has($slug))
        ->map(fn ($slug) => '<a href="'.e($pages[$slug]->url()).'" target="_blank" class="text-link hover:underline">'.e(['privacy' => 'политикой обработки персональных данных', 'oferta' => 'договором-офертой', 'vozvrat' => 'условиями обмена и возврата'][$slug]).'</a>');
@endphp

@if($links->isNotEmpty())
    <p {{ $attributes->class('text-sm text-ink-muted') }} data-legal-note>
        {{ $oferta ? 'Оформляя заказ, вы соглашаетесь с' : 'Подробнее — в' }}
        {!! $oferta ? $links->implode(', ') : str_replace('политикой', 'политике', $links->first()) !!}.
    </p>
@endif
