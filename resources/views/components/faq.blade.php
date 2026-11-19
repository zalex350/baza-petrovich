{{--
    Вопросы и ответы (раскрываются по клику, без JS — <details>).
    <x-faq :items="[['q' => 'Вопрос', 'a' => 'Ответ'], …]" title="Частые вопросы" />
    Разметка FAQPage для поиска — seo()->faq($items) в контроллере (App\Support\Seo).
    Пустой список — ничего не выводится. Ответ — текст (переносы строк сохраняются).
--}}
@props(['items' => [], 'title' => 'Частые вопросы'])

@php($items = collect($items)->filter(fn ($item) => filled($item['q'] ?? null) && filled($item['a'] ?? null))->values())

@if($items->isNotEmpty())
    <section {{ $attributes->class('rounded-card border border-line bg-surface p-card') }} data-faq>
        @if($title)
            <h2 class="mb-3 text-xl font-semibold text-ink">{{ $title }}</h2>
        @endif
        <div class="divide-y divide-line">
            @foreach($items as $item)
                <details class="group py-3" data-faq-item>
                    <summary class="flex cursor-pointer list-none items-start justify-between gap-4 font-medium text-ink hover:text-primary">
                        <span>{{ $item['q'] }}</span>
                        <span class="mt-0.5 text-ink-muted transition-transform group-open:rotate-45" aria-hidden="true">+</span>
                    </summary>
                    <p class="mt-2 whitespace-pre-line text-ink-muted">{{ $item['a'] }}</p>
                </details>
            @endforeach
        </div>
    </section>
@endif
