{{-- Бейдж статуса заказа. Данные: $status (App\Enums\OrderStatus) --}}
<span class="inline-flex rounded-full px-3 py-1 text-sm {{ $status->color() }}">
    {{ $status->label() }}
</span>
