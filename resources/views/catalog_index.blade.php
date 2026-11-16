{{--
    Каталог: разделы с подразделами. Данные: $sections — CatalogMenu::tree() (видимые разделы с опубликованными
    товарами, порядок — CategoryOrder: по количеству товаров, закреплённые первыми / вручную / по алфавиту).
--}}
@extends('layouts.app')

@section('title', 'Каталог продукции')

@section('content')

<div class="mb-10">

    <h1 class="text-4xl font-bold text-slate-800">
        Каталог продукции
    </h1>

</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8" data-catalog-sections>

    @foreach($sections as $parent)

        <div data-catalog-section="{{ $parent['id'] }}">

            <h2 class="text-lg font-bold text-slate-800 mb-3 flex items-center gap-2">
                <x-category-icon :icon="$parent['icon'] ?? null" :svg="$parent['icon_svg'] ?? null" class="h-6 w-6 text-link" />
                <a href="{{ $parent['url'] }}" class="hover:text-blue-600">{{ $parent['name'] }}</a>
            </h2>

            @foreach($parent['children'] as $child)

                <a
                    href="{{ $child['url'] }}"
                    class="group flex items-start gap-2 text-sm text-slate-600 hover:text-blue-600 py-1"
                    data-catalog-child
                >
                    <x-category-icon :icon="$child['icon'] ?? null" :svg="$child['icon_svg'] ?? null" class="mt-px h-4 w-4 text-slate-400 group-hover:text-blue-600" />
                    <span>
                        {{ $child['name'] }}
                        <span class="text-slate-400">
                            ({{ $child['count'] }})
                        </span>
                    </span>
                </a>

            @endforeach

        </div>

    @endforeach

</div>

@endsection
