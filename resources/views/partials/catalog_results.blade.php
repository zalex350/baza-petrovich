{{--
    Список товаров категории: количество, выбранные фильтры (чипы), сортировка, товары, пагинация.
    Данные: $listing, $products, $chips, $baseUrl.
--}}
<div data-catalog-results aria-live="polite">

    <div class="flex flex-wrap items-center gap-3 mb-4">

        <p class="text-slate-500 grow" data-found>
            Найдено товаров: {{ $products->total() }}
        </p>

        {{-- Мобильный: открыть панель фильтров --}}
        <button
            type="button"
            @click="open = true"
            class="lg:hidden inline-flex items-center gap-2 rounded-xl border border-slate-300 px-4 py-2 text-sm"
            aria-controls="catalog-filters-panel"
            data-filters-toggle
        >
            @svg('heroicon-o-adjustments-horizontal', 'w-5 h-5')
            Фильтры{{ $listing->activeCount() ? ' (' . $listing->activeCount() . ')' : '' }}
        </button>

        <label class="flex items-center gap-2 text-sm">
            <span class="text-slate-500">Сортировка:</span>
            <select name="sort" form="catalog-form" class="border rounded-lg px-2 py-1.5" @change="changed($event)" data-sort>
                @foreach($listing->sorts() as $value => $label)
                    <option value="{{ $value === 'default' ? '' : $value }}" @selected($listing->sort === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>

    </div>

    @if($chips)

        <ul class="flex flex-wrap items-center gap-2 mb-6" data-chips>
            @foreach($chips as $chip)
                <li>
                    <a href="{{ $chip['url'] }}" class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 hover:bg-slate-200 px-3 py-1 text-sm" data-catalog-link data-chip>
                        {{ $chip['label'] }}
                        <span aria-hidden="true" class="text-slate-500">×</span>
                        <span class="sr-only">— убрать</span>
                    </a>
                </li>
            @endforeach
            <li>
                <a href="{{ $listing->resetUrl($baseUrl) }}" class="text-sm text-slate-500 hover:text-slate-900 underline" data-catalog-link data-reset>
                    Сбросить всё
                </a>
            </li>
        </ul>

    @endif

    @if($products->isEmpty())

        <div class="bg-white rounded-2xl border border-slate-200 p-8 text-center text-slate-500" data-empty>
            Товаров не найдено.
            @if($chips)
                <a href="{{ $listing->resetUrl($baseUrl) }}" class="underline" data-catalog-link>Сбросить фильтры</a>
            @endif
        </div>

    @else

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
            @foreach($products as $product)
                @include('partials.catalog_product_card', ['product' => $product])
            @endforeach
        </div>

    @endif

    <div class="mt-8" data-pagination>
        {{ $products->links() }}
    </div>

</div>
