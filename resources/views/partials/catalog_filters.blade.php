{{--
    Фильтры категории (SRS §3.9). Одна GET-форма: на десктопе — колонка слева,
    на мобильном — выезжающая панель («Фильтры (N)» / «Показать товары»).
    Без JS — обычная отправка формы; с JS — список обновляется без перезагрузки (catalog-filters.js).
    Данные: $listing, $facets, $baseUrl.
--}}
<form
    id="catalog-form"
    method="GET"
    action="{{ $baseUrl }}"
    @submit.prevent="submit()"
    @change="changed($event)"
    data-catalog-form
>

    {{-- Сортировка — тоже в URL: select над списком связан с этой формой атрибутом form --}}

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
            ? 'fixed inset-y-0 left-0 z-[70] w-[min(22rem,88vw)] bg-white shadow-xl flex flex-col lg:static lg:w-auto lg:shadow-none lg:bg-transparent'
            : 'hidden lg:block'"
        aria-label="Фильтры"
        data-catalog-filters
    >

        <div class="h-14 px-4 flex items-center justify-between border-b border-slate-200 shrink-0 lg:hidden">
            <span class="font-semibold">Фильтры</span>
            <button type="button" @click="open = false" aria-label="Закрыть фильтры" class="w-10 h-10 flex items-center justify-center text-slate-600">
                @svg('heroicon-o-x-mark', 'w-6 h-6')
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-4 lg:p-0 space-y-6">

            @forelse($facets as $facet)

                <fieldset class="bg-white lg:rounded-2xl lg:border lg:border-slate-200 lg:p-4" data-facet="{{ $facet['key'] }}">

                    <legend class="font-semibold mb-2 lg:float-left lg:w-full">{{ $facet['title'] }}</legend>

                    @if($facet['kind'] === 'list')

                        <ul @class(['space-y-1.5 clear-both', 'max-h-64 overflow-y-auto pr-1' => count($facet['options']) > 10])>
                            @foreach($facet['options'] as $option)
                                <li>
                                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                                        <input type="checkbox" name="{{ $facet['name'] }}" value="{{ $option['value'] }}" @checked($option['checked']) class="rounded border-slate-300">
                                        <span class="grow">{{ $option['label'] }}</span>
                                        <span class="text-xs text-slate-400">{{ $option['count'] }}</span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>

                    @else

                        @php($fmt = fn ($n) => $n === null ? '' : rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'))

                        <div class="flex items-center gap-2 clear-both">
                            <input
                                type="number"
                                step="any"
                                inputmode="decimal"
                                name="{{ $facet['name'] }}[from]"
                                value="{{ $fmt($facet['from']) }}"
                                placeholder="от {{ $fmt($facet['min']) }}"
                                aria-label="{{ $facet['title'] }}: от"
                                class="w-full border rounded px-2 py-1.5 text-sm"
                            >
                            <span class="text-slate-400">—</span>
                            <input
                                type="number"
                                step="any"
                                inputmode="decimal"
                                name="{{ $facet['name'] }}[to]"
                                value="{{ $fmt($facet['to']) }}"
                                placeholder="до {{ $fmt($facet['max']) }}"
                                aria-label="{{ $facet['title'] }}: до"
                                class="w-full border rounded px-2 py-1.5 text-sm"
                            >
                        </div>

                    @endif

                </fieldset>

            @empty

                <p class="text-sm text-slate-500">Для этой категории фильтров нет.</p>

            @endforelse


            {{-- Без JS и на десктопе — «Применить»; на мобильном — «Показать товары» внизу панели --}}
            <button type="submit" class="hidden lg:block w-full bg-slate-800 hover:bg-slate-700 text-white py-2.5 rounded-xl" data-apply>
                Применить
            </button>

        </div>

        <div class="border-t border-slate-200 p-4 shrink-0 lg:hidden flex gap-3">
            <a href="{{ $listing->resetUrl($baseUrl) }}" class="px-4 py-2.5 rounded-xl border border-slate-300 text-slate-700" data-catalog-link>Сбросить</a>
            <button type="submit" class="grow bg-slate-800 hover:bg-slate-700 text-white py-2.5 rounded-xl">
                Показать товары
            </button>
        </div>

    </aside>

</form>
