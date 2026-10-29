{{--
    Слайдер баннеров на главной (админка «Баннеры»): автопрокрутка, стрелки, точки, пауза
    при наведении (resources/js/banner-slider.js). Мобильная картинка — до md, иначе десктопная.
    <x-banner-slider :banners="$banners" />   — пусто — ничего не выводится.
--}}
@props(['banners'])

@if($banners->isNotEmpty())
<section
    {{ $attributes->class('relative overflow-hidden rounded-card bg-media') }}
    x-data="bannerSlider({{ $banners->count() }})"
    @mouseenter="paused = true"
    @mouseleave="paused = false"
    @focusin="paused = true"
    @focusout="paused = false"
    aria-roledescription="carousel"
    aria-label="Баннеры"
    data-banner-slider
>
    <div class="flex transition-transform duration-500 ease-out motion-reduce:transition-none" :style="'transform: translateX(-' + (index * 100) + '%)'">
        @foreach($banners as $banner)
            <div class="w-full shrink-0" role="group" aria-roledescription="slide" aria-label="{{ $loop->iteration }} из {{ $loop->count }}" :aria-hidden="(index !== {{ $loop->index }}).toString()" data-banner>
                @if($banner->url)<a href="{{ $banner->url }}" class="block" :tabindex="index === {{ $loop->index }} ? 0 : -1">@endif
                    <picture>
                        @if($banner->image_mobile)
                            <source media="(max-width: 767px)" srcset="{{ $banner->mobileUrl() }}">
                        @endif
                        <img src="{{ $banner->desktopUrl() }}" alt="{{ $banner->title ?: 'Баннер '.$loop->iteration }}" class="block aspect-banner-mobile w-full object-cover md:aspect-banner" @unless($loop->first) loading="lazy" @endunless>
                    </picture>
                @if($banner->url)</a>@endif
            </div>
        @endforeach
    </div>

    @if($banners->count() > 1)
        <button type="button" @click="manual(index - 1)" class="absolute left-3 top-1/2 hidden h-touch w-touch -translate-y-1/2 items-center justify-center rounded-full bg-surface/90 text-primary shadow hover:bg-surface md:flex" aria-label="Предыдущий баннер" data-banner-prev>
            @svg('heroicon-o-chevron-left', 'h-5 w-5')
        </button>
        <button type="button" @click="manual(index + 1)" class="absolute right-3 top-1/2 hidden h-touch w-touch -translate-y-1/2 items-center justify-center rounded-full bg-surface/90 text-primary shadow hover:bg-surface md:flex" aria-label="Следующий баннер" data-banner-next>
            @svg('heroicon-o-chevron-right', 'h-5 w-5')
        </button>

        <div class="absolute inset-x-0 bottom-3 flex justify-center gap-2" data-banner-dots>
            @foreach($banners as $banner)
                <button
                    type="button"
                    @click="manual({{ $loop->index }})"
                    class="h-2.5 rounded-full transition-all"
                    :class="index === {{ $loop->index }} ? 'w-6 bg-surface' : 'w-2.5 bg-surface/60 hover:bg-surface/80'"
                    aria-label="Баннер {{ $loop->iteration }}"
                    :aria-current="(index === {{ $loop->index }}).toString()"
                ></button>
            @endforeach
        </div>
    @endif
</section>
@endif
