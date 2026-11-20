{{--
    Отзыв: оценка (звёзды), источник (Google / Яндекс), текст, фото объекта, автор и дата;
    скан письменного отзыва — вертикальная миниатюра, по клику открывается.
    <x-review-card :review="$review" />
--}}
@props(['review'])

<article {{ $attributes->class('flex h-full flex-col gap-3 rounded-card border border-line bg-surface p-4') }} data-review @if($review->source) data-review-source="{{ $review->source }}" @endif>
    @if($review->rating || $review->isExternal())
        <div class="flex items-center justify-between gap-2">
            @if($review->rating)
                <div class="flex gap-0.5 text-accent" aria-label="Оценка {{ $review->rating }} из 5">
                    @for($s = 1; $s <= 5; $s++)
                        @svg($s <= $review->rating ? 'heroicon-s-star' : 'heroicon-o-star', 'h-5 w-5')
                    @endfor
                </div>
            @endif
            @if($review->isExternal())
                <span class="rounded-tag border border-line px-2 py-0.5 text-meta text-ink-muted">Отзыв в {{ \App\Models\Review::SOURCES[$review->source] }}</span>
            @endif
        </div>
    @endif
    @if($review->text)
        <p class="whitespace-pre-line text-ink">{{ $review->text }}</p>
    @endif
    @if($review->imageUrl())
        <a href="{{ $review->imageUrl() }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-control bg-media" @if($review->isScan()) data-review-scan @else data-review-photo @endif>
            <img src="{{ $review->imageUrl() }}" alt="{{ $review->isScan() ? 'Отзыв' : 'Объект' }}{{ $review->author ? ': '.$review->author : '' }}" loading="lazy"
                 @class(['w-full transition hover:scale-105', 'aspect-[3/4] object-cover object-top' => $review->isScan(), 'aspect-[4/3] object-cover' => ! $review->isScan()])>
        </a>
    @endif
    @if($review->author || $review->reviewed_on || $review->work)
        <footer class="mt-auto text-sm text-ink-muted">
            {{ collect([$review->author, $review->reviewed_on ? date_ru($review->reviewed_on) : null])->filter()->implode(', ') }}
            @if($review->work)
                <a href="{{ $review->work->url() }}" class="block text-link hover:underline">{{ $review->work->title }}</a>
            @endif
        </footer>
    @endif
</article>
