<?php

namespace Tests\Concerns;

/**
 * Тест прежнего B2B-режима цен: цена товара без НДС, НДС начисляется сверху
 * (shop.prices_include_vat = false). По умолчанию сайт в розничном режиме — цены с НДС.
 */
trait B2bPricing
{
    protected function setUpB2bPricing(): void
    {
        config(['shop.prices_include_vat' => false]);
    }
}
