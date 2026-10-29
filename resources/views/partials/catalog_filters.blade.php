{{--
    Фильтры категории (SRS §3.9), макет docs/design/Catalog. Одна GET-форма: на десктопе — карточка
    слева, на мобильном — выезжающая панель («Фильтры (N)» / «Показать товары»).
    Порядок: «Только в наличии» (stock[]=in_stock) первым, затем остальные фильтры категории
    в порядке настройки; у списков больше BRIEF значений — «Показать все». «Сбросить фильтры» — внизу.
    Без JS — обычная отправка формы («Применить»); с JS — список обновляется без перезагрузки (catalog-filters.js).
    Данные: $listing, $facets, $baseUrl.
--}}
@php
    // Значений списка видно сразу; остальные — по «Показать все» (отмеченные видны всегда)
    $brief = 5;

    $stockFacet = collect($facets)->firstWhere('key', 'stock');
    $inStock = $stockFacet ? collect($stockFacet['options'])->firstWhere('value', 'in_stock') : null;

    $fmt = fn ($n) => $n === null ? '' : rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
@endphp

<form
    id="catalog-form"
    method="GET"
    action="{{ $baseUrl }}"
    @submit.prevent="submit()"
    @change="changed($event)"
    data-catalog-form
>

    {{-- Сортировка — тоже в URL: select над списком связан с этой формой атрибутом form --}}
    {{-- Вид списка (плитка) сохраняется при смене фильтров --}}
    @if(($view ?? 'list') === 'grid')
        <input type="hidden" name="view" value="grid">
    @endif

    <div
        class="fixed inset-0 bg-black/40 z-[60] lg:hidden"
        x-show="open"
        x-transition.opacity
        x-cloak
        @click="open = false"
        aria-hidden="true"
    ></div>

    <aside
        id="catalog-filters-panel"
        class="lg:block"
        :class="open
            ? 'fixed inset-y-0 left-0 z-[70] w-[min(22rem,88vw)] bg-surface shadow-xl flex flex-col lg:static lg:w-auto lg:shadow-none'
            : 'hidden lg:block'"
        aria-label="Фильтры"
        data-catalog-filters
    >

        <div class="h-14 px-4 flex items-center justify-between border-b border-line shrink-0 lg:hidden">
            <span class="font-semibold">Фильтры</span>
            <button type="button" @click="open = false" aria-label="Закрыть фильтры" class="w-touch h-touch -mr-2.5 flex items-center justify-center text-ink-muted">
                @svg('heroicon-o-x-mark', 'w-6 h-6')
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-4 flex flex-col gap-5 lg:overflow-visible lg:rounded-card lg:border lg:border-line lg:bg-surface lg:p-4">

            {{-- «Только в наличии» — тот же параметр stock[]=in_stock, что и в списке «Наличие» --}}
            @if($inStock)
                <div data-facet="stock">
                    <x-checkbox
                        name="stock[]"
                        id="f-stock-in_stock"
                        value="in_stock"
                        label="Только в наличии"
                        :checked="$inStock['checked']"
                        :unchecked="false"
                        position="end"
                        class="font-semibold"
                    />

                    {{-- «Под заказ» / «Нет в наличии» отдельными галочками не показываются, но выбор из URL сохраняется --}}
                    @foreach($stockFacet['options'] as $option)
                        @if($option['checked'] && $option['value'] !== 'in_stock')
                            <input type="hidden" name="stock[]" value="{{ $option['value'] }}">
                        @endif
                    @endforeach
                </div>
            @endif

            @forelse($facets as $facet)

                @continue($facet['key'] === 'stock')

                <fieldset data-facet="{{ $facet['key'] }}">

                    <legend class="mb-2 font-semibold">{{ $facet['title'] }}</legend>

                    @if($facet['kind'] === 'list')

                        @php($more = count($facet['options']) > $brief)

                        <ul class="flex flex-col gap-2" @if($more) x-data="{ all: false }" @endif>
                            @foreach($facet['options'] as $option)
                                <li @if($more && $loop->index >= $brief && ! $option['checked']) x-show="all" x-cloak @endif>
                                    <x-checkbox
                                        :name="$facet['name']"
                                        :id="'f-'.\Illuminate\Support\Str::slug($facet['key']).'-'.$loop->index"
                                        :value="$option['value']"
                                        :label="$option['label']"
                                        :checked="$option['checked']"
                                        :unchecked="false"
                                        :meta="$option['count']"
                                    />
                                </li>
                            @endforeach

                            @if($more)
                                <li>
                                    <button type="button" class="text-sm text-link hover:underline" @click="all = ! all" x-text="all ? 'Свернуть' : 'Показать все'" :aria-expanded="all.toString()">
                                        Показать все
                                    </button>
                                </li>
                            @endif
                        </ul>

                    @else

                        <div class="flex items-center gap-2">
                            <x-input
                                :name="$facet['name'].'[from]'"
                                type="number"
                                step="any"
                                inputmode="decimal"
                                :value="$fmt($facet['from'])"
                                :placeholder="'от '.$fmt($facet['min'])"
                                :aria-label="$facet['title'].': от'"
                                class="flex-1"
                            />
                            <x-input
                                :name="$facet['name'].'[to]'"
                                type="number"
                                step="any"
                                inputmode="decimal"
                                :value="$fmt($facet['to'])"
                                :placeholder="'до '.$fmt($facet['max'])"
                                :aria-label="$facet['title'].': до'"
                                class="flex-1"
                            />
                        </div>

                    @endif

                </fieldset>

            @empty

                <p class="text-sm text-ink-muted">Для этой категории фильтров нет.</p>

            @endforelse

            {{-- Без JS фильтры применяются этой кнопкой; с JS — сразу при изменении --}}
            <noscript>
                <x-button class="hidden w-full lg:flex" data-apply>Применить</x-button>
            </noscript>

            <x-button variant="secondary" :href="$listing->resetUrl($baseUrl)" class="hidden w-full lg:flex" data-catalog-link data-reset-filters>
                Сбросить фильтры
            </x-button>

        </div>

        <div class="border-t border-line p-4 shrink-0 lg:hidden flex gap-3">
            <x-button variant="secondary" :href="$listing->resetUrl($baseUrl)" data-catalog-link>Сбросить</x-button>
            <x-button class="grow">Показать товары</x-button>
        </div>

    </aside>

</form>
