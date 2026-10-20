@extends('layouts.app')

@section('title', $product->display_name ?: $product->name)

@section('content')

@isset($preview)

    {{-- Предпросмотр из админки: страница как на сайте, с черновыми текстами --}}
    <div class="max-w-6xl mx-auto mb-6 bg-amber-50 border border-amber-200 rounded-2xl px-4 py-3 flex flex-wrap items-center gap-3 text-sm" data-preview-bar>

        <strong class="text-amber-800">Предпросмотр</strong>

        <span class="text-amber-700">
            @if(! $product->is_published)
                Товар ещё не опубликован — покупатели его не видят.
            @elseif($preview['draft'])
                Показан неопубликованный черновик текстов.
            @else
                Так товар выглядит на сайте.
            @endif
        </span>

        <span class="grow"></span>

        <a href="/admin/product/{{ $product->id }}" class="rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-slate-700 hover:bg-amber-100">
            ← К редактированию
        </a>

        @if(! $product->is_published || $preview['draft'])
            <form method="POST" action="/admin/product/{{ $product->id }}/publish">
                @csrf
                <button type="submit" class="rounded-lg bg-green-700 hover:bg-green-600 text-white px-3 py-1.5">
                    Опубликовать
                </button>
            </form>
        @endif

    </div>

@endisset

<div class="max-w-6xl mx-auto">

    <div class="flex flex-col md:flex-row gap-6 md:gap-10">

        <!-- Images -->
        <div class="flex-1 min-w-0">

            <div class="border border-slate-200 bg-white p-5 rounded-2xl">

                @include('partials.product_gallery')

            </div>

        </div>

        <!-- Product Info -->
        <div class="flex-1 min-w-0">

            <p class="mb-4">
                <a
                    href="/catalog/{{ $product->category->slug ?? '' }}"
                    class="underline"
                >
                    ← Назад в каталог
                </a>
            </p>

            <h1 class="text-3xl font-bold mb-4">
                {{ $product->display_name ?: $product->name }}
            </h1>

            <p class="mb-2">
                <strong>Артикул:</strong>
                {{ $product->sku }}
            </p>

            @if($product->brand)
                <p class="mb-2">
                    <strong>Производитель:</strong>
                    {{ $product->brand }}
                </p>
            @endif

            @auth

                @if($product->price === null)

                <p class="mb-6 text-2xl font-semibold text-slate-500" data-price-tbd>
                    Цена уточняется
                </p>

                @else

                <div class="mb-6 space-y-1">

                    <p class="text-slate-600">
                        Цена без НДС:
                        {{ number_format($product->price, 2) }} BYN
                    </p>

                    <p class="text-slate-600">
                        @if($product->vat_rate === null)
                            НДС: Без НДС
                        @else
                            НДС ({{ $product->vat_rate_label }}):
                            {{ number_format($product->vat_amount, 2) }} BYN
                        @endif
                    </p>

                    <h2 class="text-4xl font-semibold text-green-800">
                        Цена с НДС:
                        {{ number_format($product->price_with_vat, 2) }} BYN
                    </h2>

                </div>

                @endif

                <p class="mb-4">
                    В наличии: {{ $product->stock }}
                </p>

                @if(! auth()->user()->isClient())

                    {{-- BR-121: оформлять заказы могут только клиенты --}}

                @elseif($product->price === null)

                    {{-- Цена уточняется (показано выше) — заказать нельзя --}}

                @elseif($product->stock > 0 && $product->is_active)

                    <form
                        method="POST"
                        action="/order/add/{{ $product->id }}"
                        class="flex items-center gap-4"
                    >

                        @csrf

                        <input
                            type="number"
                            name="qty"
                            value="1"
                            min="1"
                            max="{{ $product->stock }}"
                            class="border rounded px-2 py-1 w-20"
                        >

                        <button
                            type="submit"
                            class="w-full bg-slate-800 hover:bg-slate-700 text-white py-4 rounded-xl transition font-semibold"
                        >
                            Добавить в заказ
                        </button>

                    </form>

                @else

                    <div class="bg-red-100 border border-red-400 p-4 rounded mt-4">
                        Нет в наличии
                    </div>

                @endif

            @else

                <p class="mb-4">
                    Цена доступна после входа
                </p>

                <a
                    href="/login"
                    class="underline"
                >
                    Войти
                </a>

            @endauth

        </div>

    </div>

    @php
        // BR-082: ошибка в характеристиках не роняет карточку — блок просто не показывается
        $specRows = rescue(fn () => $product->specRows(), collect());
        $specText = trim((string) $product->specifications);
        $hasSpecs = $specRows->isNotEmpty() || $specText !== '';
    @endphp

    <!-- Tabs -->
    <div class="mt-10">

        <div class="flex gap-4 mb-6">

            <button
                onclick="showTab('desc')"
                class="border px-4 py-2 rounded"
            >
                Описание
            </button>

            {{-- §4.9.4: нет характеристик — нет и вкладки --}}
            @if($hasSpecs)
                <button
                    onclick="showTab('chars')"
                    class="border px-4 py-2 rounded"
                >
                    Характеристики
                </button>
            @endif

            <button
                onclick="showTab('kit')"
                class="border px-4 py-2 rounded"
            >
                Комплектация
            </button>

        </div>

        <!-- Description -->
        <div id="desc" class="tab-content">

            <p>
                {{ $product->description ?? 'Описание отсутствует' }}
            </p>

        </div>

        <!-- Specifications: «Характеристика — Значение» в заданном порядке, пустые не выводятся (BR-062) -->
        @if($hasSpecs)

            <div
                id="chars"
                class="tab-content hidden"
                data-specs
            >

                @if($specRows->isNotEmpty())

                    <table class="w-full border-collapse text-sm sm:text-base">
                        <tbody class="divide-y divide-slate-200 border-y border-slate-200">
                            @foreach($specRows as $row)
                                <tr data-spec-row>
                                    <th scope="row" class="py-2 pr-4 text-left font-normal text-slate-500 align-top w-1/2">{{ $row['name'] }}</th>
                                    <td class="py-2 text-slate-900">{{ $row['value'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                @endif

                @if($specText !== '')
                    <p @class(['text-slate-700', 'mt-4' => $specRows->isNotEmpty()])>
                        {!! nl2br(e($specText)) !!}
                    </p>
                @endif

            </div>

        @endif

        <!-- Package -->
        <div
            id="kit"
            class="tab-content hidden"
        >

            <p>
                {!! nl2br(e($product->package_contents ?? 'Не указано')) !!}
            </p>

        </div>

    </div>

</div>

<script>

function showTab(id)
{
    document.querySelectorAll('.tab-content').forEach(function(tab) {
        tab.classList.add('hidden');
    });

    document.getElementById(id).classList.remove('hidden');
}

</script>

@endsection