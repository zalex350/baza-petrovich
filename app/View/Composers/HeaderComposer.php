<?php

namespace App\View\Composers;

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

        // Удалённые товары в корзине помечены как недоступные и в сводку не входят
        $row = DB::table('cart_items')
            ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
            ->join('products', 'products.id', '=', 'cart_items.product_id')
            ->where('carts.user_id', $user->id)
            ->whereNull('products.deleted_at')
            ->selectRaw('COUNT(*) AS positions, COALESCE(SUM(cart_items.quantity * cart_items.price_at_add), 0) AS total')
            ->first();

        return [
            'count' => (int) $row->positions,
            'total' => (float) $row->total,
        ];
    }
}
