<?php

namespace Tests\Concerns;

/**
 * Тест прежнего B2B-режима (smesitel.by): цены и наличие — только после входа, корзина — только
 * клиенту, цена товара без НДС, НДС начисляется сверху (shop.retail = false, shop.prices_include_vat = false).
 * По умолчанию сайт в розничном режиме — цены видны всем и включают НДС.
 */
trait B2bPricing
{
    protected function setUpB2bPricing(): void
    {
        config(['shop.retail' => false, 'shop.prices_include_vat' => false]);
    }
}
