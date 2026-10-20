{{-- Карточка товара в списке категории. Данные: $product (с mainImage) --}}
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-xl transition duration-300 overflow-hidden flex flex-col" data-product-card>

    <a href="{{ route('product.show', $product->id) }}" data-product-link>

        <div class="aspect-square bg-slate-100 flex items-center justify-center">

            @include('partials.product_image', [
                'image' => $product->mainImage,
                'alt'   => $product->display_name ?: $product->name,
                'class' => 'w-full h-full object-contain p-6',
            ])

        </div>

    </a>

    <div class="p-5 flex flex-col flex-grow">

        @if($product->brand)
            <div class="text-xs uppercase tracking-widest text-slate-400 mb-2">
                {{ $product->brand }}
            </div>
        @endif

        <h2 class="text-lg font-semibold leading-6 min-h-[56px]">
            <a href="{{ route('product.show', $product->id) }}" class="hover:text-blue-700" data-product-link>
                {{ $product->display_name ?: $product->name }}
            </a>
        </h2>

        @if($product->sku)
            <div class="text-sm text-slate-400 mt-2">
                Артикул: {{ $product->sku }}
            </div>
        @endif

        <div class="mt-5">

            @auth

                @if($product->price === null)

                    <div class="text-lg font-semibold text-slate-500" data-price-tbd>
                        Цена уточняется
                    </div>

                @else

                    <div class="text-3xl font-bold text-slate-800">

                        {{ number_format($product->price, 2) }} BYN

                        <span class="text-sm font-normal text-slate-500">
                            @if($product->vat_rate === null)
                                (без НДС)
                            @else
                                (с НДС: {{ number_format($product->price_with_vat, 2) }} BYN)
                            @endif
                        </span>

                    </div>

                @endif

            @else

                <div class="text-slate-500">
                    Цена доступна после входа
                </div>

            @endauth

        </div>

        <div class="mt-3">
            @include('partials.stock_badge', ['product' => $product])
        </div>

        <div class="mt-auto pt-6">

            <a
                href="{{ route('product.show', $product->id) }}"
                class="block w-full rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-center py-3 transition"
                data-product-link
            >
                Подробнее
            </a>

            @include('partials.product_buy', ['product' => $product])

        </div>

    </div>

</div>
