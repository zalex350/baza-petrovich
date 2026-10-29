{{--
    Таблица заказов кабинета (BR-113, BR-114): заказы всей организации, колонка «Оформил»
    (вы — отмечено), сумма без НДС, НДС и с НДС в одной колонке. «Повторить» (SRS §8.11) — позиции в текущую корзину. На телефоне — карточки.
    Данные: $orders (с user).
--}}
<x-table stack="lg" caption="Заказы">
    <x-slot:head>
        <x-table.th>№ заказа</x-table.th>
        <x-table.th>Дата</x-table.th>
        <x-table.th>Статус</x-table.th>
        <x-table.th>Оформил</x-table.th>
        <x-table.th align="right">Сумма</x-table.th>
        <x-table.th><span class="sr-only">Действия</span></x-table.th>
    </x-slot:head>

    @foreach($orders as $order)
        <tr data-order-row="{{ $order->id }}">
            <x-table.td label="№ заказа" nowrap>
                <a href="/order/{{ $order->id }}" class="font-medium text-link hover:underline">{{ $order->number }}</a>
            </x-table.td>
            <x-table.td label="Дата" nowrap>{{ date_ru($order->placed_at) }}</x-table.td>
            <x-table.td label="Статус">
                <span class="inline-flex flex-wrap items-center gap-2">
                    <x-badge :status="$order->status" />
                    @include('partials.cancel_request_badge', ['order' => $order])
                    @if($order->is_paid)
                        <x-badge tone="success">Оплачен</x-badge>
                    @endif
                </span>
            </x-table.td>
            <x-table.td label="Оформил" data-ordered-by>
                <span>{{ $order->user->name ?? '—' }}@if($order->user_id === auth()->id())<span class="text-ink-muted"> (вы)</span>@endif</span>
            </x-table.td>
            {{-- Без НДС и НДС — мелко, под ними итог с НДС (одна колонка: таблица помещается в кабинет на 1280) --}}
            <x-table.td label="Сумма" align="right" nowrap>
                <span class="inline-flex flex-col items-end">
                    <span class="text-meta text-ink-muted">Без НДС <x-price :amount="$order->total_without_vat" /> · НДС <x-price :amount="$order->total_vat" /></span>
                    <span class="font-semibold text-ink">С НДС <x-price :amount="$order->total_with_vat" /></span>
                </span>
            </x-table.td>
            <x-table.td>
                {{-- Кнопки одна под другой: колонка узкая, таблица помещается в кабинет на 1280 --}}
                <div class="flex flex-wrap items-center justify-end gap-1.5 lg:flex-col lg:items-stretch">
                    <x-button variant="secondary" size="sm" :href="'/order/'.$order->id">Открыть</x-button>
                    @if(auth()->user()->isClient())
                        <form method="POST" action="/order/{{ $order->id }}/reorder">
                            @csrf
                            <x-button variant="secondary" size="sm" class="w-full" title="Добавить позиции заказа в корзину">Повторить</x-button>
                        </form>
                    @endif
                </div>
            </x-table.td>
        </tr>
    @endforeach
</x-table>
