{{--
    Блок «Характеристики» формы товара (SRS §4.9, BR-058…061). Поля — в форме $form.
    Название — из справочника (подсказки datalist) или новое: оно добавится в справочник
    при сохранении. Порядок строк = порядок на сайте; с JS — перетаскивание,
    добавление и удаление строк (attribute-rows.js), без JS — свободные строки внизу.
    Данные: $attributeRows — [['name', 'value', 'unit']], $form.
--}}
@php
    $dictionary = \App\Models\ProductAttribute::orderBy('name')->get(['name', 'unit', 'type']);
    $rows = collect($attributeRows)->values();
    // Без JS — несколько пустых строк для новых характеристик
    $spareRows = 3;
@endphp

<div
    class="bg-white rounded-2xl border border-slate-200 p-6 mb-6"
    x-data="attributeRows()"
    data-attributes-block
>

    <h2 class="text-lg font-semibold mb-1">
        Характеристики
        <span class="text-sm font-normal text-slate-400 ml-2">данные сайта · в черновик, на сайт после публикации</span>
    </h2>

    <p class="text-sm text-slate-500 mb-4">
        Выберите из <a href="/admin/attributes" class="underline" target="_blank">справочника</a> или введите новую — она добавится в справочник.
        Порядок здесь — порядок на сайте. Пустые строки не сохраняются.
    </p>

    @error('attributes')
        <div class="bg-red-50 border border-red-200 rounded-xl p-3 mb-4">
            <ul class="list-disc list-inside text-red-700 text-sm space-y-1">
                @foreach($errors->get('attributes') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @enderror

    <input type="hidden" name="attributes_sent" value="1" form="{{ $form }}">

    <datalist id="attribute-names">
        @foreach($dictionary as $item)
            <option value="{{ $item->name }}">{{ collect([$item->unit, $item->type === 'number' ? 'число' : null])->filter()->implode(', ') }}</option>
        @endforeach
    </datalist>

    <ul x-ref="list" class="space-y-2" data-attribute-rows>

        @foreach($rows as $i => $row)
            @include('partials.admin_product_attribute_row', ['index' => $i, 'row' => $row, 'form' => $form, 'spare' => false])
        @endforeach

        @for($i = $rows->count(); $i < $rows->count() + $spareRows; $i++)
            @include('partials.admin_product_attribute_row', ['index' => $i, 'row' => ['name' => '', 'value' => '', 'unit' => null], 'form' => $form, 'spare' => true])
        @endfor

    </ul>

    <template x-ref="template">
        @include('partials.admin_product_attribute_row', ['index' => '__INDEX__', 'row' => ['name' => '', 'value' => '', 'unit' => null], 'form' => $form, 'spare' => false])
    </template>

    <button type="button" x-cloak x-show="ready" @click="add()" class="mt-3 rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
        + Добавить характеристику
    </button>

</div>
