{{--
    Таблица адресов доставки — общая для админки и кабинета.
    Данные: $addresses, $baseUrl — префикс действий ('/addresses' или '/admin/organization/{id}/address').
--}}
<table class="w-full text-left">

    <thead class="border-b border-slate-200">

        <tr>

            <th class="py-2 pr-4 text-sm font-semibold text-slate-600">
                Название
            </th>

            <th class="py-2 pr-4 text-sm font-semibold text-slate-600">
                Адрес
            </th>

            <th class="py-2 pr-4 text-sm font-semibold text-slate-600">
                Контакт
            </th>

            <th class="py-2 text-sm font-semibold text-slate-600">
            </th>

        </tr>

    </thead>

    <tbody class="divide-y divide-slate-200">

        @foreach($addresses as $address)

            <tr>

                <td class="py-3 pr-4 align-top">

                    <span class="font-medium text-slate-800">
                        {{ $address->title }}
                    </span>

                    @if($address->is_default)

                        <span class="inline-flex rounded-full bg-green-100 text-green-700 px-2 py-0.5 text-xs ml-1">
                            По умолчанию
                        </span>

                    @endif

                </td>

                <td class="py-3 pr-4 align-top text-slate-700">
                    {{ $address->address }}
                </td>

                <td class="py-3 pr-4 align-top text-slate-700">

                    {{ $address->contact_name ?? '—' }}

                    @if($address->contact_phone)

                        <div class="text-sm text-slate-500">
                            {{ $address->contact_phone }}
                        </div>

                    @endif

                </td>

                <td class="py-3 align-top">

                    <div class="flex flex-wrap items-center gap-3 justify-end text-sm">

                        @if(! $address->is_default)

                            <form method="POST" action="{{ $baseUrl }}/{{ $address->id }}/default" class="inline">

                                @csrf

                                <button type="submit" class="text-slate-700 hover:text-blue-600 underline">
                                    Сделать по умолчанию
                                </button>

                            </form>

                        @endif

                        <a href="{{ $baseUrl }}/{{ $address->id }}/edit" class="text-blue-600 hover:text-blue-700 underline">
                            Изменить
                        </a>

                        <form method="POST" action="{{ $baseUrl }}/{{ $address->id }}/delete" class="inline">

                            @csrf

                            <button
                                type="submit"
                                class="text-red-600 hover:text-red-700 underline"
                                onclick="return confirm('Удалить адрес «{{ addslashes($address->title) }}»?')"
                            >
                                Удалить
                            </button>

                        </form>

                    </div>

                </td>

            </tr>

        @endforeach

    </tbody>

</table>
