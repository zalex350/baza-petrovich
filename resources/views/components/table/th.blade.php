{{-- Заголовок колонки x-table. align: left | right --}}
@props(['align' => 'left'])

<th scope="col" {{ $attributes->class([
    'px-cell-x py-cell-y text-sm font-semibold text-slate-600 whitespace-nowrap',
    'text-right' => $align === 'right',
]) }}>{{ $slot }}</th>
