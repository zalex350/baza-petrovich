{{-- Наличие (SRS §4.11.3): В наличии / Под заказ / Нет в наличии. Данные: $product --}}
@if($product->stock > 0)
    <span class="inline-flex rounded-full bg-green-100 text-green-700 px-3 py-1 text-sm" data-stock="in_stock">
        В наличии ({{ $product->stock }})
    </span>
@elseif($product->on_order)
    <span class="inline-flex rounded-full bg-amber-100 text-amber-800 px-3 py-1 text-sm" data-stock="on_order">
        Под заказ
    </span>
@else
    <span class="inline-flex rounded-full bg-red-100 text-red-700 px-3 py-1 text-sm" data-stock="out_of_stock">
        Нет в наличии
    </span>
@endif
