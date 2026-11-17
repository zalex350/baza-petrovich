<?php

namespace App\View\Composers;

use App\Services\CartSummary;
use Illuminate\View\View;

/**
 * Данные шапки витрины: сводка корзины (позиции + сумма с НДС) одним запросом.
 */
class HeaderComposer
{
    public function __construct(private readonly CartSummary $cart) {}

    public function compose(View $view): void
    {
        $view->with('cartSummary', $this->cart->totals());
    }
}
