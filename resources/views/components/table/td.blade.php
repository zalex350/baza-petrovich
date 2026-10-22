{{--
    Ячейка x-table. label — подпись колонки в карточке на телефоне (x-table stack);
    без label ячейка в карточке прижимается вправо (для кнопок «Открыть» и т.п.).
    align: left | right; nowrap — не переносить (суммы, даты).
--}}
@props(['label' => null, 'align' => 'left', 'nowrap' => false])

<td
    @if($label) data-label="{{ $label }}" @endif
    {{ $attributes->class([
        'px-cell-x py-cell-y text-slate-700',
        'text-right' => $align === 'right',
        'whitespace-nowrap' => $nowrap,
    ]) }}
>{{ $slot }}</td>
