<?php

namespace App\View\Composers;

use App\Services\Vat\VatCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Данные шапки витрины: сводка корзины (позиции + сумма с НДС) одним запросом.
 */
class HeaderComposer
{
    public function compose(View $view): void
    {
        $view->with('cartSummary', $this->cartSummary());
    }

    /**
     * total — сумма с НДС, vat — НДС в ней; НДС считается по позициям (VatCalculator),
     * поэтому сумма та же, что в «Сумма с НДС» на странице корзины.
     *
     * @return array{count: int, total: float, vat: float}
     */
    private function cartSummary(): array
    {
        $user = auth()->user();

        if (! $user) {
            return ['count' => 0, 'total' => 0.0, 'vat' => 0.0];
        }

        // Недоступные товары (удалён, скрыт, снят с публикации) в корзине помечены
        // и в сводку не входят — как и в «Итого» на странице корзины
        $rows = DB::table('cart_items')
            ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
            ->join('products', 'products.id', '=', 'cart_items.product_id')
            ->where('carts.user_id', $user->id)
            ->whereNull('products.deleted_at')
            ->where('products.is_active', true)
            ->where('products.is_published', true)
            ->whereNotNull('products.price')
            ->get(['cart_items.quantity', 'cart_items.price_at_add', 'products.vat_rate']);

        $vat = app(VatCalculator::class);

        $totals = $vat->total($rows->map(
            fn ($row) => $vat->line($row->price_at_add, (int) $row->quantity, $row->vat_rate)
        ));

        return [
            'count' => $rows->count(),
            'total' => $totals->gross,
            'vat'   => $totals->vat,
        ];
    }
}
