<?php

namespace App\View\Composers;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Данные шапки витрины: сводка корзины (позиции + сумма) одним запросом.
 */
class HeaderComposer
{
    public function compose(View $view): void
    {
        $view->with('cartSummary', $this->cartSummary());
    }

    /**
     * @return array{count: int, total: float}
     */
    private function cartSummary(): array
    {
        $user = auth()->user();

        if (! $user) {
            return ['count' => 0, 'total' => 0.0];
        }

        $row = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.user_id', $user->id)
            ->where('orders.status', Order::STATUS_DRAFT)
            ->selectRaw('COUNT(*) AS positions, COALESCE(SUM(order_items.quantity * order_items.price), 0) AS total')
            ->first();

        return [
            'count' => (int) $row->positions,
            'total' => (float) $row->total,
        ];
    }
}
