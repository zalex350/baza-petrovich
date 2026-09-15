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

                <h2 class="text-lg font-bold text-slate-800 mb-3">
                    {{ $parent->name }}
                </h2>

                @foreach($parent->children as $child)

                    <a
                        href="{{ route('catalog.show', $child->slug) }}"
                        class="block text-sm text-slate-600 hover:text-blue-600 py-1"
                    >
                        {{ $child->name }}
                        <span class="text-slate-400">
                            ({{ $child->products_count }})
                        </span>
                    </a>

                @endforeach

            </div>

        @endif

    @endforeach

</div>

@endsection