<?php

namespace App\Services\Exchange;

use App\Enums\OrderExchangeState;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Vat\VatCalculator;
use App\Support\ExchangeTime;
use Illuminate\Support\Collection;

/**
 * Поток 3 (docs/1C_EXCHANGE_SPEC.md, раздел 6): GET /orders/new — подтверждённые менеджером заказы,
 * ещё не записанные в 1С и не отклонённые, старые первыми.
 *
 * Розница (baza-petrovich.by): у заказа гостя нет организации — organization = null, покупатель —
 * в customer (имя, телефон, email), получение и оплата — в delivery и payment_method. prices_include_vat:
 * true — price и total строки с НДС (VatCalculator::pricesIncludeVat), sum — без НДС.
 */
class OrdersFeed
{
    public const MAX_LIMIT = 200;

    /** @return Collection<int, Order> */
    public function pending(int $limit = 50): Collection
    {
        return Order::query()
            ->with(['items', 'organization', 'user'])
            ->where('status', OrderStatus::Confirmed)
            ->where('exchange_state', OrderExchangeState::Pending)
            ->whereNull('guid_1c')
            ->orderBy('placed_at')
            ->orderBy('id')
            ->limit(max(1, min(self::MAX_LIMIT, $limit)))
            ->get();
    }

    /** @return array<string, mixed> */
    public function payload(Order $order): array
    {
        $organization = $order->organization;

        return [
            'number' => $order->number,
            'created_at' => ExchangeTime::format($order->placed_at ?? $order->created_at),
            'organization' => $organization ? [
                'guid' => $organization->guid,
                'unp' => $organization->unp,
                'name' => $organization->name,
            ] : null,
            'user' => $order->user ? [
                'name' => $order->user->name,
                'email' => $order->user->email,
                'phone' => $order->user->phone,
            ] : null,
            'customer' => $order->user_id === null ? [
                'name' => $order->delivery_contact_name,
                'phone' => $order->delivery_contact_phone,
                'email' => $order->customer_email,
            ] : null,
            'delivery_address' => [
                'guid' => null,
                'title' => $order->delivery_title,
                'address' => $order->delivery_address,
            ],
            'delivery' => $order->delivery_method ? [
                'method' => $order->delivery_method,
                'title' => $order->deliveryLabel(),
            ] : null,
            'payment_method' => $order->payment_method,
            'comment' => $order->comment,
            'currency' => 'BYN',
            'prices_include_vat' => VatCalculator::pricesIncludeVat(),
            'total_without_vat' => (float) $order->total_without_vat,
            'vat' => (float) $order->total_vat,
            'total' => (float) $order->total_with_vat,
            'items' => $order->items->map(fn (OrderItem $item) => [
                'product_guid' => $item->product_guid,
                'sku' => $item->product_sku,
                'article' => $item->product_article,
                'client_code' => $item->client_code,
                'name' => $item->product_name,
                'qty' => (int) $item->quantity,
                'unit' => $item->unit,
                'price' => (float) $item->price,
                'vat_rate' => $item->vat_rate === null ? null : (float) $item->vat_rate,
                'sum' => (float) $item->amount_without_vat,
                'vat' => (float) $item->vat_amount,
                'total' => (float) $item->amount_with_vat,
            ])->values()->all(),
        ];
    }
}
