{{--
    Позиции заказа с НДС (снимок при оформлении) и итог.
    Данные: $order (с items.product), $admin — ссылки ведут в админку.
--}}
<div class="bg-white rounded-2xl border border-slate-200 overflow-x-auto">

    <table class="w-full text-left">

        <thead class="bg-slate-50 border-b border-slate-200">

            <tr>

                <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                    Фото
                </th>

                <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                    Товар
                </th>

                <th class="px-4 py-3 text-sm font-semibold text-slate-600">
                    Артикул
                </th>

                <th class="px-4 py-3 text-sm font-semibold text-slate-600 text-right">
                    Цена без НДС
                </th>

                <th class="px-4 py-3 text-sm font-semibold text-slate-600 text-right">
                    Количество
                </th>

                <th class="px-4 py-3 text-sm font-semibold text-slate-600 text-right">
                    Сумма без НДС
                </th>

                <th class="px-4 py-3 text-sm font-semibold text-slate-600 text-right">
                    Ставка НДС
                </th>

                <th class="px-4 py-3 text-sm font-semibold text-slate-600 text-right">
                    Сумма НДС
                </th>

                <th class="px-4 py-3 text-sm font-semibold text-slate-600 text-right">
                    Сумма с НДС
                </th>

            </tr>

        </thead>

        <tbody class="divide-y divide-slate-200">

            @foreach($order->items as $item)

                <tr data-order-item="{{ $item->id }}">

                    <td class="px-4 py-3">

                        @if($item->product?->image)

                            <img
                                src="{{ asset('storage/'.$item->product->image) }}"
                                alt="{{ $item->product_name }}"
                                class="w-12 h-12 object-contain rounded border border-slate-200">

                        @else

                            <div class="w-12 h-12 bg-slate-100 flex items-center justify-center rounded border border-slate-200 text-2xl">
                                🚰
                            </div>

                        @endif

                    </td>

                    <td class="px-4 py-3">

                        {{-- Название и артикул — из снимка заказа; ссылка только пока товар существует --}}
                        @if($admin && $item->product && ! $item->product->trashed())

                            <a
                                href="/admin/product/{{ $item->product->id }}"
                                class="font-medium text-slate-800 hover:text-blue-600 transition"
                            >
                                {{ $item->product_name }}
                            </a>

                        @elseif(! $admin && $item->product && $item->product->is_active && $item->product->is_published)

                            <a
                                href="{{ route('product.show', $item->product->id) }}"
                                class="font-medium text-slate-800 hover:text-blue-600 transition"
                            >
                                {{ $item->product_name }}
                            </a>

                        @else

                            <span class="font-medium text-slate-800">
                                {{ $item->product_name }}
                            </span>

                        @endif

                    </td>

                    <td class="px-4 py-3 text-sm text-slate-400">
                        {{ $item->product_sku }}
                    </td>

                    <td class="px-4 py-3 text-right text-slate-700 whitespace-nowrap">
                        {{ number_format($item->price, 2, '.', '') }} BYN
                    </td>

                    <td class="px-4 py-3 text-right text-slate-700">
                        {{ $item->quantity }}
                    </td>

                    <td class="px-4 py-3 text-right text-slate-700 whitespace-nowrap">
                        {{ number_format($item->amount_without_vat, 2, '.', '') }} BYN
                    </td>

                    <td class="px-4 py-3 text-right text-slate-700 whitespace-nowrap">
                        {{ $item->vat_rate_label }}
                    </td>

                    <td class="px-4 py-3 text-right text-slate-700 whitespace-nowrap">
                        {{ number_format($item->vat_amount, 2, '.', '') }} BYN
                    </td>

                    <td class="px-4 py-3 text-right font-semibold text-slate-800 whitespace-nowrap">
                        {{ number_format($item->amount_with_vat, 2, '.', '') }} BYN
                    </td>

                </tr>

            @endforeach

        </tbody>

    </table>

</div>

<div class="mt-6 bg-white rounded-2xl border border-slate-200 p-6">
    @include('partials.vat_totals', ['totals' => $order->vatTotals()])
</div>
