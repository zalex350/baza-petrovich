{{--
    Блок «Изображения» на странице товара в админке (SRS §10.4.8). Применяется сразу.
    Без JS — формы и кнопки «вверх/вниз»; с JS — очередь до загрузки (image-queue.js)
    и перетаскивание (admin-images.js).
    Данные: $product (с images), $form — id формы товара (поле очереди и кнопка «Загрузить сейчас»).
--}}
@php
    $images = $product->images;
    $maxMb = (int) round(config('shop.images.max_kb') / 1024);
    $accept = implode(',', config('shop.images.mimetypes'));
@endphp

<div
    id="images"
    class="bg-white rounded-2xl border border-slate-200 p-6 mb-6 scroll-mt-6"
    x-data="adminImages('/admin/product/{{ $product->id }}/images/reorder')"
    data-csrf="{{ csrf_token() }}"
>

    <h2 class="text-lg font-semibold mb-1">
        Фото товара
        <span class="text-sm font-normal text-slate-400 ml-2">данные сайта · применяются сразу</span>
    </h2>

    <p class="text-sm text-slate-500 mb-4">
        JPG, PNG или WebP, до {{ $maxMb }} МБ на файл. Основное фото показывается в каталоге, поиске и корзине.
    </p>

    {{-- Все ошибки фото — здесь: загрузка (images, images.N), замена (image); вверху страницы — только общий alert --}}
    @php
        $imageErrors = collect($errors->getMessages())
            ->filter(fn ($messages, $key) => in_array($key, ['images', 'image'], true) || str_starts_with($key, 'images.'))
            ->flatten();
    @endphp

    @if($imageErrors->isNotEmpty())

        <div class="bg-red-50 border border-red-200 rounded-xl p-3 mb-4" data-images-errors>
            <ul class="list-disc list-inside text-red-700 text-sm space-y-1">
                @foreach($imageErrors as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>

    @endif

    {{-- Очередь — часть формы товара: фото из неё загружаются и при сохранении товара,
         и сразу кнопкой «Загрузить» (та же форма, другой адрес) --}}
    <div class="mb-6">

        @include('partials.image_queue', ['form' => $form])

        <button
            type="submit"
            form="{{ $form }}"
            formaction="/admin/product/{{ $product->id }}/images"
            formnovalidate
            class="mt-4 bg-slate-800 hover:bg-slate-700 text-white px-5 py-2 rounded-xl transition"
        >
            Загрузить сейчас
        </button>

    </div>

    @if($images->isEmpty())

        <p class="text-slate-500">
            Фото ещё нет — на сайте показывается заглушка.
        </p>

    @else

        <p class="text-sm text-slate-500 mb-2">
            Порядок меняется перетаскиванием за <span aria-hidden="true">⠿</span> или кнопками ↑ ↓.
            <span x-show="status" x-text="status" :class="statusError ? 'text-red-600' : 'text-green-700'" class="ml-2" role="status"></span>
        </p>

        <ul x-ref="list" class="divide-y divide-slate-200 border border-slate-200 rounded-xl" data-images-list>

            @foreach($images as $image)

                <li class="flex flex-wrap items-center gap-4 p-3 bg-white" data-image-id="{{ $image->id }}">

                    <span class="cursor-move select-none text-slate-400 text-xl px-1" data-drag-handle title="Перетащите, чтобы изменить порядок" aria-hidden="true">⠿</span>

                    <span class="w-6 text-slate-500 text-sm text-right" data-position>{{ $loop->iteration }}</span>

                    @php([$w, $h] = $image->dimensions('thumb'))

                    <img
                        src="{{ $image->url('thumb') }}"
                        alt="{{ $image->original_name }}"
                        width="{{ $w }}"
                        height="{{ $h }}"
                        loading="lazy"
                        class="w-20 h-20 object-contain bg-slate-50 rounded-lg border border-slate-200"
                        onerror="this.onerror=null;this.src='{{ \App\Models\ProductImage::placeholderUrl() }}'"
                    >

                    <div class="grow min-w-40 text-sm">

                        <div class="font-medium text-slate-800 break-all">{{ $image->original_name ?? basename($image->path) }}</div>

                        <div class="text-slate-500">
                            @if($image->width)
                                {{ $image->width }}×{{ $image->height }}
                            @else
                                перенесено из прежнего поля, без размеров
                            @endif
                        </div>

                        @if($image->is_main)
                            <span class="inline-flex mt-1 rounded-full bg-green-100 text-green-700 px-2 py-0.5 text-xs" data-main-badge>Основное</span>
                        @endif

                        {{-- Заглушка «нет фото» (PlaceholderImages): на сайте не показывается; снять — удалить или заменить файл --}}
                        @if($image->is_placeholder)
                            <x-badge tone="warning" class="mt-1" data-placeholder-badge title="Фото-заглушка: на сайте не показывается, товар считается без фото">Заглушка — не показывается</x-badge>
                        @endif

                    </div>

                    <div class="flex flex-wrap items-center gap-2 text-sm">

                        @unless($image->is_main)

                            <form method="POST" action="/admin/product/{{ $product->id }}/images/{{ $image->id }}/main">
                                @csrf
                                <button type="submit" class="rounded-lg border border-slate-300 px-3 py-1.5 hover:bg-slate-50">
                                    Сделать основным
                                </button>
                            </form>

                        @endunless

                        <form method="POST" action="/admin/product/{{ $product->id }}/images/{{ $image->id }}/move">
                            @csrf
                            <input type="hidden" name="direction" value="up">
                            <button type="submit" class="rounded-lg border border-slate-300 px-2.5 py-1.5 hover:bg-slate-50 disabled:opacity-40" @disabled($loop->first) title="Вверх" aria-label="Переместить вверх">↑</button>
                        </form>

                        <form method="POST" action="/admin/product/{{ $product->id }}/images/{{ $image->id }}/move">
                            @csrf
                            <input type="hidden" name="direction" value="down">
                            <button type="submit" class="rounded-lg border border-slate-300 px-2.5 py-1.5 hover:bg-slate-50 disabled:opacity-40" @disabled($loop->last) title="Вниз" aria-label="Переместить вниз">↓</button>
                        </form>

                        <details class="relative">

                            <summary class="cursor-pointer rounded-lg border border-slate-300 px-3 py-1.5 hover:bg-slate-50 list-none">
                                Заменить
                            </summary>

                            <form
                                method="POST"
                                action="/admin/product/{{ $product->id }}/images/{{ $image->id }}/replace"
                                enctype="multipart/form-data"
                                class="absolute right-0 z-10 mt-2 w-72 bg-white border border-slate-200 rounded-xl shadow-lg p-3 space-y-2"
                            >
                                @csrf
                                <input type="file" name="image" accept="{{ $accept }}" required class="text-xs w-full">
                                <p class="text-xs text-slate-500">Позиция и признак «основное» сохранятся.</p>
                                <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-white px-3 py-1.5 rounded-lg">
                                    Заменить файл
                                </button>
                            </form>

                        </details>

                        <form
                            method="POST"
                            action="/admin/product/{{ $product->id }}/images/{{ $image->id }}/delete"
                            onsubmit="return confirm('Удалить фото «{{ addslashes($image->original_name ?? basename($image->path)) }}»? Файлы всех размеров будут удалены.')"
                        >
                            @csrf
                            <button type="submit" class="rounded-lg border border-red-300 text-red-700 px-3 py-1.5 hover:bg-red-50">
                                Удалить
                            </button>
                        </form>

                    </div>

                </li>

            @endforeach

        </ul>

    @endif

</div>
