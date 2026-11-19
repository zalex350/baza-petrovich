{{--
    Галерея фото с просмотром на весь экран (Alpine): крупное фото, ниже миниатюры; клик — просмотр,
    стрелки / Esc / свайп. <x-photo-gallery :urls="[...]" alt="…" />
--}}
@props(['urls' => [], 'alt' => ''])

<div x-data="{ i: 0, open: false, n: {{ count($urls) }}, urls: @js(array_values($urls)),
        next() { this.i = (this.i + 1) % this.n }, prev() { this.i = (this.i - 1 + this.n) % this.n } }"
     @keydown.window.escape="open = false" @keydown.window.arrow-right="open && next()" @keydown.window.arrow-left="open && prev()"
     {{ $attributes->class('flex flex-col gap-3') }} data-photo-gallery>
    <button type="button" class="block aspect-[4/3] w-full overflow-hidden rounded-card bg-media" @click="open = true" aria-label="Открыть фото">
        <img :src="urls[i]" src="{{ $urls[0] ?? '' }}" alt="{{ $alt }}" class="h-full w-full object-cover">
    </button>
    @if(count($urls) > 1)
        <div class="grid grid-cols-4 gap-2 sm:grid-cols-6">
            @foreach($urls as $k => $url)
                <button type="button" class="aspect-square overflow-hidden rounded-control bg-media ring-primary" :class="i === {{ $k }} && 'ring-2'" @click="i = {{ $k }}" aria-label="Фото {{ $k + 1 }}">
                    <img src="{{ $url }}" alt="" loading="lazy" class="h-full w-full object-cover">
                </button>
            @endforeach
        </div>
    @endif

    <div x-show="open" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center bg-black/90 p-4" @click.self="open = false" role="dialog" aria-modal="true">
        <img :src="urls[i]" alt="{{ $alt }}" class="max-h-full max-w-full object-contain">
        <button type="button" class="absolute right-4 top-4 rounded-full bg-white/10 p-2 text-white hover:bg-white/20" @click="open = false" aria-label="Закрыть">@svg('heroicon-o-x-mark', 'h-6 w-6')</button>
        <template x-if="n > 1">
            <div>
                <button type="button" class="absolute left-4 top-1/2 -translate-y-1/2 rounded-full bg-white/10 p-3 text-white hover:bg-white/20" @click="prev()" aria-label="Предыдущее">@svg('heroicon-o-chevron-left', 'h-6 w-6')</button>
                <button type="button" class="absolute right-4 top-1/2 -translate-y-1/2 rounded-full bg-white/10 p-3 text-white hover:bg-white/20" @click="next()" aria-label="Следующее">@svg('heroicon-o-chevron-right', 'h-6 w-6')</button>
            </div>
        </template>
    </div>
</div>
