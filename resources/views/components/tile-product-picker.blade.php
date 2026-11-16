{{--
    Фото на плитке раздела (админка, форма категории верхнего уровня; CategoryTiles):
    «Не выбран» (по режиму «Фото на плитках разделов»: иконка или самый продаваемый товар раздела с фото
    и в наличии), выбор из популярных товаров раздела (карточки с фото) или артикул / SKU любого товара
    с фото. Своя картинка категории важнее.
    <x-tile-product-picker :category="$category" :candidates="$tile['candidates']" :chosen="$tile['chosen']" :url="$tile['url']" />
    Поля формы: tile_sent=1, tile_product (id или пусто), tile_sku.
--}}
@props(['category', 'candidates', 'chosen' => null, 'url' => null])

@php
    $auto = \App\Services\CategoryTiles::mode() === \App\Services\CategoryTiles::AUTO;
    $value = (string) old('tile_product', $category->tile_product_id);
    $options = collect($candidates);
    if ($chosen && ! $options->contains('id', $chosen->id)) {
        $options->prepend($chosen);
    }
@endphp

<fieldset {{ $attributes->class('space-y-3') }} data-tile-picker>
    <legend class="mb-1 font-medium text-ink">Фото на плитке раздела</legend>
    <p class="text-sm text-ink-muted">
        Главная и «Каталог». Своя картинка категории (блок «Картинка» ниже) важнее; без неё — выбранный товар;
        не выбран — {{ $auto ? 'самый продаваемый товар раздела с фото и в наличии, нет такого — иконка' : 'иконка' }}
        (настройка «Фото на плитках разделов» в списке категорий).
    </p>

    <input type="hidden" name="tile_sent" value="1">

    <div class="flex items-center gap-3">
        <span class="flex h-20 w-28 items-center justify-center overflow-hidden rounded-control border border-line bg-media" data-tile-current>
            @if($url)
                <img src="{{ $url }}" alt="" class="h-full w-full object-contain p-1">
            @else
                <x-category-icon :category="$category" class="h-12 w-12" />
            @endif
        </span>
        <span class="text-sm text-ink-muted">
            Сейчас на плитке:
            {{ $category->image ? 'своя картинка' : ($chosen ? 'выбранный товар' : ($url ? 'самый продаваемый товар' : 'иконка')) }}
        </span>
    </div>

    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4" role="radiogroup" aria-label="Товар для плитки">
        <label class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-control border border-line p-2 text-center text-sm has-[:checked]:border-primary has-[:checked]:bg-tone-info-bg">
            <input type="radio" name="tile_product" value="" class="sr-only" @checked($value === '')>
            <span class="font-medium text-ink">{{ $auto ? 'Автоматически' : 'Не выбран' }}</span>
            <span class="text-meta text-ink-muted">{{ $auto ? 'самый продаваемый, с фото и в наличии' : 'иконка раздела' }}</span>
        </label>
        @foreach($options as $product)
            <label class="flex cursor-pointer flex-col gap-1 rounded-control border border-line p-2 text-sm has-[:checked]:border-primary has-[:checked]:bg-tone-info-bg" data-tile-option="{{ $product->id }}">
                <input type="radio" name="tile_product" value="{{ $product->id }}" class="sr-only" @checked($value === (string) $product->id)>
                <span class="flex aspect-square items-center justify-center overflow-hidden rounded-control bg-media">
                    <img src="{{ $product->mainImage?->url('thumb') ?? \App\Models\ProductImage::placeholderUrl() }}" alt="" loading="lazy" class="h-full w-full object-contain p-1">
                </span>
                <span class="line-clamp-2 text-meta text-ink">{{ $product->title() }}</span>
                <span class="text-meta text-ink-muted">{{ $product->displayArticle() }}</span>
            </label>
        @endforeach
    </div>

    <x-input name="tile_sku" label="Или артикул / SKU товара" :value="old('tile_sku')" placeholder="L4755-2" hint="Любой товар витрины с фото; заполненное поле важнее выбора выше" class="max-w-sm" />
</fieldset>
