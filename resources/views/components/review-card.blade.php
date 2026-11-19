{{--
    Отзыв: оценка (звёзды), текст, автор и дата; скан письменного отзыва — миниатюра, по клику открывается.
    <x-review-card :review="$review" />
--}}
@props(['review'])

<article {{ $attributes->class('flex h-full flex-col gap-3 rounded-card border border-line bg-surface p-4') }} data-review>
    @if($review->rating)
        <div class="flex gap-0.5 text-accent" aria-label="Оценка {{ $review->rating }} из 5">
            @for($s = 1; $s <= 5; $s++)
                @svg($s <= $review->rating ? 'heroicon-s-star' : 'heroicon-o-star', 'h-5 w-5')
            @endfor
        </div>
    @endif
    @if($review->imageUrl())
        <a href="{{ $review->imageUrl() }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-control bg-media" data-review-scan>
            <img src="{{ $review->imageUrl() }}" alt="Отзыв{{ $review->author ? ': '.$review->author : '' }}" loading="lazy" class="aspect-[3/4] w-full object-cover object-top transition hover:scale-105">
        </a>
    @endif
    @if($review->text)
        <p class="whitespace-pre-line text-ink">{{ $review->text }}</p>
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
