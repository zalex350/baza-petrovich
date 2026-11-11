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

                        @include('partials.product_image', [
                            'image' => $item->product?->mainImage,
                            'alt'   => $item->product_name,
                            'class' => 'w-12 h-12 object-contain rounded border border-slate-200 bg-white',
                        ])

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
                        {{ $item->displayArticle() }}
                        {{-- SKU — внутренний код обмена с 1С: только в админке --}}
                        @if($admin && $item->product_sku && $item->product_article)
                            <div class="whitespace-nowrap" data-order-item-sku>SKU: {{ $item->product_sku }}</div>
                        @endif
                        {{-- Снимок кода клиента при оформлении: клиенту — «Ваш код», в админке — «Код клиента» --}}
                        @if($item->client_code)
                            <div class="text-slate-700 whitespace-nowrap" data-client-code>
                                {{ $admin ? 'Код клиента' : 'Ваш код' }}: <span class="font-semibold">{{ $item->client_code }}</span>
                            </div>
                        @endif
                    </td>

                    <td class="px-4 py-3 text-right text-slate-700 whitespace-nowrap">
                        <x-price :amount="$item->price" />
                    </td>

                    <td class="px-4 py-3 text-right text-slate-700">
                        {{ $item->quantity }}
                    </td>

                    <td class="px-4 py-3 text-right text-slate-700 whitespace-nowrap">
                        <x-price :amount="$item->amount_without_vat" />
                    </td>

                    <td class="px-4 py-3 text-right text-slate-700 whitespace-nowrap">
                        {{ $item->vat_rate_label }}
                    </td>

                    <td class="px-4 py-3 text-right text-slate-700 whitespace-nowrap">
                        <x-price :amount="$item->vat_amount" />
                    </td>

                    <td class="px-4 py-3 text-right font-semibold text-slate-800 whitespace-nowrap">
                        <x-price :amount="$item->amount_with_vat" />
                    </td>

                </tr>

            @endforeach

        </tbody>

    </table>

</div>

<div class="mt-6 bg-white rounded-2xl border border-slate-200 p-6">
    @include('partials.vat_totals', ['totals' => $order->vatTotals()])
</div>
