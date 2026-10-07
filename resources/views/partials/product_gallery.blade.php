{{--
    Галерея карточки товара (SRS §4.7): основное фото medium, миниатюры (при двух и более фото),
    увеличенный просмотр — product-gallery.js. Без JS: основное фото и ссылки на большие версии.
    Данные: $product (с images).
--}}
@php
    $gallery = $product->gallery();
    $galleryData = $gallery->map->toGalleryItem()->all();
    $alt = $product->display_name ?: $product->name;
    $placeholder = \App\Models\ProductImage::placeholderUrl();
    $first = $gallery->first();
@endphp

@if(! $first)

    @include('partials.product_image', [
        'image' => null,
        'alt'   => $alt,
        'size'  => 'medium',
        'eager' => true,
        'class' => 'w-full h-auto rounded-xl object-contain',
    ])

@else

    @php([$mw, $mh] = $first->dimensions('medium'))

    <div
        x-data="productGallery(@js($galleryData), @js($placeholder))"
        @keydown.window="keydown($event)"
        data-gallery
    >

        {{-- Основное фото: клик — увеличенный просмотр --}}
        <a
            href="{{ $first->url('large') }}"
            class="block cursor-zoom-in"
            @click.prevent="show()"
            aria-label="Увеличить фото"
        >
            <img
                src="{{ $first->url('medium') }}"
                :src="image.medium"
                :width="image.mw"
                :height="image.mh"
                alt="{{ $alt }}"
                width="{{ $mw }}"
                height="{{ $mh }}"
                decoding="async"
                class="w-full h-auto max-h-[520px] rounded-xl object-contain"
                onerror="this.onerror=null;this.src='{{ $placeholder }}'"
                data-gallery-main
            >
        </a>

        {{-- Миниатюры — только если фото больше одного --}}
        @if($gallery->count() > 1)

            <ul class="flex gap-2 mt-4 flex-wrap" data-gallery-thumbs>

                @foreach($gallery as $index => $image)

                    @php([$tw, $th] = $image->dimensions('thumb'))

                    <li>
                        <a
                            href="{{ $image->url('large') }}"
                            @click.prevent="select({{ $index }})"
                            class="block rounded-lg border-2 p-0.5 transition"
                            :class="current === {{ $index }} ? 'border-slate-800' : 'border-transparent hover:border-slate-300'"
                            aria-label="Фото {{ $index + 1 }} из {{ $gallery->count() }}"
                        >
                            <img
                                src="{{ $image->url('thumb') }}"
                                alt="{{ $alt }}"
                                width="{{ $tw }}"
                                height="{{ $th }}"
                                loading="lazy"
                                decoding="async"
                                class="w-16 h-16 object-contain rounded-md bg-white"
                                onerror="this.onerror=null;this.src='{{ $placeholder }}'"
                            >
                        </a>
                    </li>

                @endforeach

            </ul>

        @endif

        {{-- Увеличенный просмотр --}}
        <div
            x-show="open"
            x-cloak
            x-transition.opacity
            class="fixed inset-0 z-50 bg-black/90 flex items-center justify-center"
            role="dialog"
            aria-modal="true"
            aria-label="Фото товара"
            @click.self="close()"
            @touchstart.passive="touchStart($event)"
            @touchend="touchEnd($event)"
            data-lightbox
        >

            {{-- Большая версия грузится только при открытии --}}
            <template x-if="open">
                <img
                    :src="image.large"
                    :width="image.lw"
                    :height="image.lh"
                    alt="{{ $alt }}"
                    class="max-w-[92vw] max-h-[85vh] w-auto h-auto object-contain select-none"
                    x-on:error="fallback($event)"
                >
            </template>

            <button
                type="button"
                x-ref="close"
                @click="close()"
                class="absolute top-3 right-3 w-11 h-11 rounded-full bg-white/10 hover:bg-white/20 text-white text-2xl"
                aria-label="Закрыть"
            >×</button>

            @if($gallery->count() > 1)

                <button
                    type="button"
                    @click="prev()"
                    class="absolute left-2 sm:left-4 top-1/2 -translate-y-1/2 w-11 h-11 rounded-full bg-white/10 hover:bg-white/20 text-white text-2xl"
                    aria-label="Предыдущее фото"
                >‹</button>

                <button
                    type="button"
                    @click="next()"
                    class="absolute right-2 sm:right-4 top-1/2 -translate-y-1/2 w-11 h-11 rounded-full bg-white/10 hover:bg-white/20 text-white text-2xl"
                    aria-label="Следующее фото"
                >›</button>

                <div class="absolute bottom-4 left-1/2 -translate-x-1/2 text-white/90 text-sm" x-text="counter" aria-live="polite"></div>

            @endif

        </div>

    </div>

@endif
