<div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">

    <table class="w-full text-left">

        <thead class="bg-slate-50 border-b border-slate-200">

            <tr>

                <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                    № заказа
                </th>

                <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                    Дата
                </th>

                <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                    Статус
                </th>

                <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                    Оформил
                </th>

                <th class="px-4 py-3 text-sm font-semibold text-slate-600 text-right">
                    Сумма
                </th>

                <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                </th>

            </tr>

        </thead>

        <tbody class="divide-y divide-slate-200">

            @foreach($orders as $order)

                <tr>

                    <td class="px-4 py-3 font-medium text-slate-800">
                        {{ $order->number }}
                    </td>

                    <td class="px-4 py-3 text-slate-700">
                        {{ $order->placed_at?->format('d.m.Y') }}
                    </td>

                    <td class="px-4 py-3">

                        <div class="flex items-center gap-2">

                            @include('partials.order_status', ['status' => $order->status])

                            @if($order->is_paid)

                                <span class="inline-flex rounded-full bg-green-100 text-green-700 px-2 py-0.5 text-xs">
                                    Оплачен
                                </span>

                            @endif

                        </div>

                    </td>

                    <td class="px-4 py-3 text-slate-700">
                        {{ $order->user->name ?? '—' }}
                    </td>

                    <td class="px-4 py-3 text-right font-semibold text-slate-800">
                        {{ $order->total }} BYN
                    </td>

                    <td class="px-4 py-3">

                        <div class="flex items-center gap-3">

                            <a
                                href="/order/{{ $order->id }}"
                                class="text-slate-700 hover:text-blue-600 text-sm underline"
                            >
                                Открыть
                            </a>

                            <form
                                method="POST"
                                action="/order/{{ $order->id }}/reorder"
                                class="inline"
                            >

                                @csrf

                                <button
                                    type="submit"
                                    class="text-blue-600 hover:text-blue-700 text-sm underline"
                                >
                                    Повторить
                                </button>

                            </form>

                            {{-- Отменить можно только свой заказ и только пока он «Новый» --}}
                            @if($order->status === \App\Enums\OrderStatus::New && $order->user_id === auth()->id())

                                <form
                                    method="POST"
                                    action="/order/{{ $order->id }}/cancel"
                                    class="inline"
                                >

                                    @csrf

                                    <button
                                        type="submit"
                                        class="text-red-600 hover:text-red-700 text-sm underline"
                                        onclick="return confirm('Отменить заказ {{ $order->number }}?')"
                                    >
                                        Отменить
                                    </button>

                                </form>

                            @endif

                        </div>

                    </td>

                </tr>

            @endforeach

        </tbody>

    </table>

</div>
