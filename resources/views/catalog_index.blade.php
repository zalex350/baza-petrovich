{{--
    Каталог: разделы плитками (x-category-tile — фото типичного товара, без фото — иконка), под плиткой —
    подразделы с иконками и числом товаров (первые 8, остальные — «ещё N»).
    Данные: $sections — CatalogMenu::tree() (видимые разделы с опубликованными товарами, порядок — CategoryOrder:
    по количеству товаров, закреплённые первыми / вручную / по алфавиту), $tiles — CategoryTiles::forTree().
--}}
@extends('layouts.app')

@section('title', 'Каталог продукции')

@php(seo()->description('Каталог металлобазы «'.\App\Support\Seo::siteName().'»: профнастил, металлочерепица, сетка, заборы, ворота, металлопрокат и комплектующие. Цены на сайте, доставка по Беларуси, самовывоз в Минске и Бресте.'))

@section('content')

<h1 class="mb-8 text-3xl font-bold tracking-tight">Каталог продукции</h1>

@php($brief = 8)

<div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" data-catalog-sections>
    @foreach($sections as $section)
        <x-category-tile :node="$section" :tile="$tiles[$section['id']] ?? null">
            @if(! empty($section['children']))
                <ul class="space-y-1.5">
                    @foreach(array_slice($section['children'], 0, $brief) as $child)
                        <li>
                            <a href="{{ $child['url'] }}" class="group flex items-center gap-2 text-sm text-ink hover:text-link" data-catalog-child>
                                <x-category-icon :icon="$child['icon'] ?? null" :svg="$child['icon_svg'] ?? null" class="h-6 w-6" />
                                <span class="min-w-0 flex-1">{{ $child['name'] }}</span>
                                <span class="text-meta text-ink-muted tabular-nums">{{ $child['count'] }}</span>
                            </a>
                        </li>
                    @endforeach
                    @if(count($section['children']) > $brief)
                        <li>
                            <a href="{{ $section['url'] }}" class="text-sm text-link hover:underline">ещё {{ count($section['children']) - $brief }}</a>
                        </li>
                    @endif
                </ul>
            @endif
        </x-category-tile>
    @endforeach
</div>

@endsection
