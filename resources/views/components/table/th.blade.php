{{--
    Заголовок колонки x-table. align: left | right.
    sticky: start | end — колонка закреплена при прокрутке таблицы вбок (как у x-table.td).
--}}
@props(['align' => 'left', 'sticky' => null])

<th scope="col" {{ $attributes->class([
    'px-cell-x py-cell-y text-sm font-semibold text-slate-600 whitespace-nowrap',
    'text-right' => $align === 'right',
    'sticky z-10 bg-slate-50' => $sticky !== null,
    'left-0 max-xl:border-r max-xl:border-slate-200' => $sticky === 'start',
    'right-0 max-xl:border-l max-xl:border-slate-200' => $sticky === 'end',
]) }}>{{ $slot }}</th>
