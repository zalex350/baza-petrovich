@extends('layouts.app')

@section('title', $product->display_name ?: $product->name)

@section('content')

<div class="max-w-6xl mx-auto">

    <div class="flex gap-10">

        <!-- Images -->
        <div class="flex-1">

            <div class="border p-5 rounded">

                @if($product->images && $product->images->count())

                    <div>

                        <img
                            id="main-image"
                            src="/storage/products/{{ $product->images->first()->path }}"
                            class="w-full rounded-xl object-contain max-h-[520px]"
                        >

                        <div class="flex gap-2 mt-4 flex-wrap">

                            @foreach($product->images as $img)

                                <img
                                    src="/storage/products/{{ $img->path }}"
                                    width="80"
                                    class="cursor-pointer border rounded"
                                    onclick="document.getElementById('main-image').src=this.src"
                                >

                            @endforeach

                        </div>

                    </div>

                @else

                    <img
                        src="https://via.placeholder.com/400"
                        class="rounded"
                    >

                @endif

            </div>

        </div>

        <!-- Product Info -->
        <div class="flex-1">

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

            @auth

                <div class="mb-6 space-y-1">

                    <p class="text-slate-600">
                        Цена без НДС:
                        {{ number_format($product->price, 2) }} BYN
                    </p>

                    <p class="text-slate-600">
                        НДС ({{ rtrim(rtrim(number_format($product->vat_rate, 2), '0'), '.') }}%):
                        {{ number_format($product->vat_amount, 2) }} BYN
                    </p>

                    <h2 class="text-4xl font-semibold text-green-800">
                        Цена с НДС:
                        {{ number_format($product->price_with_vat, 2) }} BYN
                    </h2>

                </div>

                <p class="mb-4">
                    В наличии: {{ $product->stock }}
                </p>

                @if(! auth()->user()->isClient())

                    {{-- BR-121: оформлять заказы могут только клиенты --}}

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

    <!-- Tabs -->
    <div class="mt-10">

        <div class="flex gap-4 mb-6">

            <button
                onclick="showTab('desc')"
                class="border px-4 py-2 rounded"
            >
                Описание
            </button>

            <button
                onclick="showTab('chars')"
                class="border px-4 py-2 rounded"
            >
                Характеристики
            </button>

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

        <!-- Specifications -->
        <div
            id="chars"
            class="tab-content hidden"
        >

            <table class="table-auto border-collapse border w-full">

                <tr>
                    <td class="border p-2">Артикул</td>
                    <td class="border p-2">{{ $product->sku }}</td>
                </tr>

                <tr>
                    <td class="border p-2">Бренд</td>
                    <td class="border p-2">{{ $product->brand }}</td>
                </tr>

                <tr>
                    <td class="border p-2">Категория</td>
                    <td class="border p-2">
                        {{ $product->category->name ?? '-' }}
                    </td>
                </tr>

                <tr>
                    <td class="border p-2">Характеристики</td>
                    <td class="border p-2">
                        {!! nl2br(e($product->specifications)) !!}
                    </td>
                </tr>

            </table>

        </div>

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