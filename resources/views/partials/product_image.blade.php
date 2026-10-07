{{--
    Основное фото товара (BR-051) или заглушка — при отсутствии фото и при ошибке загрузки файла.
    Данные: $image (ProductImage|null), $alt, $size — thumb|medium|large, $class,
    $eager — без lazy (первый экран карточки).
--}}
@php
    $size ??= 'thumb';
    $class ??= '';
    $eager ??= false;
    $placeholder = \App\Models\ProductImage::placeholderUrl();
    [$width, $height] = $image ? $image->dimensions($size) : [config("shop.images.sizes.{$size}"), config("shop.images.sizes.{$size}")];
@endphp

<img
    src="{{ $image ? $image->url($size) : $placeholder }}"
    alt="{{ $alt }}"
    width="{{ $width }}"
    height="{{ $height }}"
    @unless($eager) loading="lazy" @endunless
    decoding="async"
    class="{{ $class }}"
    onerror="this.onerror=null;this.src='{{ $placeholder }}'"
    @unless($image) data-placeholder @endunless
>
