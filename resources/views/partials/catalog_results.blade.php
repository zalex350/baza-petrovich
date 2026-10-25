{{--
    Список товаров категории (макет docs/design/Catalog): выбранные фильтры (чипы с ×) и сортировка,
    строки товаров (x-product-row) в одной карточке, пагинация.
    Данные: $listing, $products, $chips, $baseUrl, $cartItems.
--}}
<div class="flex flex-col gap-3" data-catalog-results aria-live="polite">

    <div class="flex flex-wrap items-center justify-between gap-2.5">

        {{-- Мобильный: открыть панель фильтров --}}
        <x-button
            variant="secondary"
            type="button"
            x-on:click="open = true"
            class="lg:hidden"
            aria-controls="catalog-filters-panel"
            data-filters-toggle
        >
            @svg('heroicon-o-adjustments-horizontal', 'w-5 h-5')
            Фильтры{{ $listing->activeCount() ? ' (' . $listing->activeCount() . ')' : '' }}
        </x-button>

        @if($chips)
            <ul class="hidden flex-wrap items-center gap-2 lg:flex" data-chips>
                @foreach($chips as $chip)
                    <li>
                        <a href="{{ $chip['url'] }}" class="inline-flex items-center gap-1.5 rounded-full bg-chip py-1 pl-3 pr-2 text-sm text-primary hover:bg-chip/70" data-catalog-link data-chip>
                            {{ $chip['label'] }}
                            <span aria-hidden="true" class="flex h-6 w-6 items-center justify-center text-base leading-none">×</span>
                            <span class="sr-only">— убрать</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="ml-auto flex items-center gap-2">
            <label for="f-sort" class="text-sm text-ink-muted">Сортировка</label>
            <x-select
                name="sort"
                form="catalog-form"
                :options="collect($listing->sorts())->mapWithKeys(fn ($label, $value) => [$value === 'default' ? '' : $value => $label])->all()"
                :value="$listing->sort === 'default' ? '' : $listing->sort"
                class="text-sm"
                x-on:change="changed($event)"
                data-sort
            />
        </div>

    </div>

    {{-- На телефоне чипы — отдельной строкой под кнопкой «Фильтры» --}}
    @if($chips)
        <ul class="flex flex-wrap items-center gap-2 lg:hidden" data-chips-mobile>
            @foreach($chips as $chip)
                <li>
                    <a href="{{ $chip['url'] }}" class="inline-flex items-center gap-1.5 rounded-full bg-chip py-1 pl-3 pr-2 text-sm text-primary" data-catalog-link>
                        {{ $chip['label'] }}
                        <span aria-hidden="true" class="flex h-6 w-6 items-center justify-center text-base leading-none">×</span>
                        <span class="sr-only">— убрать</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    @if($products->isEmpty())

        <x-empty-state title="Товаров не найдено" icon="magnifying-glass" data-empty>
            @if($chips)
                Попробуйте убрать часть фильтров.
                <x-slot:action>
                    <x-button variant="secondary" :href="$listing->resetUrl($baseUrl)" data-catalog-link data-reset>Сбросить фильтры</x-button>
                </x-slot:action>
            @else
                В этом разделе пока нет товаров.
            @endif
        </x-empty-state>

    @else

        <div class="divide-y divide-line-soft overflow-hidden rounded-card border border-line bg-surface" data-product-list>
            @foreach($products as $product)
                <x-product-row :product="$product" :in-cart="$cartItems[$product->id] ?? null" />
            @endforeach
        </div>

    @endif

    <div class="mt-2" data-pagination>
        <x-pagination :paginator="$products" />
    </div>

</div>
