{{--
    Ячейка x-table. label — подпись колонки в карточке на телефоне (x-table stack);
    без label ячейка в карточке прижимается вправо (для кнопок «Открыть» и т.п.).
    align: left | right; nowrap — не переносить (суммы, даты).
    sticky: start | end — для широких таблиц без stack: главная колонка (start) и «Действия» (end)
    остаются на месте, остальные прокручиваются вбок внутри блока таблицы (узкий экран).
    Ставить и на x-table.th той же колонки.
--}}
@props(['label' => null, 'align' => 'left', 'nowrap' => false, 'sticky' => null])

<td
    @if($label) data-label="{{ $label }}" @endif
    @if($sticky) data-sticky="{{ $sticky }}" @endif
    {{ $attributes->class([
        'px-cell-x py-cell-y text-slate-700',
        'text-right' => $align === 'right',
        'whitespace-nowrap' => $nowrap,
        'sticky z-10 bg-surface' => $sticky !== null,
        'left-0 max-xl:border-r max-xl:border-slate-200' => $sticky === 'start',
        'right-0 max-xl:border-l max-xl:border-slate-200' => $sticky === 'end',
    ]) }}
>{{ $slot }}</td>
