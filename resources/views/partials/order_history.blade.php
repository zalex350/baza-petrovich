{{--
    История статусов заказа (SRS §10.7.5).
    Данные: $order (с загруженной statusHistory), $showAuthor — показывать, кто изменил (админка).
--}}
<div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6">

    <h2 class="text-xl font-semibold mb-4">
        История статусов
    </h2>

    @if($order->statusHistory->isEmpty())

        <p class="text-slate-500">
            Изменений статуса не было
        </p>

    @else

        <table class="w-full text-left">

            <thead class="border-b border-slate-200">

                <tr>

                    <th class="py-2 pr-4 text-sm font-semibold text-slate-600">
                        Дата
                    </th>

                    <th class="py-2 pr-4 text-sm font-semibold text-slate-600">
                        Статус
                    </th>

                    @if($showAuthor)

                        <th class="py-2 pr-4 text-sm font-semibold text-slate-600">
                            Кто изменил
                        </th>

                    @endif

                    <th class="py-2 text-sm font-semibold text-slate-600">
                        Комментарий
                    </th>

                </tr>

            </thead>

            <tbody class="divide-y divide-slate-200">

                @foreach($order->statusHistory as $record)

                    <tr>

                        <td class="py-2 pr-4 text-slate-700 whitespace-nowrap">
                            {{ $record->created_at?->format('d.m.Y H:i') }}
                        </td>

                        <td class="py-2 pr-4">

                            @if($record->event)

                                {{-- Событие без смены статуса: запрос на отмену и решение по нему --}}
                                <span class="inline-flex rounded-full px-3 py-1 text-sm {{ $record->event->color() }}">
                                    {{ $record->event->label() }}
                                </span>

                            @else

                            @if($record->from_status)

                                <span class="text-slate-500">
                                    {{ $record->from_status->label() }} →
                                </span>

                            @endif

                            @include('partials.order_status', ['status' => $record->to_status])

                            @endif

                        </td>

                        @if($showAuthor)

                            <td class="py-2 pr-4 text-slate-700">
                                {{ $record->user->name ?? '—' }}
                            </td>

                        @endif

                        <td class="py-2 text-slate-700">
                            {{ $record->comment }}
                        </td>

                    </tr>

                @endforeach

            </tbody>

        </table>

    @endif

</div>
