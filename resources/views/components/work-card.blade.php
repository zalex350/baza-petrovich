{{--
    Карточка объекта «Наши работы»: обложка (первое фото), метка, название, место, число фото.
    <x-work-card :work="$work" />   — work с cover и images_count (withCount)
--}}
@props(['work'])

<a href="{{ $work->url() }}" {{ $attributes->class('group flex h-full flex-col overflow-hidden rounded-card border border-line bg-surface hover:border-primary') }} data-work-card>
    <span class="relative block aspect-[4/3] overflow-hidden bg-media">
        @if($work->cover)
            <img src="{{ $work->cover->url() }}" alt="{{ $work->title }}" loading="lazy" class="h-full w-full object-cover transition group-hover:scale-105">
        @endif
        @if($work->tag)
            <span class="absolute left-3 top-3 rounded-tag bg-accent px-2 py-0.5 text-meta font-semibold text-accent-fg">{{ $work->tag }}</span>
        @endif
        @if(($work->images_count ?? 0) > 1)
            <span class="absolute bottom-3 right-3 inline-flex items-center gap-1 rounded-tag bg-black/60 px-2 py-0.5 text-meta text-white">@svg('heroicon-o-photo', 'h-4 w-4') {{ $work->images_count }}</span>
        @endif
    </span>
    <span class="flex flex-col gap-1 p-4">
        <span class="font-semibold leading-snug text-ink group-hover:text-link">{{ $work->title }}</span>
        @if($work->location)
            <span class="text-sm text-ink-muted">{{ $work->location }}</span>
        @endif
    </span>
</a>
