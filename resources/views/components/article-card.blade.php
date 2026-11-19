{{--
    Карточка статьи: обложка, дата, заголовок, анонс.
    <x-article-card :article="$page" />   — Page type = article
--}}
@props(['article'])

<a href="{{ $article->url() }}" {{ $attributes->class('group flex h-full flex-col overflow-hidden rounded-card border border-line bg-surface hover:border-primary') }} data-article-card>
    <span class="block aspect-[16/9] overflow-hidden bg-media">
        @if($image = $article->imageUrl())
            <img src="{{ $image }}" alt="" loading="lazy" class="h-full w-full object-cover transition group-hover:scale-105">
        @endif
    </span>
    <span class="flex flex-1 flex-col gap-2 p-4">
        @if($article->published_at)
            <time datetime="{{ $article->published_at->toDateString() }}" class="text-meta text-ink-muted">{{ date_ru($article->published_at) }}</time>
        @endif
        <span class="font-semibold leading-snug text-ink group-hover:text-link">{{ $article->title }}</span>
        <span class="text-sm text-ink-muted">{{ $article->summary(160) }}</span>
    </span>
</a>
