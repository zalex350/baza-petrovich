<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Смена статуса заказа: только по допустимым переходам, с записью в историю
 * (SRS §10.7.5) и возвратом остатка при отмене.
 */
class OrderStatusService
{
    /**
     * @throws InvalidStatusTransition
     */
    public function change(Order $order, OrderStatus $to, ?User $by = null, ?string $comment = null): Order
    {
        return DB::transaction(function () use ($order, $to, $by, $comment) {
            // Блокировка заказа: два одновременных изменения не пройдут оба от одного исходного статуса
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $from = $order->status;

            if (! $from->canTransitionTo($to)) {
                throw new InvalidStatusTransition($from, $to);
            }

            if ($to === OrderStatus::Cancelled) {
                $this->returnStock($order);
            }

            $order->status = $to;
            $order->save();

            $this->record($order, $from, $to, $by, $comment);

            return $order;
        });
    }

    /**
     * Первая запись истории: заказ оформлен.
     */
    public function recordPlaced(Order $order, User $by): void
    {
        $this->record($order, null, $order->status, $by, null);
    }

    private function record(Order $order, ?OrderStatus $from, OrderStatus $to, ?User $by, ?string $comment): void
    {
        $comment = trim((string) $comment);

        OrderStatusHistory::create([
            'order_id'    => $order->id,
            'from_status' => $from,
            'to_status'   => $to,
            'user_id'     => $by?->id,
            'comment'     => $comment !== '' ? $comment : null,
        ]);
    }

    /**
     * Возвращает на склад списанное при оформлении. stock_returned_at делает возврат
     * идемпотентным: повторный вызов для того же заказа ничего не меняет.
     */
    private function returnStock(Order $order): void
    {
        if ($order->stock_returned_at !== null) {
            return;
        }

        $quantities = $order->items()
            ->whereNotNull('product_id')
            ->get(['product_id', 'quantity'])
            ->groupBy('product_id')
            ->map->sum('quantity');

        // withTrashed: остаток возвращается и удалённому (soft delete) товару — его могут восстановить.
        // Физически удалённому товару (product_id = null) возвращать некуда.
        $products = Product::withTrashed()
            ->whereIn('id', $quantities->keys())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($products as $product) {
            $product->stock += $quantities[$product->id];
            $product->save();
        }

        $order->stock_returned_at = now();
    }
}
