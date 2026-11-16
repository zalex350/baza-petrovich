@extends('layouts.app')

@section('title', 'Каталог продукции')

@section('content')

<div class="mb-10">

    <h1 class="text-4xl font-bold text-slate-800">
        Каталог продукции
    </h1>

</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">

    @foreach($parents as $parent)

        @if($parent->children->count())

            <div>

                <h2 class="text-lg font-bold text-slate-800 mb-3 flex items-center gap-2">
                    <x-category-icon :category="$parent" class="h-6 w-6 text-link" />
                    <span>{{ $parent->name }}</span>
                </h2>

                @foreach($parent->children as $child)

                    <a
                        href="{{ route('catalog.show', $child->slug) }}"
                        class="group flex items-start gap-2 text-sm text-slate-600 hover:text-blue-600 py-1"
                    >
                        <x-category-icon :category="$child" class="mt-px h-4 w-4 text-slate-400 group-hover:text-blue-600" />
                        <span>
                            {{ $child->name }}
                            <span class="text-slate-400">
                                ({{ $child->products_count }})
                            </span>
                        </span>
                    </a>

                @endforeach

            </div>

        @endif

    @endforeach

</div>

@endsection