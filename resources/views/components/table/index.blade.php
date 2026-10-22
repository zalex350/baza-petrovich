{{--
    Таблица в карточке. Всегда прокручивается по горизонтали, если не помещается.
    stack — ниже брейкпоинта строки превращаются в карточки: подпись колонки берётся из
    <x-table.td label="…">, шапка скрывается визуально (остаётся для скринридеров).
    stack (= sm, телефон) | stack="md" | "lg" | "xl" — для широких таблиц, которым мало планшета.

    <x-table stack caption="Заказы">
        <x-slot:head>
            <x-table.th>№</x-table.th>
            <x-table.th align="right">Сумма</x-table.th>
        </x-slot:head>
        @foreach($orders as $order)
            <tr>
                <x-table.td label="№">{{ $order->number }}</x-table.td>
                <x-table.td label="Сумма" align="right"><x-price :amount="$order->total" /></x-table.td>
            </tr>
        @endforeach
    </x-table>
--}}
@props(['stack' => false, 'caption' => null])

@php
    // Полные имена классов — чтобы Tailwind не выбросил их из сборки (стили — resources/css/app.css)
    $stackClass = match ($stack) {
        false, null => null,
        'md'        => 'ui-table-stack-md',
        'lg'        => 'ui-table-stack-lg',
        'xl'        => 'ui-table-stack-xl',
        default     => 'ui-table-stack-sm',
    };
@endphp

{{-- relative: иначе sr-only-элементы (position: absolute) в прокручиваемой таблице растягивают страницу --}}
<div {{ $attributes->class('relative overflow-x-auto rounded-card border border-slate-200 bg-white') }}>
    <table @class(['w-full text-left', $stackClass])>
        @if($caption)
            <caption class="sr-only">{{ $caption }}</caption>
        @endif

        @isset($head)
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>{{ $head }}</tr>
            </thead>
        @endisset

        <tbody class="divide-y divide-slate-200">
            {{ $slot }}
        </tbody>

        @isset($foot)
            <tfoot class="border-t border-slate-200 bg-slate-50">{{ $foot }}</tfoot>
        @endisset
    </table>
</div>
