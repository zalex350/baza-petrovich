{{--
    Ошибки загрузки кодов клиента по строкам файла (предпросмотр и отчёт).
    Данные: $rowErrors — [['row' => …, 'sku' => …, 'code' => …, 'message' => …], …] (ClientCodeImport::preview).
--}}
<x-table stack caption="Строки с ошибками" data-import-errors>
    <x-slot:head>
        <x-table.th>Строка</x-table.th>
        <x-table.th>Наш артикул</x-table.th>
        <x-table.th>Код клиента</x-table.th>
        <x-table.th>Ошибка</x-table.th>
    </x-slot:head>
    @foreach($rowErrors as $error)
        <tr>
            <x-table.td label="Строка" nowrap>{{ $error['row'] }}</x-table.td>
            <x-table.td label="Наш артикул" class="font-mono">{{ $error['sku'] !== '' ? $error['sku'] : '—' }}</x-table.td>
            <x-table.td label="Код клиента" class="font-mono">{{ $error['code'] !== '' ? $error['code'] : '—' }}</x-table.td>
            <x-table.td label="Ошибка" class="text-tone-danger-text">{{ $error['message'] }}</x-table.td>
        </tr>
    @endforeach
</x-table>
