{{-- Строка блока «Характеристики»: $index, $row ['name', 'value', 'unit', 'warning'?], $form, $spare (пустая запасная строка) --}}
<li
    class="flex flex-wrap {{ empty($row['warning']) && ! \App\Models\Product::isPackageAttribute($row['name']) ? 'sm:flex-nowrap' : '' }} items-center gap-2"
    data-attribute-row
    @if($spare ?? false) data-spare x-show="! ready" @endif
>
    <span class="cursor-move select-none text-slate-400 text-xl px-1" data-drag-handle title="Перетащите, чтобы изменить порядок" aria-hidden="true" x-show="ready" x-cloak>⠿</span>

    <input
        type="text"
        name="attributes[{{ $index }}][name]"
        form="{{ $form }}"
        value="{{ $row['name'] }}"
        list="attribute-names"
        placeholder="Характеристика"
        aria-label="Характеристика"
        maxlength="255"
        class="w-full sm:w-2/5 border rounded px-3 py-2"
        autocomplete="off"
    >

    {{-- Длинное значение (например, «Комплектация» из импорта) — многострочным полем, видно целиком --}}
    @if(mb_strlen((string) $row['value']) > 80)
        <textarea
            name="attributes[{{ $index }}][value]"
            form="{{ $form }}"
            rows="{{ min(8, (int) ceil(mb_strlen((string) $row['value']) / 70) + 1) }}"
            placeholder="Значение"
            aria-label="Значение"
            maxlength="{{ \App\Services\ProductAttributes::VALUE_MAX }}"
            class="w-full sm:flex-1 border rounded px-3 py-2"
        >{{ $row['value'] }}</textarea>
    @else
        <input
            type="text"
            name="attributes[{{ $index }}][value]"
            form="{{ $form }}"
            value="{{ $row['value'] }}"
            placeholder="Значение"
            aria-label="Значение"
            maxlength="{{ \App\Services\ProductAttributes::VALUE_MAX }}"
            class="w-full sm:flex-1 border rounded px-3 py-2"
        >
    @endif

    <span class="w-12 text-sm text-slate-500" title="Единица из справочника">{{ $row['unit'] ?? '' }}</span>

    @if(\App\Models\Product::isPackageAttribute($row['name']))
        <p class="w-full text-xs text-slate-500 sm:order-last" data-package-attribute-note>
            На сайте — в разделе «Комплектация», если поле «Комплектация» товара пусто; в таблице характеристик не выводится.
        </p>
    @endif

    @if(! empty($row['warning']))
        <p class="w-full text-xs text-tone-warning-text sm:order-last" data-attribute-warning>{{ $row['warning'] }}</p>
    @endif

    <button type="button" x-show="ready" x-cloak @click="remove($event)" class="w-9 h-9 rounded-lg border border-slate-300 text-slate-500 hover:text-red-600 hover:border-red-300" aria-label="Удалить характеристику" title="Удалить">×</button>
</li>
