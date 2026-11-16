{{--
    Иконка категории (App\Services\CategoryIcons): inline-SVG, цвет — currentColor (задаётся цветом
    текста родителя или классом), размер — классом. Нет иконки — ничего не выводит.
    <x-category-icon :category="$category" class="h-6 w-6" />                     — модель Category
    <x-category-icon :icon="$node['icon']" :svg="$node['icon_svg']" class="h-5 w-5" />  — узел CatalogMenu
--}}
@props(['category' => null, 'icon' => null, 'svg' => null])

@php
    $icon = $category ? $category->icon : $icon;
    $svg = $category ? $category->icon_svg : $svg;
    $html = app(\App\Services\CategoryIcons::class)->render($icon, $svg, trim('shrink-0 '.($attributes->get('class') ?: 'h-6 w-6')));
@endphp

@if($html){{ $html }}@endif
