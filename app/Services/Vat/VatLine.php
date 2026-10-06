<?php

namespace App\Services\Vat;

/**
 * Позиция с НДС: цена без НДС, количество, ставка (null — «Без НДС») и суммы.
 */
final class VatLine
{
    public function __construct(
        public readonly float $price,
        public readonly int $quantity,
        public readonly ?float $rate,
        public readonly float $net,
        public readonly float $vat,
        public readonly float $gross,
    ) {
    }

    public function rateLabel(): string
    {
        return VatCalculator::rateLabel($this->rate);
    }
}
